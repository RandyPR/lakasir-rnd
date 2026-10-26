<?php

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperReceivable
 */
class Receivable extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function selling(): BelongsTo
    {
        return $this->belongsTo(Selling::class);
    }

    /**
     * Only receivables of sales that are not in the trash: trashing a sale leaves its receivable untouched
     * and hides it here, so restoring the sale brings it back unchanged.
     */
    public function scopeOfSellingNotTrashed(Builder $builder): Builder
    {
        return $builder->whereHas('selling');
    }

    public function receivableItems(): HasMany
    {
        return $this->hasMany(ReceivableItem::class);
    }

    public function receivablePayments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class);
    }
}
