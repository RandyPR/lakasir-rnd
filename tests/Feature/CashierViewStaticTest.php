<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Static checks of the cashier views (no database, no browser)
|--------------------------------------------------------------------------
|
| Guards what the browser tests and the old scripts rely on, and mistakes that only show up in a browser:
| fixed ids, test selectors, includes that point nowhere, raw output of user data, payment kinds guessed from
| names, dialogs without a role, and Alpine components put on <x-filament::modal> (they hide the modal's own refs).
*/

if (! function_exists('cashierViewSources')) {
    /** @return array<string, string> path => source of every cashier view */
    function cashierViewSources(): array
    {
        $files = [
            'resources/views/filament/tenant/pages/cashier.blade.php',
            'resources/views/filament/tenant/partials/receipt-print.blade.php',
        ];

        foreach (['resources/views/filament/tenant/pages/cashier', 'resources/views/livewire/cashier'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/\\');
                }
            }
        }

        sort($files);

        $sources = [];
        foreach (array_unique($files) as $file) {
            $sources[$file] = file_get_contents(base_path($file));
        }

        return $sources;
    }
}

if (! function_exists('cashierViewSource')) {
    function cashierViewSource(): string
    {
        return implode("\n", cashierViewSources());
    }
}

test('the fixed ids the receipt printing and the old scripts use are kept', function () {
    $source = cashierViewSource();

    expect($source)->toContain('id="printReceiptButton"')
        ->toContain('id="qr-reader"');

    foreach (['edit-detail', 'proceed-the-payment', 'success-modal', 'modal-selected-table', 'qr-scanner-modal'] as $modal) {
        expect(preg_match('/<x-filament::modal\s[^>]*\bid="'.preg_quote($modal, '/').'"/s', $source))
            ->toBe(1, "modal {$modal}");
    }
});

test('every test selector of the plan exists in the cashier views', function () {
    $source = cashierViewSource();

    $selectors = [
        // plan.md 4.7 (one card partial serves the grid and the list, so there is no product-row)
        'product-card-{id}', 'category-chip-all', 'category-chip-{id}', 'view-toggle-grid', 'view-toggle-list',
        'product-search', 'product-load-more', 'cart-group-{slug}', 'cart-item-{id}', 'cart-item-note-toggle-{id}',
        'cart-item-note-{id}', 'customer-name-input', 'customer-name-hint', 'cart-dock', 'cart-sheet',
        // plan-v3 7.8
        'bills-toggle', 'bills-count', 'bills-drawer', 'bills-filter-{key}', 'bill-row-{id}', 'bill-open-{id}',
        'bill-pay-{id}', 'bill-print-{id}', 'bill-void-{id}', 'bill-conflict', 'save-bill', 'send-kitchen',
        'provisional-bill', 'new-order-chip', 'bill-chip', 'pay-method-{id}', 'pay-numpad-{key}', 'pay-exact',
        'pay-received', 'pay-change', 'pay-error', 'pay-submit', 'pay-items-toggle', 'receipt-preview-modal',
        'receipt-preview-text', 'cart-item-unit-{id}', 'cart-item-total-{id}',
        // plan-v3 14.10
        'save-bill-only', 'takeaway-prompt', 'takeaway-confirm', 'bill-saved-toast', 'kitchen-print-failed',
        'kitchen-print-retry', 'kitchen-mark-sent', 'kitchen-pending-strip', 'line-chip-{id}', 'cancel-sent-dialog',
        'cancel-reason', 'cancel-confirm', 'bill-resume-chip', 'bills-search', 'bills-unsent-dot',
        'bill-unsent-chip-{id}', 'bill-menu-{id}', 'pay-kitchen-switch', 'pay-stock-override', 'pay-conflict',
        'pay-price-stale',
        // the quick cash buttons
        'pay-quick-exact', 'pay-add-{amount}', 'pay-quick-{amount}', 'pay-quick-clear',
    ];

    $missing = [];
    foreach ($selectors as $selector) {
        if (str_contains($selector, '{')) {
            $prefix = preg_quote(Str::before($selector, '{'), '/');
            // data-testid="prefix{{ ... }}", x-bind:data-testid="'prefix' + ...", testid: 'prefix' + ...
            $found = preg_match('/(data-testid="'.$prefix.'|[\'"]'.$prefix.'[\'"]\s*\+|[\'"]'.$prefix.'\'\s*\+)/', $source);
        } else {
            $found = preg_match('/(data-testid="'.preg_quote($selector, '/').'"|testid:\s*\''.preg_quote($selector, '/').'\')/', $source);
        }
        if (! $found) {
            $missing[] = $selector;
        }
    }

    expect($missing)->toBe([]);
});

