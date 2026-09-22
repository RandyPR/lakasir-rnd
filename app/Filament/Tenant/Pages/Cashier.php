<?php

namespace App\Filament\Tenant\Pages;

use App\Features\Member as FeaturesMember;
use App\Features\Voucher;
use App\Filament\Tenant\Pages\Traits\CartInteraction;
use App\Filament\Tenant\Pages\Traits\TableProduct;
use App\Filament\Tenant\Resources\Traits\RefreshThePage;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Member;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\Voucher as TenantsVoucher;
use App\Rules\CheckProductStock;
use App\Rules\ShouldSameWithSellingDetail;
use App\Services\Tenants\SellingService;
use App\Services\VoucherService;
use App\Traits\HasTranslatableResource;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\RawJs;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as CollectionSupport;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\Tenants\Profile;

class Cashier extends Page implements HasForms, HasTable
{
    use CartInteraction, HasTranslatableResource, RefreshThePage, TableProduct;

    public static ?string $label = 'POS';

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'filament.tenant.pages.cashier';

    public static function canAccess(): bool
    {
        return can('create selling');
    }

    public Collection $cartItems;

    public Collection $availableVoucher;

    protected static string $layout = 'filament-panels::components.layout.base';

    public array $cartDetail = [];

    public array $paymentMethods;

    public CollectionSupport $members;

    public float $tax;

    public string $currency;

    public string $locale;

    public float $sub_total = 0;

    public float $total_price = 0;

    public ?About $about;

    public ?Collection $tableOption;

    private float $discount_price = 0;

