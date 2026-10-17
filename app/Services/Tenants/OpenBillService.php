<?php

namespace App\Services\Tenants;

use App\Enums\OpenBillStatus;
use App\Exceptions\OpenBill\BillClosedException;
use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillEmptyException;
use App\Exceptions\OpenBill\BillForbiddenException;
use App\Exceptions\OpenBill\BillReasonRequiredException;
use App\Exceptions\SaleBusyException;
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
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Support\BusinessDay;
use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Everything that changes an open bill ("Bill"): lines, header, kitchen tickets, void and payment.
 *
 * LOCK ORDER (plan-v3 14.0 R1). Never take these in another order, in this class or anywhere else:
 *   1. numbering_locks row 'sales'   (NumberingLock::acquire, FIRST statement of the transaction)
 *   2. the open_bills row            (lockForUpdate)
 *   3. its open_bill_items rows      (lockForUpdate)
 *   4. the cashier's cart_items rows (lockForUpdate)
 *   5. products rows                 (StockGuard)
 *
 *  - createFromCart and pay() need (1): createFromCart takes it itself, pay() lets SellingService::create take
 *    it, then locks the bill in the 'before' hook. A rename (updateHeader with a new customer_name) uses
 *    mutate(sales_lock: true) for the same reason: the "(2)" suffix is computed under the sales lock.
 *  - Every other mutation takes only (2) and (3) and never asks for (1) afterwards.
 *  - A deadlock (1213) or lock wait timeout (1205) is retried once, then SaleBusyException.
 *
 * Money and quantities are only compared/rounded through App\Support\Money. Every mutation writes an
 * open_bill_events row inside its own transaction (R7). The database is the source of truth (R8): the
 * client's version is only used to refuse a stale pay / void / header change.
 */
final class OpenBillService
{
    /**
     * Set by a mutate() closure that changed nothing, so the bill version is not bumped for a no-op.
     */
    private bool $keepVersion = false;

    public function __construct(private SellingService $sellingService)
    {
    }

    // ------------------------------------------------------------------------------------------------
    // create / read
    // ------------------------------------------------------------------------------------------------

    /**
     * Turns the user's private cart into a new bill. The cart is emptied in the same transaction.
     *
     * @param  array{customer_name?:?string,table_id?:?int,member_id?:?int,note?:?string,discount_price?:mixed}  $header
     *
     * @throws BillEmptyException the cart is empty
     * @throws SaleBusyException
     */
    public function createFromCart(User $by, array $header): OpenBill
    {
        $billId = $this->transact(function () use ($by, $header): int {
            $businessDate = BusinessDay::today();

            $name = $this->cleanName($header['customer_name'] ?? null);
            if ($name !== null) {
                $name = CustomerNameService::generateDailyCustomerName($name, $businessDate) ?? $name;
            }

            $bill = new OpenBill();
            $bill->fill([
                'number' => DailyNumber::next($businessDate),
                'business_date' => $businessDate,
                'status' => OpenBillStatus::Open,
                'version' => 1,
                'customer_name' => $name,
                'table_id' => $this->existingId(Table::class, $header['table_id'] ?? null),
                'member_id' => $this->existingId(Member::class, $header['member_id'] ?? null),
                'note' => $this->cleanText($header['note'] ?? null),
                'tax_percent' => $this->defaultTax(),
                'discount_price' => $this->moneyIn($header['discount_price'] ?? 0),
                'kitchen_batch_count' => 0,
                'created_by' => $by->getKey(),
            ]);
            $bill->save();

            $cart = CartItem::query()
                ->where('user_id', $by->getKey())
                ->with('priceUnit')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($cart->isEmpty()) {
                throw new BillEmptyException();
            }

            foreach ($this->mergeCart($cart) as $line) {
                OpenBillItem::query()->create([
                    'open_bill_id' => $bill->getKey(),
                    'product_id' => $line['product_id'],
                    'user_id' => $by->getKey(),
                    'qty' => $line['qty'],
                    'price' => $line['price'],
                    'discount_price' => $line['discount_price'],
                    'price_unit_id' => $line['price_unit_id'],
                    'note' => $line['note'],
                    'sent_qty' => 0,
                ]);
            }

            CartItem::query()->whereIn('id', $cart->modelKeys())->delete();

            $this->log($bill, OpenBillEvent::CREATED, $by, [
                'detail' => ['number' => $bill->number, 'lines' => $cart->count()],
            ]);

            return (int) $bill->getKey();
        }, salesLock: true, retryDuplicate: true);

        return $this->find($billId);
    }

    /**
     * The bill with everything the cashier screen and the printers need.
     *
     * @throws BillClosedException when there is no such bill
     */
    public function find(int $id): OpenBill
    {
        $bill = OpenBill::query()
            ->with([
                'items' => fn ($query) => $query->orderBy('id'),
                'items.product.category',
                'items.priceUnit',
                'table',
                'member',
                'creator',
            ])
            ->find($id);

        if (! $bill) {
            throw BillClosedException::notFound();
        }

        return $bill;
    }

    /**
     * One transaction with the bill row locked, the status checked and the version raised afterwards.
     *
     * With opts['sales_lock'] the sales lock comes first (R1) and the bill is locked after it.
     * The closure receives the LOCKED bill and may call $this->keepVersion = true when it changed nothing.
     *
     * @param  array{sales_lock?:bool}  $opts
     *
     * @throws BillClosedException the bill is gone, paid or void
     * @throws SaleBusyException
     */
    public function mutate(int $billId, Closure $fn, array $opts = []): mixed
    {
        return $this->transact(function () use ($billId, $fn): mixed {
            $this->keepVersion = false;

            $bill = OpenBill::query()->whereKey($billId)->lockForUpdate()->first();
            if (! $bill) {
                throw BillClosedException::notFound();
            }
            if (! $bill->status->isOpen()) {
                throw new BillClosedException(status: $bill->status, sellingId: $bill->selling_id);
            }

            $result = $fn($bill);

            if ($this->keepVersion) {
                $this->keepVersion = false;

                return $result;
            }

            $bill->version = (int) $bill->version + 1;
            $bill->save();

            return $result;
        }, salesLock: (bool) ($opts['sales_lock'] ?? false));
    }

    /**
     * Writes one audit row. Call it inside the transaction of the mutation it describes.
     *
     * @param  array{product_id?:int|null,product_name?:string|null,qty_from?:float|int|null,qty_to?:float|int|null,reason?:string|null,detail?:array|null}  $attrs
     */
    public function log(OpenBill $bill, string $action, ?User $by, array $attrs = []): void
    {
        OpenBillEvent::record($bill, $action, $by, $attrs);
    }

    // ------------------------------------------------------------------------------------------------
    // lines (lock 2 and 3 only; no expectedVersion)
    // ------------------------------------------------------------------------------------------------

    /**
     * Adds $qty (a difference, not the new total) of a product. An existing line grows; a new line is priced
     * at today's price. If the product was removed after the kitchen got it, the removal is netted: the new
     * line starts with the quantity the kitchen already knows, so remove + add again prints nothing new.
     * Stock is NOT checked here (the cart validates quickly, the payment is the authority).
     */
    public function addLine(
        int $billId,
        Product $product,
        float $qty,
        ?int $priceUnitId,
        ?string $note,
        User $by,
    ): OpenBillItem {
        return $this->mutate($billId, fn (OpenBill $bill): OpenBillItem => $this->addLineLocked(
            $bill,
            $product,
            $qty,
            $priceUnitId,
            $note,
            $by,
        ));
    }

