<?php

namespace App\Services\Tenants;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers which bill a cashier was working on, so a page reload brings the same bill back ("Resuming Bill #12").
 * Only a UX convenience: the cache says WHICH bill, the database decides whether it can still be opened
 * (the caller restores it only while the bill is still open). Static methods, so they also work on an instance.
 */
final class OpenBillContext
{
    private function __construct()
    {
    }

    public static function remember(int $userId, int $billId): void
    {
        Cache::put(self::key($userId), $billId, now()->addMinutes(self::ttlMinutes()));
    }

    public static function recall(int $userId): ?int
    {
        $billId = Cache::get(self::key($userId));

        return is_numeric($billId) && (int) $billId > 0 ? (int) $billId : null;
    }

    public static function forget(int $userId): void
    {
        Cache::forget(self::key($userId));
    }

    private static function key(int $userId): string
    {
        return "cashier-open-bill:{$userId}";
    }

    private static function ttlMinutes(): int
    {
        return max(1, (int) config('pos.active_context_ttl_minutes', 30));
    }
}
