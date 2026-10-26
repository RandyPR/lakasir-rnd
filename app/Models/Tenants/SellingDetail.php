<?php

namespace App\Models\Tenants;

use App\Models\Tenants\Concerns\HasOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperSellingDetail
 */
class SellingDetail extends Model
{
    use HasFactory, HasOrderLine;

    protected $guarded = [];

    public function selling()
    {
        return $this->belongsTo(Selling::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Only lines of sales that are not in the trash. A line has no soft delete of its own, so a query that starts
     * from SellingDetail (stats per product, lists) has to ask for this; the Selling scope does the filtering.
     */
    public function scopeOfSellingNotTrashed(Builder $builder): Builder
    {
        return $builder->whereHas('selling');
    }

    public function pricePerUnit(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->price / $this->qty,
        );
    }

    public function totalPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->price - $this->discount_price,
        );
    }
}
