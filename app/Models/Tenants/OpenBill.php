<?php

namespace App\Models\Tenants;

use App\Enums\OpenBillStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved, not yet paid order ("Bill"). Not a sale: it does not touch stock, reports, receivables or the cash
 * drawer until it is paid (then `selling_id` points at the created Selling).
 *
 * @property int $id
 * @property int $number
 * @property OpenBillStatus $status
 * @property int $version
 */
class OpenBill extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OpenBillStatus::class,
            'business_date' => 'date:Y-m-d',
            'tax_percent' => 'float',
            'discount_price' => 'float',
            'pending_cancel' => 'array',
            'last_kitchen_batch' => 'array',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'kitchen_sent_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpenBillItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OpenBillEvent::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function selling(): BelongsTo
    {
        return $this->belongsTo(Selling::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('status'), OpenBillStatus::Open->value);
    }

    /**
     * Customer name, else "Meja {table}", else "Bill #012". Does not load anything itself:
     * eager load `table` when listing.
     */
    public function label(): string
    {
        $name = trim((string) $this->customer_name);
        if ($name !== '') {
            return $name;
        }

        // $this->table inside the model is Eloquent's table-name property, not the relation.
        $tableNumber = trim((string) $this->getRelationValue('table')?->number);
        if ($tableNumber !== '') {
            return __('Table').' '.$tableNumber;
        }

        return 'Bill #'.$this->paddedNumber();
    }

    /**
     * The order number as printed on kitchen tickets and receipts, e.g. "Order #012".
     */
    public function formattedNumber(): string
    {
        return 'Order #'.$this->paddedNumber();
    }

    private function paddedNumber(): string
    {
        return str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }
}
