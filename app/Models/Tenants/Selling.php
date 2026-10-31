<?php

namespace App\Models\Tenants;

use App\Services\Tenants\CustomerNameService;
use App\Services\Tenants\DailyNumber;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\OrderLineSorter;
use App\Services\Tenants\PlainText;
use App\Support\BusinessDay;
use App\Traits\UseTimezoneAwareQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @mixin IdeHelperSelling
 */
class Selling extends Model
{
    use HasFactory, SoftDeletes, UseTimezoneAwareQuery;

    protected $guarded = ['friend_price'];

    protected $appends = [
        'grand_total_price',
        'formatted_daily_order_number',
    ];

    protected bool $receiptLoaded = false;

    /**
     * Transient flags set by SellingService::create (not columns; they never go through fill()).
     * numberingLocked: the caller already holds NumberingLock::acquire() as the first statement of its transaction.
     * keepCustomerName: the name is already final ("Budi (2)", from an open bill), so do not number it again.
     */
    public bool $numberingLocked = false;

    public bool $keepCustomerName = false;

    protected static function booted(): void
    {
        // Fallback numbering for sales created outside SellingService (seeders, factories, tests).
        // SellingService::create does the locking itself; see NumberingLock for why the order matters.
        static::creating(function (Selling $selling) {
            $moment = $selling->date ?: $selling->created_at;
            if (blank($moment)) {
                // Same instant SellingObserver would use; fixed here so number, name and column agree.
                $moment = $selling->date = BusinessDay::normalize(null);
            }
            $businessDate = BusinessDay::dateOf($moment);

            $needsNumber = empty($selling->daily_order_number);
            $needsName = ! empty($selling->customer_name) && ! $selling->keepCustomerName;

            if (($needsNumber || $needsName) && ! $selling->numberingLocked && DB::transactionLevel() > 0) {
                NumberingLock::acquire();
            }

            if ($needsNumber) {
                try {
                    $selling->daily_order_number = DailyNumber::next($businessDate);
                } catch (Throwable $e) {
                    if (NumberingLock::isContention($e)) {
                        throw $e;
                    }
                    unset($selling->daily_order_number);
                }
            }

            if ($needsName) {
                try {
                    $selling->customer_name = CustomerNameService::generateDailyCustomerName(
                        $selling->customer_name,
                        $businessDate,
                        $selling->id ?? null
                    );
                } catch (Throwable $e) {
                    if (NumberingLock::isContention($e)) {
                        throw $e;
                    }
                }
            }
        });
    }

    public function sellingDetails()
    {
        return $this->hasMany(SellingDetail::class);
    }

    /**
     * Loads everything the receipt and the sale detail page need, with the lines already ordered by
     * category and product name. Idempotent: a second call neither queries nor sorts again.
     * Products are loaded with soft-deleted ones included, so an old sale still prints after a product is removed.
     */
    public function loadForReceipt(): static
    {
        if ($this->receiptLoaded) {
            return $this;
        }

        $this->load([
            'sellingDetails.product' => fn ($query) => $query->withTrashed(),
            'sellingDetails.product.category',
            'paymentMethod',
            'user',
            'member',
            'table',
        ]);

        $this->setRelation('sellingDetails', OrderLineSorter::sort($this->sellingDetails));
        $this->append(['note_plain', 'printed_on_label', 'printed_at_label']);
        $this->receiptLoaded = true;

        return $this;
    }

    /**
     * The order note as plain text (legacy notes were HTML from a rich editor).
     */
    public function notePlain(): Attribute
    {
        return Attribute::make(get: fn () => PlainText::fromHtml($this->note));
    }

    /**
     * The date and time a kitchen ticket made from this finished sale shows (the keys of an open bill's ticket):
     * when the sale was made, in the business timezone, so a ticket of yesterday can be told from today's.
     */
    public function printedOnLabel(): Attribute
    {
        return Attribute::make(get: fn () => $this->saleMoment()->format('d/m/Y'));
    }

    public function printedAtLabel(): Attribute
    {
        return Attribute::make(get: fn () => $this->saleMoment()->format('H:i'));
    }

    private function saleMoment(): Carbon
    {
        return Carbon::parse(BusinessDay::normalize($this->date ?: $this->created_at), 'UTC')
            ->setTimezone(BusinessDay::timezone());
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

    /**
     * Who moved the sale to the trash (only set while it is in the trash).
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }
}
