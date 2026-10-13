# AGENTS.md - Coding Agent Instructions

## Project Overview

This is a multi-tenant Point of Sale (POS) application built with Laravel 11.x, Filament 3.x admin panel, and Livewire/Volt for reactive UI. Uses `stancl/tenancy` for multi-tenancy (database per tenant pattern).

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
- Env: `cp .env.herd.example .env` (127.0.0.1 MySQL, database/session/cache/queue drivers, no Redis/Docker), then `php artisan key:generate`.
- Tests (Pest) create a tenant database per test, about 15s each; run a single file while iterating: `php vendor/bin/pest tests/Feature/<File>.php`.
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
herd php artisan migrate --path=database/migrations/tenant --seed
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

### Multi-tenancy
- Uses `stancl/tenancy` with database-per-tenant
- Tenant models in `app/Models/Tenants/`
- Use `RefreshDatabaseWithTenant` trait in tests
- Call `mockTenant()` for tenant context in tests

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
- `database/migrations/tenant/` - Tenant migrations
- `tests/Feature/` - Feature tests

## Before Committing
1. Run `php artisan test` to ensure all tests pass
2. Verify code follows PSR-12 formatting
3. Use `$guarded = ['id']` for new models
4. Add proper type hints to all methods