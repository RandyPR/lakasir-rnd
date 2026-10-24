<?php

use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The payment dialog
|--------------------------------------------------------------------------
|
| Its Alpine component (payment/script.blade.php) runs in Node with a fake $wire (tests/Support/payment-harness.mjs):
| suggested amounts, the numpad, change and shortfall, cash / exact / credit, what is sent to the server, keys and a
| bill changed elsewhere. Skipped when node is not installed. Then the page with its real view: what the server
| refuses is shown in the dialog, and the kind of a method comes from its flags, never from its name.
*/

if (! function_exists('pfCashier')) {
    /** The cashier page with its real view, the cart of the signed-in user ready to be paid. */
    function pfCashier(): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        return Livewire::test(Cashier::class)->call('syncCartForPayment');
    }
}

if (! function_exists('pfMethod')) {
    function pfMethod(string $name, array $flags): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'name' => $name,
            'is_cash' => false,
            'is_debit' => false,
            'is_credit' => false,
            'is_wallet' => false,
            'icon' => 'assets/images/payment-methods/cash.png',
        ], $flags));
    }
}

if (! function_exists('pfPayError')) {
    /** The text of the problem box of the payment dialog. */
    function pfPayError(string $html): string
    {
        expect(preg_match('/data-testid="pay-error".*?<\/div>/s', $html, $box))->toBe(1);

        return html_entity_decode(strip_tags($box[0]));
    }
}

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Setting::set('cash_drawer_enabled', false);
    Cache::clear();

    $this->owner = User::first();
    actingAs($this->owner);

    $this->nasi = Product::factory()->create([
        'name' => 'Nasi Goreng', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 50,
        'category_id' => Category::first()->id,
    ]);
    CartItem::create(['user_id' => $this->owner->id, 'product_id' => $this->nasi->id, 'qty' => 2, 'price' => 50000]);
});

test('the payment dialog counts, checks and sends the payment right', function () {
    $node = (new ExecutableFinder())->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }

    app()->setLocale('id');
    try {
        $html = view('filament.tenant.pages.cashier.payment.script')->render();
    } finally {
        app()->setLocale(config('app.locale'));
    }
    expect(preg_match('/<script>(.*)<\/script>/s', $html, $script))->toBe(1);

    $path = tempnam(sys_get_temp_dir(), 'payment-fixture-');
    file_put_contents($path, json_encode(['locale' => 'id', 'script' => $script[1]], JSON_THROW_ON_ERROR));

    try {
        $result = Process::timeout(120)->run([$node, base_path('tests/Support/payment-harness.mjs'), $path]);
    } finally {
        @unlink($path);
    }

    $report = json_decode(trim($result->output()), true);
    expect($report)->toBeArray("No report from the harness:\n".$result->output()."\n".$result->errorOutput());
    expect($report['failures'])->toBe([])
        ->and($report['checks'])->toBeGreaterThan(80)
        ->and($result->exitCode())->toBe(0);
});

test('cash that does not reach the total is refused, and the dialog says why', function () {
    $page = pfCashier();
    expect($page->get('total_price'))->toBe(50000.0);

    $page->set('cartDetail.payment_method_id', PaymentMethod::where('name', 'Cash')->value('id'))
        ->set('cartDetail.payed_money', '40000')
        ->call('proceedThePayment')
        ->assertHasErrors(['payed_money']);

    expect(Selling::count())->toBe(0)
        ->and(pfPayError($page->html()))->toContain(__('The received money must be at least the total'));

    // The total does not drift between requests.
    $page->call('syncCartForPayment');
    expect($page->get('total_price'))->toBe(50000.0);

    $page->set('cartDetail.payed_money', '50000')->call('proceedThePayment')->assertHasNoErrors();
    expect((float) Selling::sole()->payed_money)->toBe(50000.0)
        ->and((float) Selling::sole()->money_changes)->toBe(0.0);
});

test('credit needs a member and a due date, and the dialog says so', function () {
    $credit = pfMethod('Kasbon', ['is_credit' => true]);

    $page = pfCashier()
        ->set('cartDetail.payment_method_id', $credit->id)
        ->set('cartDetail.payed_money', '10000')
        ->call('proceedThePayment')
        ->assertHasErrors(['member_id', 'due_date']);

    expect(Selling::count())->toBe(0)
        ->and(pfPayError($page->html()))->toContain(__('Member is required for credit'))
        ->toContain(__('Due date is required for credit'));
});

test('the kind of a method comes from its flags, never from its name', function () {
    $cashNamedQris = pfMethod('QRIS Toko', ['is_cash' => true]);
    $walletNamedCash = pfMethod('Cash Digital', ['is_wallet' => true]);

    // Flagged as cash: the received money must reach the total, whatever the name says.
    pfCashier()
        ->set('cartDetail.payment_method_id', $cashNamedQris->id)
        ->set('cartDetail.payed_money', '1000')
        ->call('proceedThePayment')
        ->assertHasErrors(['payed_money']);
    expect(Selling::count())->toBe(0);

    // Flagged as a wallet: paid exactly, the typed amount is ignored.
    pfCashier()
        ->set('cartDetail.payment_method_id', $walletNamedCash->id)
        ->set('cartDetail.payed_money', '1000')
        ->call('proceedThePayment')
        ->assertHasNoErrors();

    $sale = Selling::sole();
    expect((int) $sale->payment_method_id)->toBe($walletNamedCash->id)
        ->and((float) $sale->payed_money)->toBe(50000.0)
        ->and((float) $sale->money_changes)->toBe(0.0);
});
