<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;
use Illuminate\Support\Carbon;

class CustomerNameService
{
    /**
     * Resolves the customer name by appending duplicate counter (e.g. "Test (2)", "Test (3)")
     * if the same customer name already exists on the same day.
     */
    public static function generateDailyCustomerName(?string $name, ?string $date = null, ?int $excludeSellingId = null): ?string
    {
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

        $targetDate = $date 
            ? Carbon::parse($date)->toDateString() 
            : now()->toDateString();

        try {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('sellings', 'customer_name')) {
                return $baseName;
            }

            // Query existing sellings on targetDate that have customer_name
            $query = Selling::query()
                ->where(function ($q) use ($targetDate) {
                    $q->whereDate('date', $targetDate)
                        ->orWhere(function ($q2) use ($targetDate) {
                            $q2->whereNull('date')
                                ->whereDate('created_at', $targetDate);
                        });
                })
                ->whereNotNull('customer_name')
                ->where('customer_name', '!=', '');

            if ($excludeSellingId) {
                $query->where('id', '!=', $excludeSellingId);
            }

            $existingNames = $query->pluck('customer_name');
        } catch (\Throwable $e) {
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
}