    /**
     * Sets the quantity of a line. qty <= 0 removes it. Going below what the kitchen already got (or removing
     * a sent line) needs OpenBillGate::canCancelSent and a reason; `sent_qty` is NOT lowered here, the
     * difference becomes a "BATAL" line on the next kitchen ticket.
     *
     * @return OpenBillItem|null null when the line was removed (or does not exist any more)
     *
     * @throws BillForbiddenException
     * @throws BillReasonRequiredException
     */
    public function setLineQty(int $billId, int $itemId, float $qty, User $by, ?string $reason = null): ?OpenBillItem
    {
        $qty = Money::qty($qty);

        return $this->mutate($billId, function (OpenBill $bill) use ($itemId, $qty, $by, $reason): ?OpenBillItem {
            $item = $this->lockItem($bill, $itemId);
            if (! $item) {
                $this->keepVersion = true;

                return null;
            }
            $item->loadMissing(['product', 'priceUnit']);

            if ($qty <= 0) {
                $this->removeLine($bill, $item, $by, $reason);

                return null;
            }

            $old = Money::qty((float) $item->qty);
            if (Money::qtyEq($old, $qty)) {
                $this->keepVersion = true;

                return $item;
            }

            $belowSent = $qty < Money::qty((float) $item->sent_qty);
            $cleanReason = $belowSent ? $this->authorizeCancelSent($bill, $by, $reason) : null;

            $item->price = OrderLinePricing::repriceOnQtyChange(
                $old,
                (float) $item->price,
                $qty,
                $this->unitPriceNow($item),
            );
            $item->qty = $qty;
            $item->save();

            $this->log($bill, $belowSent ? OpenBillEvent::CANCEL_SENT : OpenBillEvent::LINE_QTY, $by, [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'qty_from' => $old,
                'qty_to' => $qty,
                'reason' => $cleanReason,
                'detail' => $belowSent ? ['sent_qty' => Money::qty((float) $item->sent_qty)] : null,
            ]);

            return $item;
        });
    }

    public function setLineNote(int $billId, int $itemId, ?string $note, User $by): void
    {
        $this->mutate($billId, function (OpenBill $bill) use ($itemId, $note, $by): void {
            $item = $this->lockItem($bill, $itemId);
            $new = OpenBillItem::normalizeNote($note);
            if (! $item || $new === $item->note) {
                $this->keepVersion = true;

                return;
            }
            $item->loadMissing('product');

            $from = $item->note;
            $item->note = $new;
            $item->save();

            $this->log($bill, OpenBillEvent::LINE_NOTE, $by, [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'detail' => ['from' => $from, 'to' => $new],
            ]);
        });
    }

    /**
     * Discount of one line in money (not percent). null / 0 clears it; it never exceeds the line total.
     */
    public function setLineDiscount(int $billId, int $itemId, ?float $discount, User $by): void
    {
        $this->mutate($billId, function (OpenBill $bill) use ($itemId, $discount, $by): void {
            $item = $this->lockItem($bill, $itemId);
            if (! $item) {
                $this->keepVersion = true;

                return;
            }
            $item->loadMissing(['product', 'priceUnit']);

            $new = $discount === null ? 0.0 : Money::round(max(0.0, $discount));
            $new = min($new, OrderTotals::lineTotal($item));
            $from = Money::round((float) ($item->discount_price ?? 0));

            if (Money::eq($from, $new)) {
                $this->keepVersion = true;

                return;
            }

            $item->discount_price = $new > 0 ? $new : null;
            $item->save();

            $this->log($bill, OpenBillEvent::LINE_DISCOUNT, $by, [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'detail' => ['from' => $from, 'to' => $new],
            ]);
        });
    }

    /**
     * Undo of a removed line: puts it back like addLine does (so a sent line is netted against its pending
     * cancellation). Snapshot: product_id, qty, and optionally price_unit_id, note, discount_price.
     *
     * @param  array{product_id:int,qty:float|int|string,price_unit_id?:int|null,note?:?string,discount_price?:float|int|string|null}  $snapshot
     */
    public function restoreLine(int $billId, array $snapshot, User $by): void
    {
        $product = Product::query()->find((int) ($snapshot['product_id'] ?? 0));
        $qty = (float) ($snapshot['qty'] ?? 0);
        if (! $product || $qty <= 0) {
            throw new InvalidArgumentException('restoreLine needs an existing product and a positive qty.');
        }

        $unit = ! empty($snapshot['price_unit_id']) ? (int) $snapshot['price_unit_id'] : null;
        $discount = isset($snapshot['discount_price']) && (float) $snapshot['discount_price'] > 0
            ? (float) $snapshot['discount_price']
            : null;

        $this->mutate($billId, function (OpenBill $bill) use ($product, $qty, $unit, $snapshot, $by, $discount): void {
            $this->addLineLocked($bill, $product, $qty, $unit, $snapshot['note'] ?? null, $by, $discount);
        });
    }

    // ------------------------------------------------------------------------------------------------
    // header
    // ------------------------------------------------------------------------------------------------

    /**
     * Changes customer_name, table_id, member_id, note and/or discount_price. Needs the version the client saw.
     * A new customer_name is numbered again ("Budi (2)") under the sales lock, ignoring this bill itself.
     *
     * @param  array{customer_name?:?string,table_id?:?int,member_id?:?int,note?:?string,discount_price?:mixed}  $header
     *
     * @throws BillConflictException
     */
    public function updateHeader(OpenBill $bill, array $header, int $expectedVersion, User $by): OpenBill
    {
        $billId = (int) $bill->getKey();

        // Cheap pre-read, only to decide whether the sales lock is needed. The version is checked again under
        // the lock, and an equal version proves nothing changed in between (every mutation raises it).
        $current = OpenBill::query()->select(['id', 'status', 'version', 'customer_name', 'selling_id'])->find($billId);
        if (! $current) {
            throw BillClosedException::notFound();
        }
        if (! $current->status->isOpen()) {
            throw new BillClosedException(status: $current->status, sellingId: $current->selling_id);
        }
        if ((int) $current->version !== $expectedVersion) {
            throw new BillConflictException((int) $current->version);
        }

        $renaming = array_key_exists('customer_name', $header)
            && $this->cleanName($header['customer_name']) !== $current->customer_name;

        $this->mutate($billId, function (OpenBill $locked) use ($header, $expectedVersion, $by, $renaming): void {
            if ((int) $locked->version !== $expectedVersion) {
                throw new BillConflictException((int) $locked->version);
            }

            $changes = [];

            if (array_key_exists('customer_name', $header)) {
                $name = $this->cleanName($header['customer_name']);
                if ($name !== $locked->customer_name) {
                    if ($name !== null && $renaming) {
                        $name = CustomerNameService::generateDailyCustomerName(
                            $name,
                            $locked->business_date->format('Y-m-d'),
                            null,
                            (int) $locked->getKey(),
                        ) ?? $name;
                    }
                    $changes['customer_name'] = [$locked->customer_name, $name];
                    $locked->customer_name = $name;
                }
            }

            if (array_key_exists('table_id', $header)) {
                $tableId = $this->existingId(Table::class, $header['table_id']);
                if ($tableId !== $locked->table_id) {
                    $changes['table_id'] = [$locked->table_id, $tableId];
                    $locked->table_id = $tableId;
                }
            }

            if (array_key_exists('member_id', $header)) {
                $memberId = $this->existingId(Member::class, $header['member_id']);
                if ($memberId !== $locked->member_id) {
                    $changes['member_id'] = [$locked->member_id, $memberId];
                    $locked->member_id = $memberId;
                }
            }

            if (array_key_exists('note', $header)) {
                $note = $this->cleanText($header['note']);
                if ($note !== $locked->note) {
                    $changes['note'] = [$locked->note, $note];
                    $locked->note = $note;
                }
            }

            if (array_key_exists('discount_price', $header)) {
                $discount = $this->moneyIn($header['discount_price']);
                if (! Money::eq($discount, (float) $locked->discount_price)) {
                    $changes['discount_price'] = [(float) $locked->discount_price, $discount];
                    $locked->discount_price = $discount;
                }
            }

            if ($changes === []) {
                $this->keepVersion = true;

                return;
            }

            $this->log($locked, OpenBillEvent::HEADER, $by, ['detail' => ['changes' => $changes]]);
        }, ['sales_lock' => $renaming]);

        return $this->find($billId);
    }

