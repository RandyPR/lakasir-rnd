{{--
  The note of one order line. A Livewire component of its own, so saving a note never re-renders the cart and the field
  being typed in is never replaced by a re-render of the page. It sits inside the line's Alpine scope (cart-item.blade.php)
  which owns `editing` (the toggle button is on the line) and reads it here.
  Saves after a pause (700 ms), on blur, on Enter and on "Done"; LakasirPending lets Pay wait for it.
--}}
<div
  x-data="{
    value: @js((string) $note),
    saved: @js((string) $note),
    state: 'saved',
    timer: null,
    unreg: null,
    init() {
      if (window.LakasirPending) {
        this.unreg = window.LakasirPending.register(() => this.flush());
      }
    },
    destroy() {
      if (this.unreg) this.unreg();
      clearTimeout(this.timer);
    },
    onInput() {
      this.state = 'unsaved';
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.flush(), 700);
    },
    async flush() {
      clearTimeout(this.timer);
      const next = String(this.value || '').trim();
      if (next === String(this.saved || '').trim()) {
        this.state = 'saved';
        return true;
      }
      this.state = 'saving';
      try {
        const p = this.$wire.save(next);
        if (window.LakasirPending) window.LakasirPending.track(p, 'note:{{ (int) $cartItemId }}');
        await p;
        const server = this.$wire.note || '';
        this.saved = server;
        if (String(this.value || '').trim() === next) this.value = server;
        this.state = String(this.value || '').trim() === server ? 'saved' : 'unsaved';
        return true;
      } catch (e) {
        this.state = 'failed';
        return false;
      }
    },
    get statusText() {
      if (!window.navigator.onLine && this.state !== 'saved') return @js(__('You are offline'));
      return {
        unsaved: @js(__('Unsaved')),
        saving: @js(__('Saving...')),
        saved: @js(__('Note saved')),
        failed: @js(__('Failed to save, tap to retry')),
      }[this.state];
    },
  }"
  x-effect="if (editing) { $nextTick(() => $refs.field && $refs.field.focus()) }"
  class="min-w-0"
>
  <p x-show="!editing && saved" x-cloak data-testid="cart-item-note-view-{{ $cartItemId }}"
    class="mt-1 flex items-start gap-1.5 rounded-md bg-orange-50 px-2 py-1 text-sm text-orange-950 dark:bg-orange-950/40 dark:text-orange-100">
    <x-heroicon-o-pencil class="mt-0.5 h-4 w-4 shrink-0" />
    <span class="min-w-0 break-words" x-text="saved"></span>
  </p>

  <div x-show="editing" x-cloak class="mt-1 space-y-1">
    <label class="sr-only" for="line-note-{{ $context }}-{{ $cartItemId }}">{{ __('Item note') }}</label>
    <div class="relative">
      <input id="line-note-{{ $context }}-{{ $cartItemId }}" x-ref="field" type="text" maxlength="100" autocomplete="off" enterkeyhint="done"
        data-testid="cart-item-note-{{ $cartItemId }}"
        placeholder="{{ __('Item note placeholder') }}"
        x-model="value"
        x-on:input="onInput()"
        x-on:blur="flush()"
        x-on:keydown.enter.prevent="flush(); editing = false"
        class="h-11 w-full rounded-lg border border-gray-300 bg-white pl-3 pr-11 text-sm focus:border-lakasir-primary-strong focus:outline-none focus:ring-1 focus:ring-lakasir-primary-strong dark:border-gray-600 dark:bg-gray-900" />
      <button type="button" x-show="value" x-cloak aria-label="{{ __('Clear note') }}"
        x-on:click="value = ''; flush(); $refs.field.focus()"
        class="absolute right-0 top-0 inline-flex h-11 w-11 items-center justify-center text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
        <x-heroicon-o-x-mark class="h-5 w-5" />
      </button>
    </div>
    <div class="flex items-center justify-between gap-2">
      <button type="button" role="status" data-testid="cart-item-note-status-{{ $cartItemId }}"
        x-text="statusText"
        x-on:click="if (state === 'failed') flush()"
        class="min-h-6 text-xs"
        :class="state === 'failed' ? 'font-semibold text-danger-700 underline dark:text-danger-400' : 'text-gray-600 dark:text-gray-300'"></button>
      <button type="button" x-on:click="flush(); editing = false"
        class="inline-flex h-11 items-center rounded-lg px-3 text-sm font-semibold text-lakasir-primary-strong hover:bg-orange-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-lakasir-primary-strong dark:text-lakasir-primary-soft dark:hover:bg-gray-800">
        {{ __('Done') }}
      </button>
    </div>
  </div>
</div>
