<?php

namespace App\Services\Tenants;

use App\Events\RecalculateEvent;
use App\Events\SellingCreated;
use App\Exceptions\SaleBusyException;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Voucher;
use App\Services\Tenants\Traits\HasNumber;
use App\Services\VoucherService;
use App\Support\BusinessDay;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SellingService
{
    use HasNumber;

    /**
     * Id of the voucher whose discount mapProductRequest applied. The quota is consumed inside the sale's
     * transaction by create() (atomically), not while the request is being mapped. Cleared by create().
     */
    protected ?int $voucherToConsume = null;

    /**
     * Creates a sale: number, name, details, stock, voucher, all in ONE transaction under the sales lock.
     *
     * Order matters (plan-v3 14.0 R1, 14.1). The lock row is created OUTSIDE the transaction, and the lock is
     * the FIRST statement inside it: on MySQL (REPEATABLE READ) the first consistent read fixes the snapshot,
     * so a read before the lock would make "max(daily_order_number) + 1" stale and hand out a duplicate number.
     * Everything that locks something else (a bill row, cart rows, product rows) does so after this.
     *
     * A deadlock (1213) or lock wait timeout (1205) is retried once with a fresh transaction, then reported as
     * SaleBusyException. Hooks run inside the transaction on every attempt, so they must be repeatable.
     *
     * @param  array<string, mixed>  $data
     * @param  array{
     *     before?: ?Closure(): void,
     *     after?: ?Closure(Selling): void,
     *     keep_customer_name?: bool,
     *     verify_stock?: bool,
     *     stock_override_product_ids?: int[],
     * }  $hooks
     *
     * @throws SaleBusyException
     * @throws ValidationException
     */
    public function create(array $data, array $hooks = []): Selling
    {
        // One moment for the whole sale: the business day, the number, the name and the column all derive from it.
        $data['date'] = BusinessDay::normalize($data['date'] ?? null);

        $voucherId = $this->voucherToConsume;
        $this->voucherToConsume = null;

        NumberingLock::ensure();

        $attempt = 0;
        while (true) {
            $attempt++;
            DB::beginTransaction();

            try {
                NumberingLock::acquire(); // FIRST statement of the transaction.

                if ($before = $hooks['before'] ?? null) {
                    $before();
                }

                if (! empty($hooks['verify_stock'])) {
                    StockGuard::assert($data['products'] ?? [], [
                        'override' => array_map('intval', $hooks['stock_override_product_ids'] ?? []),
                    ]);
                }

                $selling = new Selling;
                $selling->numberingLocked = true;
                $selling->keepCustomerName = (bool) ($hooks['keep_customer_name'] ?? false);
                $selling->fill($data);
                $selling->save();

                SellingCreated::dispatch($selling, $data);

                /** @var \Illuminate\Database\Eloquent\Collection<int, Product> $products */
                $products = Product::whereIn('id', $selling->sellingDetails->pluck('product_id'))->get();
                RecalculateEvent::dispatch($products, $data);

                if ($voucherId !== null) {
                    $this->consumeVoucher($voucherId);
                }

                if ($after = $hooks['after'] ?? null) {
                    $after($selling);
                }

                DB::commit();

                return $selling;
            } catch (Throwable $e) {
                $this->rollBackQuietly();

                if (! NumberingLock::isContention($e)) {
                    throw $e;
                }

                if ($attempt >= 2) {
                    throw new SaleBusyException(previous: $e);
                }

                usleep(random_int(50_000, 150_000));
            }
        }
    }

    public function mapProductRequest(array $data): array
    {
        $this->voucherToConsume = null;

        if (Setting::get('default_tax', 0) != 0 && !isset($data['tax'])) {
            $data['tax'] = Setting::get('default_tax');
        }
        $request = [];
        $payed_money = ($data['payed_money'] ?? 0);
        if (isset($data['friend_price']) && ! $data['friend_price']) {
            $total_price = 0;
            $total_price_after_discount = 0;
            $total_discount_per_item = 0;
            $total_cost = 0;
            $productsCollection = collect($data['products']);
            $productsCollection->each(
                function ($product) use (&$total_price, &$total_cost, &$total_price_after_discount, &$total_discount_per_item) {
                    if (isset($product['price_unit_id']) && $product['price_unit_id'] != null) {
                        $product['price'] = PriceUnit::whereId($product['price_unit_id'])->first()->selling_price * $product['qty'];
                    }
                    $modelProduct = Product::find($product['product_id']);
                    $total_price += $product['price'] ?? $modelProduct->selling_price * $product['qty'];
                    $total_discount_per_item += ($product['discount_price'] ?? 0);
                    $total_price_after_discount = $total_price - ($product['discount_price'] ?? 0);
                    $total_cost += $modelProduct->initial_price * $product['qty'];
                }
            );
            $tax = $data['tax'] ?? 0;
            $tax_price = Money::round($total_price * $tax / 100);
            $total_price = Money::round($total_price + $tax_price);
            $total_qty = collect($data['products'])->sum('qty');
            $discount_price = $data['discount_price'] ?? 0;
            if ($data['voucher'] ?? false) {
                $voucherService = new VoucherService();
                if ($voucher = $voucherService->applyable($data['voucher'], $total_price)) {
                    $discount_price = $voucher->calculate();
                    // The quota is consumed by create(), inside the sale's transaction.
                    $this->voucherToConsume = $voucher->voucher->id;
                }
            }
            $request = [
                'discount_price' => $discount_price,
                'total_price' => $total_price,
                'total_cost' => $total_cost,
                'total_qty' => $total_qty,
                'money_changes' => Money::round($payed_money - ($total_price - $discount_price - $total_discount_per_item)),
                'total_discount_per_item' => $total_discount_per_item,
                'tax_price' => $tax_price,
                'tax' => $tax,
                'payed_money' => $payed_money,
            ];
        } else {
            $request = [
                'money_changes' => Money::round(($data['payed_money'] ?? 0) - $data['total_price']),
                'payed_money' => $payed_money,
            ];
        }

        if (! isset($data['payment_method_id'])) {
            $request = array_merge($request, [
                'payment_method_id' => PaymentMethod::where('name', 'Cash')->first()->id,
            ]);
        } else {
            /** @var PaymentMethod $pMethod */
            $pMethod = PaymentMethod::find($data['payment_method_id']);
            if ($pMethod->is_credit) {
                $request['money_changes'] = 0;
            }
        }

        return $request;
    }

    /**
     * Takes one use of the voucher. One atomic UPDATE: if two sales race for the last use, exactly one wins
     * and the other fails with a validation error (the whole sale is rolled back).
     */
    private function consumeVoucher(int $voucherId): void
    {
        $consumed = Voucher::query()
            ->whereKey($voucherId)
            ->where('kuota', '>', 0)
            ->decrement('kuota');

        if ($consumed !== 1) {
            throw ValidationException::withMessages([
                'voucher' => __('voucher expired'),
            ]);
        }
    }

    /**
     * Rolls back without letting a failing rollback (lost connection) hide the exception being handled.
     */
    private function rollBackQuietly(): void
    {
        try {
            DB::rollBack();
        } catch (Throwable) {
            // The original exception is the one that matters.
        }
    }
}
