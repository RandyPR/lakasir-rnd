<?php

use App\Models\Tenants\PaymentMethod;
use App\Services\Tenants\PaymentPlanner;
use Illuminate\Validation\ValidationException;

/**
 * An unsaved payment method with exactly the given flags.
 */
function plannerMethod(bool $cash = false, bool $credit = false, bool $wallet = false, bool $debit = false, string $name = 'Metode'): PaymentMethod
{
    return new PaymentMethod([
        'name' => $name,
        'is_cash' => $cash,
        'is_credit' => $credit,
        'is_wallet' => $wallet,
        'is_debit' => $debit,
    ]);
}

/**
 * @return array<string, array<int, string>> field => messages
 */
function plannerErrors(Closure $run): array
{
    try {
        $run();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/*
|--------------------------------------------------------------------------
| PaymentMethod::kind()
|--------------------------------------------------------------------------
*/

test('kind is credit whenever is_credit is set, whatever the other flags say', function () {
    foreach ([true, false] as $cash) {
        foreach ([true, false] as $wallet) {
            foreach ([true, false] as $debit) {
                expect(plannerMethod($cash, true, $wallet, $debit)->kind())->toBe('credit');
            }
        }
    }
});

test('kind is cash only for a pure cash method', function () {
    expect(plannerMethod(cash: true)->kind())->toBe('cash');
});

test('kind is exact for wallet, debit and anything else', function () {
    expect(plannerMethod(cash: true, wallet: true)->kind())->toBe('exact');
    expect(plannerMethod(cash: true, debit: true)->kind())->toBe('exact');
    expect(plannerMethod(cash: true, wallet: true, debit: true)->kind())->toBe('exact');
    expect(plannerMethod(wallet: true)->kind())->toBe('exact');
    expect(plannerMethod(debit: true)->kind())->toBe('exact');
    expect(plannerMethod(wallet: true, debit: true)->kind())->toBe('exact');
    expect(plannerMethod()->kind())->toBe('exact');
});

test('kind covers all sixteen flag combinations', function () {
    $count = 0;
    foreach (range(0, 15) as $bits) {
        $cash = (bool) ($bits & 1);
        $credit = (bool) ($bits & 2);
        $wallet = (bool) ($bits & 4);
        $debit = (bool) ($bits & 8);

        $expected = match (true) {
            $credit => 'credit',
            $cash && ! $wallet && ! $debit => 'cash',
            default => 'exact',
        };

        expect(plannerMethod($cash, $credit, $wallet, $debit)->kind())->toBe($expected);
        $count++;
    }

    expect($count)->toBe(16);
});

test('kind never depends on the name', function () {
    expect(plannerMethod(wallet: true, name: 'Cash')->kind())->toBe('exact');
    expect(plannerMethod(cash: true, name: 'QRIS')->kind())->toBe('cash');
    expect(plannerMethod(cash: true, name: 'Credit')->kind())->toBe('cash');
    expect(plannerMethod(credit: true, name: 'Cash')->kind())->toBe('credit');
});

test('kind works on a method read from the database where flags are 0 and 1', function () {
    $method = new PaymentMethod;
    $method->setRawAttributes(['is_cash' => 1, 'is_credit' => 0, 'is_wallet' => 0, 'is_debit' => 0]);

    expect($method->kind())->toBe('cash');
});

/*
|--------------------------------------------------------------------------
| cash
|--------------------------------------------------------------------------
*/

test('cash with exact money has no change', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 47500.0, ['payed_money' => '47500']);

    expect($plan)->toBe(['kind' => 'cash', 'payed_money' => 47500.0, 'money_changes' => 0.0]);
});

test('cash with more money gives change', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 47500.0, ['payed_money' => '50000']);

    expect($plan)->toBe(['kind' => 'cash', 'payed_money' => 50000.0, 'money_changes' => 2500.0]);
});

test('cash with too little money is rejected on payed_money', function () {
    $errors = plannerErrors(fn () => PaymentPlanner::plan(plannerMethod(cash: true), 47500.0, ['payed_money' => '47499']));

    expect($errors)->toHaveKey('payed_money');
    expect($errors['payed_money'])->not->toBe([]);
    expect(array_keys($errors))->toBe(['payed_money']);
});

test('cash with an empty, zero or missing amount means "exactly the amount due"', function (mixed $input) {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 47500.0, ['payed_money' => $input]);

    expect($plan['payed_money'])->toBe(47500.0);
    expect($plan['money_changes'])->toBe(0.0);
})->with([
    'empty string' => [''],
    'zero string' => ['0'],
    'zero' => [0],
    'null' => [null],
    'not a number' => ['abc'],
]);

test('cash with no payed_money key at all means exact too', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 1000.0, []);

    expect($plan)->toBe(['kind' => 'cash', 'payed_money' => 1000.0, 'money_changes' => 0.0]);
});

test('cash compares at two decimals, so a sub-cent shortfall is accepted', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 100.004, ['payed_money' => '100']);

    expect($plan['kind'])->toBe('cash');
    expect($plan['money_changes'])->toBe(0.0);
});

test('cash change is rounded to two decimals', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 9999.99, ['payed_money' => '10000']);

    expect($plan['money_changes'])->toBe(0.01);
});

