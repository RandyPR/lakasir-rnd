<?php

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperPaymentMethod
 */
class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_cash' => 'boolean',
        'is_debit' => 'boolean',
        'is_credit' => 'boolean',
        'is_wallet' => 'boolean',
    ];

    /**
     * What a payment with this method means, from the flags only (never from the name):
     * credit wins over cash, cash needs a pure cash method, everything else (wallet, debit, ...) is paid exactly.
     *
     * @return 'credit'|'cash'|'exact'
     */
    public function kind(): string
    {
        if ($this->is_credit) {
            return 'credit';
        }

        if ($this->is_cash && ! $this->is_wallet && ! $this->is_debit) {
            return 'cash';
        }

        return 'exact';
    }

    public function icon(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => config('app.url').'/'.$value,
        );
    }
}
