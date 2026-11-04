<?php

namespace App\Filament\Tenant\Widgets\Concerns;

use Illuminate\Support\Number;

/**
 * Turns the totals of a stretch of time (and the stretch before it) into the texts of the compact sales card
 * (resources/views/filament/tenant/widgets/sales-card.blade.php): net sales, cost of goods, profit, margin, count.
 *
 * The host class provides money() (see FormatsMoney). Money needs "read revenue overview", the number of sales and
 * the discount need "read sales overview", the same split as the cards had before.
 */
trait BuildsSalesCard
{
    /**
     * @param  array{omzet: float, hpp: float, laba: float, margin: ?float, transactions: int, discount: ?float, average: float}  $current
     * @param  array{omzet: float, hpp: float, laba: float, margin: ?float, transactions: int, discount: ?float, average: float}  $previous
     * @param  array{title: string, date?: ?string, as_of?: ?string, vs?: ?string, meta?: ?string, warning?: ?array{count: int, names: list<string>}}  $text
     * @return array<string, mixed>
     */
    protected function salesCard(string $kind, array $current, array $previous, array $text): array
    {
        $locale = app()->getLocale();
        $percent = fn (float $value): string => Number::format($value, maxPrecision: 1, locale: $locale).'%';

        return [
            'kind' => $kind,
            'revenue' => can('read revenue overview'),
            'sales' => can('read sales overview'),
            'title' => $text['title'],
            'date' => $text['date'] ?? null,
            'as_of' => $text['as_of'] ?? null,
            'vs' => $text['vs'] ?? null,
            'meta' => $text['meta'] ?? null,
            'omzet' => $this->money($current['omzet']),
            'omzet_delta' => $this->delta($current['omzet'], $previous['omzet']),
            'hpp' => $this->money($current['hpp']),
            'hpp_share' => $current['omzet'] > 0 ? $percent($current['hpp'] / $current['omzet'] * 100) : null,
            'laba' => $this->money($current['laba']),
            'laba_delta' => $this->delta($current['laba'], $previous['laba']),
            'margin' => $current['margin'] !== null ? $percent($current['margin']) : null,
            'transactions' => Number::format($current['transactions'], locale: $locale),
            'transactions_delta' => $this->delta($current['transactions'], $previous['transactions']),
            'average' => $this->money($current['average']),
            'discount' => $current['discount'] !== null ? $this->money($current['discount']) : null,
            'warning' => can('read revenue overview') ? ($text['warning'] ?? null) : null,
        ];
    }

    /**
     * The change against the stretch before. A percentage of nothing says nothing, so no change is shown when the
     * stretch before had no sales.
     *
     * @return array{dir: string, text: string} dir is up, down, flat or none
     */
    protected function delta(float|int $current, float|int $previous): array
    {
        if ($previous == 0) {
            return ['dir' => 'none', 'text' => ''];
        }

        $percent = (int) round(abs($current - $previous) / abs($previous) * 100);

        return match (true) {
            $percent === 0 => ['dir' => 'flat', 'text' => '0%'],
            $current > $previous => ['dir' => 'up', 'text' => $percent.'%'],
            default => ['dir' => 'down', 'text' => $percent.'%'],
        };
    }
}
