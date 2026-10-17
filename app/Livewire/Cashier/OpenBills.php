<?php

namespace App\Livewire\Cashier;

use App\Enums\OpenBillStatus;
use App\Exceptions\OpenBill\BillClosedException;
use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillForbiddenException;
use App\Exceptions\OpenBill\BillReasonRequiredException;
use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\About;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillGate;
use App\Services\Tenants\OpenBillService;
use App\Support\BusinessDay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The list of open bills (a drawer on the cashier page).
 *
 * Every public method is an endpoint, so every one of them checks `OpenBillGate::canUse()` first, and so does
 * `boot()`, which also covers direct property updates and the render path used by `wire:poll`.
 *
 * Cost: the rows (3 constant queries via `OpenBillService::listOpen`) are loaded ONLY while the drawer is open.
 * While it is closed, a render is one aggregate query on the indexed `open_bills.status` column (the count and
 * the "not sent to the kitchen" dot of the toggle button).
 *
 * Browser events sent from here (see plan-v3 14.8):
 * - `bill-print {mode, bill, reprint, ack, job_key}`  provisional bill, last kitchen ticket again, void ticket
 * - `bill-preview {mode, bill}`                       the print preview modal
 * - `bill-voided {id, number, label}`                 a void succeeded (the view closes its dialog)
 * A failed void adds the translated message to the error bag under the key `void`.
 */
class OpenBills extends Component
{
    public const FILTERS = ['all', 'mine', 'unsent', 'stale'];

    /** Longest search text used by the server-side search. */
    private const SEARCH_MAX = 100;

    /** Whether the drawer is open. The list is loaded only while it is. */
    public bool $open = false;

    /** all|mine|unsent|stale */
    public string $filter = 'all';

    /** Number, customer name or table. Never trimmed here, or it would fight the input being typed in. */
    public string $search = '';

    /** Kitchen tickets exist (business type "fnb"): only then is a bill ever "not sent to the kitchen". */
    #[Locked]
    public bool $kitchenEnabled = false;

    /** Rows of this request, so the list is built once per render even if several methods ask for it. */
    private ?Collection $rowsCache = null;

    public function boot(): void
    {
        $this->authorizeUse();
    }

    public function mount(): void
    {
        $this->authorizeUse();

        $this->kitchenEnabled = About::query()->value('business_type') === 'fnb';
    }

    public function setOpen(bool $open): void
    {
        $this->authorizeUse();

        $this->open = $open;
        $this->rowsCache = null;
    }

    public function setFilter(string $filter): void
    {
        $this->authorizeUse();

        $this->filter = $this->normalizedFilter($filter);
    }

    /** A filter set straight from the browser (property update) is normalized as well. */
    public function updatedFilter(string $value): void
    {
        $this->filter = $this->normalizedFilter($value);
    }

    #[On('open-bills-changed')]
    public function refresh(): void
    {
        $this->authorizeUse();

        // Nothing to do but forget what this request already loaded: Livewire renders the component afterwards.
        $this->rowsCache = null;
    }

    /**
     * Open this bill in the cashier. The Cashier component checks the status again and loads it.
     */
    public function select(int $billId): void
    {
        $this->authorizeUse();

        if (! $this->isStillOpen($billId)) {
            return;
        }

        $this->dispatch('open-bill-selected', id: $billId)->to(Cashier::class);
        $this->setOpen(false);
    }

    /**
     * Open this bill in the cashier and go straight to the payment.
     */
    public function pay(int $billId): void
    {
        $this->authorizeUse();

        if (! $this->isStillOpen($billId)) {
            return;
        }

        $this->dispatch('open-bill-pay', id: $billId)->to(Cashier::class);
        $this->setOpen(false);
    }

    /**
     * The provisional bill ("Bill sementara"), printed on this device.
     */
    public function printProvisional(int $billId): void
    {
        $user = $this->authorizeUse();

        $bill = $this->openBillForPrint($billId);
        if (! $bill) {
            return;
        }

        $this->dispatchPrint('bill', $this->service()->printPayload($bill, 'bill', null, false, $user), false);
    }

    /**
     * The last kitchen ticket again, marked as a copy. Prints exactly what was sent: it changes nothing on the bill.
     */
    public function reprintKitchen(int $billId): void
    {
        $user = $this->authorizeUse();

        $bill = $this->openBillForPrint($billId);
        if (! $bill) {
            return;
        }

        $service = $this->service();
        $batch = $service->lastKitchenBatch($bill);
        if ($batch === null) {
            Notification::make()->title(__('Not sent to kitchen'))->warning()->send();

            return;
        }

        $service->log($bill, OpenBillEvent::REPRINT, $user, [
            'detail' => ['batch_no' => $batch['batch_no'] ?? null],
        ]);

        $this->dispatchPrint('kitchen', $service->printPayload($bill, 'kitchen', $batch, true, $user), true);
    }

