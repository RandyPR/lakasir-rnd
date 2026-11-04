<?php

namespace App\Filament\Tenant\Resources\SellingResource\Widgets;

use App\Filament\Tenant\Resources\SellingResource;
use App\Filament\Tenant\Resources\SellingResource\Pages\ListSellings;
use App\Models\Tenants\Profile;
use App\Support\BusinessDay;
use Filament\Widgets\Concerns\InteractsWithPageTable;

/**
 * The card on top of the sales list. It follows the date and cashier filters of the table, so the card and the
 * rows add up to the same sales; without a date filter it shows today. The search box is not followed, and the
 * Trash tab has no card (a total over trashed sales would be taken for real sales).
 */
class SellingListOverview extends SellingOverview
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListSellings::class;
    }

    protected function hasDayPicker(): bool
    {
        return false;
    }

    protected function card(): ?array
    {
        return $this->activeTab === 'trash' ? null : parent::card();
    }

    protected function scope(): array
    {
        $scope = parent::scope();
        $filters = $this->tableFilters ?? [];

        $userId = $filters['user_id']['value'] ?? null;
        $scope['user_id'] = filled($userId) ? (int) $userId : null;
        $scope['meta'] = __('Follows the date and cashier filters of the table');

        // The same days as the rows of the table.
        $range = SellingResource::dateFilterRange($filters['date']['start_date'] ?? null, $filters['date']['end_date'] ?? null);

        if (! $range) {
            return $scope;
        }

        [$from, $to] = $range;

        $scope['from'] = $from->format('Y-m-d H:i:s');
        $scope['to'] = $to->format('Y-m-d H:i:s');

        $today = BusinessDay::range();
        $isToday = $scope['from'] === $today[0]->format('Y-m-d H:i:s') && $scope['to'] === $today[1]->format('Y-m-d H:i:s');

        // The days are named in the shop timezone, the range itself is in UTC.
        $timezone = Profile::get()->timezone ?: BusinessDay::timezone();
        $firstDay = $from->copy()->setTimezone($timezone);
        $lastDay = $to->copy()->setTimezone($timezone)->subDay();
        $scope['title'] = $isToday ? __('Today') : ($firstDay->isSameDay($lastDay)
            ? $firstDay->translatedFormat('D, j M Y')
            : $firstDay->translatedFormat('j M').' – '.$lastDay->translatedFormat('j M Y'));
        $scope['date'] = $isToday ? $scope['date'] : '';

        return $scope;
    }
}
