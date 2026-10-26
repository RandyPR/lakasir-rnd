<?php

namespace App\Observers;

use App\Models\Tenants\CashDrawer;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellingObserver extends AbstractObserver implements DataAwareRule
{
    protected $data = [];

    public function setData($data)
    {
        $this->data = $data;

        return $this;
    }

    public function creating(Selling $selling)
    {
        if (! $selling->date) {
            $selling->date = now()->format('Y-m-d H:i:s');
        }
        // A count, not Selling::all()->count(): loading every sale into memory on each sale does not scale.
        // The caller holds the sales lock (NumberingLock), so two sales cannot read the same count.
        // Sales in the trash keep their code, so they are counted too.
        $lastCount = Selling::withTrashed()->count();
        $selling->code = 'SELL'.Str::of($lastCount + 1)->padLeft(4, 0)->value();
        // $selling->money_changes = $selling->payed_money - $selling->total_price;
        if (Setting::get('cash_drawer_enabled', false)) {
            $cashDrawer = CashDrawer::lastOpened()->first();
            if (! $cashDrawer) {
                throw ValidationException::withMessages([
                    'cash_drawer' => __('Cash drawer is not opened'),
                ]);
            }
            $selling->cash_drawer_id = $cashDrawer->id;
        }
        $selling->user()->associate(auth()->user());
    }
}
