<?php

namespace App\Services\Tenants;

use App\Models\Tenants\PaymentMethod;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Decides what a payment means from the payment method's flags (never from its name):
 * credit (receivable, optional down payment), cash (amount received, change) or exact (paid in full, no change).
 */
final class PaymentPlanner
{
    private function __construct()
    {
    }

    /**
     * @param  array{payed_money?:mixed,member_id?:mixed,due_date?:mixed}  $in
     * @return array{kind:string,payed_money:float,money_changes:float}
     *
     * @throws ValidationException keys payed_money | member_id | due_date
     */
    public static function plan(PaymentMethod $method, float $amountDue, array $in): array
    {
        $amountDue = Money::round($amountDue);
        $kind = $method->kind();

        // Credit is checked first: a credit method that also has is_cash set is still credit.
        if ($kind === 'credit') {
            $errors = [];
            if (blank($in['member_id'] ?? null)) {
                $errors['member_id'] = __('Member is required for credit');
            }
            if (! self::isDate($in['due_date'] ?? null)) {
                $errors['due_date'] = __('Due date is required for credit');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $downPayment = min(max(Money::parse($in['payed_money'] ?? null), 0.0), $amountDue);

            return [
                'kind' => 'credit',
                'payed_money' => Money::round($downPayment),
                'money_changes' => 0.0,
            ];
        }

        // Exact (wallet, debit, ...): the amount typed by the client is ignored.
        if ($kind === 'exact') {
            return [
                'kind' => 'exact',
                'payed_money' => $amountDue,
                'money_changes' => 0.0,
            ];
        }

        $payed = Money::parse($in['payed_money'] ?? null);
        if ($payed <= 0) {
            $payed = $amountDue;
        }

        if (! Money::gte($payed, $amountDue)) {
            throw ValidationException::withMessages([
                'payed_money' => __('The received money must be at least the total'),
            ]);
        }

        return [
            'kind' => 'cash',
            'payed_money' => Money::round($payed),
            'money_changes' => Money::round(max(0.0, $payed - $amountDue)),
        ];
    }

    private static function isDate(mixed $value): bool
    {
        if (blank($value)) {
            return false;
        }

        try {
            Carbon::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
