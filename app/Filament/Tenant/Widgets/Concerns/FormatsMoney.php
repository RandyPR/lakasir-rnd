<?php

namespace App\Filament\Tenant\Widgets\Concerns;

use App\Models\Tenants\Setting;
use Illuminate\Support\Number;

/**
 * Rupiah (or the shop's currency) written the way the shop language writes it, without decimals.
 */
trait FormatsMoney
{
    protected function currencyCode(): string
    {
        return (string) Setting::get('currency', 'IDR');
    }

    protected function money(float $value): string
    {
        return Number::currency($value, $this->currencyCode(), app()->getLocale(), 0);
    }
}
