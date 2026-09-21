<?php

namespace App\Models\Tenants;

use App\Traits\UseTimezoneAwareQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperSelling
 */
class Selling extends Model
{
    use HasFactory, UseTimezoneAwareQuery;

    protected $guarded = ['friend_price'];

    protected $appends = [
        'grand_total_price',
        'formatted_daily_order_number',
    ];

    protected static function booted(): void
    {
        static::creating(function (Selling $selling) {
            if (empty($selling->daily_order_number)) {
                try {
                    $targetDate = $selling->date 
                        ? \Illuminate\Support\Carbon::parse($selling->date)->toDateString() 
                        : ($selling->created_at ? $selling->created_at->toDateString() : now()->toDateString());

                    $max = static::whereDate('date', $targetDate)->max('daily_order_number');
                    if (! $max) {
                        $max = static::whereDate('created_at', $targetDate)->max('daily_order_number');
                    }

                    $selling->daily_order_number = ($max ?? 0) + 1;
                } catch (\Throwable $e) {
                    unset($selling->daily_order_number);
                }
            }
        });
    }

    public function sellingDetails()
    {
        return $this->hasMany(SellingDetail::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class);
    }

    public function scopeIsPaid(Builder $builder): Builder
    {
        return $builder->where('is_paid', true);
    }

    public function scopeIsNotPaid(Builder $builder): Builder
    {
        return $builder->where('is_paid', false);
    }

    public function grandTotalPrice(): Attribute
    {
        return Attribute::make(get: fn () => $this->total_price - $this->tax_price - $this->total_discount_per_item - $this->discount_price);
    }

    public function formattedDailyOrderNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->daily_order_number ? 'Order #' . str_pad((string) $this->daily_order_number, 3, '0', STR_PAD_LEFT) : null
        );
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
