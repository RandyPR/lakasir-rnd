<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\OpenBillStatus;
use App\Exceptions\CartChangedException;
use App\Exceptions\OpenBill\BillClosedException;
use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillEmptyException;
use App\Exceptions\SaleBusyException;
use App\Features\Member as FeaturesMember;
use App\Features\Voucher;
use App\Filament\Tenant\Pages\Traits\CartInteraction;
use App\Filament\Tenant\Resources\Traits\RefreshThePage;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\CashDrawer;
use App\Models\Tenants\Member;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Models\Tenants\Voucher as TenantsVoucher;
use App\Services\Tenants\CashierDraftService;
use App\Services\Tenants\CustomerNameService;
use App\Services\Tenants\DailyNumber;
use App\Services\Tenants\OpenBillContext;
use App\Services\Tenants\OpenBillGate;
use App\Services\Tenants\OpenBillService;
use App\Services\Tenants\OrderLineSorter;
use App\Services\Tenants\OrderTotals;
use App\Services\Tenants\PaymentPlanner;
use App\Services\Tenants\SellingService;
use App\Services\VoucherService;
use App\Support\BusinessDay;
use App\Support\Money;
use App\Traits\HasTranslatableResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Filament\Support\RawJs;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as CollectionSupport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Livewire;
use Throwable;

class Cashier extends Page implements HasForms
{
    use CartInteraction, HasTranslatableResource, RefreshThePage;

    /** Sent by the cashier page with every Livewire call once it holds the static parts (see getViewData()). */
    public const STATIC_PARTS_HEADER = 'X-Lakasir-Static-Parts';

    public static ?string $label = 'POS';

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'filament.tenant.pages.cashier';

    public static function canAccess(): bool
    {
        return can('create selling');
    }

    /**
     * The lines on screen: the cashier's private cart (CartItem), or the lines of the open bill (OpenBillItem).
     *
     * @var Collection<int, CartItem|OpenBillItem>
     */
    public Collection $cartItems;

    public Collection $availableVoucher;

    protected static string $layout = 'filament-panels::components.layout.base';

    public array $cartDetail = [];

    public array $paymentMethods;

    public CollectionSupport $members;

    #[Locked]
    public float $tax;

    public string $currency;

    public string $locale;

    /** Subtotal before tax and discounts. */
    public float $sub_total = 0;

    /** What the customer pays (OrderTotals amount_due): tax added, line, header and voucher discounts taken off. */
    public float $total_price = 0;

    public ?About $about;

    public ?Collection $tableOption;

    /** Customer name as the server will probably record it ("Budi (2)"); null when it is the same as typed. */
    public ?string $customerNameHint = null;

    /** True when the order header (name, note, member, table) came back from the saved draft on page load. */
    public bool $draftRestored = false;

    /** The open bill being worked on; null = the cashier's private cart ("New order"). */
    #[Locked]
    public ?int $openBillId = null;

    /** Version of that bill as this screen last read it (before its lines). Pinned while the payment modal is open. */
    #[Locked]
    public ?int $openBillVersion = null;

    /** True while the payment modal is open: syncBill() does nothing and the version stays as it was. */
    #[Locked]
    public bool $paymentPinned = false;

    /**
     * Summary of the open bill for the view: id, number, number_label, label, table_label, customer_name, version,
     * sent, batches, stale, crossed_midnight, business_date_label, items_count, pending_count (things the kitchen
     * has not been told), cancel_pending_count, tax_percent, can_void, can_cancel_sent.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $openBill = null;

    /** UX fence against a double submit; the bill status / cart rows under lock are the truth. Rotated after a failure. */
    public string $paymentToken = '';

    /** Setting open_bill_enabled (default on). Only governs CREATING bills (Save / Send); open bills stay usable. */
    #[Locked]
    public bool $billsEnabled = true;

    /** The open_bills table exists and (bills are enabled or some bill is still open). */
    #[Locked]
    public bool $billsVisible = true;

    /** Kitchen tickets exist (business type "fnb"). */
    #[Locked]
    public bool $kitchenEnabled = false;

    /** Payment modal switch: print the unsent kitchen changes right before the receipt. */
    public bool $printKitchenOnPay = true;

    /** Payment modal confirmation: let lines the kitchen already served go below zero stock (owner / update selling). */
    public bool $stockOverride = false;

    /** @var array{pending: int}|null */
    #[Locked]
    public ?array $kitchenPreview = null;

    /** The bill was brought back after a page reload (the view shows "Resuming Bill #12"). */
    #[Locked]
    public bool $billResumed = false;

    /** The bill row read (version first) by the latest reload of this request; not kept between requests. */
    private ?OpenBill $billRow = null;

    /** Why that bill could not be loaded: 'paid', 'void' or 'missing'. */
    private ?string $billGone = null;

    /** Set by reloadBill(true): the header (name, table, member, note, discount) is taken from the bill again. */
    private bool $refreshHeaderOnReload = false;

    private const CUSTOMER_NAME_MAX = 240;

    public function mount(): void
    {
        $this->about = About::first() ?? null;

        $this->tax = (float) Setting::get('default_tax', 0);

        $this->currency = Setting::get('currency', 'IDR');

        $this->locale = Profile::get()->locale ?? 'en';

        $this->billsEnabled = $this->settingEnabled('open_bill_enabled', true);
        $this->kitchenEnabled = $this->about?->business_type === 'fnb';
        $this->billsVisible = $this->computeBillsVisible();

        $this->cartItems = $this->loadCartItems();
        $today = BusinessDay::today();
        $vouchers = TenantsVoucher::query()
            ->where('minimal_buying', '<=', $this->cartItems->sum('price'))
            ->where('start_date', '<=', $today)
            ->where('expired', '>=', $today)
            ->get();

        $this->availableVoucher = $vouchers;

        $this->calculateTotalPrice();

        $cash = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            [
                'is_cash' => true,
                'is_debit' => false,
                'is_credit' => false,
                'is_wallet' => false,
                'icon' => 'assets/images/payment-methods/cash.png',
            ]
        );

        PaymentMethod::firstOrCreate(
            ['name' => 'QRIS'],
            [
                'is_cash' => false,
                'is_debit' => false,
                'is_credit' => false,
                'is_wallet' => true,
                'icon' => 'assets/images/payment-methods/qris.png',
            ]
        );

        $this->paymentMethods = PaymentMethod::query()
            ->select('id', 'name', 'is_cash', 'is_credit', 'is_wallet', 'is_debit')
            ->get()
            ->toArray();

        $this->members = Member::query()
            ->select('id', 'name')
            ->get()
            ->pluck('name', 'id');

        $this->tableOption = Table::select('id', 'number')->get();

        $this->fillOrderDefaults($cash->id);

