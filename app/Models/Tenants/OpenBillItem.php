<?php

namespace App\Models\Tenants;

use App\Models\Tenants\Concerns\HasOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an open bill. `price` is the line total before discount (same meaning as cart_items.price);
 * `sent_qty` / `sent_note` are what the kitchen has been told so far.
 *
 * @property int $id
 * @property int $open_bill_id
 * @property int $product_id
 */
class OpenBillItem extends Model
{
    use HasOrderLine;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'price' => 'float',
            'discount_price' => 'float',
            'sent_qty' => 'float',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(OpenBill::class, 'open_bill_id');
    }

    /**
     * Includes soft-deleted products so a bill never loses a line's name.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function priceUnit(): BelongsTo
    {
        return $this->belongsTo(PriceUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
