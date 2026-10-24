{{-- The lines of the order, grouped per category (OrderLineSorter::group). Needs $cartGroups, $ctxKey and what cart-item needs. --}}
@forelse ($cartGroups as $group)
  <section wire:key="group-{{ $ctxKey }}-{{ $group['key'] }}" data-testid="cart-group-{{ $group['key'] }}" class="px-3">
    <h4 class="sticky top-0 z-[1] flex items-center justify-between bg-white py-1 text-xs font-bold uppercase tracking-wide text-gray-700 dark:bg-gray-900 dark:text-gray-300">
      <span class="min-w-0 break-words">{{ $group['category'] ?? __('Uncategorized') }}</span>
      <span class="shrink-0 pl-2 font-semibold normal-case">{{ __(':count items', ['count' => $group['lines']->count()]) }}</span>
    </h4>
    @foreach ($group['lines'] as $item)
      @include('filament.tenant.pages.cashier.cart-item')
    @endforeach
  </section>
@empty
  <div wire:key="empty-{{ $ctxKey }}" data-testid="cart-empty" class="flex h-40 flex-col items-center justify-center gap-2 px-3 text-center">
    <x-heroicon-o-shopping-cart class="h-10 w-10 text-gray-500 dark:text-gray-400" />
    <p class="text-base text-gray-700 dark:text-gray-300">{{ __('No item') }}</p>
  </div>
@endforelse