    // ------------------------------------------------------------------------------------------------
    // kitchen (two phases: kitchenBatch is pure, ackKitchenBatch writes)
    // ------------------------------------------------------------------------------------------------

    /**
     * What the kitchen has not been told yet. PURE: reads the bill fresh from the database and writes nothing,
     * so printing it and failing leaves the bill untouched. null when there is nothing to tell.
     *
     * array{
     *   batch_no:int, kind:'new'|'addition'|'cancel_only'|'change_only', version:int, pending_count:int,
     *   items:list<array{item_id,product_id,name,qty,total_qty,note,category,category_id,price,sku}>,
     *   cancelled:list<array{ref,source,item_id,product_id,name,qty,note}>,
     *   changed:list<array{item_id,product_id,name,note,was}>,
     *   moved:?array{from:?string,to:?string}, table_label:?string,
     *   ack:array{bill_id,batch_no,items:list<array{item_id,to_qty,to_note,product_id,name,price_unit_id}>,cancel_refs:list<string>,table_label:?string,batch:array}
     * }
     */
    public function kitchenBatch(OpenBill $bill): ?array
    {
        $fresh = $this->find((int) $bill->getKey());
        if (! $fresh->status->isOpen()) {
            return null;
        }

        $batch = self::computeBatch($fresh);
        if ($batch !== null) {
            $batch['version'] = (int) $fresh->version;
        }

        return $batch;
    }

    /**
     * Lines + cancellations + note changes + table move that are still to be told to the kitchen
     * (0 when nothing was ever sent and the bill has no lines).
     */
    public function pendingCount(OpenBill $bill): int
    {
        $bill->loadMissing(['items.product.category', 'items.priceUnit', 'table']);

        return (int) (self::computeBatch($bill)['pending_count'] ?? 0);
    }

    /**
     * The client printed (or the cashier chose "mark as sent without printing"): remember what the kitchen knows.
     * Idempotent: an already acknowledged batch number changes nothing. A batch from the future is a conflict.
     *
     * @param  array<string, mixed>  $ack  the `ack` of kitchenBatch()
     * @param  string  $mode  'printed' (event `sent`) or 'manual' (event `sent_manual`)
     *
     * @throws BillConflictException
     */
    public function ackKitchenBatch(int $billId, array $ack, User $by, string $mode = 'printed'): OpenBill
    {
        $batchNo = (int) ($ack['batch_no'] ?? 0);
        if ($batchNo < 1 || (int) ($ack['bill_id'] ?? $billId) !== $billId) {
            throw new InvalidArgumentException('The kitchen acknowledgement does not belong to this bill.');
        }

        $this->mutate($billId, function (OpenBill $bill) use ($ack, $batchNo, $by, $mode): void {
            $count = (int) $bill->kitchen_batch_count;
            if ($batchNo <= $count) {
                $this->keepVersion = true;

                return;
            }
            if ($batchNo > $count + 1) {
                throw new BillConflictException((int) $bill->version);
            }

            $items = $this->lockedItems($bill);
            $snapshot = $this->ticketSnapshot($bill, $items, $ack, $batchNo, $by);

            $byId = $items->keyBy('id');
            $pending = array_values((array) ($bill->pending_cancel ?? []));
            foreach ((array) ($ack['items'] ?? []) as $entry) {
                $toQty = Money::qty(max(0.0, (float) ($entry['to_qty'] ?? 0)));
                $item = $byId->get((int) ($entry['item_id'] ?? 0));

                if ($item) {
                    $item->sent_qty = $toQty;
                    $item->sent_note = OpenBillItem::normalizeNote($entry['to_note'] ?? null);
                    $item->save();

                    continue;
                }

                // The line was removed after the ticket was built, but the kitchen got it: tell it next time.
                if ($toQty > 0) {
                    $pending[] = $this->pendingEntry(
                        (int) ($entry['product_id'] ?? 0),
                        (string) ($entry['name'] ?? ''),
                        $toQty,
                        OpenBillItem::normalizeNote($entry['to_note'] ?? null),
                        isset($entry['price_unit_id']) ? (int) $entry['price_unit_id'] : null,
                        $by,
                        null,
                    );
                }
            }

            $printedRefs = array_map('strval', (array) ($ack['cancel_refs'] ?? []));
            $pending = array_values(array_filter(
                $pending,
                fn ($entry): bool => ! in_array((string) ($entry['ref'] ?? ''), $printedRefs, true),
            ));

            $bill->pending_cancel = $pending === [] ? null : $pending;
            $bill->kitchen_batch_count = $batchNo;
            $bill->last_kitchen_batch = $snapshot;
            $bill->kitchen_sent_at = now();

            $this->log(
                $bill,
                $mode === 'manual' ? OpenBillEvent::SENT_MANUAL : OpenBillEvent::SENT,
                $by,
                ['detail' => [
                    'batch_no' => $batchNo,
                    'items' => count((array) ($snapshot['items'] ?? [])),
                    'cancelled' => count((array) ($snapshot['cancelled'] ?? [])),
                    'changed' => count((array) ($snapshot['changed'] ?? [])),
                ]],
            );
        });

        return $this->find($billId);
    }

    /**
     * The last acknowledged ticket, in the shape kitchenBatch returns (without `ack`), for "reprint last ticket".
     */
    public function lastKitchenBatch(OpenBill $bill): ?array
    {
        $last = $bill->last_kitchen_batch;

        return is_array($last) && $last !== [] ? $last : null;
    }

    // ------------------------------------------------------------------------------------------------
    // void
    // ------------------------------------------------------------------------------------------------

