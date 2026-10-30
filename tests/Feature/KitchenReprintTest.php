<?php

use App\Filament\Tenant\Resources\SellingResource\Pages\ViewSelling;
use App\Models\Tenants\About;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The kitchen ticket can be printed again
|--------------------------------------------------------------------------
|
| The success dialog of the cashier keeps its "Print kitchen ticket" button after the ticket printed, and the sale
| page ("Selling History" > a sale) has the same button for a restaurant. How the ticket looks (twice as wide and
| twice as high) is checked by the Node harness in ReceiptPartialTest.
*/

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    $this->owner = User::first();
    actingAs($this->owner);
    About::query()->update(['business_type' => 'fnb']);
});

if (! function_exists('kitchenReprintSale')) {
    function kitchenReprintSale(): Selling
    {
        $selling = Selling::create([
            'payment_method_id' => PaymentMethod::first()->id,
            'total_price' => 25000,
            'payed_money' => 25000,
            'money_changes' => 0,
            'total_qty' => 1,
            'date' => '2026-10-15 05:00:00',
        ]);
        $product = Product::factory()->create([
            'name' => 'Nasi Goreng Spesial',
            'category_id' => Category::factory()->create(['name' => 'Makanan'])->id,
            'stock' => 5,
        ]);

        SellingDetail::create([
            'selling_id' => $selling->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 25000,
            'cost' => 0,
            'discount_price' => 0,
            'note' => 'pedas',
        ]);

        return $selling;
    }
}

if (! function_exists('kitchenReprintUserCan')) {
    /** A non-owner whose role holds exactly these permissions. */
    function kitchenReprintUserCan(string ...$permissions): User
    {
        $user = User::create([
            'email' => 'kitchen'.uniqid().'@mail.com',
            'password' => bcrypt('password'),
            'is_owner' => false,
        ]);
        $role = Role::create(['name' => 'kitchen-'.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching(Permission::where('name', $name)->get());
        }
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->profile()->firstOrCreate([], ['timezone' => 'Asia/Jakarta']);

        return $user;
    }
}

test('the sale page of a restaurant has a button for the kitchen ticket next to the receipt', function () {
    $sale = kitchenReprintSale();

    $page = Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionVisible('printReceipt')
        ->assertActionVisible('printKitchen');

    expect($page->html())->toContain('id="printKitchenButton"')
        ->toContain('window.handlePrintKitchen')
        // The whole sale again, marked COPY, and a failed print is shown on this page (it has no cashier toast).
        ->toMatch("/mode: 'kitchen',\s+reprint: true,\s+toast: true/");
});

test('a shop that is not a restaurant has no kitchen ticket button on the sale page', function () {
    About::query()->update(['business_type' => 'retail']);

    Livewire::test(ViewSelling::class, ['record' => kitchenReprintSale()->id])
        ->assertActionVisible('printReceipt')
        ->assertActionHidden('printKitchen');
});

test('the kitchen ticket button needs the right to print a sale and a sale that is not in the trash', function () {
    $sale = kitchenReprintSale();

    actingAs(kitchenReprintUserCan('read selling'));
    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionHidden('printReceipt')
        ->assertActionHidden('printKitchen');

    actingAs(kitchenReprintUserCan('read selling', 'can print selling'));
    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionVisible('printKitchen');

    actingAs($this->owner);
    $sale->delete();
    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionHidden('printKitchen');
});

test('the success dialog keeps the kitchen button after the ticket printed and marks the next one as a copy', function () {
    $script = file_get_contents(resource_path('views/filament/tenant/pages/cashier/payment/success-script.blade.php'));
    $modal = file_get_contents(resource_path('views/filament/tenant/pages/cashier/payment/success-modal.blade.php'));

    // The button is hidden for a paid bill (its ticket goes with the bill) and nothing else.
    expect(preg_match('/get canPrintKitchen\(\) \{\s*return (.*?);/s', $script, $match))->toBe(1)
        ->and($match[1])->toBe('Boolean(this.selling) && !this.fromBill')
        ->and($script)->toContain('reprint: this.kitchenPrinted')
        ->and($modal)->toContain('x-show="canPrintKitchen"')
        ->toContain('Print kitchen ticket again');
});
