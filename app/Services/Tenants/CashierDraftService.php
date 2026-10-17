<?php

namespace App\Services\Tenants;

use Illuminate\Support\Facades\Cache;

/**
 * Per-cashier draft of the header of a NEW, not yet saved order (customer name, note, member, table) so a
 * reload does not lose it. Once the order is saved as a bill the bill row owns that state and this draft is
 * forgotten. Lives in the cache for 12 hours; the cart lines themselves are in the cart_items table.
 */
class CashierDraftService
{
    public const FIELDS = ['customer_name', 'note', 'member_id', 'table_id'];

    private const TTL_HOURS = 12;

    /**
     * @return array<string, mixed> only the whitelisted keys; empty when there is no draft
     */
    public function load(int|string $userId): array
    {
        $draft = Cache::get($this->key($userId));

        if (! is_array($draft)) {
            return [];
        }

        return array_intersect_key($draft, array_flip(self::FIELDS));
    }

    /**
     * Merges the given fields into the stored draft. Unknown keys are ignored; null or '' removes the key.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(int|string $userId, array $fields): void
    {
        $draft = $this->load($userId);

        foreach (array_intersect_key($fields, array_flip(self::FIELDS)) as $name => $value) {
            if ($value === null || $value === '') {
                unset($draft[$name]);

                continue;
            }

            $draft[$name] = $value;
        }

        if ($draft === []) {
            $this->forget($userId);

            return;
        }

        Cache::put($this->key($userId), $draft, now()->addHours(self::TTL_HOURS));
    }

    public function forget(int|string $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int|string $userId): string
    {
        return "cashier-draft:{$userId}";
    }
}
