<?php

namespace App\Services\Tenants;

use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\User;
use App\Support\BusinessDay;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Turns a bill into arrays for the screen and the printers. Reads only; never writes.
 *
 * payload(): the Selling-shaped JSON that window.LakasirReceipt.print / preview consume (mode 'bill' = provisional
 * bill, mode 'kitchen' = kitchen ticket). Times are shown in the business timezone. Table labels are bare numbers.
 * row(): one line of the bill list; it runs no query as long as the bill came from OpenBillService::listOpen.
 */
final class OpenBillPresenter
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed>|null  $batch  required for mode 'kitchen' (kitchenBatch(), lastKitchenBatch() or a void ticket)
     * @return array<string, mixed>
     */
    public static function payload(OpenBill $bill, string $mode, ?array $batch, bool $reprint, ?User $by): array
    {
        $bill->loadMissing(['items.product.category', 'items.priceUnit', 'table', 'member', 'creator']);

        $totals = OrderTotals::compute($bill->items, (float) $bill->tax_percent, (float) $bill->discount_price);
        $businessDate = $bill->business_date->format('Y-m-d');
        $dateLabel = BusinessDay::label($businessDate);

        $sentAt = $mode === 'kitchen' && $reprint && ! empty($batch['sent_at']) ? self::local($batch['sent_at']) : null;
        $printedAt = $sentAt ?? self::local(now());
        $createdAt = self::local($bill->created_at);

        $userName = $mode === 'kitchen' && $reprint && ! empty($batch['sent_by'])
            ? (string) $batch['sent_by']
            : self::userName($by) ?? self::userName($bill->creator);

        $payload = [
            'id' => (int) $bill->getKey(),
            'job_key' => self::jobKey($bill, $mode, $batch, $reprint),
            'daily_order_number' => (int) $bill->number,
            'formatted_daily_order_number' => $bill->formattedNumber(),
            'number_label' => '#'.str_pad((string) $bill->number, 3, '0', STR_PAD_LEFT),
            'business_date_label' => $dateLabel,
            'customer_name' => $bill->customer_name,
            'table' => $bill->table ? ['number' => (string) $bill->table->number] : null,
            'member' => $bill->member ? ['name' => (string) $bill->member->name] : null,
            'note' => $bill->note,
            'note_plain' => PlainText::fromHtml($bill->note),
            'user' => ['name' => $userName ?? ''],
            'created_at_label' => $createdAt->format($dateLabel === null ? 'H:i' : 'd/m H:i'),
            'printed_on_label' => $printedAt->format('d/m/Y'),
            'printed_at_label' => $printedAt->format('H:i'),
            'subtotal' => $totals['subtotal'],
            'tax' => (float) $bill->tax_percent,
            'tax_price' => $totals['tax_price'],
            'discount_price' => $totals['header_discount'],
            'total_discount_per_item' => $totals['line_discount'],
            'total_price' => $totals['total_price'],
            'amount_due' => $totals['amount_due'],
        ];

        if ($mode === 'kitchen') {
            $batch ??= [];
            $payload['selling_details'] = array_map(static fn (array $line): array => [
                'qty' => Money::qty((float) ($line['qty'] ?? 0)),
                'total_qty' => Money::qty((float) ($line['total_qty'] ?? $line['qty'] ?? 0)),
                'price' => Money::round((float) ($line['price'] ?? 0)),
                'discount_price' => 0.0,
                'note' => $line['note'] ?? null,
                'product' => [
                    'id' => (int) ($line['product_id'] ?? 0),
                    'sku' => $line['sku'] ?? null,
                    'name' => (string) ($line['name'] ?? ''),
                    'category' => ($line['category'] ?? null) !== null
                        ? ['id' => $line['category_id'] ?? null, 'name' => (string) $line['category']]
                        : null,
                ],
            ], array_values((array) ($batch['items'] ?? [])));

            $payload['kitchen'] = [
                'kind' => (string) ($batch['kind'] ?? 'new'),
                'batch_no' => (int) ($batch['batch_no'] ?? 0),
                'reprint' => $reprint,
                'cancelled' => array_values((array) ($batch['cancelled'] ?? [])),
                'changed' => array_values((array) ($batch['changed'] ?? [])),
                'moved' => $batch['moved'] ?? null,
                'table_label' => $batch['table_label'] ?? null,
                'void_reason' => $batch['void_reason'] ?? null,
                'is_void' => (bool) ($batch['is_void'] ?? false),
            ];

            return $payload;
        }

        $payload['selling_details'] = OrderLineSorter::sort($bill->items)
            ->map(static fn (OpenBillItem $item): array => self::billLine($item))
            ->values()
            ->all();

        return $payload;
    }

    /**
     * One row of the open bills list.
     *
     * @param  array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,total_price:float,amount_due:float,qty:float,count:int}  $totals  OpenBillService::totals()
     * @return array<string, mixed>
     */
    public static function row(OpenBill $bill, array $totals, User $viewer, bool $kitchenEnabled): array
    {
        $today = BusinessDay::today();
        $businessDate = $bill->business_date->format('Y-m-d');
        $createdAt = $bill->created_at;
        $ageMinutes = self::minutesSince($createdAt);

        $fromEarlierDay = $businessDate < $today;
        $stale = $fromEarlierDay && $ageMinutes > ((int) config('pos.stale_after_hours', 6)) * 60;

        $batch = OpenBillService::computeBatch($bill);
        $pending = (int) ($batch['pending_count'] ?? 0);
        $sentCount = (int) $bill->kitchen_batch_count;
        $hasLines = $totals['count'] > 0;

        return [
            'id' => (int) $bill->getKey(),
            'number' => (int) $bill->number,
            'number_label' => '#'.str_pad((string) $bill->number, 3, '0', STR_PAD_LEFT),
            'business_date_label' => BusinessDay::label($businessDate, $today),
            'label' => $bill->label(),
            'table_label' => OpenBillService::tableLabel($bill),
            'customer_name' => $bill->customer_name,
            'business_date' => $businessDate,
            'created_by' => $bill->created_by === null ? null : (int) $bill->created_by,
            'creator_name' => $bill->creator?->name,
            'items_count' => $totals['count'],
            'qty_total' => $totals['qty'],
            'amount_due' => $totals['amount_due'],
            'age_minutes' => $ageMinutes,
            'stale' => $stale,
            'crossed_midnight' => $fromEarlierDay && ! $stale,
            'sent' => $sentCount > 0,
            // Never sent: unsent as soon as there is a line (an empty bill has nothing to send).
            'unsent' => $kitchenEnabled && ($sentCount === 0 ? $hasLines : $pending > 0),
            'unsent_count' => $pending,
            'unsent_age_minutes' => self::minutesSince($bill->updated_at),
            'version' => (int) $bill->version,
            'can_void' => OpenBillGate::canVoid($bill, $viewer),
            'can_cancel_sent' => OpenBillGate::canCancelSent($bill, $viewer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function billLine(OpenBillItem $item): array
    {
        $product = $item->product;
        $category = $product?->category;
        $qty = Money::qty((float) $item->qty);
        $total = OrderTotals::lineTotal($item);

        // A plain line whose stored price no longer equals today's catalog price x qty (price unit lines never do).
        $stale = ! $item->price_unit_id
            && $product !== null
            && ! Money::eq($total, (float) $product->selling_price * $qty);

        return [
            'qty' => $qty,
            'price' => $total,
            'discount_price' => (float) ($item->discount_price ?? 0),
            'note' => $item->note,
            'price_stale' => $stale,
            'product' => [
                'id' => (int) $item->product_id,
                'sku' => $product?->sku,
                'name' => (string) $product?->name,
                'category' => $category ? ['id' => (int) $category->id, 'name' => (string) $category->name] : null,
            ],
        ];
    }

    /**
     * 'k{id}-{batch_no}' (kitchen ticket), 'b{id}-v{version}' (provisional bill), '{mode}{id}-r' (any reprint).
     * A void ticket is its own key so it is never mistaken for a numbered shipment.
     *
     * @param  array<string, mixed>|null  $batch
     */
    private static function jobKey(OpenBill $bill, string $mode, ?array $batch, bool $reprint): string
    {
        $id = (int) $bill->getKey();

        if ($reprint) {
            return $mode.$id.'-r';
        }
        if ($mode === 'kitchen') {
            return ! empty($batch['is_void']) ? 'k'.$id.'-void' : 'k'.$id.'-'.(int) ($batch['batch_no'] ?? 0);
        }

        return 'b'.$id.'-v'.(int) $bill->version;
    }

    private static function local(mixed $moment): Carbon
    {
        return Carbon::parse($moment)->setTimezone(BusinessDay::timezone());
    }

    private static function userName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) $user->name);

        return $name !== '' ? $name : (trim((string) $user->email) ?: null);
    }

    private static function minutesSince(?\DateTimeInterface $moment): int
    {
        if ($moment === null) {
            return 0;
        }

        return max(0, intdiv(now()->getTimestamp() - $moment->getTimestamp(), 60));
    }
}
