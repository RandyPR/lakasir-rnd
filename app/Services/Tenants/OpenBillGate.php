<?php

namespace App\Services\Tenants;

use App\Models\Tenants\OpenBill;
use App\Models\Tenants\User;

/**
 * Who may do what with a bill. Evaluate void / cancel decisions INSIDE the bill transaction against the
 * locked bill row, never against a copy the client held.
 */
final class OpenBillGate
{
    private function __construct()
    {
    }

    public static function canUse(User $u): bool
    {
        return $u->can('create selling');
    }

    /**
     * Never sent to the kitchen: the creator, or anyone who may update / delete a selling.
     * Already sent: the same rule as cancelling a sent item.
     */
    public static function canVoid(OpenBill $b, User $u): bool
    {
        if ((int) $b->kitchen_batch_count > 0 || $b->kitchen_sent_at !== null) {
            return self::canCancelSent($b, $u);
        }

        return ($b->created_by !== null && (int) $b->created_by === (int) $u->id)
            || $u->can('update selling')
            || $u->can('delete selling');
    }

    /**
     * Anyone with delete / update selling, or the creator within the grace window of the LAST shipment.
     */
    public static function canCancelSent(OpenBill $b, User $u): bool
    {
        if ($u->can('delete selling') || $u->can('update selling')) {
            return true;
        }

        if ($b->created_by === null || (int) $b->created_by !== (int) $u->id || $b->kitchen_sent_at === null) {
            return false;
        }

        $grace = (int) config('pos.cancel_sent_grace_minutes', 10);

        return $b->kitchen_sent_at->greaterThanOrEqualTo(now()->subMinutes($grace));
    }

    /**
     * Official stock override for lines that were already served (owner passes through Gate::after).
     */
    public static function canOverrideStock(User $u): bool
    {
        return $u->can('update selling');
    }
}
