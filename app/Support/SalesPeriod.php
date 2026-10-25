<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The periods of the dashboard sales chart: the last 7, 14 or 30 business days (one point per day) or the last
 * 12 months (one point per month). The window always ends with the current business day or month (see
 * BusinessDay), so "today" is the last point and the window slides forward at local midnight.
 */
enum SalesPeriod: string
{
    case Week = 'week';
    case TwoWeeks = 'two_weeks';
    case Month = 'month';
    case Year = 'year';

    public static function fromFilter(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Week) : self::Week;
    }

    /**
     * @return array<string, string> value => label, in display order
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $period) {
            $options[$period->value] = $period->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::Week => __('Weekly'),
            self::TwoWeeks => __('Every 2 weeks'),
            self::Month => __('Monthly'),
            self::Year => __('Yearly'),
        };
    }

    /**
     * What the period covers, for a heading ("Last 7 days").
     */
    public function description(): string
    {
        return match ($this) {
            self::Week => __('Last 7 days'),
            self::TwoWeeks => __('Last 14 days'),
            self::Month => __('Last 30 days'),
            self::Year => __('Last 12 months'),
        };
    }

    /**
     * The same description for the period right before it, used by the comparison text.
     */
    public function previousDescription(): string
    {
        return match ($this) {
            self::Week => __('the 7 days before'),
            self::TwoWeeks => __('the 14 days before'),
            self::Month => __('the 30 days before'),
            self::Year => __('the 12 months before'),
        };
    }

    public function isMonthly(): bool
    {
        return $this === self::Year;
    }

    /**
     * Points on the chart.
     */
    public function length(): int
    {
        return match ($this) {
            self::Week => 7,
            self::TwoWeeks => 14,
            self::Month => 30,
            self::Year => 12,
        };
    }

    /**
     * Local start of the point $offset steps from the current one (0 = today / this month, -1 = the one before).
     * Always snapped back to local midnight, so a daylight saving jump cannot leave it at 01:00.
     */
    public function pointStart(Carbon $localNow, int $offset): Carbon
    {
        return $this->isMonthly()
            ? $localNow->copy()->startOfMonth()->addMonthsNoOverflow($offset)->startOfDay()
            : $localNow->copy()->startOfDay()->addDays($offset)->startOfDay();
    }

    /**
     * The label of a point on the chart axis.
     */
    public function pointLabel(Carbon $localStart): string
    {
        return match ($this) {
            self::Week, self::TwoWeeks => $localStart->translatedFormat('D, j M'),
            self::Month => $localStart->translatedFormat('j M'),
            self::Year => $localStart->translatedFormat('M Y'),
        };
    }
}