test('no Filament modal of the cashier carries an Alpine component of its own', function () {
    // Its attributes land on the x-ref="modalContainer" element: an x-data there makes the modal's open() and
    // close() fail on every use, and hides the refs of the content from handlers started by window events.
    preg_match_all('/<x-filament::modal\b((?:\s+[^\s=>]+(?:="[^"]*"|=\'[^\']*\')?)*)\s*\/?>/s', cashierViewSource(), $tags);

    expect($tags[1])->not->toBeEmpty();
    foreach ($tags[1] as $attributes) {
        expect($attributes)->not->toMatch('/\bx-(data|init)\b/');
    }
});

test('every included view and Livewire component of the cashier exists', function () {
    $missing = [];

    foreach (cashierViewSources() as $file => $source) {
        preg_match_all('/@include(?:If|When|Unless)?\(\s*(?:[^,\'"\n]+,\s*)?\'([a-z0-9._\-\/]+)\'/i', $source, $includes);
        foreach ($includes[1] as $view) {
            if (! view()->exists($view)) {
                $missing[] = "{$file}: @include {$view}";
            }
        }

        preg_match_all('/(?:<livewire:([a-z0-9.\-]+)|@livewire\(\s*\'([a-z0-9.\-]+)\')/i', $source, $components);
        foreach (array_filter(array_merge($components[1], $components[2])) as $name) {
            $class = 'App\\Livewire\\'.implode('\\', array_map(fn (string $part): string => Str::studly($part), explode('.', $name)));
            if (! class_exists($class)) {
                $missing[] = "{$file}: livewire {$name}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('user data is never echoed raw', function () {
    foreach (cashierViewSources() as $file => $source) {
        expect(substr_count($source, '{!!'))->toBe(0, $file);
    }
});

test('the kind of a payment method never comes from its name', function () {
    $sources = cashierViewSource()
        .file_get_contents(app_path('Services/Tenants/PaymentPlanner.php'))
        .file_get_contents(app_path('Models/Tenants/PaymentMethod.php'));

    expect(preg_match('/[\'"\/](qris|debit|ovo|gopay|dana|shopeepay)[\'"\/]/i', $sources))->toBe(0);
});

test('the dialogs of the cashier are announced as modal dialogs', function () {
    $source = cashierViewSource();

    foreach (['cancel-sent-dialog', 'bill-conflict', 'takeaway-prompt', 'receipt-preview-dialog', 'bill-void-dialog', 'bills-drawer'] as $testid) {
        expect(preg_match('/<[a-z]+\b[^<>]*data-testid="'.preg_quote($testid, '/').'"[^<>]*>/s', $source, $tag))->toBe(1, $testid);
        expect($tag[0])->toMatch('/role="(dialog|alertdialog)"/')
            ->toContain('aria-modal="true"');
    }
});

test('a bill that changed elsewhere can only be reloaded, never saved as a new bill', function () {
    $source = cashierViewSource();

    expect($source)->not->toContain('Save as new bill')
        ->not->toContain('Simpan sebagai bill baru')
        ->not->toMatch('/\bBon\b/');
});

test('the payment script between its markers is plain JavaScript, so it can be loaded on its own', function () {
    $source = file_get_contents(resource_path('views/filament/tenant/pages/cashier/payment/script.blade.php'));

    expect(preg_match('/\/\/ paymentFlow:begin\n(.*)\/\/ paymentFlow:end/s', $source, $block))->toBe(1);
    expect($block[1])->not->toContain('{{')
        ->not->toContain('@js')
        ->not->toContain('@lang')
        ->toContain('function paymentFlow()');
});