    /**
     * Voids an open bill. The reason is mandatory (3 to 255 characters). The permission is decided against the
     * LOCKED bill. The lines stay stored. Returns the kitchen ticket that cancels everything the kitchen was told
     * (a batch for OpenBillPresenter / printPayload), or null if nothing was ever sent.
     *
     * @throws BillReasonRequiredException
     * @throws BillForbiddenException
     * @throws BillConflictException
     */
    public function void(OpenBill $bill, User $by, string $reason, int $expectedVersion): ?array
    {
        $reason = $this->requireReason($reason);

        $fn = function (OpenBill $locked) use ($by, $reason, $expectedVersion): ?array {
            if ((int) $locked->version !== $expectedVersion) {
                throw new BillConflictException((int) $locked->version);
            }

            $wasSent = (int) $locked->kitchen_batch_count > 0 || $locked->kitchen_sent_at !== null;
            if (! OpenBillGate::canVoid($locked, $by)) {
                throw $wasSent ? BillForbiddenException::cancelSent() : new BillForbiddenException();
            }

            $items = $this->lockedItems($locked);

            $cancelled = [];
            foreach (OrderLineSorter::sort($items) as $item) {
                $sent = Money::qty((float) $item->sent_qty);
                if ($sent > 0) {
                    $cancelled[] = [
                        'ref' => 'v'.$item->getKey(),
                        'source' => 'void',
                        'item_id' => (int) $item->getKey(),
                        'product_id' => (int) $item->product_id,
                        'name' => (string) $item->product?->name,
                        'qty' => $sent,
                        'note' => $item->sent_note,
                    ];
                }
            }
            foreach ((array) ($locked->pending_cancel ?? []) as $entry) {
                $cancelled[] = [
                    'ref' => (string) ($entry['ref'] ?? ''),
                    'source' => 'removed',
                    'item_id' => null,
                    'product_id' => (int) ($entry['product_id'] ?? 0),
                    'name' => (string) ($entry['name'] ?? ''),
                    'qty' => Money::qty((float) ($entry['qty'] ?? 0)),
                    'note' => $entry['note'] ?? null,
                ];
            }

            $tableLabel = self::tableLabel($locked->loadMissing('table'));

            $locked->status = OpenBillStatus::Void;
            $locked->voided_by = $by->getKey();
            $locked->voided_at = now();
            $locked->void_reason = $reason;

            $this->log($locked, OpenBillEvent::VOID, $by, [
                'reason' => $reason,
                'detail' => ['sent' => $wasSent, 'cancelled_lines' => count($cancelled)],
            ]);

            if (! $wasSent || $cancelled === []) {
                return null;
            }

            return [
                'batch_no' => 0,
                'kind' => 'void',
                'is_void' => true,
                'void_reason' => $reason,
                'version' => (int) $locked->version + 1,
                'pending_count' => 0,
                'items' => [],
                'cancelled' => $cancelled,
                'changed' => [],
                'moved' => null,
                'table_label' => $tableLabel,
                'ack' => null,
            ];
        };

        return $this->mutate((int) $bill->getKey(), $fn);
    }

    // ------------------------------------------------------------------------------------------------
    // pay
    // ------------------------------------------------------------------------------------------------

