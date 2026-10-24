<?php

namespace App\Services;

use App\Models\Tenants\Voucher;
use App\Support\BusinessDay;
use Exception;
use Illuminate\Support\Carbon;

class VoucherService
{
    public Voucher $voucher;

    private float $price;

    public function applyable(string $code, float $price): ?VoucherService
    {
        // The voucher period is in business days, not UTC days.
        $today = Carbon::parse(BusinessDay::today());
        /** @var Voucher $voucher */
        $voucher = Voucher::whereCode($code)
            ->first();
        if (! $voucher) {
            return null;
        }
        if ($voucher?->minimal_buying <= $price && $today->gte($voucher->start_date) && $today->lte($voucher->expired) && $voucher->kuota > 0) {
            $this->price = $price;
            $this->voucher = $voucher;

            return $this;
        }

        return null;
    }

    public function calculate(): float
    {
        if (! $this->voucher) {
            throw new Exception('You can\'t use calculate before assign the voucher code');
        }
        $discount = 0;
        if ($this->voucher->type == 'percentage') {
            $discount = ($this->price * $this->voucher->nominal / 100);
        }

        if ($this->voucher->type == 'flat') {
            $discount = $this->voucher->nominal;
        }

        return $discount;
    }

    public function reduceUsed()
    {
        if (! $this->voucher) {
            throw new Exception('You can\'t use calculate before assign the voucher code');
        }

        $this->voucher->update([
            'kuota' => $this->voucher->kuota - 1,
        ]);
    }
}