    /**
     * The print preview of a bill ('bill') or of its kitchen ticket ('kitchen': what would be sent now, else the
     * last ticket that was sent).
     */
    public function previewBill(int $billId, string $mode): void
    {
        $user = $this->authorizeUse();

        if (! in_array($mode, ['bill', 'kitchen'], true)) {
            return;
        }

        $bill = $this->openBillForPrint($billId);
        if (! $bill) {
            return;
        }

        $service = $this->service();
        $batch = null;
        $reprint = false;

        if ($mode === 'kitchen') {
            $batch = $service->kitchenBatch($bill);

            if ($batch === null) {
                $batch = $service->lastKitchenBatch($bill);
                $reprint = $batch !== null;
            }

            if ($batch === null) {
                Notification::make()->title(__('Nothing new to send'))->warning()->send();

                return;
            }
        }

        $this->dispatch(
            'bill-preview',
            mode: $mode,
            bill: $service->printPayload($bill, $mode, $batch, $reprint, $user),
        );
    }

    /**
     * Void a bill. The reason is required for every bill; whether this user may do it is decided by the service
     * against the locked bill (the gate is not repeated here on purpose: a copy read earlier could be out of date).
     */
    public function voidBill(int $billId, string $reason, int $expectedVersion): void
    {
        $user = $this->authorizeUse();

        $this->resetErrorBag('void');

        $service = $this->service();

        try {
            $bill = $service->find($billId);
            $ticket = $service->void($bill, $user, trim($reason), $expectedVersion);
        } catch (ModelNotFoundException) {
            $this->failVoid(__('Bill not found'));

            return;
        } catch (BillConflictException $e) {
            $this->failVoid($e->getMessage() ?: __('Bill was changed on another device'));

            return;
        } catch (BillClosedException $e) {
            $this->failVoid($e->getMessage() ?: __('Bill is closed'));

            return;
        } catch (BillForbiddenException $e) {
            $this->failVoid($e->getMessage() ?: __('You are not allowed to do this'));

            return;
        } catch (BillReasonRequiredException $e) {
            $this->failVoid($e->getMessage() ?: __('A reason is required'));

            return;
        }

        $this->rowsCache = null;

        if ($ticket !== null) {
            // The kitchen made food for this bill: tell it to stop.
            $voided = $service->find($billId);
            $this->dispatchPrint('kitchen', $service->printPayload($voided, 'kitchen', $ticket, false, $user), false);
        }

        Notification::make()->title(__('Bill voided'))->success()->send();

        $this->dispatch('bill-voided', id: $billId, number: (int) $bill->number, label: $bill->label());
    }

    public function render(): View
    {
        $user = $this->authorizeUse();

        $loadError = false;
        $rows = collect();
        $count = 0;
        $unsentCount = 0;

        try {
            [$count, $unsentCount] = $this->counts();

            if ($this->open) {
                $all = $this->loadRows($user);

                // The drawer shows the exact figure, the same one the row chips are drawn from. Under "mine" the
                // service built only this cashier's rows, so the cheap figure of all bills stays.
                if ($this->normalizedFilter($this->filter) !== 'mine') {
                    $unsentCount = $this->kitchenEnabled ? $all->where('unsent', true)->count() : 0;
                    $count = $all->count();
                }
                $rows = $this->filtered($all, $user);
            }
        } catch (QueryException $e) {
            // E.g. the migrations are not finished yet: the cashier keeps working, the list says it is unavailable.
            report($e);
            $loadError = true;
            $rows = collect();
        }

        return view('livewire.cashier.open-bills', [
            'bills' => $rows,
            'count' => $count,
            'unsentCount' => $unsentCount,
            'today' => BusinessDay::today(),
            'kitchenEnabled' => $this->kitchenEnabled,
            'loadError' => $loadError,
        ]);
    }

    /**
     * Who is allowed to use the bills at all. Every entry point goes through here.
     */
    private function authorizeUse(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && OpenBillGate::canUse($user), 403);

