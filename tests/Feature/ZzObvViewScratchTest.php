<?php

use App\Livewire\Cashier\OpenBills;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\Product;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

function zzMake(User $user, array $header, array $lines): OpenBill
{
    foreach ($lines as [$p, $q]) {
        CartItem::create(['user_id' => $user->id, 'product_id' => $p->id, 'qty' => $q, 'price' => $p->selling_price * $q]);
    }

    return app(OpenBillService::class)->createFromCart($user, $header);
}

it('renders the drawer', function () {
    $user = User::first();
    actingAs($user);
    About::query()->update(['business_type' => 'fnb']);
    $a = Product::factory()->create(['name' => 'Nasi Goreng', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 50, 'category_id' => Category::first()->id]);
    $b = Product::factory()->create(['name' => 'Es Teh', 'initial_price' => 1000, 'selling_price' => 5000, 'stock' => 50, 'category_id' => Category::first()->id]);
    $t5 = Table::create(['number' => '5']);
    $t7 = Table::create(['number' => '7']);
    $b1 = zzMake($user, ['table_id' => $t5->id, 'customer_name' => 'Budi'], [[$a, 2], [$b, 1]]);
    $b2 = zzMake($user, ['table_id' => $t7->id], [[$a, 1]]);
    $b3 = zzMake($user, [], [[$b, 3]]);
    app(OpenBillService::class)->ackKitchenBatch($b1->id, app(OpenBillService::class)->kitchenBatch(app(OpenBillService::class)->find($b1->id))['ack'], $user);

    $closed = Livewire::test(OpenBills::class);
    file_put_contents(getenv('OBV_OUT').'/closed.html', $closed->html());

    $open = Livewire::test(OpenBills::class)->call('setOpen', true)->set('search', '');
    $open->assertSee('#003');
    file_put_contents(getenv('OBV_OUT').'/open.html', $open->html());

    $open->call('setFilter', 'unsent');
    file_put_contents(getenv('OBV_OUT').'/unsent.html', $open->html());
    $open->call('setFilter', 'all')->set('search', '7');
    file_put_contents(getenv('OBV_OUT').'/search.html', $open->html());
    $open->call('voidBill', $b3->id, 'x', 999);
    file_put_contents(getenv('OBV_OUT').'/voiderr.html', $open->html());
    $svc = app(OpenBillService::class);
    $out = getenv('OBV_OUT');
    file_put_contents($out.'/preview-modal.html', view('filament.tenant.pages.cashier.bills.preview-modal')->render());
    file_put_contents($out.'/receipt-print.html', view('filament.tenant.partials.receipt-print')->render());
    file_put_contents($out.'/payload-bill.json', json_encode($svc->printPayload($svc->find($b1->id), 'bill', null, false, $user)));
    file_put_contents($out.'/payload-kitchen-pending.json', json_encode($svc->printPayload($svc->find($b2->id), 'kitchen', $svc->kitchenBatch($svc->find($b2->id)), false, $user)));
    $b1f = $svc->find($b1->id);
    file_put_contents($out.'/payload-kitchen-reprint.json', json_encode($svc->printPayload($b1f, 'kitchen', $svc->lastKitchenBatch($b1f), true, $user)));
    expect(true)->toBeTrue();
});