        $this->restoreDraft();
        $this->resumeBillContext();
        $this->registerRenderHooks();
    }

    /**
     * Scripts and styles of the cashier page are rendered by Filament render hooks (full page load only), so a
     * Livewire request never pays for them. The print script is shared with the sale detail page.
     */
    protected function registerRenderHooks(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn () => view('livewire.cashier.partials.head'),
            scopes: static::class,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('filament.tenant.pages.cashier.bootstrap', ['cartQty' => $this->cartQtyMap()]),
            scopes: static::class,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('filament.tenant.partials.receipt-print'),
            scopes: static::class,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('filament.tenant.pages.cashier.scripts'),
            scopes: static::class,
        );
    }

    /**
     * A setting that is on unless it was explicitly switched off ('0', 'false', 0, false, 'off', 'no').
     * Setting::get() caches for 3 hours, so a switch flipped in the database needs `cache:clear`.
     */
    private function settingEnabled(string $key, bool $default): bool
    {
        $value = Setting::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return $default;
        }

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * The bill UI only exists once the open_bills table does (a deploy that is not finished must never 500).
     * With the kill switch off the UI stays visible only while some bill is still open, so it can be paid.
     */
    private function computeBillsVisible(): bool
    {
        if (! DailyNumber::billsTableAvailable()) {
            return false;
        }

        if ($this->billsEnabled) {
            return true;
        }

        try {
            return OpenBill::query()->open()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Blank order header: default payment method, no customer, member, table, note or voucher.
     */
    private function fillOrderDefaults(int|string|null $paymentMethodId): void
    {
        $this->storeCartForm->fill([
            'payment_method_id' => $paymentMethodId,
            'total_price' => $this->total_price,
            'friend_price' => false,
            'voucher' => null,
            'discount_price' => 0,
            'note' => null,
            'member_id' => null,
        ]);

        $this->cartDetail['customer_name'] = null;
        $this->cartDetail['payment_method_id'] = $paymentMethodId;
        $this->cartDetail['table_id'] = null;
        $this->cartDetail['table_label'] = null;

        $this->fillPayemntMethod();
        $this->fillTableLabel();
    }

    /**
     * Brings back name, note, member and table saved by this cashier, but only while the cart still has lines:
     * a draft without a cart is leftover from a finished order. Ids that no longer exist are dropped.
     * Only for the NEW (not yet saved) order: a saved bill owns its own header.
     */
    protected function restoreDraft(): void
    {
        $userId = Filament::auth()->id();
        if ($userId === null || $this->openBillId !== null) {
            return;
        }

        $drafts = app(CashierDraftService::class);

        if ($this->cartItems->isEmpty()) {
            $drafts->forget($userId);

            return;
        }

        $draft = $drafts->load($userId);
        $restored = false;

        if (! empty($draft['customer_name'])) {
            $this->cartDetail['customer_name'] = $this->normalizeCustomerName((string) $draft['customer_name']);
            $restored = $restored || $this->cartDetail['customer_name'] !== null;
        }

        if (! empty($draft['note'])) {
            $this->cartDetail['note'] = (string) $draft['note'];
            $restored = true;
        }

        if (! empty($draft['member_id']) && $this->members->has((int) $draft['member_id'])) {
            $this->cartDetail['member_id'] = (int) $draft['member_id'];
            $restored = true;
        }

        if (! empty($draft['table_id']) && $this->tableOption->contains('id', (int) $draft['table_id'])) {
            $this->cartDetail['table_id'] = (int) $draft['table_id'];
            $restored = true;
        }

        $this->fillMember();
        $this->fillTableLabel();

        $this->customerNameHint = $this->resolveCustomerNameHint($this->cartDetail['customer_name'] ?? null);
        $this->draftRestored = $restored;
    }

    public function discardDraft(): void
    {
        if ($this->openBillId !== null) {
            return;
        }

        $this->forgetDraft();

        $this->cartDetail['customer_name'] = null;
        $this->cartDetail['note'] = null;
        $this->cartDetail['member_id'] = null;
        $this->cartDetail['member_label'] = null;
        $this->cartDetail['table_id'] = null;
        $this->cartDetail['table_label'] = null;
        $this->customerNameHint = null;
        $this->draftRestored = false;

        $this->dispatch('order-reset');
    }

    /**
     * The draft belongs to the new, unsaved order only: while a bill is open its header is on the bill.
     */
    private function saveDraft(): void
    {
        $userId = Filament::auth()->id();
        if ($userId === null || $this->openBillId !== null) {
            return;
        }

        app(CashierDraftService::class)->save($userId, [
            'customer_name' => $this->cartDetail['customer_name'] ?? null,
            'note' => $this->cartDetail['note'] ?? null,
            'member_id' => $this->cartDetail['member_id'] ?? null,
            'table_id' => $this->cartDetail['table_id'] ?? null,
        ]);
    }

    private function forgetDraft(): void
    {
        $userId = Filament::auth()->id();
        if ($userId !== null) {
            app(CashierDraftService::class)->forget($userId);
        }
    }

    protected function getForms(): array
    {
        return [
            'storeCartForm',
        ];
    }

    public function storeCartForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('member_id')
                    ->visible(hasFeatureAndPermission(FeaturesMember::class))
                    ->label('Member')
                    ->getSearchResultsUsing(function (string $search): array {
                        return Member::query()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->hiddenLabel()
                    ->extraAttributes([
                        'data-field' => 'memberSelect',
                    ])
                    ->searchable(),
                Textarea::make('note')
                    ->hiddenLabel()
                    ->rows(2)
                    ->maxLength(500)
                    ->extraAttributes([
                        'data-field' => 'noteInput',
                    ]),
                TextInput::make('voucher')
                    ->hiddenLabel()
                    ->extraAttributes([
                        'data-field' => 'voucherInput',
                    ])
                    ->visible(hasFeatureAndPermission(Voucher::class)),
                TextInput::make('discount_price')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->prefix(Setting::get('currency', 'IDR'))
                    ->extraAttributes([
                        'data-field' => 'discountInput',
                    ])
                    ->hiddenLabel()
                    ->label(__('Manual Discount')),
            ])
            ->statePath('cartDetail')
            ->model(Selling::class);
    }

    /**
     * Pulls the edit-detail form (member, note, voucher, discount) into $cartDetail. A form that does not
     * validate is reported to the cashier instead of being swallowed.
     */
    private function syncFormState(): bool
    {
        try {
            $this->cartDetail = array_merge($this->cartDetail, $this->storeCartForm->getState());
        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function storeCart(): void
    {
        if (! $this->syncFormState()) {
            return;
        }

        if (! empty($this->cartDetail['voucher'])) {
            $this->validateVoucher($this->cartDetail['voucher']);
        }

        // On a bill the header is written to the bill (member, note and manual discount; the name and the table have
        // their own commit). Nothing changed = nothing written.
        if ($this->openBillId !== null) {
            $this->pushBillHeader([
                'member_id' => $this->cartDetail['member_id'] ?? null,
                'note' => $this->cartDetail['note'] ?? null,
                'discount_price' => $this->manualDiscount(),
            ]);
        }

        $this->calculateTotalPrice();
        $this->fillMember();
        $this->fillPayemntMethod();
        $this->customerNameHint = $this->openBillId === null
            ? $this->resolveCustomerNameHint($this->cartDetail['customer_name'] ?? null)
            : null;
        $this->saveDraft();

        $this->dispatch('close-modal', id: 'edit-detail');
    }

    private function fillPayemntMethod()
    {
        $paymentMethod = collect($this->paymentMethods)->filter(function ($value, int $key) {
            return $value['id'] == $this->cartDetail['payment_method_id'];
        })->first();
        if (isset($paymentMethod['name'])) {
            $this->cartDetail['payment_method_label'] = $paymentMethod['name'];
        }
    }

    private function fillMember()
    {
        $member = $this->members->filter(function (string $value, int $key) {
            return $key == ($this->cartDetail['member_id'] ?? null);
        })->first();
        $this->cartDetail['member_label'] = $member;
    }

    public function selectTable(?int $tableId = null): void
    {
        $table = $tableId ? Table::find($tableId) : null;

        if ($this->openBillId !== null) {
            $this->pushBillHeader(['table_id' => $table?->id]);
            $this->dispatch('close-modal', id: 'modal-selected-table');

            return;
        }

        if ($table) {
            $this->cartDetail['table_id'] = $table->id;
            $this->cartDetail['table_label'] = $table->number;
        } else {
            $this->cartDetail['table_id'] = null;
            $this->cartDetail['table_label'] = null;
        }

        $this->saveDraft();
        $this->dispatch('close-modal', id: 'modal-selected-table');
    }

    public function saveCustomTable(?string $customNumber = null): void
    {
        $number = trim((string) $customNumber);
        $table = null;

        if (! empty($number)) {
            $table = Table::firstOrCreate(['number' => $number]);
            $this->tableOption = Table::select('id', 'number')->get();
        }

        if ($this->openBillId !== null) {
            $this->pushBillHeader(['table_id' => $table?->id]);
            $this->dispatch('close-modal', id: 'modal-selected-table');

            return;
        }

        $this->cartDetail['table_id'] = $table?->id;
        $this->cartDetail['table_label'] = $table?->number;

        $this->saveDraft();
        $this->dispatch('close-modal', id: 'modal-selected-table');
    }

    private function fillTableLabel(): void
    {
        if (! empty($this->cartDetail['table_id'])) {
            $table = Table::find($this->cartDetail['table_id']);
            $this->cartDetail['table_label'] = $table?->number;
        } else {
            $this->cartDetail['table_label'] = null;
        }
    }

    /**
     * Trim, cap at 240 characters, empty means no name. The text is kept exactly as typed otherwise: the
     * daily "(2)" numbering happens once, on the server, when the sale (or the bill) is created.
     */
    private function normalizeCustomerName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim(mb_substr(trim($name), 0, self::CUSTOMER_NAME_MAX));

        return $name === '' ? null : $name;
    }

    /**
     * What the sale will probably be recorded as ("Budi (2)"), or null when it is the name as typed.
     * Informational only: the real number is decided inside SellingService::create.
     */
    private function resolveCustomerNameHint(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $resolved = CustomerNameService::generateDailyCustomerName($name);

        return $resolved !== null && $resolved !== $name ? $resolved : null;
    }

    public function commitCustomerName(?string $name = null): void
    {
        $name = $this->normalizeCustomerName($name);

        $this->cartDetail['customer_name'] = $name;

        if ($this->openBillId !== null) {
            // The bill numbers the name itself ("Budi (2)"), so the final name comes back and no hint is needed.
            $this->customerNameHint = null;
            $this->pushBillHeader(['customer_name' => $name]);

            return;
        }

        $this->customerNameHint = $this->resolveCustomerNameHint($name);
        $this->saveDraft();
    }

    /**
     * Called when the payment dialog opens (and by "Reload bill" in it): take the latest lines (notes are saved by
     * their own components), refresh the totals and the "will be recorded as" hint, and start a new payment:
     * a fresh token, and on a bill the version is PINNED (read before the lines) until the dialog closes.
     */
    public function syncCartForPayment(): void
    {
        if ($this->openBillId !== null) {
            $this->billUser();

            // Unpin first so the reload takes the newest version, then pin that one.
            $this->paymentPinned = false;
            $this->reloadBill(true);

            if ($this->openBillId === null) {
                // Paid or voided elsewhere (the toast says which). The page is back on the new order, which may
                // have lines of its own: the dialog must not go on to take money for those.
                $this->dispatch('close-modal', id: 'proceed-the-payment');

                return;
            }
        } else {
            $this->cartItems = $this->loadCartItems();
            $this->customerNameHint = $this->resolveCustomerNameHint($this->cartDetail['customer_name'] ?? null);
            $this->calculateTotalPrice();
        }

        $this->paymentToken = (string) Str::uuid();
        $this->paymentPinned = true;
        $this->printKitchenOnPay = true;
        $this->stockOverride = false;
        $this->kitchenPreview = $this->openBillId !== null && $this->kitchenEnabled
            ? ['pending' => (int) ($this->openBill['pending_count'] ?? 0)]
            : null;
    }

    /**
     * The payment dialog was closed: the bill may be synchronised again.
     */
    public function paymentClosed(): void
    {
        $this->paymentPinned = false;
        $this->kitchenPreview = null;
        $this->stockOverride = false;
        $this->printKitchenOnPay = true;
    }

    #[On('product-scanned')]
    public function onProductScanned(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        $this->addCartUsingScanner($code);
    }

    /**
     * Undo of a removed line: puts the product back with the quantity, note, discount and price unit it had.
     * On a bill this goes through OpenBillService::restoreLine, which nets a line the kitchen had already received
     * against its pending cancellation (the kitchen hears nothing about a removal that was undone).
     */
    public function restoreCartItem(int $productId, float $qty, ?string $note = null, ?float $discountPrice = null, ?int $priceUnitId = null): void
    {
        $product = Product::query()->find($productId);
        if (! $product || $qty <= 0) {
            $this->refreshCart();

            return;
        }

        if ($this->openBillId !== null) {
            $user = $this->billUser();

            try {
                app(OpenBillService::class)->restoreLine((int) $this->openBillId, [
                    'product_id' => $product->getKey(),
                    'qty' => $qty,
                    'note' => $note,
                    'discount_price' => $discountPrice,
                    'price_unit_id' => $priceUnitId,
                ], $user);
            } catch (DomainException|ValidationException|InvalidArgumentException $e) {
                $this->reportBillFailure($e);

                return;
            }

            $this->refreshCart();
            $this->dispatch('cart-item-touched', productId: (int) $product->getKey());

            return;
        }

        if (! $this->validateStock($product, $qty)) {
            return;
        }

        $cartItem = CartItem::query()->firstOrNew([
            'product_id' => $product->getKey(),
            'user_id' => Filament::auth()->id(),
        ]);
        $cartItem->qty = $qty;
        $cartItem->price = $product->selling_price * $qty;

        if ($note !== null) {
            $cartItem->note = $note;
        }

        if ($discountPrice !== null && $discountPrice > 0) {
            $cartItem->discount_price = $discountPrice;
        }

        $cartItem->price_unit_id = $priceUnitId !== null && PriceUnit::query()
            ->whereKey($priceUnitId)
            ->where('product_id', $product->getKey())
            ->exists() ? $priceUnitId : null;
        $cartItem->save();

        $this->refreshCart();
        $this->dispatch('cart-item-touched', productId: (int) $product->getKey());
    }

    public function clearCart()
    {
        // "Clear" is about the new order; it must never wipe a bill.
        if ($this->openBillId !== null) {
            return;
        }

        CartItem::query()
            ->cashier()
            ->delete();

        Notification::make()
            ->title(__('Cart has been cleared'))
            ->success()
            ->send();

        $this->resetOrder();
        $this->dispatch('order-reset');
    }

    /**
     * Back to an empty order: cart reloaded, header blank, hint and draft gone. Lighter than mount()
     * (no payment method seeding, no member/table reload).
     */
    private function resetOrder(): void
    {
        $this->refreshCart();

        $this->fillOrderDefaults($this->defaultPaymentMethodId());

        $this->customerNameHint = null;
        $this->draftRestored = false;
        $this->forgetDraft();
    }

    private function defaultPaymentMethodId(): int|string|null
    {
        return collect($this->paymentMethods)->firstWhere('name', 'Cash')['id']
            ?? ($this->paymentMethods[0]['id'] ?? null);
    }

    protected function onCartRefreshed(): void
    {
        if ($this->openBillId !== null) {
            if ($this->billRow === null) {
                $this->closeGoneBill();

                return;
            }

            if ($this->refreshHeaderOnReload) {
                $this->fillHeaderFromBill($this->billRow);
                $this->calculateTotalPrice();
            }
            $this->refreshHeaderOnReload = false;

            $this->openBill = $this->describeBill($this->billRow);
        }

        $this->dispatch('cart-qty-updated', qty: $this->cartQtyMap());

        if ($this->openBillId === null && $this->cartItems->isEmpty()) {
            $this->forgetDraft();
            $this->draftRestored = false;
        }
    }

    // ----------------------------------------------------------------------------------------------------
    // Open bill: context
    // ----------------------------------------------------------------------------------------------------

    protected function activeBillId(): ?int
    {
        return $this->openBillId;
    }

    /**
     * Read the bill row BEFORE its lines (and keep that version): lines can then only be newer than the version
     * we hold, never older, so a payment can never be signed with a version that is newer than what was shown.
     * While the payment dialog is open the version stays pinned.
     */
    protected function beforeCartReload(): void
    {
        $this->billRow = null;
        $this->billGone = null;

        if ($this->openBillId === null) {
            return;
        }

        $bill = OpenBill::query()->with('table')->find($this->openBillId);

        if (! $bill) {
            $this->billGone = 'missing';

            return;
        }

        if ($bill->status !== OpenBillStatus::Open) {
            $this->billGone = $bill->status === OpenBillStatus::Paid ? 'paid' : 'void';

            return;
        }

        $this->billRow = $bill;

        if (! $this->paymentPinned || $this->openBillVersion === null) {
            $this->openBillVersion = (int) $bill->version;
        }
    }

    /**
     * Reload the open bill: version first, then (with $withHeader) its header, then the lines.
     */
    private function reloadBill(bool $withHeader): void
    {
        $this->refreshHeaderOnReload = $withHeader;
        $this->refreshCart();
        $this->refreshHeaderOnReload = false;
    }

    /**
     * The bill is not open any more (paid or voided elsewhere): say so and go back to the new order.
     */
    private function closeGoneBill(): void
    {
        $message = match ($this->billGone) {
            'paid' => __('Bill already paid'),
            'void' => __('Bill is closed'),
            default => __('Bill not found'),
        };

        Notification::make()->title($message)->warning()->send();

        $this->onBillClosed();
    }

    /**
     * Used by the trait when a bill call reports a closed bill: leave the bill without another toast.
     */
    protected function onBillClosed(): void
    {
        if ($this->openBillId === null) {
            return;
        }

        $this->exitBillContext();
        $this->dispatch('open-bills-changed');
        $this->dispatch('order-reset');
    }

    /**
     * Leave the bill context and show the (private) new order again, header draft included.
     */
    private function exitBillContext(): void
    {
        $userId = Filament::auth()->id();
        if ($userId !== null) {
            OpenBillContext::forget((int) $userId);
        }

        $this->openBillId = null;
        $this->openBillVersion = null;
        $this->openBill = null;
        $this->billRow = null;
        $this->billGone = null;
        $this->billResumed = false;
        $this->paymentPinned = false;
        $this->kitchenPreview = null;
        $this->stockOverride = false;
        $this->printKitchenOnPay = true;
        $this->customerNameHint = null;
        $this->draftRestored = false;

        $this->refreshCart();
        $this->fillOrderDefaults($this->defaultPaymentMethodId());
        $this->restoreDraft();
    }

    private function resumeBillContext(): void
    {
        $userId = Filament::auth()->id();
        if ($userId === null || ! $this->billsVisible) {
            return;
        }

        $billId = OpenBillContext::recall((int) $userId);
        if ($billId === null) {
            return;
        }

        try {
            $bill = OpenBill::query()->open()->whereKey($billId)->first(['id']);
        } catch (Throwable) {
            $bill = null;
        }

        if (! $bill) {
            OpenBillContext::forget((int) $userId);

            return;
        }

        $this->openBillId = (int) $bill->getKey();
        $this->openBillVersion = null;
        $this->reloadBill(true);

        if ($this->openBillId !== null) {
            $this->billResumed = true;
            OpenBillContext::remember((int) $userId, $this->openBillId);
        }
    }

    private function fillHeaderFromBill(OpenBill $bill): void
    {
        $discount = (float) $bill->discount_price;

        $this->cartDetail['customer_name'] = $bill->customer_name;
        $this->cartDetail['note'] = $bill->note;
        $this->cartDetail['member_id'] = $bill->member_id;
        $this->cartDetail['table_id'] = $bill->table_id;
        $this->cartDetail['table_label'] = $bill->getRelationValue('table')?->number;
        $this->cartDetail['discount_price'] = $discount > 0 ? $discount : 0;
        $this->cartDetail['voucher'] = null;

        $this->fillMember();

        $this->customerNameHint = null;
        $this->draftRestored = false;
    }

    /**
     * The summary the view needs, built from the bill row and the lines already loaded (no extra queries).
     *
     * @return array<string, mixed>
     */
    private function describeBill(OpenBill $bill): array
    {
        $user = Filament::auth()->user();
        $today = BusinessDay::today();
        $businessDate = $bill->business_date instanceof \DateTimeInterface
            ? $bill->business_date->format('Y-m-d')
            : (string) $bill->business_date;

        $bill->setRelation('items', $this->cartItems);

        $pending = $this->kitchenEnabled ? app(OpenBillService::class)->pendingCount($bill) : 0;
        $reduced = $this->cartItems
            ->filter(fn (OpenBillItem $item): bool => Money::qty((float) $item->qty) < Money::qty((float) $item->sent_qty))
            ->count();

        $stale = $businessDate < $today
            && $bill->created_at !== null
            && $bill->created_at->lt(now()->subHours((int) config('pos.stale_after_hours', 6)));

        $number = (int) $bill->number;

        return [
            'id' => (int) $bill->getKey(),
            'number' => $number,
            'number_label' => '#'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'label' => $bill->label(),
            'table_label' => $bill->getRelationValue('table')?->number,
            'customer_name' => $bill->customer_name,
            'version' => (int) $bill->version,
            'sent' => (int) $bill->kitchen_batch_count > 0,
            'batches' => (int) $bill->kitchen_batch_count,
            'stale' => $stale,
            'crossed_midnight' => $businessDate < $today && ! $stale,
            'business_date_label' => BusinessDay::label($businessDate, $today),
            'items_count' => $this->cartItems->count(),
            'pending_count' => $pending,
            'cancel_pending_count' => count((array) ($bill->pending_cancel ?? [])) + $reduced,
            'tax_percent' => (float) $bill->tax_percent,
            'can_void' => $user instanceof User && OpenBillGate::canVoid($bill, $user),
            'can_cancel_sent' => $user instanceof User && OpenBillGate::canCancelSent($bill, $user),
        ];
    }

    private function billService(): OpenBillService
    {
        return app(OpenBillService::class);
    }

    /**
     * Bills can be used at all (the table exists). Tells the cashier otherwise.
     */
    private function ensureBillsAvailable(): bool
    {
        if ($this->billsVisible && DailyNumber::billsTableAvailable()) {
            return true;
        }

        Notification::make()
            ->title(__('Bills are unavailable until the update is finished'))
            ->warning()
            ->send();

        return false;
    }

    /**
     * A NEW bill may be created: the table exists and the kill switch is on.
     */
    private function ensureBillCreation(): bool
    {
        if (! $this->ensureBillsAvailable()) {
            return false;
        }

        if (! $this->billsEnabled) {
            Notification::make()
                ->title(__('Bills are unavailable until the update is finished'))
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    /**
     * A bill that is still open, with everything the printers need. Tells the cashier otherwise.
     */
    private function findOpenBill(int $billId): ?OpenBill
    {
        try {
            $bill = $this->billService()->find($billId);
        } catch (BillClosedException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }

        if ($bill->status !== OpenBillStatus::Open) {
            Notification::make()
                ->title($bill->status === OpenBillStatus::Paid ? __('Bill already paid') : __('Bill is closed'))
                ->warning()
                ->send();
            $this->dispatch('open-bills-changed');

            return null;
        }

        return $bill;
    }

    public function openBill(int $billId): void
    {
        $user = $this->billUser();

        if (! $this->ensureBillsAvailable()) {
            return;
        }

        $bill = OpenBill::query()->find($billId);

        if (! $bill) {
            Notification::make()->title(__('Bill not found'))->danger()->send();
            $this->dispatch('open-bills-changed');

            return;
        }

        if ($bill->status !== OpenBillStatus::Open) {
            Notification::make()
                ->title($bill->status === OpenBillStatus::Paid ? __('Bill already paid') : __('Bill is closed'))
                ->warning()
                ->send();
            $this->dispatch('open-bills-changed');

            return;
        }

        $this->openBillId = (int) $bill->getKey();
        $this->openBillVersion = null;
        $this->billResumed = false;
        $this->paymentPinned = false;
        $this->paymentToken = '';
        $this->kitchenPreview = null;
        $this->stockOverride = false;
        $this->printKitchenOnPay = true;
        $this->customerNameHint = null;
        $this->draftRestored = false;

        $this->reloadBill(true);

        if ($this->openBillId === null) {
            return;
        }

        OpenBillContext::remember((int) $user->getKey(), $this->openBillId);

        $this->dispatch('open-bill-changed', id: $this->openBillId, number: (int) $bill->number, version: $this->openBillVersion);
    }

    public function leaveBill(): void
    {
        if ($this->openBillId === null) {
            return;
        }

        $this->exitBillContext();
        $this->dispatch('order-reset');
    }

    #[On('open-bill-selected')]
    public function onOpenBillSelected(int $id): void
    {
        $this->openBill($id);
    }

    #[On('open-bill-pay')]
    public function onOpenBillPay(int $id): void
    {
        $this->openBill($id);

        if ($this->openBillId !== $id) {
            return;
        }

        $this->syncCartForPayment();

        if ($this->openBillId === $id) {
            $this->dispatch('open-modal', id: 'proceed-the-payment');
        }
    }

    /**
     * A child component (item note, unit price) changed a line of the open bill. Reload the version FIRST, then the
     * lines (reloadBill reads in that order). Our own events and a bill that did not change cost one query.
     */
    #[On('open-bill-changed')]
    public function onBillChildChanged(int $id): void
    {
        if ($this->openBillId === null || $id !== $this->openBillId || $this->paymentPinned) {
            return;
        }

        $this->billUser();

        $version = OpenBill::query()->whereKey($this->openBillId)->value('version');

        if ($version !== null && (int) $version === $this->openBillVersion) {
            return;
        }

        $this->reloadBill(false);
    }

    /**
     * wire:poll.20s.visible: pick up changes other devices made to the open bill. Does nothing while the payment
     * dialog is open (the version is pinned) or when the bill did not change. The browser holds the poll back while
     * the cashier is typing, so a header being typed is never overwritten.
     */
    public function syncBill(): void
    {
        if ($this->openBillId === null || $this->paymentPinned) {
            return;
        }

        $this->billUser();

        $row = OpenBill::query()->whereKey($this->openBillId)->first(['id', 'status', 'version']);

        if (! $row || $row->status !== OpenBillStatus::Open) {
            $this->billGone = ! $row ? 'missing' : ($row->status === OpenBillStatus::Paid ? 'paid' : 'void');
            $this->closeGoneBill();

            return;
        }

        if ((int) $row->version === $this->openBillVersion) {
            return;
        }

        $this->reloadBill(true);

        if ($this->openBillId !== null) {
            Notification::make()->title(__('Bill was changed on another device'))->info()->send();
        }
    }

    #[On('bill-voided')]
    public function onBillVoided(int $id): void
    {
        if ($this->openBillId !== null && $id === $this->openBillId) {
            $this->onBillClosed();
        }
    }

    /**
     * Writes header fields (customer_name, table_id, member_id, note, discount_price) to the open bill with the
     * version this screen holds. Returns false (and says why) when it was refused.
     *
     * @param  array<string, mixed>  $changes
     */
    private function pushBillHeader(array $changes): bool
    {
        $user = $this->billUser();

        if ($this->openBillId === null || $this->openBillVersion === null) {
            return false;
        }

        $service = $this->billService();

        try {
            $bill = OpenBill::query()->find($this->openBillId);

            if (! $bill) {
                throw BillClosedException::notFound();
            }

            $updated = $service->updateHeader($bill, $changes, $this->openBillVersion, $user);
        } catch (BillConflictException $e) {
            $this->handleBillConflict($e);

            return false;
        } catch (DomainException|ValidationException $e) {
            $this->reportBillFailure($e);

            return false;
        }

        // Our own write raised the version: keep it, even while the payment dialog has it pinned.
        $this->openBillVersion = (int) $updated->version;
        $this->reloadBill(true);
        $this->dispatch('open-bills-changed');

        return true;
    }

    /**
     * The bill moved on while this screen held an older version. Nothing was written. Tells the cashier, and the
     * browser (`bill-conflict`) shows the new total with a "Reload bill" button.
     */
    private function handleBillConflict(BillConflictException $e): void
    {
        Notification::make()
            ->title(__('Bill was changed on another device'))
            ->warning()
            ->send();

        $billId = $this->openBillId;
        if ($billId === null) {
            return;
        }

        try {
            $service = $this->billService();
            $fresh = $service->find($billId);
            $amountDue = $service->totals($fresh)['amount_due'];
            $label = $fresh->label();
        } catch (BillClosedException) {
            $this->onBillClosed();

            return;
        }

        $this->dispatch(
            'bill-conflict',
            id: $billId,
            serverVersion: $e->serverVersion,
            label: $label,
            amount_due: $amountDue,
        );
    }

    // ----------------------------------------------------------------------------------------------------
    // Open bill: save, kitchen, print, void
    // ----------------------------------------------------------------------------------------------------

    /**
     * Turns the private cart into a bill (the cart is emptied by the service). Null with a message when it cannot.
     */
    private function createBillFromCart(User $user): ?OpenBill
    {
        if (! $this->syncFormState()) {
            return null;
        }

        // What the screen showed before: a cart that was full there and is empty here means the first request
        // probably did create the bill and only its answer got lost.
        $hadItems = $this->cartItems->isNotEmpty();

        try {
            return $this->billService()->createFromCart($user, [
                'customer_name' => $this->normalizeCustomerName($this->cartDetail['customer_name'] ?? null),
                'table_id' => $this->cartDetail['table_id'] ?? null,
                'member_id' => $this->cartDetail['member_id'] ?? null,
                'note' => $this->cartDetail['note'] ?? null,
                'discount_price' => $this->manualDiscount(),
            ]);
        } catch (BillEmptyException) {
            Notification::make()
                ->title($hadItems ? __('The bill may already exist, check the bills list') : __('Add the bill first'))
                ->warning()
                ->send();
            $this->refreshCart();

            return null;
        } catch (SaleBusyException|DomainException|ValidationException $e) {
            $this->reportBillFailure($e);

            return null;
        }
    }

    /**
     * After a bill was created from the private cart the cart is empty: back to a blank new order.
     */
    private function startNewOrderAfterBill(): void
    {
        $this->resetOrder();
        $this->dispatch('order-reset');
    }

    private function announceSaved(OpenBill $bill, bool $sent): void
    {
        $this->dispatch(
            'bill-saved',
            id: (int) $bill->getKey(),
            number: (int) $bill->number,
            label: $bill->label(),
            sent: $sent,
            // A shop without a kitchen gets a plain "saved", never "NOT sent to the kitchen yet".
            kitchen: $this->kitchenEnabled,
        );
        $this->dispatch('open-bills-changed');
    }

    /**
     * "Save only". A new order becomes a bill and the screen goes back to an empty new order; on an open bill
     * (everything is already written) the screen just goes back to the new order.
     */
    public function saveBill(): void
    {
        $user = $this->billUser();

        if ($this->openBillId !== null) {
            $summary = $this->openBill ?? [];
            $bill = OpenBill::query()->with('table')->find($this->openBillId);

            if (! $bill) {
                $this->onBillClosed();

                return;
            }

            $sent = $this->kitchenEnabled
                && (int) ($summary['batches'] ?? 0) > 0
                && (int) ($summary['pending_count'] ?? 0) === 0;

            $this->exitBillContext();
            $this->dispatch('order-reset');
            $this->announceSaved($bill, $sent);

            return;
        }

        if (! $this->ensureBillCreation()) {
            return;
        }

        $bill = $this->createBillFromCart($user);
        if (! $bill) {
            return;
        }

        $this->startNewOrderAfterBill();
        $this->announceSaved($bill, false);
    }

    /**
     * Kitchen only: the order has neither a table nor a name, so the ticket would read TAKEAWAY. The cashier must
     * confirm (or fill the table); the browser asks and calls sendToKitchen(..., takeaway: true).
     */
    private function promptTakeaway(?int $number): void
    {
        $this->dispatch(
            'takeaway-prompt',
            number: $number,
            number_label: $number !== null ? '#'.str_pad((string) $number, 3, '0', STR_PAD_LEFT) : null,
        );
    }

    private function estimateNextNumber(): ?int
    {
        try {
            return DailyNumber::next(BusinessDay::today());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "Send to kitchen": saves the order as a bill if needed, builds the ticket (a pure read), and hands it to the
     * browser together with the acknowledgement the browser sends back (`kitchen-ack`) after it printed. Then the
     * screen is a blank new order again. Given a bill id (from the bills list) it works on that bill and leaves the
     * screen where it is.
     */
    public function sendToKitchen(?int $billId = null, bool $takeaway = false): void
    {
        $user = $this->billUser();

        if (! $this->kitchenEnabled) {
            return;
        }

        $remote = $billId !== null && $billId !== $this->openBillId;
        $targetId = $billId ?? $this->openBillId;
        $created = false;

        if ($targetId === null) {
            if (! $this->ensureBillCreation()) {
                return;
            }

            if (! $takeaway && $this->cartItems->isNotEmpty()
                && empty($this->cartDetail['table_id'])
                && $this->normalizeCustomerName($this->cartDetail['customer_name'] ?? null) === null) {
                $this->promptTakeaway($this->estimateNextNumber());

                return;
            }

            $bill = $this->createBillFromCart($user);
            if (! $bill) {
                return;
            }

            $created = true;
        } else {
            $bill = $this->findOpenBill($targetId);
            if (! $bill) {
                if (! $remote) {
                    $this->onBillClosed();
                }

                return;
            }

            if (! $takeaway && (int) $bill->kitchen_batch_count === 0
                && $bill->table_id === null
                && blank($bill->customer_name)) {
                $this->promptTakeaway((int) $bill->number);

                return;
            }
        }

        $service = $this->billService();

        try {
            $batch = $service->kitchenBatch($bill);
        } catch (BillClosedException $e) {
            $this->reportBillFailure($e);

            return;
        }

        if ($batch === null) {
            $this->notifyNothingNew($bill);

            if ($created) {
                $this->startNewOrderAfterBill();
                $this->announceSaved($bill, false);
            }

            return;
        }

        $payload = $service->printPayload($bill, 'kitchen', $batch, false, $user);
        $this->dispatch(
            'bill-print',
            mode: 'kitchen',
            bill: $payload,
            reprint: false,
            ack: $batch['ack'] ?? null,
            job_key: (string) ($payload['job_key'] ?? 'k'.$bill->getKey().'-'.($batch['batch_no'] ?? 0)),
        );

        if ($created) {
            $this->startNewOrderAfterBill();
        } elseif (! $remote) {
            $this->exitBillContext();
            $this->dispatch('order-reset');
        }

        $this->announceSaved($bill, true);
    }

    /**
     * "Nothing new to send", with a button to print the last ticket again when there is one.
     */
    private function notifyNothingNew(OpenBill $bill): void
    {
        $notification = Notification::make()
            ->title(__('Nothing new to send'))
            ->warning();

        if ($this->billService()->lastKitchenBatch($bill) !== null) {
            $notification->actions([
                NotificationAction::make('reprint')
                    ->label(__('Reprint last kitchen ticket'))
                    ->button()
                    ->close()
                    ->dispatch('reprint-kitchen-last', [(int) $bill->getKey()]),
            ]);
        }

        $notification->send();
    }

    #[On('reprint-kitchen-last')]
    public function onReprintKitchenLast(int $id): void
    {
        $this->reprintKitchen($id);
    }

    /**
     * The kitchen printed (or the cashier chose "mark as sent without printing"): remember what it knows.
     * Idempotent on the server. If it is the bill open on screen, the version moved, so reload it.
     *
     * @param  array<string, mixed>  $ack  the `ack` of the ticket that was printed
     * @param  string  $mode  'printed' or 'manual'
     */
    #[On('kitchen-ack')]
    public function ackKitchen(array $ack, string $mode = 'printed'): void
    {
        $user = $this->billUser();

        $billId = (int) ($ack['bill_id'] ?? 0);
        if ($billId <= 0) {
            return;
        }

        try {
            $this->billService()->ackKitchenBatch($billId, $ack, $user, $mode === 'manual' ? 'manual' : 'printed');
        } catch (BillConflictException) {
            // The ticket is from a bill state that no longer exists: the next Send builds a fresh one.
            $this->dispatch('open-bills-changed');

            return;
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->dispatch('open-bills-changed');

        if ($this->openBillId !== null && $billId === $this->openBillId && ! $this->paymentPinned) {
            $this->reloadBill(false);
        }
    }

    /**
     * The provisional bill ("Bill sementara"). An unsaved order has no number yet, so it is saved as a bill first
     * and then stays open on screen (the cashier is probably about to take payment).
     */
    public function printProvisional(?int $billId = null): void
    {
        $user = $this->billUser();

        $targetId = $billId ?? $this->openBillId;

        if ($targetId === null) {
            if (! $this->ensureBillCreation()) {
                return;
            }

            $created = $this->createBillFromCart($user);
            if (! $created) {
                return;
            }

            $this->resetOrder();
            $this->openBill((int) $created->getKey());

            if ($this->openBillId === null) {
                return;
            }

            $targetId = (int) $created->getKey();
        }

        $bill = $this->findOpenBill($targetId);
        if (! $bill) {
            return;
        }

        $payload = $this->billService()->printPayload($bill, 'bill', null, false, $user);
        $this->dispatch(
            'bill-print',
            mode: 'bill',
            bill: $payload,
            reprint: false,
            ack: null,
            job_key: (string) ($payload['job_key'] ?? 'b'.$bill->getKey().'-v'.$bill->version),
        );
    }

    /**
     * The last kitchen ticket again, marked as a copy, exactly as it was sent. Changes nothing on the bill.
     */
    public function reprintKitchen(?int $billId = null): void
    {
        $user = $this->billUser();

        $targetId = $billId ?? $this->openBillId;
        if ($targetId === null) {
            return;
        }

        $bill = $this->findOpenBill($targetId);
        if (! $bill) {
            return;
        }

        $service = $this->billService();
        $batch = $service->lastKitchenBatch($bill);

        if ($batch === null) {
            Notification::make()->title(__('Not sent to kitchen'))->warning()->send();

            return;
        }

        $service->log($bill, OpenBillEvent::REPRINT, $user, [
            'detail' => ['batch_no' => $batch['batch_no'] ?? null],
        ]);

        $payload = $service->printPayload($bill, 'kitchen', $batch, true, $user);
        $this->dispatch(
            'bill-print',
            mode: 'kitchen',
            bill: $payload,
            reprint: true,
            ack: null,
            job_key: (string) ($payload['job_key'] ?? 'kitchen'.$bill->getKey().'-r'),
        );
    }

    /**
     * Lowers a line below what the kitchen already received, or removes such a line. Needs a reason and the right
     * to cancel sent items (checked by the service against the locked bill). The kitchen is told at the next Send.
     */
    public function cancelSent(int $itemId, float $newQty, string $reason): void
    {
        $user = $this->billUser();

        if ($this->openBillId === null) {
            return;
        }

        $reason = trim($reason);

        /** @var OpenBillItem|null $item */
        $item = $this->lineQuery()->find($itemId);

        if (! $item) {
            $this->refreshCart();

            return;
        }

        $newQty = Money::qty(max(0.0, $newQty));

        try {
            if ($newQty <= 0) {
                $this->dispatchCartItemRemoved($item);
            }

            $this->billService()->setLineQty((int) $this->openBillId, $itemId, $newQty, $user, $reason);
        } catch (DomainException|ValidationException|InvalidArgumentException $e) {
            $this->reportBillFailure($e);

            return;
        }

        $this->refreshCart();
        $this->dispatch('open-bills-changed');
    }

    /**
     * Voids a bill (the open one, or the given one without leaving the screen). The reason is required for every
     * bill; whether this user may do it is decided by the service against the locked bill. A bill the kitchen
     * already received prints a "BILL VOIDED" ticket.
     */
    public function voidBill(string $reason, ?int $billId = null): void
    {
        $user = $this->billUser();

        $targetId = $billId ?? $this->openBillId;
        if ($targetId === null) {
            return;
        }

        $service = $this->billService();
        $active = $targetId === $this->openBillId;

        try {
            $bill = $service->find($targetId);
            $expected = $active ? (int) $this->openBillVersion : (int) $bill->version;
            $ticket = $service->void($bill, $user, trim($reason), $expected);
        } catch (BillConflictException $e) {
            if ($active) {
                $this->handleBillConflict($e);
            } else {
                Notification::make()->title($e->getMessage())->warning()->send();
            }

            return;
        } catch (DomainException $e) {
            $this->reportBillFailure($e);

            return;
        }

        if ($ticket !== null) {
            $voided = $service->find($targetId);
            $payload = $service->printPayload($voided, 'kitchen', $ticket, false, $user);
            $this->dispatch(
                'bill-print',
                mode: 'kitchen',
                bill: $payload,
                reprint: false,
                ack: null,
                job_key: (string) ($payload['job_key'] ?? 'kitchen'.$targetId.'-void'),
            );
        }

        Notification::make()->title(__('Bill voided'))->success()->send();

        $label = $bill->label();
        $number = (int) $bill->number;

        if ($active) {
            $this->exitBillContext();
            $this->dispatch('order-reset');
        }

        $this->dispatch('open-bills-changed');
        $this->dispatch('bill-voided', id: $targetId, number: $number, label: $label);
    }

    // ----------------------------------------------------------------------------------------------------
    // Payment
    // ----------------------------------------------------------------------------------------------------

    /**
     * Pays the open bill (through OpenBillService::pay) or the private cart (straight through SellingService).
     * Both paths: friend_price is forced off, the payment kind comes from PaymentPlanner (never from the method's
     * name), the stock is verified inside the sale transaction, and a failure rotates the payment token.
     *
     * Problems the payment dialog shows next to a field are thrown as ValidationException (payed_money, member_id,
     * due_date, products.N.qty); the rest become notifications.
     */
    public function proceedThePayment(SellingService $sellingService): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->openBillId !== null) {
            abort_unless(OpenBillGate::canUse($user), 403);
        }

        if ($this->paymentToken === '') {
            $this->paymentToken = (string) Str::uuid();
        }

        $doneKey = $this->paymentCacheKey('paydone');
        $lockKey = $this->paymentCacheKey('paylock');

        // The same payment was already recorded (its answer got lost): show that receipt instead of staying silent.
        $doneId = Cache::get($doneKey);
        if ($doneId) {
            $existing = Selling::query()->find($doneId);

            if ($existing) {
                $this->announcePaid($existing, alreadyPaid: true, kitchenPrinted: false);

                return;
            }
        }

        $succeeded = false;
        $lockState = 'idle';

        try {
            if (! $this->cashDrawerReady()) {
                Notification::make()
                    ->title(__('Cash drawer is not opened'))
                    ->danger()
                    ->send();

                return;
            }

            $paid = $this->openBillId !== null
                ? $this->payBill($user, $lockKey, $lockState)
                : $this->payDirect($sellingService, $user, $lockKey, $lockState);

            if ($paid === null) {
                return;
            }

            [$selling, $alreadyPaid, $kitchenPrinted] = $paid;

            Cache::put($doneKey, $selling->getKey(), 600);
            $succeeded = true;

            $this->announcePaid($selling, $alreadyPaid, $kitchenPrinted);
        } catch (ValidationException $e) {
            // Keys the payment dialog does not show next to a field would otherwise vanish.
            $outsideDialog = collect($e->errors())
                ->reject(fn ($messages, string $key): bool => in_array($key, ['payed_money', 'member_id', 'due_date'], true)
                    || str_starts_with($key, 'products.'))
                ->flatten()
                ->first();

            if ($outsideDialog) {
                Notification::make()->title($outsideDialog)->danger()->send();
            }

            throw $e;
        } catch (BillConflictException $e) {
            $this->handleBillConflict($e);
        } catch (BillClosedException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
            $this->onBillClosed();
        } catch (SaleBusyException|CartChangedException|DomainException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            if ($e instanceof CartChangedException) {
                $this->syncCartForPayment();
            }
        } finally {
            if ($lockState === 'locked') {
                Cache::forget($lockKey);
            }

            // Any failure rotates the token (a retry is a new attempt), except when another request owns it.
            if (! $succeeded && $lockState !== 'busy') {
                $this->paymentToken = (string) Str::uuid();
            }
        }
    }

    private function paymentCacheKey(string $prefix): string
    {
        return $prefix.':'.Filament::auth()->id().':'.$this->paymentToken;
    }

    /**
     * Setting cash_drawer_enabled needs an opened drawer. False = say so (no 500).
     */
    private function cashDrawerReady(): bool
    {
        if (! Setting::get('cash_drawer_enabled', false)) {
            return true;
        }

        return CashDrawer::lastOpened()->exists();
    }

    /**
     * What the cashier typed in the payment dialog. The server never trusts the lines, discounts or tax of the
     * client, only these fields.
     *
     * @return array{payment_method_id: mixed, payed_money: mixed, member_id: mixed, due_date: mixed, voucher: mixed}
     */
    private function paymentInput(): array
    {
        return [
            'payment_method_id' => $this->cartDetail['payment_method_id'] ?? null,
            'payed_money' => $this->cartDetail['payed_money'] ?? null,
            'member_id' => $this->cartDetail['member_id'] ?? null,
            'due_date' => $this->cartDetail['due_date'] ?? null,
            'voucher' => filled($this->cartDetail['voucher'] ?? null) ? trim((string) $this->cartDetail['voucher']) : null,
        ];
    }

    /**
     * Acquires the in-flight guard of this payment token ('locked'). False = another request holds it ('busy'): nothing is done.
     */
    private function acquirePaymentLock(string $lockKey, string &$lockState): bool
    {
        if (! Cache::add($lockKey, 1, 30)) {
            $lockState = 'busy';
            Notification::make()->title(__('Payment is being processed'))->info()->send();

            return false;
        }

        $lockState = 'locked';

        return true;
    }

    /**
     * Pays the open bill. Returns [selling, alreadyPaid, kitchenPrinted], or null when nothing was done.
     *
     * @return array{0: Selling, 1: bool, 2: bool}|null
     */
    private function payBill(User $user, string $lockKey, string &$lockState): ?array
    {
        $billId = (int) $this->openBillId;
        $service = $this->billService();

        $bill = $service->find($billId);

        // The service validates the payment (method, cash short, credit without member or due date) before it
        // locks anything and reports it with the same field keys the dialog shows.
        $payment = $this->paymentInput();

        // Unsent kitchen changes: the ticket is built BEFORE paying and printed right after, before the receipt.
        $kitchenBatch = null;
        if ($this->kitchenEnabled && $this->printKitchenOnPay && $bill->status === OpenBillStatus::Open
            && $service->pendingCount($bill) > 0) {
            $kitchenBatch = $service->kitchenBatch($bill);
        }

        if (! $this->acquirePaymentLock($lockKey, $lockState)) {
            return null;
        }

        $result = $service->pay(
            $bill,
            $payment,
            (int) $this->openBillVersion,
            $user,
            ['stock_override' => $this->stockOverride],
        );

        $kitchenPrinted = false;
        if ($kitchenBatch !== null && ! $result->alreadyPaid) {
            $paidBill = $service->find($billId);
            $payload = $service->printPayload($paidBill, 'kitchen', $kitchenBatch, false, $user);
            $this->dispatch(
                'bill-print',
                mode: 'kitchen',
                bill: $payload,
                reprint: false,
                ack: null,
                job_key: (string) ($payload['job_key'] ?? 'kitchen'.$billId.'-pay'),
            );
            $kitchenPrinted = true;
        }

        $this->exitBillContext();
        $this->dispatch('open-bills-changed');

        return [$result->selling, $result->alreadyPaid, $kitchenPrinted];
    }

    /**
     * Pays the private cart. Returns [selling, false, false], or null when nothing was done.
     *
     * @return array{0: Selling, 1: bool, 2: bool}|null
     */
    private function payDirect(SellingService $sellingService, User $user, string $lockKey, string &$lockState): ?array
    {
        // The cart table is the single source of truth (notes are saved by their own components).
        $lines = $this->loadCartItems();
        $this->cartItems = $lines;

        if ($lines->isEmpty()) {
            Notification::make()
                ->title(__('No item'))
                ->warning()
                ->send();

            return null;
        }

        if (! $this->syncFormState()) {
            return null;
        }

        $customerName = $this->cartDetail['customer_name'] ?? null;
        $customerName = is_string($customerName) && trim($customerName) !== '' ? trim($customerName) : null;
        $this->cartDetail['customer_name'] = $customerName;

        $totals = $this->computeTotals();
        $this->total_price = $totals['amount_due'];

        $method = PaymentMethod::find($this->cartDetail['payment_method_id'] ?? null);
        if (! $method) {
            $method = PaymentMethod::firstOrCreate([
                'name' => 'Cash',
            ], [
                'is_cash' => true,
                'is_debit' => false,
                'is_credit' => false,
                'is_wallet' => false,
                'icon' => 'assets/images/payment-methods/cash.png',
            ]);
        }

        $payment = $this->paymentInput();

        $products = $lines->map(function (CartItem $cartItem): array {
            return [
                'product_id' => $cartItem->product_id,
                'qty' => $cartItem->qty,
                'price' => $cartItem->price,
                'discount_price' => $cartItem->discount_price,
                'price_unit_id' => $cartItem->price_unit_id,
                'note' => $cartItem->note,
            ];
        })->values()->all();

        $request = [
            'customer_name' => $customerName,
            'payment_method_id' => $method->getKey(),
            'member_id' => $payment['member_id'],
            'due_date' => $payment['due_date'],
            'note' => $this->cartDetail['note'] ?? null,
            'table_id' => $this->cartDetail['table_id'] ?? null,
            'voucher' => $payment['voucher'],
            'discount_price' => $this->manualDiscount(),
            'tax' => $this->currentTaxPercent(),
            // The cashier's price is never a "friend price"; the amounts come from the cart rows.
            'friend_price' => false,
            'total_price' => $totals['total_price'],
            'products' => $products,
        ];

        $validator = Validator::make($request, [
            'customer_name' => ['nullable', 'string', 'max:'.self::CUSTOMER_NAME_MAX],
            'payment_method_id' => ['required'],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'exists:products,id'],
            // Any quantity above zero, as the cart and a bill take it (half a kilo); min:1 refused 0,5 here only.
            'products.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->messages()->toArray());
        }

        $plan = PaymentPlanner::plan($method, $totals['amount_due'], [
            'payed_money' => $payment['payed_money'],
            'member_id' => $payment['member_id'],
            'due_date' => $payment['due_date'],
        ]);

        $data = array_merge($request, $sellingService->mapProductRequest($request));
        $data['payed_money'] = $plan['payed_money'];
        $data['money_changes'] = $plan['money_changes'];

        $userId = (int) $user->getKey();
        $signature = $this->lineSignature($lines);

        $hooks = [
            'verify_stock' => true,
            // Inside the sale transaction: the cart must still be exactly what the payment was built from.
            'before' => function () use ($userId, $signature): void {
                $current = CartItem::query()
                    ->where('user_id', $userId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($this->lineSignature($current) !== $signature) {
                    throw new CartChangedException();
                }
            },
            // The cart is emptied in the same transaction, so a rolled back sale never loses it.
            'after' => function () use ($userId): void {
                CartItem::query()->where('user_id', $userId)->delete();
            },
        ];

        if (! $this->acquirePaymentLock($lockKey, $lockState)) {
            return null;
        }

        $selling = $sellingService->create($data, $hooks);

        $this->forgetDraft();
        $this->resetOrder();

        return [$selling, false, false];
    }

    /**
     * A comparable fingerprint of cart rows (id, qty, price, discount, unit, note), sorted by id.
     *
     * @param  CollectionSupport<int, CartItem>  $lines
     * @return list<array<string, mixed>>
     */
    private function lineSignature(CollectionSupport $lines): array
    {
        return $lines
            ->sortBy('id')
            ->map(fn (CartItem $line): array => [
                'id' => (int) $line->getKey(),
                'qty' => Money::qty((float) $line->qty),
                'price' => Money::round((float) $line->price),
                'discount_price' => Money::round((float) ($line->discount_price ?? 0)),
                'price_unit_id' => $line->price_unit_id !== null ? (int) $line->price_unit_id : null,
                'note' => $line->note,
            ])
            ->values()
            ->all();
    }

    /**
     * Tells the browser a sale exists: success dialog, receipt data and a reset order.
     */
    private function announcePaid(Selling $selling, bool $alreadyPaid, bool $kitchenPrinted): void
    {
        Notification::make()
            ->title($alreadyPaid ? __('Payment already recorded') : __('Transaction created'))
            ->success()
            ->send();

        $this->stockOverride = false;

        $this->dispatch('close-modal', id: 'proceed-the-payment');
        $this->dispatch('open-modal', id: 'success-modal');
        $this->dispatch('order-reset');
        $this->dispatch('selling-created', selling: $selling->loadForReceipt(), kitchenPrinted: $kitchenPrinted, alreadyPaid: $alreadyPaid);
        $this->dispatch('products-refresh');
    }

    // ----------------------------------------------------------------------------------------------------
    // Vouchers and totals
    // ----------------------------------------------------------------------------------------------------

    public function assignVoucher(string $code)
    {
        if ($this->validateVoucher($code)) {
            $this->cartDetail['voucher'] = $code;
            $this->calculateTotalPrice();
        }
    }

    public function removeVoucher()
    {
        $this->cartDetail['voucher'] = null;
        $this->calculateTotalPrice();
    }

    private function validateVoucher(string $code): bool
    {
        $voucherService = new VoucherService();
        // The voucher is checked against the order total before any discount, like the sale does.
        $voucher = $voucherService->applyable($code, $this->computeTotals(withVoucher: false)['total_price']);
        if (! $voucher) {
            Notification::make('voucher_not_found')
                ->title(__('Voucher not found'))
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    /**
     * The tax percent of the order: the frozen tax of the open bill, else the shop's default tax.
     */
    private function currentTaxPercent(): float
    {
        if ($this->openBillId !== null) {
            $tax = $this->billRow?->tax_percent ?? ($this->openBill['tax_percent'] ?? null);

            if ($tax !== null) {
                return (float) $tax;
            }
        }

        return isset($this->tax) ? $this->tax : (float) Setting::get('default_tax', 0);
    }

    /**
     * The manual discount typed in the edit-detail form (masked text such as "1,500"), as money.
     */
    private function manualDiscount(): float
    {
        $raw = $this->cartDetail['discount_price'] ?? 0;

        return Money::round(Money::parse(is_string($raw) ? str_replace(',', '', $raw) : $raw));
    }

    /**
     * The one total formula (OrderTotals) for the lines on screen. The header discount is the voucher's when a
     * voucher applies (the sale replaces the manual discount with it), else the manual discount.
     *
     * @return array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,total_price:float,amount_due:float,qty:float,count:int}
     */
    private function computeTotals(bool $withVoucher = true): array
    {
        $tax = $this->currentTaxPercent();
        $totals = OrderTotals::compute($this->cartItems, $tax, $this->manualDiscount());

        $code = $this->cartDetail['voucher'] ?? null;
        if ($withVoucher && is_string($code) && $code !== '') {
            $voucher = (new VoucherService())->applyable($code, $totals['total_price']);

            if ($voucher) {
                $totals = OrderTotals::compute($this->cartItems, $tax, $voucher->calculate());
            }
        }

        return $totals;
    }

    private function calculateTotalPrice(): void
    {
        // Also called early by RefreshThePage::getListeners(), before mount() has loaded the lines.
        if (! isset($this->cartItems)) {
            return;
        }

        $totals = $this->computeTotals();

        $this->sub_total = $totals['subtotal'];
        $this->total_price = $totals['amount_due'];
    }

    /**
     * Only the order panel is rendered by this component; the product list lives in the ProductBrowser child.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Livewire only restores the relation names it saw, so make sure category/price unit are there (2-3 queries).
        $this->cartItems->loadMissing(['product.category', 'priceUnit']);

        $user = Filament::auth()->user();

        return [
            // The page load renders the pieces that never change (QR scanner, success dialog, print preview, the
            // sent-item / conflict / takeaway dialogs). A Livewire update leaves them out (the view keeps their
            // wire:ignore shells), which is about a quarter of the HTML of a cart tap, but ONLY when the page says it
            // has them: a tab that was loaded before this release has no shells, and an answer without the pieces
            // would delete them from it. The page sends the header from the bootstrap script (Livewire 'request' hook).
            'renderStaticParts' => ! (Livewire::isLivewireRequest() && request()->hasHeader(self::STATIC_PARTS_HEADER)),
            'cartGroups' => OrderLineSorter::group($this->cartItems),
            'cartCount' => $this->cartItems->count(),
            'cartQtyTotal' => (float) $this->cartItems->sum('qty'),
            'cartQty' => $this->cartQtyMap(),
            'totals' => $this->computeTotals(),
            'canOverrideStock' => $user instanceof User && OpenBillGate::canOverrideStock($user),
            'priceStaleLines' => $this->openBillId !== null ? $this->priceStaleLines() : [],
        ];
    }

    /**
     * Ids of bill lines whose quoted unit price differs from today's catalog price (a hint in the payment dialog,
     * never a blocker; the bill keeps the price it was ordered at).
     *
     * @return list<int>
     */
    private function priceStaleLines(): array
    {
        return $this->cartItems
            ->filter(function (OpenBillItem $item): bool {
                if ($item->price_unit_id !== null || ! $item->product) {
                    return false;
                }

                return ! Money::eq(OrderTotals::unitPrice($item), (float) $item->product->selling_price);
            })
            ->map(fn (OpenBillItem $item): int => (int) $item->getKey())
            ->values()
            ->all();
    }
}