        return $user;
    }

    private function service(): OpenBillService
    {
        return app(OpenBillService::class);
    }

    private function normalizedFilter(string $filter): string
    {
        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    /**
     * One aggregate query on the indexed status column: how many bills are open, and (kitchen only) how many of
     * them have something the kitchen has not been told yet. The second figure is a cheap estimate: a bill never
     * sent, or a line whose quantity or note differs from what was sent. A table move or a removed line that is
     * waiting to be told is only counted once the drawer is open and the exact rows are known.
     *
     * @return array{0:int,1:int} [open bills, bills not sent to the kitchen]
     */
    private function counts(): array
    {
        $query = OpenBill::query()->open()->toBase();

        if (! $this->kitchenEnabled) {
            return [(int) $query->count(), 0];
        }

        $row = $query->selectRaw(
            'count(*) as total, sum(case when open_bills.kitchen_batch_count = 0 or exists ('
            .'select 1 from open_bill_items where open_bill_items.open_bill_id = open_bills.id '
            .'and (abs(open_bill_items.qty - open_bill_items.sent_qty) > 0.0005 '
            ."or coalesce(open_bill_items.note, '') <> coalesce(open_bill_items.sent_note, ''))"
            .') then 1 else 0 end) as unsent'
        )->first();

        return [(int) ($row->total ?? 0), (int) ($row->unsent ?? 0)];
    }

    /**
     * The rows of the drawer, from the service (3 constant queries), unfiltered. "Mine" is applied by the service
     * so that other cashiers' bills are not built at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function loadRows(User $user): Collection
    {
        if ($this->rowsCache !== null) {
            return $this->rowsCache;
        }

        $createdBy = $this->normalizedFilter($this->filter) === 'mine' ? (int) $user->getKey() : null;

        return $this->rowsCache = collect($this->service()->listOpen($user, $createdBy));
    }

    /**
     * Filter chip, search and order. All on the rows already loaded: no query.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filtered(Collection $rows, User $user): Collection
    {
        $filter = $this->normalizedFilter($this->filter);

        if ($filter === 'unsent') {
            $rows = $rows->filter(fn (array $row): bool => (bool) ($row['unsent'] ?? false));
        } elseif ($filter === 'stale') {
            $rows = $rows->filter(fn (array $row): bool => (bool) ($row['stale'] ?? false));
        } elseif ($filter === 'mine') {
            // The service already narrowed it down; this keeps the filter right even if it ignored the argument.
            $rows = $rows->filter(fn (array $row): bool => ! array_key_exists('created_by', $row)
                || (int) $row['created_by'] === (int) $user->getKey());
        }

        $term = $this->searchTerm();
        if ($term !== '') {
            $rows = $rows->filter(fn (array $row): bool => $this->matches($row, $term));
        }

        // Not sent to the kitchen first, then oldest first. A bill's id grows with its business day and number,
        // so it is the tie-breaker (and the order itself when the rows carry no business_date).
        return $rows
            ->sort(fn (array $a, array $b): int => [
                (int) ! ($a['unsent'] ?? false),
                (string) ($a['business_date'] ?? ''),
                (int) ($a['id'] ?? 0),
            ] <=> [
                (int) ! ($b['unsent'] ?? false),
                (string) ($b['business_date'] ?? ''),
                (int) ($b['id'] ?? 0),
            ])
            ->values();
    }

    private function searchTerm(): string
    {
        return mb_strtolower(ltrim(mb_substr(trim($this->search), 0, self::SEARCH_MAX), '#'));
    }

    /**
     * Number, customer name or table. A term of digits only is a bill number or a table number (exact, leading
     * zeros ignored), so "5" does not list bill 15 or table 25; it still matches inside names.
     *
     * @param  array<string, mixed>  $row
     */
    private function matches(array $row, string $term): bool
    {
        $names = mb_strtolower(implode(' ', array_filter([
            (string) ($row['label'] ?? ''),
            (string) ($row['customer_name'] ?? ''),
        ])));

        if (ctype_digit($term)) {
            $digits = ltrim($term, '0');
            $table = mb_strtolower(trim((string) ($row['table_label'] ?? '')));
            $tableDigits = ltrim(preg_replace('/\D+/', '', $table) ?? '', '0');

            return (string) ($row['number'] ?? '') === $digits
                || ($tableDigits !== '' && $tableDigits === $digits)
                || str_contains($names, $term);
        }

        $table = mb_strtolower(trim((string) ($row['table_label'] ?? '')));
        $haystack = $names.' '.$table.' '.mb_strtolower(__('Table')).' '.$table.' '.mb_strtolower((string) ($row['number_label'] ?? ''));

        return str_contains($haystack, $term);
    }

    /**
     * A single narrow query (no relations): is this bill still open? Tells the cashier why not, otherwise.
     */
    private function isStillOpen(int $billId): bool
    {
        $status = OpenBill::query()->whereKey($billId)->value('status');

        if ($status === null) {
            Notification::make()->title(__('Bill not found'))->danger()->send();
            $this->rowsCache = null;

            return false;
        }

        $status = $status instanceof OpenBillStatus ? $status : OpenBillStatus::tryFrom((string) $status);

        if ($status === OpenBillStatus::Open) {
            return true;
        }

        Notification::make()
            ->title($status === OpenBillStatus::Paid ? __('Bill already paid') : __('Bill is closed'))
            ->warning()
            ->send();
        $this->rowsCache = null;

        return false;
    }

    /**
     * The bill with everything the print payload needs, only if it is still open.
     */
    private function openBillForPrint(int $billId): ?OpenBill
    {
        if (! $this->isStillOpen($billId)) {
            return null;
        }

        try {
            return $this->service()->find($billId);
        } catch (ModelNotFoundException) {
            Notification::make()->title(__('Bill not found'))->danger()->send();

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload  from OpenBillService::printPayload
     */
    private function dispatchPrint(string $mode, array $payload, bool $reprint): void
    {
        $this->dispatch(
            'bill-print',
            mode: $mode,
            bill: $payload,
            reprint: $reprint,
            ack: null,
            job_key: (string) ($payload['job_key'] ?? $mode.'-'.($payload['id'] ?? '')),
        );
    }

    private function failVoid(string $message): void
    {
        $this->rowsCache = null;
        $this->addError('void', $message);
    }
}