    public function mount()
    {
        $this->about = About::first() ?? null;

        $this->tax = (float) Setting::get('default_tax', 0);

        $this->currency = Setting::get('currency', 'IDR');

        $this->locale = Profile::get()->locale ?? 'en';

        $this->cartItems = CartItem::query()
            ->select('*')
            ->with('product')
            ->orderByDesc('created_at')
            ->cashier()
            ->get();
        $vouchers = TenantsVoucher::query()
            ->where('minimal_buying', '<=', $this->cartItems->sum('price'))
            ->where('start_date', '<=', today()->format('Y-m-d'))
            ->where('expired', '>=', today()->format('Y-m-d'))
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

        $this->storeCartForm->fill([
            'customer_name' => null,
            'payment_method_id' => $cash->id,
            'total_price' => $this->total_price,
            'friend_price' => false,
            'voucher' => null,
            'discount_price' => 0,
            'note' => null,
            'member_id' => null,
        ]);

        $this->cartDetail['payment_method_id'] = $cash->id;
        $this->cartDetail['table_id'] = null;
        $this->cartDetail['table_label'] = null;

        $this->fillPayemntMethod();
        $this->fillTableLabel();
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
                TextInput::make('customer_name')
                    ->label(__('Customer Name'))
                    ->hiddenLabel()
                    ->placeholder(__('Enter customer name'))
                    ->extraAttributes([
                        'data-field' => 'customerNameInput',
                    ]),
                RichEditor::make('note')
                    ->hiddenLabel()
                    ->toolbarButtons([
                        'bold',
                        'italic',
                        'bulletList',
                        'orderedList',
                        'undo',
                        'redo',
                    ])
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

    public function storeCart(): void
    {
        try {
            $state = $this->storeCartForm->getState();
            $this->cartDetail = array_merge($this->cartDetail, array_filter($state, fn ($v) => ! is_null($v) && $v !== ''));
        } catch (\Throwable $e) {
        }

        if (! empty($this->cartDetail['voucher'])) {
            $this->validateVoucher($this->cartDetail['voucher']);
        }

        if (! empty($this->cartDetail['discount_price'])) {
            $discount_price = str_replace(',', '', (string) $this->cartDetail['discount_price']);
            if ($discount_price) {
                $this->cartItems->each(function (CartItem $item) {
                    if ($item->discount_price && $item->discount_price > 0) {
                        $this->discount_price += $item->discount_price;
                    }
                });
                $this->discount_price += floatval($discount_price);
                $this->total_price = $this->sub_total + ($this->sub_total * $this->tax / 100) - $this->discount_price;
            }
        }
        $this->fillMember();
        $this->fillPayemntMethod();

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
            return $key == $this->cartDetail['member_id'];
        })->first();
        $this->cartDetail['member_label'] = $member;
    }

    public function selectTable(?int $tableId = null): void
    {
        if ($tableId) {
            $table = Table::find($tableId);
            if ($table) {
                $this->cartDetail['table_id'] = $table->id;
                $this->cartDetail['table_label'] = $table->number;
            }
        } else {
            $this->cartDetail['table_id'] = null;
            $this->cartDetail['table_label'] = null;
        }

        $this->dispatch('close-modal', id: 'modal-selected-table');
    }

    public function saveCustomTable(?string $customNumber = null): void
    {
        $number = trim((string) $customNumber);
        if (! empty($number)) {
            $table = Table::firstOrCreate(['number' => $number]);
            $this->tableOption = Table::select('id', 'number')->get();
            $this->cartDetail['table_id'] = $table->id;
            $this->cartDetail['table_label'] = $table->number;
        } else {
            $this->cartDetail['table_id'] = null;
            $this->cartDetail['table_label'] = null;
        }

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

    public function proceedThePayment(SellingService $sellingService): void
    {
        try {
            $state = $this->storeCartForm->getState();
            $this->cartDetail = array_merge($this->cartDetail, array_filter($state, fn ($v) => ! is_null($v) && $v !== ''));
        } catch (\Throwable $e) {
        }

        if (! empty($this->cartDetail['customer_name'])) {
            $this->cartDetail['customer_name'] = trim((string) $this->cartDetail['customer_name']);
        } else {
            $this->cartDetail['customer_name'] = null;
        }

        $this->cartDetail = array_merge($this->cartDetail, [
            'total_price' => $this->total_price,
        ]);

        $request = array_merge($this->cartDetail, [
            'discount_price' => floatval(str_replace(',', '', (string) ($this->cartDetail['discount_price'] ?? 0))),
            'products' => $this->cartItems->map(function (CartItem $cartItem) {
                return [
                    'product_id' => $cartItem->product_id,
                    'qty' => $cartItem->qty,
                    'price' => $cartItem->price,
                    'discount_price' => $cartItem->discount_price,
                    'price_unit_id' => $cartItem->price_unit_id,
                ];
            })->toArray(),
        ]);

        $pMethod = PaymentMethod::find($request['payment_method_id']);
        if (! $pMethod) {
            $pMethod = PaymentMethod::firstOrCreate([
                'name' => 'Cash',
            ], [
                'is_cash' => true,
                'is_debit' => false,
                'is_credit' => false,
                'is_wallet' => false,
                'icon' => 'assets/images/payment-methods/cash.png',
            ]);
            $request['payment_method_id'] = $pMethod->id;
        }

        // For non-cash methods (QRIS, wallet, debit), exact payment is guaranteed
        if ($pMethod->is_wallet || $pMethod->is_debit || ! $pMethod->is_cash) {
            $request['payed_money'] = $this->total_price;
            $request['money_changes'] = 0;
        }

        $validator = Validator::make($request, [
            'fee' => ['numeric'],
            'payment_method_id' => ['required'],
            'member_id' => Rule::requiredIf(fn () => $pMethod->is_credit),
            'due_date' => Rule::requiredIf(fn () => $pMethod->is_credit),
            'payed_money' => [
                ! $pMethod->is_credit ? 'gte:total_price' : null,
                Rule::requiredIf(fn () => ! $pMethod->is_credit),
            ],
            'total_price' => ['required_if:friend_price,true', 'numeric'],
            'total_qty' => ['required_if:friend_price,true', 'numeric', new ShouldSameWithSellingDetail('qty', $request['products'])],
            'friend_price' => ['required', 'boolean'],
            'products' => ['required', 'array'],
            'products.*.product_id' => ['required', 'exists:products,id'],
            'products.*.price' => ['required_if:friend_price,true', 'numeric'],
            'products.*.qty' => ['required', 'numeric', 'min:1', new CheckProductStock],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->messages()->toArray());

            return;
        }
        $data = array_merge($request, $sellingService->mapProductRequest($request));
        $selling = $sellingService->create($data);
        CartItem::query()
            ->cashier()
            ->delete();

        Notification::make()
            ->title(__('Transaction created'))
            ->success()
            ->send();

        $this->mount();

        $this->dispatch('close-modal', id: 'proceed-the-payment');
        $this->dispatch('open-modal', id: 'success-modal');
        $this->dispatch('selling-created', selling: $selling->load('sellingDetails.product', 'table'));
    }

    public function assignVoucher(string $code)
    {
        $this->validateVoucher($code) ? $this->cartDetail['voucher'] = $code : null;
    }

    public function removeVoucher()
    {
        $this->cartDetail['voucher'] = null;
        $this->discount_price = 0;
        $this->calculateTotalPrice();
    }

    private function validateVoucher(string $code): bool
    {
        $voucherService = new VoucherService();
        $voucher = $voucherService->applyable($code, $this->total_price);
        if (! $voucher) {
            Notification::make('voucher_not_found')
                ->title(__('Voucher not found'))
                ->warning()
                ->send();

            return false;
        }

        $this->cartItems->each(function (CartItem $item) {
            if ($item->discount_price && $item->discount_price > 0) {
                $this->discount_price += $item->discount_price;
            }
        });
        $this->discount_price += $voucher->calculate();
        $this->total_price = $this->sub_total + ($this->sub_total * $this->tax / 100) - $this->discount_price;

        return true;
    }

    private function calculateTotalPrice()
    {
        $this->sub_total = 0;

        $this->discount_price = 0;
        $this->cartItems->each(function (CartItem $item) {
            $priceUnit = $item->priceUnit?->selling_price;
            if ($priceUnit) {
                $priceUnit = $priceUnit * $item->qty;
            }

            $this->sub_total += $priceUnit ?? $item->price;
            if ($item->discount_price && $item->discount_price > 0) {
                $this->discount_price += $item->discount_price;
            }
        });

        $this->total_price = $this->sub_total + ($this->sub_total * $this->tax / 100) - $this->discount_price;
    }
}
