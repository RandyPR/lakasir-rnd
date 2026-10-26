<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CustomerNameService
{
    /**
     * Resolves the customer name by appending duplicate counter (e.g. "Test (2)", "Test (3)")
     * if the same customer name already exists on the same business day, in a sale or in an open bill.
     *
     * @param  string|null  $businessDate  'Y-m-d' business date (default: today). A full datetime is converted to its business date.
     * @param  int|null  $excludeSellingId  a sale that must not count against itself
     * @param  int|null  $excludeBillId  an open bill that must not count against itself (renaming a bill)
     */
    public static function generateDailyCustomerName(
        ?string $name,
        ?string $businessDate = null,
        ?int $excludeSellingId = null,
        ?int $excludeBillId = null,
    ): ?string {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        // Extract base name by stripping any trailing numbered pattern like " (2)", " (10)", etc.
        $baseName = preg_replace('/\s*\(\d+\)$/', '', $trimmed);
        if (trim($baseName) === '') {
            $baseName = $trimmed;
        }

        $targetDate = self::resolveBusinessDate($businessDate);

        try {
            if (! Schema::hasColumn('sellings', 'customer_name')) {
                return $baseName;
            }

            $existingNames = self::sellingNames($targetDate, $excludeSellingId)
                ->merge(self::billNames($targetDate, $excludeBillId));
        } catch (Throwable $e) {
            if (NumberingLock::isContention($e)) {
                throw $e;
            }

            return $baseName;
        }

        if ($existingNames->isEmpty()) {
            return $baseName;
        }

        $lowerBaseName = mb_strtolower($baseName);
        $escapedLowerBaseName = preg_quote($lowerBaseName, '/');

        $usedNumbers = [];

        foreach ($existingNames as $existing) {
            $trimmedExisting = trim($existing);
            $lowerExisting = mb_strtolower($trimmedExisting);

            // Exact match with base name without suffix
            if ($lowerExisting === $lowerBaseName) {
                $usedNumbers[] = 1;
            } elseif (preg_match('/^' . $escapedLowerBaseName . '\s*\((\d+)\)$/u', $lowerExisting, $matches)) {
                $num = (int) $matches[1];
                if ($num > 0) {
                    $usedNumbers[] = $num;
                }
            }
        }

        if (empty($usedNumbers)) {
            return $baseName;
        }

        // Next number is highest existing sequence number + 1
        $maxNumber = max($usedNumbers);
        $nextNumber = $maxNumber + 1;

        return "{$baseName} ({$nextNumber})";
    }

    private static function resolveBusinessDate(?string $businessDate): string
    {
        if ($businessDate === null || trim($businessDate) === '') {
            return BusinessDay::today();
        }

        $businessDate = trim($businessDate);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)
            ? $businessDate
            : BusinessDay::dateOf($businessDate);
    }

    /**
     * Names of the sales of the business day, the ones in the trash included (a restore must not create a second
     * "Budi (2)"). Two index-friendly queries instead of one OR: the rows that have a date, then the legacy rows
     * without a date (matched on created_at).
     *
     * @return Collection<int, string>
     */
    private static function sellingNames(string $businessDate, ?int $excludeSellingId): Collection
    {
        $base = fn () => Selling::withTrashed()
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->when($excludeSellingId, fn ($q) => $q->where('id', '!=', $excludeSellingId));

        return BusinessDay::scopeDay($base(), 'date', $businessDate)->pluck('customer_name')
            ->merge(
                BusinessDay::scopeDay($base()->whereNull('date'), 'created_at', $businessDate)->pluck('customer_name')
            );
    }

    /**
     * Names of the open (unpaid, not void) bills of the business day. Empty when the table does not exist yet.
     *
     * @return Collection<int, string>
     */
    private static function billNames(string $businessDate, ?int $excludeBillId): Collection
    {
        if (! DailyNumber::billsTableAvailable()) {
            return collect();
        }

        return DB::table('open_bills')
            ->where('business_date', $businessDate)
            ->where('status', 'open')
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->when($excludeBillId, fn ($q) => $q->where('id', '!=', $excludeBillId))
            ->pluck('customer_name');
    }
}
