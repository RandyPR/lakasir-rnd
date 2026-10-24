<?php

use App\Constants\Role as RoleName;
use App\Http\Controllers\Api\Tenants\SettingController;
use App\Models\Tenants\About;
use App\Models\Tenants\Category;
use App\Models\Tenants\Member;
use App\Models\Tenants\Printer;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabaseWithTenant::class);

/**
 * A signed-in user who is not the owner and whose role holds exactly these permissions, on the web and the API guard
 * alike (a name that exists on both guards is attached on both).
 */
function userCan(string ...$permissions): User
{
    $user = User::create([
        'email' => 'user'.uniqid().'@mail.com',
        'password' => bcrypt('password'),
        'is_owner' => false,
    ]);
    $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $found = Permission::where('name', $name)->get();
        throw_if($found->isEmpty(), new RuntimeException("permission [{$name}] is not seeded"));
        $role->permissions()->syncWithoutDetaching($found);
    }
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return withProfile($user);
}

/** The reports read the signed-in user's timezone from their profile. */
function withProfile(User $user): User
{
    $user->profile()->firstOrCreate([], ['timezone' => 'Asia/Jakarta']);

    return $user;
}

/** The same, for a non-owner who holds the admin role (what a store's manager account is in production). */
function adminNotOwner(): User
{
    $user = User::create([
        'email' => 'admin'.uniqid().'@mail.com',
        'password' => bcrypt('password'),
        'is_owner' => false,
    ]);
    $user->assignRole(RoleName::admin);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

beforeEach(function () {
    Cache::clear();
    $this->range = ['start_date' => '2026-10-01', 'end_date' => '2026-10-24'];
});

// ---------------------------------------------------------------------------------------------------------------------
// Web: report PDFs and the print page
// ---------------------------------------------------------------------------------------------------------------------

dataset('report routes', [
    'cashier' => ['/member/cashier-report/generate', 'generate cashier report'],
    'selling' => ['/member/selling-report/generate', 'generate selling report'],
    'product' => ['/member/product-report/generate', 'generate product report'],
]);

test('a guest is sent to the panel login instead of getting a report', function (string $url) {
    get($url.'?'.http_build_query($this->range))
        ->assertRedirect(Filament::getPanel('tenant')->getLoginUrl());
})->with(['/member/cashier-report/generate', '/member/selling-report/generate', '/member/product-report/generate',
    '/member/purchasing-report/generate', '/member/sellings/1/print']);

test('a signed-in user without the permission gets a 403 on the report', function (string $url) {
    actingAs(userCan('read selling', 'create selling'))
        ->get($url.'?'.http_build_query($this->range))
        ->assertForbidden();
})->with(['/member/cashier-report/generate', '/member/selling-report/generate', '/member/product-report/generate',
    '/member/purchasing-report/generate']);

test('a report needs its own permission, not any report permission', function () {
    actingAs(userCan('generate selling report', 'generate product report'))
        ->get('/member/cashier-report/generate?'.http_build_query($this->range))
        ->assertForbidden();
});

test('a user with the permission gets the PDF', function (string $url, string $permission) {
    actingAs(userCan($permission))
        ->get($url.'?'.http_build_query($this->range))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
})->with('report routes');

test('the owner gets every report, the purchasing one too', function (string $url) {
    actingAs(withProfile(User::first()))
        ->get($url.'?'.http_build_query($this->range))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
})->with(['/member/cashier-report/generate', '/member/selling-report/generate', '/member/product-report/generate',
    '/member/purchasing-report/generate']);

test('the report still rejects a bad date after the permission check', function () {
    actingAs(withProfile(User::first()))
        ->get('/member/cashier-report/generate?start_date=not-a-date&end_date=2026-10-24')
        ->assertSessionHasErrors('start_date');
});

test('the print page needs a login and the print permission', function () {
    get('/member/sellings/1/print')->assertRedirect();

    actingAs(userCan('read selling'))->get('/member/sellings/1/print')->assertForbidden();
    actingAs(userCan('can print selling'))->get('/member/sellings/1/print')->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// API: settings
// ---------------------------------------------------------------------------------------------------------------------

dataset('writable settings', [
    'currency' => ['currency', 'USD', 'update currency'],
    'selling_method' => ['selling_method', 'lifo', 'set selling method'],
    'cash_drawer_enabled' => ['cash_drawer_enabled', true, 'enable cash drawer'],
    'secure_initial_price_enabled' => ['secure_initial_price_enabled', true, 'enable secure initial price'],
    'secure_initial_price_using_pin' => ['secure_initial_price_using_pin', true, 'enable secure initial price'],
    'default_tax' => ['default_tax', 11, 'set default tax'],
    'minimum_stock_nofication' => ['minimum_stock_nofication', 10, 'set the minimum stock notification'],
]);

test('every setting key has a permission that exists for the API guard', function () {
    expect(array_keys(SettingController::WRITE_PERMISSION))->toEqualCanonicalizing(config('setting.key'));

    $missing = collect(SettingController::WRITE_PERMISSION)->unique()
        ->reject(fn ($name) => Permission::where('name', $name)->where('guard_name', 'sanctum')->exists())
        ->values()->all();

    expect($missing)->toBe([]);
});

test('a user without the permission cannot change a setting', function (string $key, mixed $value) {
    actingAs(userCan('read selling', 'create selling'), 'sanctum')
        ->postJson('/api/setting', ['key' => $key, 'value' => $value])
        ->assertForbidden();

    expect(Setting::get($key))->toBeNull();
})->with('writable settings');

test('a user with the permission can change that setting', function (string $key, mixed $value, string $permission) {
    actingAs(userCan($permission), 'sanctum')
        ->postJson('/api/setting', ['key' => $key, 'value' => $value])
        ->assertOk();

    expect(Setting::get($key))->toEqual($value);
})->with('writable settings');

test('the permission for one setting does not open another', function () {
    actingAs(userCan('update currency'), 'sanctum')
        ->postJson('/api/setting', ['key' => 'default_tax', 'value' => 50])
        ->assertForbidden();

    expect(Setting::get('default_tax'))->toBeNull();
});

test('the secure initial price switch cannot be flipped through the generic setting route without its permission', function () {
    actingAs(userCan('set default tax'), 'sanctum')
        ->postJson('/api/setting', ['key' => 'secure_initial_price_enabled', 'value' => true])
        ->assertForbidden();

    expect((bool) Setting::get('secure_initial_price_enabled'))->toBeFalse();
});

test('an unknown key is a validation error, also for a user without permissions', function () {
    actingAs(userCan(), 'sanctum')
        ->postJson('/api/setting', ['key' => 'app_name', 'value' => 'x'])
        ->assertUnprocessable();

    actingAs(userCan(), 'sanctum')
        ->postJson('/api/setting', ['key' => ['currency'], 'value' => 'x'])
        ->assertUnprocessable();
});

test('a guest cannot read or change settings', function () {
    $this->getJson('/api/setting/all')->assertUnauthorized();
    $this->postJson('/api/setting', ['key' => 'currency', 'value' => 'USD'])->assertUnauthorized();
});

test('every signed-in user can still read settings, the cashier needs them', function () {
    Setting::set('default_tax', 11);
    $cashier = userCan('read selling', 'create selling');

    actingAs($cashier, 'sanctum')->getJson('/api/setting/all')
        ->assertOk()
        ->assertJsonPath('data.default_tax', 11);

    actingAs($cashier, 'sanctum')->getJson('/api/setting/currency')->assertOk();
});

test('a manager who is not the owner can still change settings', function () {
    actingAs(adminNotOwner(), 'sanctum')
        ->postJson('/api/setting', ['key' => 'default_tax', 'value' => 5])
        ->assertOk();

    expect(Setting::get('default_tax'))->toEqual(5);
});

// ---------------------------------------------------------------------------------------------------------------------
// API: about
// ---------------------------------------------------------------------------------------------------------------------

test('every signed-in user can read the shop profile, the receipt needs it', function () {
    actingAs(userCan('read selling'), 'sanctum')->getJson('/api/about')
        ->assertOk()
        ->assertJsonPath('data.shop_name', 'Toko Testing');
});

test('only a user with the about permission can change the shop profile', function () {
    $payload = ['shop_name' => 'Toko Dibajak', 'business_type' => 'retail'];

    actingAs(userCan('read selling'), 'sanctum')->putJson('/api/about', $payload)->assertForbidden();
    expect(About::first()->shop_name)->toBe('Toko Testing');

    actingAs(userCan('update about'), 'sanctum')->putJson('/api/about', $payload)->assertOk();
    expect(About::first()->shop_name)->toBe('Toko Dibajak');
});

test('a manager who is not the owner can still change the shop profile', function () {
    actingAs(adminNotOwner(), 'sanctum')
        ->putJson('/api/about', ['shop_name' => 'Toko Manajer', 'business_type' => 'retail'])
        ->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// API: dashboard
// ---------------------------------------------------------------------------------------------------------------------

dataset('dashboard routes', [
    'revenue' => ['/api/transaction/dashboard/total-revenue', 'read revenue overview'],
    'gross profit' => ['/api/transaction/dashboard/total-gross-profit', 'read revenue overview'],
    'sales' => ['/api/transaction/dashboard/total-sales', 'read sales overview'],
]);

test('a guest gets a 401 on the dashboard', function (string $url) {
    $this->getJson($url.'?filter_type=this_week&timezone=Asia/Jakarta')->assertUnauthorized();
})->with('dashboard routes');

test('a user without the overview permission gets a 403 on the dashboard', function (string $url) {
    actingAs(userCan('read selling', 'create selling'), 'sanctum')
        ->getJson($url.'?filter_type=this_week&timezone=Asia/Jakarta')
        ->assertForbidden();
})->with('dashboard routes');

test('a user with the overview permission reads the dashboard', function (string $url, string $permission) {
    actingAs(userCan($permission), 'sanctum')
        ->getJson($url.'?filter_type=this_week&timezone=Asia/Jakarta')
        ->assertOk();
})->with('dashboard routes');

test('the sales permission does not show revenue and the other way around', function () {
    actingAs(userCan('read sales overview'), 'sanctum')
        ->getJson('/api/transaction/dashboard/total-revenue?filter_type=this_week&timezone=Asia/Jakarta')
        ->assertForbidden();

    actingAs(userCan('read revenue overview'), 'sanctum')
        ->getJson('/api/transaction/dashboard/total-sales?filter_type=this_week&timezone=Asia/Jakarta')
        ->assertForbidden();
});

test('a manager who is not the owner and the owner still read the whole dashboard', function (string $who) {
    $user = $who === 'owner' ? User::first() : adminNotOwner();

    foreach (['total-revenue', 'total-gross-profit', 'total-sales'] as $path) {
        actingAs($user, 'sanctum')
            ->getJson("/api/transaction/dashboard/{$path}?filter_type=this_week&timezone=Asia/Jakarta")
            ->assertOk();
    }
})->with(['admin', 'owner']);

// ---------------------------------------------------------------------------------------------------------------------
// API: the push token must not be a way to edit the account
// ---------------------------------------------------------------------------------------------------------------------

test('registering a push token stores the token and nothing else', function () {
    $user = userCan('read selling');
    $password = $user->password;

    actingAs($user, 'sanctum')
        ->postJson('/api/register-fcm-token', [
            'fcm_token' => 'token-abc',
            'is_owner' => true,
            'email' => 'attacker@mail.com',
            'password' => 'hacked',
            'name' => 'Pemilik',
        ])
        ->assertOk();

    $user->refresh();
    expect($user->fcm_token)->toBe('token-abc')
        ->and($user->is_owner)->toBeFalsy()
        ->and($user->email)->not->toBe('attacker@mail.com')
        ->and($user->password)->toBe($password)
        ->and($user->name)->not->toBe('Pemilik');
});

test('a user cannot make themselves the owner and then change settings', function () {
    $user = userCan('read selling');

    actingAs($user, 'sanctum')->postJson('/api/register-fcm-token', ['fcm_token' => 't', 'is_owner' => true])->assertOk();

    actingAs($user->fresh(), 'sanctum')
        ->postJson('/api/setting', ['key' => 'default_tax', 'value' => 99])
        ->assertForbidden();
});

test('a request with no token changes nothing', function () {
    $user = userCan('read selling');
    $user->forceFill(['fcm_token' => 'old'])->save();

    actingAs($user, 'sanctum')->postJson('/api/register-fcm-token', [])->assertOk();

    expect($user->fresh()->fcm_token)->toBe('old');
});

// ---------------------------------------------------------------------------------------------------------------------
// API: master data is filled from known columns only
// ---------------------------------------------------------------------------------------------------------------------

test('a category is created from its name only', function () {
    actingAs(userCan('create category'), 'sanctum')
        ->postJson('/api/master/category', ['name' => 'Minuman', 'id' => 4242, 'created_at' => '2001-01-01 00:00:00'])
        ->assertOk();

    $category = Category::where('name', 'Minuman')->first();
    expect($category->id)->not->toBe(4242)
        ->and($category->created_at->year)->not->toBe(2001);
});

test('a member keeps its known columns and drops the rest', function () {
    actingAs(userCan('create member'), 'sanctum')
        ->postJson('/api/master/member', [
            'name' => 'Budi',
            'address' => 'Jl. Mawar 1',
            'id' => 4242,
            'created_at' => '2001-01-01 00:00:00',
        ])
        ->assertOk();

    $member = Member::where('name', 'Budi')->first();
    expect($member->address)->toBe('Jl. Mawar 1')
        ->and($member->id)->not->toBe(4242)
        ->and($member->created_at->year)->not->toBe(2001)
        ->and($member->code)->toStartWith('CUS');
});

test('a printer is saved from its four columns', function () {
    // No `create printer` permission is seeded for the API guard, so only the owner reaches this route.
    actingAs(User::first(), 'sanctum')
        ->postJson('/api/printer', [
            'name' => 'Dapur',
            'driver' => 'network',
            'ip_address' => '192.168.1.50',
            'port' => '9100',
            'id' => 4242,
        ])
        ->assertOk();

    $printer = Printer::where('name', 'Dapur')->first();
    expect($printer->ip_address)->toBe('192.168.1.50')
        ->and($printer->port)->toBe('9100')
        ->and($printer->id)->not->toBe(4242);
});
