{{--
  The open bills ("Bill terbuka"): the toggle button of the cashier and its drawer. Component: App\Livewire\Cashier\OpenBills.

  View data: bills (rows of OpenBillPresenter::row, already filtered, searched and ordered), count, unsentCount,
  today, kitchenEnabled, loadError. Public properties used: open, filter, search.
  Browser events handled: bill-voided (closes the void dialog), bill-preview (the preview modal takes over).
  Rules: no transform on any ancestor of the fixed drawer (the sheet fades, it does not slide); a row that has
  focus or an open menu is not re-rendered by a poll (morph hook below); dialogs trap focus, make the page inert
  and give the focus back (window.LakasirDialog, bills/dialog-js).
--}}
@php
  use App\Models\Tenants\Profile;
  use App\Models\Tenants\Setting;
  use Carbon\CarbonInterval;
  use Illuminate\Support\Number;

  // One format for every amount, the same one as price_format(), without a profile query per row.
  $currencyFormat = null;
  $money = function (float|int|string|null $value) use (&$currencyFormat): string {
    $currencyFormat ??= [Setting::get('currency', 'IDR'), Profile::get(['locale'])->locale ?? 'en'];

    return Number::currency((float) $value, $currencyFormat[0], $currencyFormat[1]);
  };
  $qtyFormat = fn (float|int|string|null $value): string => Number::format((float) $value, maxPrecision: 3);
  $ageFormat = function (int $minutes): string {
    if ($minutes < 60) {
      return __(':minutes min', ['minutes' => max(0, $minutes)]);
    }

    return CarbonInterval::minutes($minutes)
      ->cascade()
      ->locale(app()->getLocale())
      ->forHumans(['short' => true, 'parts' => 2, 'join' => ' ']);
  };

  $alertAfter = max(1, (int) config('pos.unsent_alert_minutes', 3));

  $filters = ['all' => __('All bills'), 'mine' => __('My bills')];
  if ($kitchenEnabled) {
    $filters['unsent'] = __('Unsent bills');
  }
  $filters['stale'] = __('From yesterday');

  // Shared classes (Tailwind sees them here). Orange-700 / orange-400 keep white / dark text readable (5.2:1).
  $ring = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#c2410c] focus-visible:ring-offset-2 '
    .'dark:focus-visible:ring-[#fb923c] dark:focus-visible:ring-offset-gray-900';
  $primary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#c2410c] px-4 text-sm font-semibold '
    .'text-white hover:bg-[#9a3412] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-[#fb923c] '
    .'dark:text-[#1f1209] dark:hover:bg-[#fdba74]';
  $secondary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 '
    .'text-sm font-medium text-gray-900 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 '
    .'dark:border-white/20 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700';
  $iconButton = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 '
    .'dark:text-gray-200 dark:hover:bg-white/10';
  $muted = 'text-gray-600 dark:text-gray-400';
