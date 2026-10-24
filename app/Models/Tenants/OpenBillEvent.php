<?php

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail of an open bill. Rows are never updated (no `updated_at`).
 *
 * @property int $id
 * @property int $open_bill_id
 * @property string $action
 */
class OpenBillEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';
    public const HEADER = 'header';
    public const LINE_ADDED = 'line_added';
    public const LINE_QTY = 'line_qty';
    public const LINE_REMOVED = 'line_removed';
    public const LINE_NOTE = 'line_note';
    public const LINE_DISCOUNT = 'line_discount';
    public const SENT = 'sent';
    public const SENT_MANUAL = 'sent_manual';
    public const CANCEL_SENT = 'cancel_sent';
    public const VOID = 'void';
    public const PAID = 'paid';
    public const PAY_WITH_UNSENT = 'pay_with_unsent';
    public const STOCK_OVERRIDE = 'stock_override';
    public const REPRINT = 'reprint';

    public const ACTIONS = [
        self::CREATED,
        self::HEADER,
        self::LINE_ADDED,
        self::LINE_QTY,
        self::LINE_REMOVED,
        self::LINE_NOTE,
        self::LINE_DISCOUNT,
        self::SENT,
        self::SENT_MANUAL,
        self::CANCEL_SENT,
        self::VOID,
        self::PAID,
        self::PAY_WITH_UNSENT,
        self::STOCK_OVERRIDE,
        self::REPRINT,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'qty_from' => 'float',
            'qty_to' => 'float',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(OpenBill::class, 'open_bill_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write one audit row. Call it inside the transaction of the mutation it describes.
     *
     * @param  array{product_id?:int|null,product_name?:string|null,qty_from?:float|int|null,qty_to?:float|int|null,reason?:string|null,detail?:array|null}  $attrs
     */
    public static function record(OpenBill $bill, string $action, ?User $by, array $attrs = []): self
    {
        $productName = $attrs['product_name'] ?? null;
        $reason = $attrs['reason'] ?? null;

        return static::query()->create([
            'open_bill_id' => $bill->getKey(),
            'user_id' => $by?->getKey(),
            'action' => $action,
            'product_id' => $attrs['product_id'] ?? null,
            'product_name' => $productName === null ? null : mb_substr((string) $productName, 0, 255),
            'qty_from' => $attrs['qty_from'] ?? null,
            'qty_to' => $attrs['qty_to'] ?? null,
            'reason' => $reason === null ? null : mb_substr((string) $reason, 0, 255),
            'detail' => $attrs['detail'] ?? null,
        ]);
    }
}
