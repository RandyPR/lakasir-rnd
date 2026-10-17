<?php

use App\Support\Money;

test('round keeps two decimals and treats null and numeric strings as numbers', function () {
    expect(Money::round(1234.567))->toBe(1234.57);
    expect(Money::round('1234.564'))->toBe(1234.56);
    expect(Money::round(null))->toBe(0.0);
    expect(Money::round(7))->toBe(7.0);
    expect(Money::round(''))->toBe(0.0);
});

test('round accepts another scale', function () {
    expect(Money::round(1.23456, 3))->toBe(1.235);
    expect(Money::round(1234.5, 0))->toBe(1235.0);
});

test('round removes float noise from sums', function () {
    expect(Money::round(0.1 + 0.2))->toBe(0.3);
    expect(Money::round(3333.33 * 3))->toBe(9999.99);
});

test('parse keeps only digits and the dot like the old livewire input handling', function () {
    expect(Money::parse('50000'))->toBe(50000.0);
    expect(Money::parse('Rp 50000'))->toBe(50000.0);
    expect(Money::parse('1,500'))->toBe(1500.0);
    expect(Money::parse('12.5'))->toBe(12.5);
    // Raw digit strings only: a thousands dot is a decimal point.
    expect(Money::parse('1.500'))->toBe(1.5);
    expect(Money::parse(2500))->toBe(2500.0);
});

test('parse turns anything unreadable into zero', function () {
    expect(Money::parse(null))->toBe(0.0);
    expect(Money::parse(''))->toBe(0.0);
    expect(Money::parse('abc'))->toBe(0.0);
    expect(Money::parse([1, 2]))->toBe(0.0);
    expect(Money::parse(new stdClass))->toBe(0.0);
    expect(Money::parse(false))->toBe(0.0);
});

test('parse drops a minus sign so a negative amount cannot come from a string', function () {
    expect(Money::parse('-5000'))->toBe(5000.0);
});

test('gte compares at two decimals', function () {
    expect(Money::gte(100.0, 100.0))->toBeTrue();
    expect(Money::gte(100.004, 100.0))->toBeTrue();
    expect(Money::gte(99.994, 100.0))->toBeFalse();
    expect(Money::gte(0.1 + 0.2, 0.3))->toBeTrue();
    expect(Money::gte(0.3, 0.1 + 0.2))->toBeTrue();
    expect(Money::gte(100.02, 100.0))->toBeTrue();
    expect(Money::gte(99.98, 100.0))->toBeFalse();
});

test('eq compares at two decimals', function () {
    expect(Money::eq(0.1 + 0.2, 0.3))->toBeTrue();
    expect(Money::eq(10.001, 10.0))->toBeTrue();
    expect(Money::eq(10.01, 10.0))->toBeFalse();
    expect(Money::eq(9999.99, 3333.33 * 3))->toBeTrue();
});

test('qty rounds to three decimals', function () {
    expect(Money::qty(1.00049))->toBe(1.0);
    expect(Money::qty(0.3333333))->toBe(0.333);
    expect(Money::qty(2.5))->toBe(2.5);
});

test('qtyEq compares quantities at three decimals', function () {
    expect(Money::qtyEq(1.0004, 1.0))->toBeTrue();
    expect(Money::qtyEq(1.0006, 1.0))->toBeFalse();
    expect(Money::qtyEq(0.1 + 0.2, 0.3))->toBeTrue();
    expect(Money::qtyEq(3.0, 2.0))->toBeFalse();
});
