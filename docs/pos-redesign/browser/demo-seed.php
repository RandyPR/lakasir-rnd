<?php

// Seeds a throwaway demo database for the browser checks (never database/database.sqlite). From the repo root:
//   touch database/demo.sqlite
//   DB_CONNECTION=sqlite DB_DATABASE=database/demo.sqlite php artisan migrate:fresh --force
//   DB_CONNECTION=sqlite DB_DATABASE=database/demo.sqlite php docs/pos-redesign/browser/demo-seed.php [fnb|retail]

use App\Constants\Role;
use App\Models\Tenants\About;
use App\Models\Tenants\Category;
use App\Models\Tenants\Member;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\Artisan;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// It creates an owner with the password "password": refuse anything but the demo file, a real shop included.
$database = (string) config('database.connections.'.config('database.default').'.database');
if (config('database.default') !== 'sqlite' || basename($database) !== 'demo.sqlite') {
    fwrite(STDERR, "Refusing to seed {$database}: use DB_CONNECTION=sqlite DB_DATABASE=database/demo.sqlite\n");
    exit(1);
}

$type = $argv[1] ?? 'fnb';

$owner = User::create([
    'name' => 'Kasir Demo',
    'email' => 'owner@demo.test',
    'password' => bcrypt('password'),
    'is_owner' => true,
]);
foreach (['PermissionSeeder', 'PaymentMethodSeeder', 'CategorySeeder'] as $seeder) {
    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}
$owner->assignRole(Role::admin);
$owner->profile()->create(['locale' => $argv[2] ?? 'id', 'timezone' => 'Asia/Jakarta']);

About::create(['shop_name' => 'Warung Demo', 'business_type' => $type === 'fnb' ? 'fnb' : 'Retail']);

PaymentMethod::create(['name' => 'Debit BCA', 'is_cash' => false, 'is_debit' => true, 'is_credit' => false, 'is_wallet' => false]);
PaymentMethod::create(['name' => 'Kredit', 'is_cash' => false, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false]);

$cats = [];
foreach (['Makanan', 'Minuman', 'Snack', 'Paket 10'] as $name) {
    $cats[$name] = Category::create(['name' => $name]);
}

$menu = [
    ['Nasi Goreng Spesial', 'Makanan', 25000],
    ['Mie Goreng', 'Makanan', 22000],
    ['Soto Ayam', 'Makanan', 20000],
    ['Ayam Bakar Madu', 'Makanan', 32000],
    ['Nasi Uduk Komplit dengan Telur Balado dan Tempe Orek', 'Makanan', 27500],
    ['Es Teh Manis', 'Minuman', 5000],
    ['Es Jeruk', 'Minuman', 8000],
    ['Kopi Susu', 'Minuman', 15000],
    ['Air Mineral', 'Minuman', 4000],
    ['Jus Alpukat', 'Minuman', 18000],
    ['Keripik Singkong', 'Snack', 7000],
    ['Pisang Goreng', 'Snack', 10000],
    ['Paket Hemat A', 'Paket 10', 35000],
    ['Paket Hemat B', 'Paket 10', 45000],
];
foreach ($menu as $i => [$name, $cat, $price]) {
    Product::create([
        'name' => $name,
        'category_id' => $cats[$cat]->id,
        'stock' => 50,
        'initial_price' => (int) round($price * 0.6),
        'selling_price' => $price,
        'unit' => 'PCS',
        'type' => 'product',
        'sku' => 'DEMO'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
    ]);
}
// one product with low stock and one without a category-like long name, one service
Product::create(['name' => 'Kerupuk', 'category_id' => $cats['Snack']->id, 'stock' => 2, 'initial_price' => 500, 'selling_price' => 1000, 'unit' => 'PCS', 'type' => 'product', 'sku' => 'DEMO100']);
Product::create(['name' => 'Ongkos Antar', 'category_id' => $cats['Makanan']->id, 'stock' => 0, 'initial_price' => 0, 'selling_price' => 5000, 'unit' => 'PCS', 'type' => 'service', 'sku' => 'DEMO101']);

foreach (range(1, 8) as $n) {
    Table::create(['number' => (string) $n]);
}
Member::create(['name' => 'Budi Santoso', 'code' => 'MBR001']);

Setting::set('currency', 'IDR');
echo "seeded {$type}: ".Product::count()." products, owner@demo.test / password\n";
