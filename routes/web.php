<?php

use App\Http\Controllers\Api\Tenants\Reports\PurchasingReportController;
use App\Http\Controllers\CashierReportController;
use App\Http\Controllers\ProductReportController;
use App\Http\Controllers\SellingReportController;
use App\Livewire\ResetPassword;
use Illuminate\Support\Facades\Route;

Route::view('/offline', 'offline');

Route::get('/serviceworker.js', function () {
    return response()->file(public_path('serviceworker.js'))
        ->header('Content-Type', 'application/javascript');
});

Route::get('/', function () {
    return redirect()->to('/member');
});

// Asked by the panel's page script (resources/views/livewire-resilience.blade.php): 204 while the session lives, 401
// when it is gone. A request that reaches it also renews the session, so a screen that stays open does not time out.
Route::get('/session-ping', fn () => response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']))
    ->middleware('auth')
    ->name('session.ping');
// Reports and printing sit next to the Filament panel but outside it, so they check the same permissions the panel
// pages check in canAccess().
Route::middleware('auth')->group(function () {
    Route::get('/member/purchasing-report/generate', PurchasingReportController::class)
        ->can('generate purchasing report')
        ->name('purchasing-report.generate');
    Route::get('/member/selling-report/generate', SellingReportController::class)
        ->can('generate selling report')
        ->name('selling-report.generate');
    Route::get('/member/product-report/generate', ProductReportController::class)
        ->can('generate product report')
        ->name('product-report.generate');
    Route::get('/member/cashier-report/generate', CashierReportController::class)
        ->can('generate cashier report')
        ->name('cashier-report.generate');
    Route::view('/member/sellings/{selling}/print', 'filament.tenant.pages.selling.print-receipt')
        ->can('can print selling')
        ->name('selling.print');
});
Route::get('/reset-password/{token}', ResetPassword::class)
    ->middleware('guest')
    ->name('reset-password.index');