@endphp
<div>
  <div class="contents" x-data="lakasirBills" x-on:bill-voided.window="onVoided($event.detail)"
    x-on:bill-preview.window="if (shown) closeDrawer()">
    {{-- The toggle: count, and a dot while a bill has not been told to the kitchen --}}
    <button type="button" x-ref="toggle" x-on:click="openDrawer()" data-testid="bills-toggle" aria-haspopup="dialog"
      aria-controls="bills-drawer" x-bind:aria-expanded="shown ? 'true' : 'false'"
      class="{{ $secondary }} {{ $ring }} relative">
      <x-heroicon-o-clipboard-document-list class="h-5 w-5" />
      <span>{{ __('Bills') }}</span>
      <span data-testid="bills-count" aria-hidden="true"
        class="{{ $count > 0 ? 'bg-[#c2410c] text-white dark:bg-[#fb923c] dark:text-[#1f1209]' : 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' }} inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-bold tabular-nums">{{ $count }}</span>
      @if ($kitchenEnabled && $unsentCount > 0)
        <span data-testid="bills-unsent-dot" aria-hidden="true"
          class="absolute -right-1 -top-1 h-3.5 w-3.5 rounded-full bg-amber-400 ring-2 ring-white dark:ring-gray-900"></span>
      @endif
    </button>
    <span role="status" class="sr-only" data-testid="bills-count-status">{{ __('Open bills') }}: {{ $count }}@if ($kitchenEnabled && $unsentCount > 0), {{ __('Not sent to kitchen') }}: {{ $unsentCount }}@endif</span>

    {{-- The drawer: bottom sheet below md, side panel from md. Fixed, with no transformed ancestor. --}}
    <div x-ref="drawer" x-show="shown" x-cloak x-transition:enter="transition-opacity duration-150 ease-out motion-reduce:transition-none"
      x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
      x-transition:leave="transition-opacity duration-100 ease-in motion-reduce:transition-none"
      x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-40">
      <div class="absolute inset-0 bg-gray-950/50" aria-hidden="true" x-on:click="closeDrawer()"></div>

      <section id="bills-drawer" x-ref="panel" data-testid="bills-drawer" role="dialog" aria-modal="true"
        aria-labelledby="bills-drawer-title" tabindex="-1"
        class="absolute inset-x-0 bottom-0 flex max-h-[92vh] flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl focus:outline-none dark:bg-gray-900 md:inset-y-0 md:left-auto md:right-0 md:max-h-none md:w-[26rem] md:rounded-none">
        <div class="flex items-start justify-between gap-3 px-4 pb-2 pt-4">
          <div class="min-w-0">
            <h2 id="bills-drawer-title" class="text-lg font-semibold text-gray-950 dark:text-white">{{ __('Open bills') }}</h2>
            {{-- Count and total of the open bills; an empty list says so itself below. --}}
            @if ($open && ! $loadError && $bills->isNotEmpty())
              <p class="{{ $muted }} text-sm tabular-nums" data-testid="bills-summary">{{ $bills->count() }} · {{ $money($bills->sum('amount_due')) }}</p>
            @endif
          </div>
          <button type="button" data-testid="bills-close" x-on:click="closeDrawer()" aria-label="{{ __('Back') }}"
            class="{{ $iconButton }} {{ $ring }}">
            <x-heroicon-o-x-mark class="h-6 w-6" />
          </button>
        </div>

        <div class="px-4 pb-2">
          <label for="bills-search" class="sr-only">{{ __('Search table, name or number') }}</label>
          <div class="relative">
            <x-heroicon-m-magnifying-glass class="{{ $muted }} pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2" />
            <input type="search" id="bills-search" x-ref="search" data-testid="bills-search"
              wire:model.live.debounce.300ms="search" placeholder="{{ __('Search table, name or number') }}"
              autocomplete="off" enterkeyhint="search" maxlength="100"
              class="{{ $ring }} block min-h-11 w-full rounded-lg border border-gray-300 bg-white py-2 pl-10 pr-12 text-base text-gray-950 placeholder:text-gray-500 dark:border-white/20 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-400 [&::-webkit-search-cancel-button]:hidden" />
            @if (trim($search) !== '')
              <button type="button" data-testid="bills-search-clear" wire:click="$set('search', '')"
                x-on:click="$refs.search.focus()" aria-label="{{ __('Clear') }}"
                class="{{ $iconButton }} {{ $ring }} absolute right-0 top-0">
                <x-heroicon-m-x-mark class="h-5 w-5" />
              </button>
            @endif
          </div>
        </div>

        <div class="flex gap-2 overflow-x-auto px-4 pb-2" role="group" aria-label="{{ __('Open bills') }}">
          @foreach ($filters as $key => $label)
            <button type="button" wire:click="setFilter('{{ $key }}')" data-testid="bills-filter-{{ $key }}"
              aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
              class="{{ $ring }} {{ $filter === $key ? 'border-[#c2410c] bg-[#c2410c] text-white dark:border-[#fb923c] dark:bg-[#fb923c] dark:text-[#1f1209]' : 'border-gray-300 bg-white text-gray-900 hover:bg-gray-50 dark:border-white/20 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700' }} inline-flex min-h-11 shrink-0 items-center rounded-full border px-4 text-sm font-medium">{{ $label }}</button>
          @endforeach
        </div>

        @php
          // Quick table chips (kitchen only): the tables that have an open bill in the list shown now.
          $tableChips = collect();
          if ($open && $kitchenEnabled && trim($search) === '') {
            $tableChips = $bills
              ->pluck('table_label')
              ->map(fn ($table): string => trim((string) $table))
              ->filter()
              ->unique()
              ->sort(SORT_NATURAL | SORT_FLAG_CASE)
              ->values();
          }
        @endphp
        @if ($tableChips->count() > 1)
          <div class="flex gap-2 overflow-x-auto px-4 pb-2" data-testid="bills-table-chips">
            @foreach ($tableChips as $tableChip)
              <button type="button" wire:click="$set('search', @js($tableChip))" data-testid="bills-table-chip-{{ $tableChip }}"
                class="{{ $secondary }} {{ $ring }} shrink-0 rounded-full">{{ __('Table') }} {{ $tableChip }}</button>
            @endforeach
          </div>
        @endif

        <div class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain bg-gray-50 px-4 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-3 dark:bg-gray-950"
          x-ref="list">
          <span wire:loading wire:target="search,setFilter,setOpen,pay,select" role="status" class="sr-only">{{ __('Processing...') }}</span>

          @if (! $open)
            {{-- Waiting for the server to load the list (the drawer opens at once) --}}
            <div class="space-y-3" data-testid="bills-loading" aria-hidden="true">
              @foreach (range(1, 3) as $placeholder)
                <div class="h-28 animate-pulse rounded-xl bg-gray-200 dark:bg-white/10"></div>
              @endforeach
            </div>
          @elseif ($loadError)
            <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
              data-testid="bills-error">
              <p class="font-medium">{{ __('Bill list could not be loaded') }}</p>
              <button type="button" wire:click="$refresh" class="{{ $secondary }} {{ $ring }} mt-3">{{ __('Try again') }}</button>
            </div>
          @else
            <div wire:poll.20s.visible wire:loading.class="opacity-60" wire:target="search,setFilter">
              @if ($bills->isEmpty())
                <div class="flex flex-col items-center gap-3 px-4 py-12 text-center" data-testid="bills-empty">
                  <x-heroicon-o-clipboard-document-list class="{{ $muted }} h-10 w-10" />
                  <p class="text-base font-medium text-gray-950 dark:text-white">{{ __('No open bills') }}</p>
                  @if ($filter !== 'all' || trim($search) !== '')
                    <button type="button" x-on:click="$wire.setFilter('all'); $wire.set('search', '')"
                      data-testid="bills-reset" class="{{ $secondary }} {{ $ring }}">{{ __('All bills') }}</button>
                  @endif
                </div>
              @else
                <ul class="space-y-3" data-testid="bills-list">
                  @foreach ($bills as $bill)
                    @php
                      $id = (int) $bill['id'];
                      $customer = trim((string) ($bill['customer_name'] ?? ''));
                      $table = trim((string) ($bill['table_label'] ?? ''));
                      $title = $customer !== '' ? $customer : ($table !== '' ? __('Table').' '.$table : '');
                      $identity = trim($bill['number_label'].($title !== '' ? ' · '.$title : ''));
                      $hasLines = (int) ($bill['items_count'] ?? 0) > 0;
                      $unsent = $kitchenEnabled && (bool) ($bill['unsent'] ?? false);
                      $sent = $kitchenEnabled && (bool) ($bill['sent'] ?? false);
                      $unsentAge = (int) ($bill['unsent_age_minutes'] ?? 0);
                      $late = $unsent && $unsentAge >= $alertAfter;
                      // The main action: pay, unless the kitchen still has to be told (then open the bill).
                      $payFirst = $hasLines && ! $unsent;
                      $lines = is_array($bill['lines'] ?? null) ? $bill['lines'] : null;
                    @endphp
                    <li wire:key="bill-{{ $id }}" data-bill-row data-testid="bill-row-{{ $id }}" data-bill-id="{{ $id }}"
                      data-label="{{ $identity }}" data-total="{{ $money($bill['amount_due'] ?? 0) }}"
                      data-version="{{ (int) ($bill['version'] ?? 0) }}" data-sent="{{ $sent ? 1 : 0 }}"
                      x-bind:data-hot="hot({{ $id }}) ? '1' : null" x-on:focusin="focusRow = {{ $id }}"
                      x-on:focusout="rowFocusOut($event, {{ $id }})"
                      class="{{ $late ? 'border-red-400 dark:border-red-500/60' : 'border-gray-200 dark:border-white/10' }} rounded-xl border bg-white p-3 shadow-sm dark:bg-gray-800/60">
                      {{-- Two lines: who / how much, then age, cashier and status --}}
                      <button type="button" x-on:click="toggleExpanded({{ $id }})" aria-controls="bill-detail-{{ $id }}"
                        x-bind:aria-expanded="isExpanded({{ $id }}) ? 'true' : 'false'" data-testid="bill-toggle-{{ $id }}"
                        class="{{ $ring }} -m-1 block min-h-11 w-[calc(100%+0.5rem)] rounded-lg p-1 text-left">
                        <span class="flex items-start justify-between gap-3">
                          <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ $bill['number_label'] }}</span>
                            @if (! empty($bill['business_date_label']))
                              <span data-testid="bill-date-{{ $id }}"
                                class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ $bill['business_date_label'] }}</span>
                            @endif
                            @if ($title !== '')
                              <span class="min-w-0 truncate font-medium text-gray-950 dark:text-white">{{ $title }}</span>
                            @endif
                            @if ($customer !== '' && $table !== '')
                              <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ __('Table') }} {{ $table }}</span>
                            @endif
                          </span>
                          <span class="shrink-0 text-base font-bold tabular-nums text-gray-950 dark:text-white" data-testid="bill-total-{{ $id }}">{{ $money($bill['amount_due'] ?? 0) }}</span>
                        </span>
                        <span class="{{ $muted }} mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                          <span class="inline-flex items-center gap-1" data-testid="bill-age-{{ $id }}">
                            <x-heroicon-m-clock class="h-4 w-4" />
                            <span class="sr-only">{{ __('Age') }}:</span>{{ $ageFormat((int) ($bill['age_minutes'] ?? 0)) }}
                          </span>
                          @if (! empty($bill['creator_name']))
                            <span class="inline-flex min-w-0 items-center gap-1">
                              <x-heroicon-m-user class="h-4 w-4 shrink-0" />
                              <span class="sr-only">{{ __('Cashier') }}:</span><span class="truncate">{{ $bill['creator_name'] }}</span>
                            </span>
                          @endif
                          @if ($unsent)
                            <span data-testid="bill-unsent-chip-{{ $id }}" data-tone="{{ $late ? 'red' : 'amber' }}"
                              class="{{ $late ? 'bg-red-100 text-red-900 dark:bg-red-500/20 dark:text-red-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-400/20 dark:text-amber-200' }} inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold">
                              @if ($sent)
                                {{ __('Unsent changes') }}@if ($late) · {{ __(':minutes min', ['minutes' => $unsentAge]) }}@endif
                              @elseif ($late)
                                {{ __('Not sent to kitchen :minutes min', ['minutes' => $unsentAge]) }}
                              @else
                                {{ __('Not sent to kitchen') }}
                              @endif
                            </span>
                          @elseif ($sent)
                            <span data-testid="bill-sent-chip-{{ $id }}"
                              class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-900 dark:bg-green-500/20 dark:text-green-200">{{ __('Sent to kitchen') }}</span>
                          @endif
                          @if (! empty($bill['stale']))
                            <span data-testid="bill-stale-chip-{{ $id }}"
                              class="inline-flex items-center rounded-full bg-gray-900 px-2 py-0.5 text-xs font-semibold text-white dark:bg-white dark:text-gray-900">{{ __('From yesterday') }}</span>
                          @elseif (! empty($bill['crossed_midnight']))
                            <span data-testid="bill-midnight-chip-{{ $id }}"
                              class="inline-flex items-center rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-900 dark:bg-white/10 dark:text-gray-100">{{ __('Past midnight') }}</span>
                          @endif
                          <x-heroicon-m-chevron-down class="ml-auto h-5 w-5 shrink-0 text-gray-500"
                            x-bind:class="isExpanded({{ $id }}) ? 'rotate-180' : ''" />
                        </span>
                      </button>

                      <div x-show="isExpanded({{ $id }})" x-cloak id="bill-detail-{{ $id }}" data-testid="bill-detail-{{ $id }}"
                        class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                          @if ($table !== '')
                            <dt class="{{ $muted }}">{{ __('Table') }}</dt>
                            <dd class="text-right font-medium text-gray-950 dark:text-white">{{ $table }}</dd>
                          @endif
                          <dt class="{{ $muted }}">{{ __('Order items') }}</dt>
                          <dd class="text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ (int) ($bill['items_count'] ?? 0) }} ({{ $qtyFormat($bill['qty_total'] ?? 0) }})</dd>
                          <dt class="{{ $muted }}">{{ __('Amount due') }}</dt>
                          <dd class="text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($bill['amount_due'] ?? 0) }}</dd>
                        </dl>
                        @if ($lines)
                          {{-- Per line: quantity x unit price = line total. "~" marks a rounded unit price. --}}
                          <ul class="divide-y divide-gray-200 dark:divide-white/10" data-testid="bill-lines-{{ $id }}">
                            @foreach ($lines as $line)
                              <li class="py-1.5">
                                <p class="font-medium text-gray-950 dark:text-white">{{ $line['name'] ?? '' }}</p>
                                <p class="{{ $muted }} tabular-nums">{{ $qtyFormat($line['qty'] ?? 0) }} x {{ ($line['unit_exact'] ?? true) ? '' : '~' }}{{ $money($line['unit'] ?? 0) }} = {{ $money($line['total'] ?? 0) }}</p>
                                @if (filled($line['note'] ?? null))
                                  <p class="{{ $muted }}">&gt; {{ $line['note'] }}</p>
                                @endif
                              </li>
                            @endforeach
                          </ul>
                        @endif
                      </div>

                      {{-- One main action, plus the menu --}}
                      <div class="mt-3 flex items-center gap-2">
                        @if ($payFirst)
                          <button type="button" wire:click="pay({{ $id }})" wire:loading.attr="disabled" wire:target="pay,select"
                            data-testid="bill-pay-{{ $id }}" class="{{ $primary }} {{ $ring }} flex-1">{{ __('Pay bill') }}</button>
                        @else
                          <button type="button" wire:click="select({{ $id }})" wire:loading.attr="disabled" wire:target="pay,select"
                            data-testid="bill-open-{{ $id }}" aria-label="{{ __('Open this bill') }} {{ $identity }}"
                            class="{{ $primary }} {{ $ring }} flex-1">{{ __('Open') }}</button>
                        @endif
                        <button type="button" x-on:click="toggleMenu({{ $id }})" data-testid="bill-menu-{{ $id }}"
                          aria-haspopup="true" aria-controls="bill-menu-list-{{ $id }}"
                          x-bind:aria-expanded="menuFor === {{ $id }} ? 'true' : 'false'"
                          aria-label="{{ __('Open bill (:label)', ['label' => $identity]) }}"
                          class="{{ $secondary }} {{ $ring }} w-11 shrink-0 px-0">
                          <x-heroicon-m-ellipsis-vertical class="h-5 w-5" />
                        </button>
                      </div>
                      <ul x-show="menuFor === {{ $id }}" x-cloak id="bill-menu-list-{{ $id }}" data-testid="bill-menu-list-{{ $id }}"
                        class="mt-2 divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 bg-white text-sm dark:divide-white/10 dark:border-white/10 dark:bg-gray-800">
                        @if ($hasLines)
                          <li>
                            @if ($payFirst)
                              <button type="button" wire:click="select({{ $id }})" x-on:click="menuFor = null"
                                data-testid="bill-open-alt-{{ $id }}"
                                class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                                <x-heroicon-o-pencil-square class="{{ $muted }} h-5 w-5" />{{ __('Open this bill') }}
                              </button>
                            @else
                              <button type="button" wire:click="pay({{ $id }})" x-on:click="menuFor = null"
                                data-testid="bill-pay-alt-{{ $id }}"
                                class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                                <x-heroicon-o-banknotes class="{{ $muted }} h-5 w-5" />{{ __('Pay bill') }}
                              </button>
                            @endif
                          </li>
                          <li>
                            <button type="button" wire:click="printProvisional({{ $id }})" x-on:click="menuFor = null"
                              data-testid="bill-print-{{ $id }}"
                              class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                              <x-heroicon-o-printer class="{{ $muted }} h-5 w-5" />{{ __('Provisional bill') }}
                            </button>
                          </li>
                          <li>
                            <button type="button" wire:click="previewBill({{ $id }}, 'bill')" x-on:click="menuFor = null"
                              data-testid="bill-preview-{{ $id }}"
                              class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                              <x-heroicon-o-eye class="{{ $muted }} h-5 w-5" />{{ __('Preview') }}
                            </button>
                          </li>
                          @if ($kitchenEnabled)
                            <li>
                              <button type="button" wire:click="previewBill({{ $id }}, 'kitchen')" x-on:click="menuFor = null"
                                data-testid="bill-preview-kitchen-{{ $id }}"
                                class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                                <x-heroicon-o-eye class="{{ $muted }} h-5 w-5" />{{ __('Preview') }} ({{ __('KITCHEN') }})
                              </button>
                            </li>
                          @endif
                        @endif
                        @if ($kitchenEnabled && $sent)
                          <li>
                            <button type="button" wire:click="reprintKitchen({{ $id }})" x-on:click="menuFor = null"
                              data-testid="bill-reprint-{{ $id }}"
                              class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                              <x-heroicon-o-arrow-path class="{{ $muted }} h-5 w-5" />{{ __('Reprint last kitchen ticket') }}
                            </button>
                          </li>
                        @endif
                        @if (! empty($bill['can_void']))
                          <li>
                            <button type="button" x-on:click="openVoid({{ $id }})" data-testid="bill-void-{{ $id }}"
                              class="{{ $ring }} flex min-h-11 w-full items-center gap-3 px-3 text-left font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10">
                              <x-heroicon-o-no-symbol class="h-5 w-5" />{{ __('Void bill') }}
                            </button>
                          </li>
                        @endif
                      </ul>
                    </li>
                  @endforeach
                </ul>
              @endif
            </div>
          @endif
        </div>
      </section>

      {{-- Void dialog: always repeats which bill and how much, asks for a reason, shows the server's answer --}}
      <div x-show="voidFor" x-cloak class="absolute inset-0 z-10 flex items-end justify-center md:items-center">
        <div class="absolute inset-0 bg-gray-950/60" aria-hidden="true"></div>
        <div x-ref="voidDialog" role="dialog" aria-modal="true" aria-labelledby="bills-void-title" tabindex="-1"
          data-testid="bill-void-dialog"
          class="relative max-h-[92vh] w-full space-y-4 overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl focus:outline-none dark:bg-gray-900 md:max-w-md md:rounded-2xl">
          <h3 id="bills-void-title" class="text-lg font-semibold text-gray-950 dark:text-white">{{ __('Void bill') }}</h3>

          <div class="rounded-lg bg-gray-100 p-3 dark:bg-white/5" data-testid="bill-void-summary">
            <p class="font-semibold text-gray-950 dark:text-white" x-text="voidFor ? voidFor.label : ''"></p>
            <p class="{{ $muted }} text-sm">{{ __('Amount due') }}:
              <span class="font-semibold tabular-nums text-gray-950 dark:text-white" x-text="voidFor ? voidFor.total : ''"></span>
            </p>
          </div>

          @if ($kitchenEnabled)
            <p id="bills-void-hint" data-testid="bill-void-hint"
              class="rounded-lg border-l-4 border-amber-500 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-400/10 dark:text-amber-100">
              {{ __('Move or merge tables? Do not void the bill: change the table instead (the kitchen receives MOVED).') }}
            </p>
          @endif

          <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Void reason') }}</legend>
            <div class="flex flex-wrap gap-2">
              @foreach (['wrong' => __('Wrong input'), 'customer' => __('Customer cancelled'), 'stock' => __('Out of stock')] as $key => $label)
                <button type="button" x-on:click="pickReason('{{ $key }}', @js($label))" data-testid="bill-void-reason-{{ $key }}"
                  x-bind:aria-pressed="chip === '{{ $key }}' ? 'true' : 'false'"
                  x-bind:class="chip === '{{ $key }}' ? 'border-[#c2410c] bg-[#c2410c] text-white dark:border-[#fb923c] dark:bg-[#fb923c] dark:text-[#1f1209]' : 'border-gray-300 bg-white text-gray-900 dark:border-white/20 dark:bg-gray-800 dark:text-white'"
                  class="{{ $ring }} inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-medium">{{ $label }}</button>
              @endforeach
              <button type="button" x-on:click="pickReason('other', '')" data-testid="bill-void-reason-other"
                x-bind:aria-pressed="chip === 'other' ? 'true' : 'false'"
                x-bind:class="chip === 'other' ? 'border-[#c2410c] bg-[#c2410c] text-white dark:border-[#fb923c] dark:bg-[#fb923c] dark:text-[#1f1209]' : 'border-gray-300 bg-white text-gray-900 dark:border-white/20 dark:bg-gray-800 dark:text-white'"
                class="{{ $ring }} inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-medium">{{ __('Other') }}</button>
            </div>
            <label for="bills-void-reason" class="sr-only">{{ __('Void reason') }}</label>
            <textarea id="bills-void-reason" x-ref="reason" x-model="reason" rows="2" maxlength="255" required
              aria-required="true" data-testid="bill-void-reason" placeholder="{{ __('Write the reason') }}"
              x-bind:aria-invalid="tried && reason.trim().length < 3 ? 'true' : 'false'"
              class="{{ $ring }} block w-full rounded-lg border border-gray-300 bg-white p-3 text-base text-gray-950 placeholder:text-gray-500 dark:border-white/20 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-400"></textarea>
            <p class="{{ $muted }} text-sm" x-show="reason.trim().length < 3">{{ __('A reason is required') }}</p>
          </fieldset>

          <p role="alert" x-show="tried" x-cloak data-testid="bill-void-error"
            class="rounded-lg bg-red-50 p-3 text-sm font-medium text-red-900 dark:bg-red-500/10 dark:text-red-200">
            @error('void')
              {{ $message }}
            @enderror
          </p>

          <div class="grid grid-cols-2 gap-3">
            <button type="button" x-ref="voidBack" x-on:click="closeVoid()" x-bind:disabled="busy" data-testid="bill-void-back"
              class="{{ $secondary }} {{ $ring }}">{{ __('Back') }}</button>
            <button type="button" x-on:click="submitVoid()" x-bind:disabled="busy || reason.trim().length < 3"
              x-bind:aria-busy="busy ? 'true' : 'false'" data-testid="bill-void-confirm"
              class="{{ $ring }} inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-red-500 dark:text-red-950 dark:hover:bg-red-400">
              <span x-show="!busy">{{ __('Void bill') }}</span>
              <span x-show="busy" x-cloak>{{ __('Processing...') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  @script
  <script>
    const registerLakasirBills = () => {
      Alpine.data('lakasirBills', () => ({
        shown: false,
        pendingOpen: false,
        menuFor: null,
        expanded: [],
        focusRow: null,
        voidFor: null,
        reason: '',
        chip: '',
        tried: false,
        busy: false,
        layer: null,
        voidLayer: null,

        init() {
          this.$watch('shown', (value) => {
            this.$nextTick(() => (value ? this.activate() : this.deactivate()));
          });

          // The server closes the drawer itself after Open / Pay. It never opens it on its own.
          this.$wire.$watch('open', (value) => {
            if (value === false && this.shown && !this.pendingOpen) {
              this.shown = false;
              this.menuFor = null;
            }
          });
        },

        openDrawer() {
          if (this.shown) {
            return;
          }

          this.shown = true;
          this.pendingOpen = true;
          Promise.resolve(this.$wire.setOpen(true)).finally(() => {
            this.pendingOpen = false;
          });
        },

        closeDrawer() {
          if (!this.shown) {
            return;
          }

          this.shown = false;
          this.menuFor = null;
          this.$wire.setOpen(false);
        },

        activate() {
          if (this.layer || !this.$refs.drawer) {
            return;
          }

          this.layer = LakasirDialog.activate(this.$refs.drawer, {
            initialFocus: this.$refs.panel,
            onEscape: () => this.escape(),
          });
        },

        deactivate() {
          this.closeVoid(true);

          if (!this.layer) {
            return;
          }

          this.layer.deactivate(this.$refs.toggle);
          this.layer = null;
        },

        escape() {
          if (this.menuFor !== null) {
            const id = this.menuFor;
            this.menuFor = null;
            this.$nextTick(() => {
              const button = this.$root.querySelector('[data-testid="bill-menu-' + id + '"]');
              if (button) {
                button.focus();
              }
            });

            return;
          }

          const search = this.$refs.search;
          if (search && document.activeElement === search && search.value !== '') {
            this.$wire.set('search', '');

            return;
          }

          this.closeDrawer();
        },

        rowEl(id) {
          return this.$root.querySelector('[data-bill-row][data-bill-id="' + id + '"]');
        },

        isExpanded(id) {
          return this.expanded.includes(id);
        },

        toggleExpanded(id) {
          this.expanded = this.isExpanded(id) ? this.expanded.filter((item) => item !== id) : [...this.expanded, id];
        },

        toggleMenu(id) {
          this.menuFor = this.menuFor === id ? null : id;
        },

        // A row with focus or an open menu is left alone by the poll (see the morph hook below).
        hot(id) {
          return this.menuFor === id || this.focusRow === id;
        },

        rowFocusOut(event, id) {
          if (!event.currentTarget.contains(event.relatedTarget) && this.focusRow === id) {
            this.focusRow = null;
          }
        },

        readRow(row) {
          return {
            id: Number(row.dataset.billId),
            label: row.dataset.label,
            total: row.dataset.total,
            version: Number(row.dataset.version),
          };
        },

        openVoid(id) {
          const row = this.rowEl(id);
          if (!row) {
            return;
          }

          this.menuFor = null;
          this.voidFor = this.readRow(row);
          this.reason = '';
          this.chip = '';
          this.tried = false;
          this.busy = false;

          this.$nextTick(() => {
            this.voidLayer = LakasirDialog.activate(this.$refs.voidDialog, {
              initialFocus: this.$refs.voidBack,
              onEscape: () => this.closeVoid(),
            });
          });
        },

        closeVoid(force = false) {
          if (!this.voidFor || (this.busy && !force)) {
            return;
          }

          const id = this.voidFor.id;
          const layer = this.voidLayer;
          this.voidLayer = null;
          this.voidFor = null;
          this.busy = false;

          if (layer) {
            layer.deactivate(this.$root.querySelector('[data-testid="bill-menu-' + id + '"]'));
          }
          if (this.shown) {
            this.$nextTick(() => {
              if (!document.activeElement || document.activeElement === document.body) {
                this.$refs.panel.focus();
              }
            });
          }
        },

        pickReason(key, text) {
          this.chip = key;
          this.reason = text;
          if (text === '') {
            this.$nextTick(() => this.$refs.reason.focus());
          }
        },

        async submitVoid() {
          const reason = this.reason.trim();
          if (!this.voidFor || this.busy || reason.length < 3) {
            return;
          }

          // The row may have been refreshed since the dialog opened: send the version it shows now.
          const fresh = this.rowEl(this.voidFor.id);
          if (fresh) {
            this.voidFor = this.readRow(fresh);
          }

          const { id, version } = this.voidFor;
          this.busy = true;
          this.tried = false;

          try {
            await this.$wire.voidBill(id, reason, version);
          } catch (error) {
            // Livewire reports a failed request itself.
          } finally {
            this.busy = false;
            this.tried = true;

            const again = this.voidFor ? this.rowEl(this.voidFor.id) : null;
            if (again) {
              this.voidFor = this.readRow(again);
            }
          }
        },

        onVoided(detail) {
          if (this.voidFor && detail && Number(detail.id) === this.voidFor.id) {
            this.closeVoid(true);
          }
        },
      }));
    };

    registerLakasirBills();

    // A poll does not re-render the row the cashier is working in.
    Livewire.hook('morph.updating', ({ el, component, skip }) => {
      if (component.id !== $wire.$id) {
        return;
      }
      if (el.hasAttribute && el.hasAttribute('data-bill-row') && el.getAttribute('data-hot') === '1') {
        skip();
      }
    });

    @include('filament.tenant.pages.cashier.bills.dialog-js')
  </script>
  @endscript
</div>
