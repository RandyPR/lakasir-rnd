# AGENTS.md - Coding Agent Instructions

## Project Overview

This is a single-tenant, single-deployment Point of Sale (POS) application built with Laravel 11.x, Filament 3.x admin panel, and Livewire/Volt for reactive UI. One shop, one database; there is no tenancy layer.

## Build/Lint/Test Commands

### Environment: Laravel Herd (Windows)
Always use `herd` command prefix:
```bash
herd php ...
herd composer ...
herd artisan ...
```

### Herd setup (verified 2026-10-13)
- Site: `http://lakasir-rnd.test` (served by Herd nginx, PHP 8.4.x as required by `composer.json` `^8.4`).
- The global `php` on PATH may be a different version (e.g. `C:\php85`). Put Herd first or call it explicitly, then check `php -v` says 8.4:
  ```powershell
  $env:Path = "$env:USERPROFILE\.config\herd\bin;$env:Path"
  ```
- Env: `cp .env.herd.example .env` (SQLite at `database/database.sqlite`, file cache, database session/queue, no MySQL/Redis/Docker), then `php artisan key:generate`.
- Tests (Pest) run on their own SQLite file `database/testing.sqlite` (created automatically, never touches `database/database.sqlite`). `RefreshDatabaseWithTenant` migrates once per run and seeds the shop in a per-test transaction (`seedShop()` in `tests/Pest.php`). Run a single file while iterating: `php vendor/bin/pest tests/Feature/<File>.php`.
- `php artisan migrate:status` against your dev DB may list Pending migrations (some drop tables); review before running `migrate` on data you care about.

### graphify (code knowledge graph)
```bash
pip install graphifyy                 # PyPI name has two y's; CLI is `graphify`
graphify install --platform agents    # refresh the skill if graphify warns it is outdated
graphify update .                     # build/refresh graph without an LLM (~15s); writes graphify-out/ (gitignored)
graphify explain "SomeClass"          # explain a node
graphify path "A" "B"                 # shortest path between two nodes
```
Read `graphify-out/GRAPH_REPORT.md` before broad codebase searches.

### Dependencies
```bash
herd composer install    # Install PHP dependencies
npm install              # Install frontend dependencies
```

### Build
```bash
npm run dev              # Development build (Vite)
npm run build            # Production build
```

### Test Commands
```bash
herd php artisan test                                    # Run all tests
herd php artisan test --filter=TestName                  # Run specific test by name
herd php artisan test tests/Feature/Path/To/Test.php     # Run single test file
herd php vendor/bin/pest                                 # Run Pest directly
herd php vendor/bin/pest --filter="test name here"       # Run specific test
```

### Database Setup
```bash
herd php artisan key:generate
herd php artisan migrate --seed
herd php artisan filament:assets
herd php artisan livewire:publish --assets
```

### Code Formatting
- Blade: Uses `.bladeformatterrc.json` (2-space indent, 120 line length)
- EditorConfig: Defined in `.editorconfig`

## Code Style Guidelines

### Import Ordering
```php
// Internal classes first, then external packages
use App\Models\Tenants\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
```

### Naming Conventions
- **Classes:** PascalCase (e.g., `ProductController`, `VoucherService`)
- **Methods:** camelCase (e.g., `filterCategory()`, `applicable()`)
- **Variables:** camelCase (e.g., `$product`, `$cartItems`)
- **Database columns:** snake_case (e.g., `product_id`, `created_at`)
- **Constants:** UPPER_CASE (e.g., `ROLE_ADMIN`)

### Formatting
- **PHP:** 4 spaces indentation
- **Blade/JS/CSS:** 2 spaces indentation
- **Line length:** 120 characters max
- **Arrays:** Short syntax `[]` not `array()`
- **Braces:** PSR-12 style (opening brace on same line)

### Type Annotations
- Use PHP 8.4 typed properties and return types
- Always declare parameter types and return types
```php
public function index(): JsonResponse
public function validate(string $attribute, mixed $value, Closure $fail): void
```

### Models
```php
protected $guarded = ['id'];              // Prefer guarded over fillable
protected $appends = ['computed_field'];   // For accessors

public function product(): BelongsTo       // Relationship with return type
{
    return $this->belongsTo(Product::class);
}
```

### Controllers
- Use Form Requests for validation (e.g., `ProductRequest`)
- Use `ApiResponseService` for consistent JSON responses
- Controllers extend base `Controller` class

### Error Handling
```php
try {
    DB::beginTransaction();
    // operations
    DB::commit();
} catch (Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### Testing (Pest)
```php
<?php

use App\Models\Tenants\User;
use Illuminate\Http\Response;
use Tests\RefreshDatabaseWithTenant;

uses(RefreshDatabaseWithTenant::class);

test('can create product', function () {
    $user = User::first();
    actingAs($user)->postJson('/api/master/product', $data)
        ->assertStatus(Response::HTTP_CREATED);
});
```

## Architecture Patterns

### Single tenant
- One shop per deployment, one database. `stancl/tenancy` was removed; do not reintroduce `tenant()`/`tenancy()`.
- Models live in `app/Models/Tenants/` (namespace name kept, no tenancy behind it).
- Use `RefreshDatabaseWithTenant` trait in tests; it seeds the shop via `seedShop()` (owner `toko_testing@mail.com` / `password`).

### Filament Resources
- Located in `app/Filament/Tenant/Resources/`
- Use `HasTranslatableResource` trait for navigation labels

### Feature Flags
Use Laravel Pennant:
```php
if (feature(FeatureClass::class)) {
    // feature enabled
}
```

### Helper Functions
Global helpers in `app/helpers.php`:
- `hasFeatureAndPermission()`
- `can()`
- `price_format()`

## Key Directories
- `app/Http/Controllers/Api/Tenants/` - API controllers
- `app/Filament/Tenant/Resources/` - Filament admin resources
- `app/Services/` - Business logic services
- `app/Policies/` - Authorization policies
- `database/migrations/` - Migrations (single database)
- `tests/Feature/` - Feature tests

## Before Committing
1. Run `php artisan test` to ensure all tests pass
2. Verify code follows PSR-12 formatting
3. Use `$guarded = ['id']` for new models
4. Add proper type hints to all methods