    /**
     * Pays the bill: one sale, built only from what the BILL says. From the client only the payment fields are
     * taken (payment_method_id, payed_money, member_id, due_date, voucher); lines, header discount and the frozen
     * tax come from the bill and `friend_price` is forced to false.
     *
     * No outer transaction: SellingService::create owns it (sales lock first), locks the bill in the `before`
     * hook, re-checks status / version / line signature / cash drawer, checks stock under row locks and marks
     * the bill paid in the `after` hook, so everything commits or rolls back together.
     * A bill that is already paid returns the existing sale (alreadyPaid) instead of paying twice.
     *
     * @param  array{payment_method_id?:mixed,payed_money?:mixed,member_id?:mixed,due_date?:mixed,voucher?:mixed}  $payment
     * @param  array{stock_override?:bool}  $opts  stock_override: let lines already served go below zero stock
     *                                              (only for OpenBillGate::canOverrideStock users)
     *
     * @throws ValidationException payment_method_id | payed_money | member_id | due_date | cash_drawer | products.N.qty | voucher
     * @throws BillConflictException
     * @throws BillClosedException
     * @throws BillEmptyException
     * @throws BillForbiddenException
     * @throws SaleBusyException
     */
    public function pay(OpenBill $bill, array $payment, int $expectedVersion, User $by, array $opts = []): PayResult
    {
        // SellingObserver stamps the sale with auth()->user(), so the payer must be the signed-in user.
        $authId = auth()->id();
        if ($authId === null || (int) $authId !== (int) $by->getKey() || ! OpenBillGate::canUse($by)) {
            throw new BillForbiddenException();
        }

        $payment = Arr::only($payment, ['payment_method_id', 'payed_money', 'member_id', 'due_date', 'voucher']);

        // Pre-checks outside the transaction: cheap, and they give the cashier a clear message.
        $current = $this->find((int) $bill->getKey());

        if ($current->status === OpenBillStatus::Paid) {
            $existing = $current->selling_id ? Selling::query()->find($current->selling_id) : null;
            if ($existing) {
                return new PayResult($existing, true);
            }
        }
        if (! $current->status->isOpen()) {
            throw new BillClosedException(status: $current->status, sellingId: $current->selling_id);
        }
        if ((int) $current->version !== $expectedVersion) {
            throw new BillConflictException((int) $current->version);
        }

        $items = $current->items;
        if ($items->isEmpty()) {
            throw new BillEmptyException();
        }

        $method = PaymentMethod::query()->find($payment['payment_method_id'] ?? null);
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => __('Payment method not found')]);
        }

        $this->assertCashDrawerOpen();
        $this->assertProductsExist($items);

        // One moment for the whole payment: the day, the number, the name and the `date` column derive from it.
        $date = BusinessDay::normalize(null);
        $sameDay = $current->business_date->format('Y-m-d') === BusinessDay::dateOf($date);

        $signature = $this->signature($current, $items);
        $memberId = $this->existingId(Member::class, $payment['member_id'] ?? null) ?? $current->member_id;

        $request = [
            'customer_name' => $current->customer_name,
            'table_id' => $current->table_id,
            'member_id' => $memberId,
            'note' => $current->note,
            'payment_method_id' => $method->getKey(),
            'voucher' => filled($payment['voucher'] ?? null) ? trim((string) $payment['voucher']) : null,
            'due_date' => $payment['due_date'] ?? null,
            'discount_price' => (float) $current->discount_price,
            'tax' => (float) $current->tax_percent,
            'friend_price' => false,
            'payed_money' => 0,
            'products' => $this->saleLines($items),
        ];

        // The same instance that maps the request (and remembers the voucher) must create the sale.
        $data = array_merge($request, $this->sellingService->mapProductRequest($request));

        $amountDue = Money::round(max(
            0.0,
            (float) $data['total_price'] - (float) $data['discount_price'] - (float) $data['total_discount_per_item']
        ));
        $plan = PaymentPlanner::plan($method, $amountDue, [
            'payed_money' => $payment['payed_money'] ?? null,
            'member_id' => $memberId,
            'due_date' => $payment['due_date'] ?? null,
        ]);

        $data['payed_money'] = $plan['payed_money'];
        $data['money_changes'] = $plan['money_changes'];
        $data['date'] = $date;
        if ($sameDay) {
            $data['daily_order_number'] = (int) $current->number;
        }

        $overrideIds = [];
        if (! empty($opts['stock_override']) && OpenBillGate::canOverrideStock($by)) {
            $overrideIds = $items
                ->filter(fn (OpenBillItem $item): bool => Money::qty((float) $item->sent_qty) > 0)
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $locked = null;
        $lockedItems = null;
        $overridden = [];

        $before = function () use (
            &$locked,
            &$lockedItems,
            &$overridden,
            $current,
            $expectedVersion,
            $signature,
            $overrideIds,
        ): void {
            $locked = OpenBill::query()->whereKey($current->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw BillClosedException::notFound();
            }
            if (! $locked->status->isOpen()) {
                // Lost the race against another payment of the same bill: report the sale that won.
                throw new BillClosedException(status: $locked->status, sellingId: $locked->selling_id);
            }
            if ((int) $locked->version !== $expectedVersion) {
                throw new BillConflictException((int) $locked->version);
            }

            $lockedItems = $this->lockedItems($locked);
            if ($this->signature($locked, $lockedItems) !== $signature) {
                throw new BillConflictException((int) $locked->version);
            }

            $this->assertCashDrawerOpen();

            $overridden = $overrideIds === [] ? [] : $this->overriddenLines($lockedItems, $overrideIds);
        };

        $after = function (Selling $selling) use (
            &$locked,
            &$lockedItems,
            &$overridden,
            $by,
            $method,
            $sameDay,
            $amountDue,
        ): void {
            /** @var OpenBill $bill */
            $bill = $locked;

            $bill->status = OpenBillStatus::Paid;
            $bill->paid_by = $by->getKey();
            $bill->paid_at = now();
            $bill->selling_id = $selling->getKey();
            $bill->version = (int) $bill->version + 1;
            $bill->save();

            $this->log($bill, OpenBillEvent::PAID, $by, ['detail' => [
                'selling_id' => (int) $selling->getKey(),
                'payment_method' => $method->name,
                'amount_due' => $amountDue,
                'same_day' => $sameDay,
                'order_number' => $selling->daily_order_number,
            ]]);

            $bill->setRelation('items', $lockedItems);
            $pending = (int) (self::computeBatch($bill->loadMissing('table'))['pending_count'] ?? 0);
            if ($pending > 0) {
                $this->log($bill, OpenBillEvent::PAY_WITH_UNSENT, $by, ['detail' => ['pending_count' => $pending]]);
            }

            foreach ($overridden as $line) {
                $this->log($bill, OpenBillEvent::STOCK_OVERRIDE, $by, [
                    'product_id' => $line['product_id'],
                    'product_name' => $line['name'],
                    'qty_from' => $line['stock'],
                    'qty_to' => $line['needed'],
                    'detail' => ['stock' => $line['stock'], 'needed' => $line['needed']],
                ]);
            }
        };

        try {
            $selling = $this->sellingService->create($data, [
                'before' => $before,
                'after' => $after,
                'keep_customer_name' => $sameDay,
                'verify_stock' => true,
                'stock_override_product_ids' => $overrideIds,
            ]);
        } catch (BillClosedException $e) {
            if ($e->isPaid() && $e->sellingId !== null) {
                $existing = Selling::query()->find($e->sellingId);
                if ($existing) {
                    return new PayResult($existing, true);
                }
            }

            throw $e;
        }

        return new PayResult($selling);
    }

    // ------------------------------------------------------------------------------------------------
    // totals, list, print
    // ------------------------------------------------------------------------------------------------

    /**
     * The one total formula (OrderTotals) applied to this bill's lines, its frozen tax and its header discount.
     *
     * @return array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,total_price:float,amount_due:float,qty:float,count:int}
     */
    public function totals(OpenBill $bill): array
    {
        if (! $bill->relationLoaded('items')) {
            $bill->load('items.priceUnit');
        }

        return OrderTotals::compute($bill->items, (float) $bill->tax_percent, (float) $bill->discount_price);
    }

    /**
     * The rows of the bill list: one array per open bill, built by OpenBillPresenter::row. Three constant queries
     * however many bills there are (bills with table and creator joined in, their lines with the unit price joined
     * in, and the business type that decides whether kitchen tickets exist). Nothing is loaded per bill.
     * `created_by` narrows the list to one cashier ("my bills").
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listOpen(User $viewer, ?int $createdBy = null): Collection
    {
        $bills = $this->listOpenBills($viewer, $createdBy);
        if ($bills->isEmpty()) {
            return collect();
        }

        $kitchenEnabled = About::query()->value('business_type') === 'fnb';

        return $bills
            ->map(fn (OpenBill $bill): array => OpenBillPresenter::row(
                $bill,
                $this->totals($bill),
                $viewer,
                $kitchenEnabled,
            ))
            ->values();
    }

    /**
     * The open bills as models, newest first, with just what OrderTotals, label() and OpenBillPresenter::row need
     * and nothing more (no product names): two queries, a table number / creator name / unit price joined in.
     *
     * @return Collection<int, OpenBill>
     */
    public function listOpenBills(User $viewer, ?int $createdBy = null): Collection
    {
        if (! OpenBillGate::canUse($viewer)) {
            throw new BillForbiddenException();
        }

        $rows = DB::table('open_bills')
            ->leftJoin('tables', 'tables.id', '=', 'open_bills.table_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'open_bills.created_by')
            ->where('open_bills.status', OpenBillStatus::Open->value)
            ->when($createdBy !== null, fn ($query) => $query->where('open_bills.created_by', $createdBy))
            ->orderByDesc('open_bills.id')
            ->get(['open_bills.*', 'tables.number as join_table_number', 'creators.name as join_creator_name']);

        if ($rows->isEmpty()) {
            return collect();
        }

        $lineRows = DB::table('open_bill_items')
            ->leftJoin('price_units', 'price_units.id', '=', 'open_bill_items.price_unit_id')
            ->whereIn('open_bill_items.open_bill_id', $rows->pluck('id')->all())
            ->orderBy('open_bill_items.id')
            ->get([
                'open_bill_items.*',
                'price_units.selling_price as join_unit_price',
                'price_units.stock as join_unit_stock',
            ])
            ->groupBy('open_bill_id');

        return $rows->map(function (object $row) use ($lineRows): OpenBill {
            $attributes = (array) $row;
            $tableNumber = $attributes['join_table_number'];
            $creatorName = $attributes['join_creator_name'];
            unset($attributes['join_table_number'], $attributes['join_creator_name']);

            $bill = (new OpenBill())->newFromBuilder($attributes);
            $bill->setRelation('table', $tableNumber === null ? null : (new Table())->forceFill([
                'id' => $attributes['table_id'],
                'number' => $tableNumber,
            ]));
            $bill->setRelation('creator', $creatorName === null ? null : (new User())->forceFill([
                'id' => $attributes['created_by'],
                'name' => $creatorName,
            ]));

            $items = ($lineRows->get($attributes['id']) ?? collect())->map(function (object $line): OpenBillItem {
                $lineAttributes = (array) $line;
                $unitPrice = $lineAttributes['join_unit_price'];
                $unitStock = $lineAttributes['join_unit_stock'];
                unset($lineAttributes['join_unit_price'], $lineAttributes['join_unit_stock']);

                $item = (new OpenBillItem())->newFromBuilder($lineAttributes);
                $item->setRelation('priceUnit', $unitPrice === null ? null : (new PriceUnit())->forceFill([
                    'id' => $lineAttributes['price_unit_id'],
                    'selling_price' => $unitPrice,
                    'stock' => $unitStock,
                ]));

                return $item;
            });
            $bill->setRelation('items', new EloquentCollection($items->all()));

            return $bill;
        })->values();
    }

    /**
     * The JSON a printer needs. `mode` 'bill' is the provisional bill; 'kitchen' needs $batch (from
     * kitchenBatch() or lastKitchenBatch()). Reads only; the bill is loaded fresh.
     *
     * @return array<string, mixed>
     */
    public function printPayload(
        OpenBill $bill,
        string $mode,
        ?array $batch = null,
        bool $reprint = false,
        ?User $by = null,
    ): array {
        if (! in_array($mode, ['bill', 'kitchen'], true)) {
            throw new InvalidArgumentException('Print mode must be bill or kitchen.');
        }
        if ($mode === 'kitchen' && $batch === null) {
            throw new InvalidArgumentException('A kitchen ticket needs a batch.');
        }

        return OpenBillPresenter::payload($this->find((int) $bill->getKey()), $mode, $batch, $reprint, $by);
    }

    // ------------------------------------------------------------------------------------------------
    // the difference between a bill and what the kitchen knows (pure; used by the list, the ticket and pay)
    // ------------------------------------------------------------------------------------------------

    /**
     * Pure and query-free as long as `items` (and ideally `items.product.category`, `table`) are loaded; names
     * that are not loaded come out empty instead of triggering a query.
     *
     * Rules: delta = qty - sent_qty > 0 is a line to cook (kind new / addition); qty < sent_qty is a
     * cancellation (ref "r{item_id}"); equal qty with another note is a changed note; pending_cancel entries are
     * removed lines (cancelled, source "removed"); a table label other than the one in the last acknowledged
     * ticket is a move.
     *
     * @return array<string, mixed>|null null when there is nothing to tell
     */
    public static function computeBatch(OpenBill $bill, ?Collection $items = null): ?array
    {
        $items ??= $bill->items;
        $count = (int) $bill->kitchen_batch_count;
        $tableLabel = self::tableLabel($bill);

        $lines = [];
        $cancelled = [];
        $changed = [];
        $ackItems = [];

        foreach (OrderLineSorter::sort($items) as $item) {
            $qty = Money::qty((float) $item->qty);
            $sent = Money::qty((float) $item->sent_qty);
            $delta = Money::qty($qty - $sent);
            $product = $item->relationLoaded('product') ? $item->getRelation('product') : null;
            $category = $product && $product->relationLoaded('category') ? $product->getRelation('category') : null;
            $name = (string) ($product?->name ?? '');

            $ackEntry = [
                'item_id' => (int) $item->getKey(),
                'to_qty' => $qty,
                'to_note' => $item->note,
                'product_id' => (int) $item->product_id,
                'name' => $name,
                'price_unit_id' => $item->price_unit_id === null ? null : (int) $item->price_unit_id,
            ];

            if ($delta > 0) {
                $lines[] = [
                    'item_id' => (int) $item->getKey(),
                    'product_id' => (int) $item->product_id,
                    'name' => $name,
                    'qty' => $delta,
                    'total_qty' => $qty,
                    'note' => $item->note,
                    'category' => $category?->name,
                    'category_id' => $category?->id,
                    'price' => OrderTotals::lineTotal($item),
                    'sku' => $product?->sku,
                ];
                $ackItems[] = $ackEntry;
            } elseif ($delta < 0) {
                $cancelled[] = [
                    'ref' => 'r'.$item->getKey(),
                    'source' => 'reduce',
                    'item_id' => (int) $item->getKey(),
                    'product_id' => (int) $item->product_id,
                    'name' => $name,
                    'qty' => Money::qty($sent - $qty),
                    'note' => $item->sent_note,
                ];
                $ackItems[] = $ackEntry;
            } elseif ($sent > 0 && ($item->note ?? '') !== ($item->sent_note ?? '')) {
                $changed[] = [
                    'item_id' => (int) $item->getKey(),
                    'product_id' => (int) $item->product_id,
                    'name' => $name,
                    'note' => $item->note,
                    'was' => $item->sent_note,
                ];
                $ackItems[] = $ackEntry;
            }
        }

        $cancelRefs = [];
        foreach ((array) ($bill->pending_cancel ?? []) as $entry) {
            $cancelled[] = [
                'ref' => (string) ($entry['ref'] ?? ''),
                'source' => 'removed',
                'item_id' => null,
                'product_id' => (int) ($entry['product_id'] ?? 0),
                'name' => (string) ($entry['name'] ?? ''),
                'qty' => Money::qty((float) ($entry['qty'] ?? 0)),
                'note' => $entry['note'] ?? null,
            ];
            $cancelRefs[] = (string) ($entry['ref'] ?? '');
        }

        $moved = null;
        $last = $bill->last_kitchen_batch;
        if ($count > 0 && is_array($last)) {
            $was = isset($last['table_label']) && $last['table_label'] !== '' ? (string) $last['table_label'] : null;
            if ($was !== $tableLabel) {
                $moved = ['from' => $was, 'to' => $tableLabel];
            }
        }

        if ($lines === [] && $cancelled === [] && $changed === [] && $moved === null) {
            return null;
        }

        $kind = $count === 0
            ? 'new'
            : ($lines !== [] ? 'addition' : ($cancelled !== [] ? 'cancel_only' : 'change_only'));

        $batch = [
            'batch_no' => $count + 1,
            'kind' => $kind,
            'pending_count' => count($lines) + count($cancelled) + count($changed) + ($moved !== null ? 1 : 0),
            'items' => $lines,
            'cancelled' => $cancelled,
            'changed' => $changed,
            'moved' => $moved,
            'table_label' => $tableLabel,
        ];

        $batch['ack'] = [
            'bill_id' => (int) $bill->getKey(),
            'batch_no' => $count + 1,
            'items' => $ackItems,
            'cancel_refs' => $cancelRefs,
            'table_label' => $tableLabel,
            // What is stored as "last ticket" (and reprinted) once this batch is acknowledged.
            'batch' => Arr::only($batch, ['batch_no', 'kind', 'items', 'cancelled', 'changed', 'moved', 'table_label']),
        ];

        return $batch;
    }

    /**
     * The bare table number the kitchen sees ("4"), or null for takeaway. Does not query when `table` is loaded.
     */
    public static function tableLabel(OpenBill $bill): ?string
    {
        if ($bill->table_id === null) {
            return null;
        }

        $number = trim((string) $bill->getRelationValue('table')?->number);

        return $number === '' ? null : $number;
    }

    // ------------------------------------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------------------------------------

    /**
     * Runs $body in a transaction (sales lock first when asked, R1). A deadlock or lock wait timeout is
     * retried once on a fresh transaction, then reported as SaleBusyException. With an outer transaction
     * already open (nested savepoint) nothing is retried: the outer owner decides.
     */
    private function transact(Closure $body, bool $salesLock = false, bool $retryDuplicate = false): mixed
    {
        if ($salesLock) {
            NumberingLock::ensure();
        }

        $retryable = DB::transactionLevel() === 0;
        $contended = false;
        $duplicated = false;

        while (true) {
            DB::beginTransaction();

            try {
                if ($salesLock) {
                    NumberingLock::acquire(); // FIRST statement of the transaction.
                }

                $result = $body();
                DB::commit();

                return $result;
            } catch (Throwable $e) {
                $this->rollBackQuietly();

                if (! $retryable) {
                    throw $e;
                }

                if (NumberingLock::isContention($e)) {
                    if ($contended) {
                        throw new SaleBusyException(previous: $e);
                    }
                    $contended = true;
                    usleep(random_int(50_000, 150_000));

                    continue;
                }

                if ($retryDuplicate && ! $duplicated && $this->isDuplicateNumber($e)) {
                    $duplicated = true;

                    continue;
                }

                throw $e;
            }
        }
    }

    private function isDuplicateNumber(Throwable $e): bool
    {
        return $e instanceof QueryException
            && (string) $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'open_bills');
    }

    private function rollBackQuietly(): void
    {
        try {
            DB::rollBack();
        } catch (Throwable) {
            // The original exception is the one that matters.
        }
    }

    /**
     * @return EloquentCollection<int, OpenBillItem> all lines of the bill, locked (R1 step 3), with product, category and unit
     */
    private function lockedItems(OpenBill $bill): EloquentCollection
    {
        $items = OpenBillItem::query()
            ->where('open_bill_id', $bill->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $items->load(['product.category', 'priceUnit']);

        return $items;
    }

    private function lockItem(OpenBill $bill, int $itemId): ?OpenBillItem
    {
        return OpenBillItem::query()
            ->where('open_bill_id', $bill->getKey())
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();
    }

    private function addLineLocked(
        OpenBill $bill,
        Product $product,
        float $qty,
        ?int $priceUnitId,
        ?string $note,
        User $by,
        ?float $discount = null,
    ): OpenBillItem {
        $qty = Money::qty($qty);
        if ($qty <= 0) {
            throw new InvalidArgumentException('The quantity to add must be positive.');
        }

        $unit = null;
        if ($priceUnitId !== null) {
            $unit = PriceUnit::query()->where('id', $priceUnitId)->where('product_id', $product->getKey())->first();
            if (! $unit) {
                throw new InvalidArgumentException('The price unit does not belong to the product.');
            }
        }

        $item = OpenBillItem::query()
            ->where('open_bill_id', $bill->getKey())
            ->where('product_id', $product->getKey())
            ->lockForUpdate()
            ->first();

        if ($item) {
            $item->loadMissing('priceUnit');
            if ($unit && (int) $item->price_unit_id !== (int) $unit->getKey()) {
                throw ValidationException::withMessages([
                    'price_unit_id' => __('This product is already in the bill with another unit'),
                ]);
            }

            $old = Money::qty((float) $item->qty);
            $new = Money::qty($old + $qty);
            $current = $item->priceUnit ? (float) $item->priceUnit->selling_price : (float) $product->selling_price;

            $item->price = OrderLinePricing::repriceOnQtyChange($old, (float) $item->price, $new, $current);
            $item->qty = $new;
            if ($note !== null) {
                $item->note = $note;
            }
            if ($discount !== null) {
                $item->discount_price = Money::round($discount);
            }
            $item->save();

            $this->log($bill, OpenBillEvent::LINE_QTY, $by, [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'qty_from' => $old,
                'qty_to' => $new,
            ]);
        } else {
            // Netting: a sent line that was removed earlier comes back already "sent" with its old quantity.
            $sentQty = 0.0;
            $sentNote = null;
            $keep = [];
            foreach (array_values((array) ($bill->pending_cancel ?? [])) as $entry) {
                if ((int) ($entry['product_id'] ?? 0) === (int) $product->getKey()) {
                    $sentQty += (float) ($entry['qty'] ?? 0);
                    $sentNote = $entry['note'] ?? $sentNote;
                } else {
                    $keep[] = $entry;
                }
            }
            if ($sentQty > 0) {
                $bill->pending_cancel = $keep === [] ? null : $keep;
            }

            $unitPrice = $unit ? (float) $unit->selling_price : (float) $product->selling_price;

            $item = new OpenBillItem();
            $item->fill([
                'open_bill_id' => $bill->getKey(),
                'product_id' => $product->getKey(),
                'user_id' => $by->getKey(),
                'qty' => $qty,
                'price' => OrderLinePricing::repriceOnQtyChange(0.0, 0.0, $qty, $unitPrice),
                'discount_price' => $discount !== null ? Money::round($discount) : null,
                'price_unit_id' => $unit?->getKey(),
                'note' => $note,
                'sent_qty' => Money::qty($sentQty),
                'sent_note' => $sentNote,
            ]);
            $item->save();
            $item->setRelation('priceUnit', $unit);

            $this->log($bill, OpenBillEvent::LINE_ADDED, $by, [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'qty_from' => 0,
                'qty_to' => $qty,
                'detail' => $sentQty > 0 ? ['netted_sent_qty' => Money::qty($sentQty)] : null,
            ]);
        }

        $item->setRelation('product', $product);

        return $item;
    }

    /**
     * Removes a line. A line the kitchen already got needs the permission and a reason, and leaves a
     * pending cancellation that the next ticket prints as BATAL (or that adding the product again nets away).
     */
    private function removeLine(OpenBill $bill, OpenBillItem $item, User $by, ?string $reason): void
    {
        $sent = Money::qty((float) $item->sent_qty);
        $cleanReason = null;

        if ($sent > 0) {
            $cleanReason = $this->authorizeCancelSent($bill, $by, $reason);

            $pending = array_values((array) ($bill->pending_cancel ?? []));
            $pending[] = $this->pendingEntry(
                (int) $item->product_id,
                (string) $item->product?->name,
                $sent,
                $item->sent_note,
                $item->price_unit_id === null ? null : (int) $item->price_unit_id,
                $by,
                $cleanReason,
            );
            $bill->pending_cancel = $pending;
        }

        $this->log($bill, OpenBillEvent::LINE_REMOVED, $by, [
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name,
            'qty_from' => Money::qty((float) $item->qty),
            'qty_to' => 0,
            'reason' => $cleanReason,
            'detail' => $sent > 0 ? ['sent_qty' => $sent] : null,
        ]);

        $item->delete();
    }

    /**
     * @return array{ref:string,product_id:int,name:string,qty:float,note:?string,price_unit_id:?int,removed_by:?int,removed_at:string,reason:?string}
     */
    private function pendingEntry(
        int $productId,
        string $name,
        float $qty,
        ?string $note,
        ?int $priceUnitId,
        User $by,
        ?string $reason,
    ): array {
        return [
            'ref' => bin2hex(random_bytes(4)),
            'product_id' => $productId,
            'name' => $name,
            'qty' => Money::qty($qty),
            'note' => $note,
            'price_unit_id' => $priceUnitId,
            'removed_by' => $by->getKey(),
            'removed_at' => now()->toIso8601String(),
            'reason' => $reason,
        ];
    }

    /**
     * The gate is asked about the LOCKED bill (a copy the client held could be older), then the reason is required.
     *
     * @throws BillForbiddenException
     * @throws BillReasonRequiredException
     */
    private function authorizeCancelSent(OpenBill $locked, User $by, ?string $reason): string
    {
        if (! OpenBillGate::canCancelSent($locked, $by)) {
            throw BillForbiddenException::cancelSent();
        }

        return $this->requireReason($reason);
    }

    private function requireReason(?string $reason): string
    {
        $clean = OpenBillItem::normalizeNote($reason);
        if ($clean === null || mb_strlen($clean) < 3) {
            throw new BillReasonRequiredException();
        }

        return mb_substr($clean, 0, 255);
    }

    private function unitPriceNow(OpenBillItem $item): float
    {
        if ($item->priceUnit) {
            return (float) $item->priceUnit->selling_price;
        }

        return (float) ($item->product?->selling_price ?? 0);
    }

    /**
     * The sale lines (one per product) built from bill lines. `price` is the line total before its discount.
     *
     * @param  Collection<int, OpenBillItem>  $items
     * @return list<array<string, mixed>>
     */
    private function saleLines(Collection $items): array
    {
        return $items->map(fn (OpenBillItem $item): array => [
            'product_id' => (int) $item->product_id,
            'qty' => Money::qty((float) $item->qty),
            'price' => OrderTotals::lineTotal($item),
            'discount_price' => (float) ($item->discount_price ?? 0),
            'price_unit_id' => $item->price_unit_id === null ? null : (int) $item->price_unit_id,
            'note' => $item->note,
        ])->values()->all();
    }

    /**
     * Fingerprint of everything the payment amount depends on: lines (and the unit price of unit lines), the
     * frozen tax and the header discount. Compared again under the lock.
     *
     * @param  Collection<int, OpenBillItem>  $items
     */
    private function signature(OpenBill $bill, Collection $items): string
    {
        return json_encode([
            'tax' => Money::round((float) $bill->tax_percent, 3),
            'discount' => Money::round((float) $bill->discount_price),
            'lines' => $items->sortBy('id')->map(fn (OpenBillItem $item): array => [
                (int) $item->getKey(),
                (int) $item->product_id,
                Money::qty((float) $item->qty),
                Money::round((float) $item->price),
                Money::round((float) ($item->discount_price ?? 0)),
                $item->price_unit_id === null ? null : (int) $item->price_unit_id,
                $item->price_unit_id === null || ! $item->priceUnit
                    ? null
                    : Money::round((float) $item->priceUnit->selling_price),
                $item->note,
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ValidationException
     */
    private function assertCashDrawerOpen(): void
    {
        if (Setting::get('cash_drawer_enabled', false) && ! CashDrawer::lastOpened()->first()) {
            throw ValidationException::withMessages(['cash_drawer' => __('Cash drawer is not opened')]);
        }
    }

    /**
     * A soft-deleted product cannot be sold (and would break the sale mapping): name it and ask to remove the line.
     * Same error key as StockGuard (products.{index}.qty).
     *
     * @param  Collection<int, OpenBillItem>  $items
     *
     * @throws ValidationException
     */
    private function assertProductsExist(Collection $items): void
    {
        $errors = [];
        foreach ($items->values() as $index => $item) {
            $product = $item->product;
            if (! $product || $product->trashed()) {
                $errors['products.'.$index.'.qty'] = __('Product :name was deleted', [
                    'name' => (string) $product?->name,
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Lines the cashier was allowed to sell below zero stock, and where the stock really was short.
     *
     * @param  Collection<int, OpenBillItem>  $items
     * @param  int[]  $overrideIds
     * @return list<array{product_id:int,name:string,needed:float,stock:float}>
     */
    private function overriddenLines(Collection $items, array $overrideIds): array
    {
        $need = [];
        foreach ($items as $item) {
            $productId = (int) $item->product_id;
            if (! in_array($productId, $overrideIds, true)) {
                continue;
            }
            $perUnit = $item->priceUnit ? (float) $item->priceUnit->stock : 1.0;
            $need[$productId] = ($need[$productId] ?? 0.0) + (float) $item->qty * $perUnit;
        }

        if ($need === []) {
            return [];
        }

        $products = Product::withTrashed()->whereIn('id', array_keys($need))->get()->keyBy('id');
        $short = [];
        foreach ($need as $productId => $needed) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product || $product->trashed() || $product->isStockUnlimited()) {
                continue;
            }
            if (Money::qty((float) $product->stock) < Money::qty($needed)) {
                $short[] = [
                    'product_id' => $productId,
                    'name' => (string) $product->name,
                    'needed' => Money::qty($needed),
                    'stock' => Money::qty((float) $product->stock),
                ];
            }
        }

        return $short;
    }

    /**
     * What is stored as "last kitchen ticket": the batch the client printed (from the ack) so a reprint is
     * identical; if the client sent a bare ack it is rebuilt from the locked state, before it is applied.
     *
     * @param  EloquentCollection<int, OpenBillItem>  $items
     * @return array<string, mixed>
     */
    private function ticketSnapshot(
        OpenBill $bill,
        EloquentCollection $items,
        array $ack,
        int $batchNo,
        User $by,
    ): array {
        $given = $ack['batch'] ?? null;
        $keys = ['kind', 'items', 'cancelled', 'changed', 'moved', 'table_label'];
        $shape = is_array($given) ? Arr::only($given, $keys) : [];

        if ($shape === []) {
            $bill->setRelation('items', $items);
            $computed = self::computeBatch($bill->loadMissing('table'));
            $shape = $computed === null
                ? [
                    'kind' => 'change_only',
                    'items' => [],
                    'cancelled' => [],
                    'changed' => [],
                    'moved' => null,
                    'table_label' => self::tableLabel($bill),
                ]
                : Arr::only($computed, $keys);
        }

        return array_merge($shape, [
            'batch_no' => $batchNo,
            'sent_at' => now()->toIso8601String(),
            'sent_by' => $this->userLabel($by),
        ]);
    }

    private function userLabel(User $user): ?string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : ($user->email ?: null);
    }

    /**
     * One line per product: quantities and prices add up, the first unit and the first note win.
     *
     * @param  EloquentCollection<int, CartItem>  $cart
     * @return list<array{product_id:int,qty:float,price:float,discount_price:?float,price_unit_id:?int,note:?string}>
     */
    private function mergeCart(EloquentCollection $cart): array
    {
        $merged = [];
        foreach ($cart as $line) {
            $productId = (int) $line->product_id;
            $discount = (float) ($line->discount_price ?? 0);

            if (! isset($merged[$productId])) {
                $merged[$productId] = [
                    'product_id' => $productId,
                    'qty' => 0.0,
                    'price' => 0.0,
                    'discount_price' => 0.0,
                    'price_unit_id' => null,
                    'note' => null,
                ];
            }

            $merged[$productId]['qty'] += (float) $line->qty;
            $merged[$productId]['price'] += OrderTotals::lineTotal($line);
            $merged[$productId]['discount_price'] += max(0.0, $discount);
            $merged[$productId]['price_unit_id'] ??= $line->price_unit_id === null ? null : (int) $line->price_unit_id;
            $merged[$productId]['note'] ??= $line->note;
        }

        return array_values(array_map(fn (array $line): array => [
            'product_id' => $line['product_id'],
            'qty' => Money::qty($line['qty']),
            'price' => Money::round($line['price']),
            'discount_price' => $line['discount_price'] > 0 ? Money::round($line['discount_price']) : null,
            'price_unit_id' => $line['price_unit_id'],
            'note' => $line['note'],
        ], $merged));
    }

    /**
     * The id when the row exists (soft-deleted rows do not count), else null.
     *
     * @param  class-string<Model>  $model
     */
    private function existingId(string $model, mixed $id): ?int
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return $model::query()->whereKey((int) $id)->exists() ? (int) $id : null;
    }

    private function cleanName(mixed $value): ?string
    {
        return is_scalar($value) ? OpenBillItem::normalizeNote((string) $value) : null;
    }

    /**
     * Free text (the order note): control characters except line breaks and tabs are dropped.
     */
    private function cleanText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = mb_scrub((string) $value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = mb_substr(trim($text), 0, 5000);

        return trim($text) === '' ? null : $text;
    }

    private function moneyIn(mixed $value): float
    {
        $amount = is_numeric($value) ? (float) $value : Money::parse($value);

        return Money::round(max(0.0, $amount));
    }

    private function defaultTax(): float
    {
        return Money::round(min(999.999, max(0.0, (float) Setting::get('default_tax', 0))), 3);
    }
}
