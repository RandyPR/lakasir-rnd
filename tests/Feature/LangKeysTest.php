<?php

/*
|--------------------------------------------------------------------------
| Every text the cashier shows is translated
|--------------------------------------------------------------------------
|
| Scans the cashier views and the classes behind them for __('...'), @lang('...') and trans('...') with a literal
| key, and checks lang/id.json and lang/en.json. Keys of a translation file (validation.required, filament::...)
| are not JSON keys and are skipped. No database is needed.
*/

if (! function_exists('langKeysSources')) {
    /** @return list<string> paths relative to the project root */
    function langKeysSources(): array
    {
        $files = [
            'resources/views/filament/tenant/pages/cashier.blade.php',
            'resources/views/filament/tenant/partials/receipt-print.blade.php',
            'resources/views/filament/tenant/resources/sellings/pages/view-selling.blade.php',
            'resources/views/livewire/price-setting.blade.php',
            'app/Filament/Tenant/Pages/Cashier.php',
            'app/Filament/Tenant/Pages/Printer.php',
            'app/Filament/Tenant/Pages/Traits/CartInteraction.php',
            'app/Models/Tenants/OpenBill.php',
            'app/Models/Tenants/OpenBillItem.php',
            'app/Models/Tenants/OpenBillEvent.php',
        ];

        foreach ([
            'resources/views/filament/tenant/pages/cashier',
            'resources/views/livewire/cashier',
            'app/Livewire/Cashier',
            'app/Services/Tenants',
            'app/Exceptions',
            'app/Rules',
        ] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/\\');
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }
}

if (! function_exists('langKeysUsed')) {
    /** @return array<string, string> key => first place it is used */
    function langKeysUsed(): array
    {
        $keys = [];
        $pattern = '/(?:__|@lang|trans)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

        foreach (langKeysSources() as $file) {
            $source = file_get_contents(base_path($file));
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $key = isset($match[2]) && $match[2][1] >= 0 ? $match[2][0] : $match[1][0];
                $key = str_replace(["\\'", '\\"'], ["'", '"'], $key);

                // A key of a translation file, not of the JSON files.
                if (preg_match('/^[a-z0-9_-]+(::[a-z0-9_.\/-]+)?\.[a-z0-9_.-]+$/', $key)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $keys[$key] ??= $file.':'.$line;
            }
        }

        ksort($keys);

        return $keys;
    }
}

if (! function_exists('langKeysJson')) {
    /** @return array<string, string> */
    function langKeysJson(string $locale): array
    {
        $decoded = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

test('the cashier sources use translation keys at all (the scan works)', function () {
    $keys = langKeysUsed();

    expect(count($keys))->toBeGreaterThan(150)
        ->and($keys)->toHaveKeys(['Send to kitchen', 'Bill #:number', 'Pay :amount', 'KITCHEN']);
});

test('every translation key of the cashier exists in lang/id.json and lang/en.json', function (string $locale) {
    $json = langKeysJson($locale);

    $missing = array_filter(langKeysUsed(), fn (string $where, string $key): bool => ! array_key_exists($key, $json), ARRAY_FILTER_USE_BOTH);

    expect($missing)->toBe([], "Missing in lang/{$locale}.json:\n".implode("\n", array_map(
        fn (string $key, string $where): string => "  {$key}  ({$where})",
        array_keys($missing),
        $missing,
    )));
})->with(['id', 'en']);

test('an Indonesian text keeps every placeholder of its key', function () {
    $id = langKeysJson('id');
    $placeholders = function (string $text): array {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    };

    $broken = [];
    foreach (array_keys(langKeysUsed()) as $key) {
        if (isset($id[$key]) && $placeholders($key) !== $placeholders($id[$key])) {
            $broken[] = $key.' => '.$id[$key];
        }
    }

    expect($broken)->toBe([]);
});

test('the cashier vocabulary follows the plan: Bill, never Bon, and short button verbs', function () {
    $id = langKeysJson('id');

    foreach (['Add' => 'Tambah', 'Open' => 'Buka', 'Remove' => 'Hapus', 'Save' => 'Simpan', 'Close' => 'Tutup',
        'Provisional bill' => 'Bill sementara', 'Open bills' => 'Bill terbuka', 'Send to kitchen' => 'Kirim ke dapur',
        'Exact amount' => 'Uang pas'] as $key => $value) {
        expect($id[$key] ?? null)->toBe($value);
    }

    $bon = array_filter(
        array_intersect_key($id, langKeysUsed()),
        fn (string $value): bool => (bool) preg_match('/\bbon\b/i', $value),
    );
    expect($bon)->toBe([]);
});
