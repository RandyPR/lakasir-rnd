<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use App\Constants\Role;
use App\Models\Tenants\About;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\Artisan;

// Tests run against their own SQLite file, never the dev database.
if (! file_exists(__DIR__.'/../database/testing.sqlite')) {
    touch(__DIR__.'/../database/testing.sqlite');
}

uses(
    Tests\TestCase::class,
    // Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed the single shop (owner, shop profile, permissions, payment methods, categories).
 * Called inside the test transaction, so everything is rolled back after each test.
 */
function seedShop(): User
{
    $user = User::create([
        'email' => 'toko_testing@mail.com',
        'password' => bcrypt('password'),
        'is_owner' => true,
    ]);

    About::create([
        'shop_name' => 'Toko Testing',
        'business_type' => 'Retail',
    ]);

    foreach (['PermissionSeeder', 'PaymentMethodSeeder', 'CategorySeeder'] as $seeder) {
        Artisan::call('db:seed', ['--class' => $seeder]);
    }
    $user->assignRole(Role::admin);

    return $user;
}
