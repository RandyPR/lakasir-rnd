<?php

namespace App\Filament\Tenant\Widgets\Concerns;

use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A widget property $day ('Y-m-d', empty is today) and the business day it stands for: a real date that is not in
 * the future, otherwise today.
 */
trait PicksBusinessDay
{
    /** The business day shown ('Y-m-d'); empty is today. */
    public ?string $day = null;

    protected function pickedDay(): string
    {
        $today = BusinessDay::today();

        if (! is_string($this->day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day)) {
            return $today;
        }

        try {
            $day = Carbon::createFromFormat('!Y-m-d', $this->day, 'UTC');
        } catch (Throwable) {
            return $today;
        }

        return $day->format('Y-m-d') === $this->day && $this->day < $today ? $this->day : $today;
    }
}