test('cash with a zero amount due is paid with zero change', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 0.0, ['payed_money' => '']);

    expect($plan)->toBe(['kind' => 'cash', 'payed_money' => 0.0, 'money_changes' => 0.0]);
});

/*
|--------------------------------------------------------------------------
| exact (wallet, debit)
|--------------------------------------------------------------------------
*/

test('exact pays the amount due and ignores whatever the client sent', function (mixed $clientAmount) {
    foreach ([plannerMethod(wallet: true), plannerMethod(debit: true), plannerMethod(cash: true, wallet: true), plannerMethod()] as $method) {
        $plan = PaymentPlanner::plan($method, 47500.0, ['payed_money' => $clientAmount]);

        expect($plan)->toBe(['kind' => 'exact', 'payed_money' => 47500.0, 'money_changes' => 0.0]);
    }
})->with([
    'less' => ['1'],
    'more' => ['999999999'],
    'empty' => [''],
    'null' => [null],
    'garbage' => ['abc'],
]);

test('exact does not require a member or a due date', function () {
    $plan = PaymentPlanner::plan(plannerMethod(wallet: true), 100.0, []);

    expect($plan['kind'])->toBe('exact');
});

test('exact rounds the amount due', function () {
    $plan = PaymentPlanner::plan(plannerMethod(debit: true), 100.006, []);

    expect($plan['payed_money'])->toBe(100.01);
});

/*
|--------------------------------------------------------------------------
| credit
|--------------------------------------------------------------------------
*/

test('credit keeps the down payment, for is_cash true and false alike', function (bool $isCash) {
    $method = plannerMethod(cash: $isCash, credit: true);

    $plan = PaymentPlanner::plan($method, 100000.0, [
        'payed_money' => '30000',
        'member_id' => 5,
        'due_date' => '2026-11-15',
    ]);

    expect($plan)->toBe(['kind' => 'credit', 'payed_money' => 30000.0, 'money_changes' => 0.0]);
})->with([
    'is_cash true' => [true],
    'is_cash false' => [false],
]);

test('credit without a down payment is a full receivable', function (mixed $input) {
    $plan = PaymentPlanner::plan(plannerMethod(credit: true), 100000.0, [
        'payed_money' => $input,
        'member_id' => 5,
        'due_date' => '2026-11-15',
    ]);

    expect($plan['payed_money'])->toBe(0.0);
    expect($plan['money_changes'])->toBe(0.0);
})->with([
    'empty' => [''],
    'zero' => [0],
    'null' => [null],
    'garbage' => ['abc'],
]);

test('credit clamps a down payment above the amount due to the amount due', function () {
    $plan = PaymentPlanner::plan(plannerMethod(credit: true), 100000.0, [
        'payed_money' => '250000',
        'member_id' => 5,
        'due_date' => '2026-11-15',
    ]);

    expect($plan['payed_money'])->toBe(100000.0);
    expect($plan['money_changes'])->toBe(0.0);
});

test('credit never gives change', function () {
    $plan = PaymentPlanner::plan(plannerMethod(credit: true, cash: true), 100.0, [
        'payed_money' => '500',
        'member_id' => 5,
        'due_date' => '2026-11-15',
    ]);

    expect($plan['money_changes'])->toBe(0.0);
});

test('credit requires a member', function () {
    $errors = plannerErrors(fn () => PaymentPlanner::plan(plannerMethod(credit: true), 1000.0, [
        'payed_money' => '0',
        'due_date' => '2026-11-15',
    ]));

    expect(array_keys($errors))->toBe(['member_id']);
});

test('credit requires a due date', function (mixed $dueDate) {
    $errors = plannerErrors(fn () => PaymentPlanner::plan(plannerMethod(credit: true), 1000.0, [
        'member_id' => 5,
        'due_date' => $dueDate,
    ]));

    expect(array_keys($errors))->toBe(['due_date']);
})->with([
    'missing as null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'garbage' => ['not a date'],
]);

test('credit reports a missing member and a missing due date together', function () {
    $errors = plannerErrors(fn () => PaymentPlanner::plan(plannerMethod(credit: true), 1000.0, []));

    expect(array_keys($errors))->toBe(['member_id', 'due_date']);
});

test('credit is checked first, before any cash rule: no "received money" error for a credit method', function () {
    $errors = plannerErrors(fn () => PaymentPlanner::plan(plannerMethod(credit: true, cash: true), 1000.0, [
        'payed_money' => '1',
    ]));

    expect($errors)->not->toHaveKey('payed_money');
    expect(array_keys($errors))->toBe(['member_id', 'due_date']);
});

test('the credit due date can be a parseable datetime', function () {
    $plan = PaymentPlanner::plan(plannerMethod(credit: true), 1000.0, [
        'member_id' => 5,
        'due_date' => '2026-11-15 00:00:00',
    ]);

    expect($plan['kind'])->toBe('credit');
});

/*
|--------------------------------------------------------------------------
| amount due
|--------------------------------------------------------------------------
*/

test('the amount due is rounded to two decimals before anything is decided', function () {
    $plan = PaymentPlanner::plan(plannerMethod(cash: true), 9999.994, ['payed_money' => '9999.99']);

    expect($plan['money_changes'])->toBe(0.0);
});
