@php
  use Filament\Facades\Filament;
  use App\Features\{PaymentShortcutButton, SellingTax, Discount};

@endphp
<div class="">
  <div class="grid grid-cols-3 gap-x-4">
    <div class="col-span-2">
      {{ $this->table }}
    </div>
    <div class="fixed right-0 h-screen w-1/3 overflow-y-scroll pb-10">
      <div class="mt-4 h-screen space-y-2 px-4">
        <div class="flex items-center justify-between" x-data="fullscreen">
          <p class="text-xl font-semibold">{{ __('Orders details') }}</p>
          <div class="flex items-center">
            <div class="hidden items-center gap-x-2 xl:flex">
              <a href="/member/sellings"
                class="flex items-center justify-center gap-x-1 rounded-lg bg-gray-100 px-4 py-1 text-gray-500">
                <x-heroicon-o-arrow-left class="h-4 w-4 text-gray-500" />
                <p class="hidden lg:block">{{ __('Back') }} </p>
              </a>

              <button x-on:click="$dispatch('open-modal', {id: 'qr-scanner-modal'})" type="button"
                class="rounded-full p-2 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Scan with camera">
                <x-heroicon-o-qr-code class="h-8 w-8 text-gray-900 dark:text-gray-300" />
              </button>

            </div>
            <div class="gap-x-2">
              <x-filament::dropdown placement="top-start">
                <x-slot name="trigger">
                  <x-heroicon-o-ellipsis-vertical class="h-8 w-8 cursor-pointer text-gray-900 dark:text-gray-300" />
                </x-slot>

                <x-filament::dropdown.list>
                  <x-filament::dropdown.list.item x-on:mousedown="document.location.reload()">
                    <div class="flex gap-x-2">
                      <x-heroicon-m-arrow-path class="h-5 w-5 cursor-pointer text-gray-900 dark:text-gray-300" />
                      <p>{{ __('Reload') }} </p>
                    </div>
                  </x-filament::dropdown.list.item>

                  <x-filament::dropdown.list.item x-on:mousedown="requestFullscreen">
                    <div class="flex gap-x-2">
                      <x-heroicon-o-arrows-pointing-out
                        class="h-5 w-5 cursor-pointer text-gray-900 dark:text-gray-300" />
                      <p>{{ __('Fullscreen') }} </p>
                    </div>
                  </x-filament::dropdown.list.item>
                  <x-filament::dropdown.list.item>
                    <p class="flex gap-x-2" wire:confirm="Are you sure you want to clear all of the items?"
                      wire:click.prevent="clearCart">
                      <x-heroicon-o-trash class="h-5 w-5 cursor-pointer text-gray-900 dark:text-gray-300" />
                      <span>{{ __('Clear') }} </span>
                    </p>
                  </x-filament::dropdown.list.item>

                </x-filament::dropdown.list>
              </x-filament::dropdown>
            </div>
          </div>
        </div>
        <hr />
        <div class="hidden justify-between lg:flex">
          <p class="">{{ Filament::auth()->user()->cashier_name }}</p>
        </div>
        <div class="flex items-center justify-between">
          <p class="mb-2 hidden text-2xl font-semibold lg:block">{{ __('Current Orders') }}</p>
          <div class="flex gap-x-1"></div>
        </div>
        <div class="max-h-[35%] min-h-40 overflow-auto overflow-y-scroll" wire:loading.class="opacity-20"
          wire:target="addCart,reduceCart,deleteCart,addDiscountPricePerItem,addCartUsingScanner">
          @forelse($cartItems as $item)
            <div class="mb-2 rounded-lg border bg-white px-4 py-2 dark:border-gray-900 dark:bg-gray-900"
              id="{{ $item->id }}" key="{{ rand() }}">
              <div class="grid items-center space-x-3">
                <div class="flex justify-between">
                  <p class="font-semibold"> {{ $item->product->name }}</p>
                  <p class="font-semibold text-lakasir-primary">{{ $item->price_format_money }}</p>
                </div>
              </div>
              <div class="grid grid-cols-2 items-center space-y-2 py-2 text-right">
                <div class="col-span-2">
                  @feature(Discount::class)
                    <div class="mb-1 flex justify-end">
                      <x-filament::input.wrapper class="w-1/2">
                        <x-filament::input type="text" id="{{ $item->product->name }}-{{ $item->id }}"
                          value="{{ $item->discount_price == 0 ? '' : $item->discount_price }}"
                          wire:keyup.debounce.500ms="addDiscountPricePerItem({{ $item }}, parseFloat($event.target.value.replace(/,/g, '')))"
                          placeholder="{{ __('Discount') }}" class="w-1/2 text-right" inputMode="numeric"
                          x-mask:dynamic="$money($input)" />
                      </x-filament::input.wrapper>
                    </div>
                  @endfeature
                  @if ($item->discount_price && $item->discount_price > 0)
                    <p class="font-semibold text-lakasir-primary">{{ $item->final_price_format }}</p>
                  @endif
                </div>
              </div>
              <div class="flex h-8 space-x-3">
                <button class="rounded-lg !bg-lakasir-primary px-2 py-1"
                  wire:click.stop="addCart( {{ $item->product_id }} )" wire:loading.attr="disabled">
                  <x-heroicon-o-plus-small class="h-4 w-4 !text-white" />
                </button>
                <x-filament::input.wrapper class="w-20" x-data="cart">
                  <x-filament::input type="text"
                    id="{{ $item->product->name }}-{{ $item->id }}-qty-{{ rand() }}"
                    data-value="{{ $item->qty }}" value="{{ $item->qty }}"
                    x-on:keyup.debounce.500ms="(e) => add('{{ $item->product_id }}', e.target.value)"
                    placeholder="{{ __('Discount') }}" class="w-1/2 text-right" inputMode="numeric" />
                </x-filament::input.wrapper>
                <button class="rounded-lg !bg-gray-100 px-2 py-1"
                  x-on:click="$wire.reduceCart({{ $item->product_id }});" wire:loading.attr="disabled">
                  <x-heroicon-o-minus-small class="h-4 w-4 !text-green-900" />
                </button>
                <button class="rounded-lg !bg-danger-100 px-2 py-1" wire:click="deleteCart({{ $item->id }})"
                  wire:loading.attr="disabled">
                  <x-heroicon-o-trash class="h-4 w-4 !text-danger-900" />
                </button>
                <livewire:price-setting :cart-item="$item" key="{{ $item->id }}" />
              </div>
            </div>
          @empty
            <div
              class="flex h-40 items-center justify-center rounded-lg border bg-white dark:border-gray-900 dark:bg-gray-900">
              <x-heroicon-o-x-mark class="hidden h-10 w-10 text-gray-900 dark:text-white lg:block" />
              <p class="text-xl text-gray-600 dark:text-white lg:text-3xl">{{ __('No item') }}</p>
            </div>
          @endforelse
        </div>
        <div>
          <div
            class="w-full rounded-lg border bg-white px-4 py-2 text-gray-600 dark:border-gray-900 dark:bg-gray-900 dark:text-white">
            @include('filament.tenant.pages.cashier.detail')
          </div>
        </div>
        <div>
          <div
            class="w-full rounded-lg border bg-white px-4 py-2 text-gray-600 dark:border-gray-900 dark:bg-gray-900 dark:text-white">
            @include('filament.tenant.pages.cashier.total')
          </div>
        </div>
        <button class="w-full rounded-lg bg-lakasir-primary px-2 py-4 text-white"
          x-on:mousedown="$dispatch('open-modal', {id: 'proceed-the-payment'})">{{ __('Proceed to payment') }}</button>
      </div>
    </div>
  </div>
  {{-- modal --}}
  <x-filament::modal id="edit-detail" width="md">
    <x-slot name="heading">
      <span x-data="{ title: '{{ __('Edit detail') }}' }"
            x-on:open-modal.window="if ($event.detail?.id === 'edit-detail') { title = $event.detail?.title || '{{ __('Edit detail') }}'; }"
            x-text="title">
        {{ __('Edit detail') }}
      </span>
    </x-slot>
    <div x-data="{ activeField: '' }"
         x-on:open-modal.window="if ($event.detail?.id === 'edit-detail') { activeField = $event.detail?.inputId || ''; }"
         :class="activeField ? 'show-field-' + activeField : ''">
      <form wire:submit.prevent="storeCart">
        {{ $this->storeCartForm }}
        <div class="mt-4 flex justify-end">
          <x-filament::button type="submit">
            {{ __('Save') }}
          </x-filament::button>
        </div>
      </form>
    </div>
  </x-filament::modal>

  <script>
    function detail() {
      return {
        isTouchScreen: Boolean(typeof window !== 'undefined' && (('ontouchstart' in window) || (navigator.maxTouchPoints > 0) || (navigator.msMaxTouchPoints > 0))),
        payedMoney: 0,
        displayValue: '',
        selectedMethodId: null,

        get paymentMethods() {
          return (this.$wire && this.$wire.paymentMethods) ? this.$wire.paymentMethods : [];
        },

        get cartDetail() {
          return (this.$wire && this.$wire.cartDetail) ? this.$wire.cartDetail : {};
        },

        get subtotal() {
          return (this.$wire && this.$wire.total_price) ? (Number(this.$wire.total_price) || 0) : 0;
        },

        get selectedPaymentMethod() {
          const pms = this.paymentMethods;
          const currentId = this.selectedMethodId || (this.cartDetail ? this.cartDetail['payment_method_id'] : null);
          return pms.find(pm => pm.id == currentId) || pms[0] || null;
        },

        get isCash() {
          const pm = this.selectedPaymentMethod;
          if (!pm) return true;
          return Boolean(pm.is_cash) && !pm.name.toLowerCase().includes('qris');
        },

        get isQris() {
          const pm = this.selectedPaymentMethod;
          if (!pm) return false;
          return pm.name.toLowerCase().includes('qris') || Boolean(pm.is_wallet);
        },

        get moneyChanges() {
          if (this.isQris) return 0;
          const payed = Number(this.payedMoney) || 0;
          const total = Number(this.subtotal) || 0;
          return Math.max(0, payed - total);
        },

        get suggestions() {
          const total = Number(this.subtotal) || 0;
          if (total <= 0) return [10000, 20000, 50000];

          const list = [];
          const add = (v) => {
            const num = Math.round(Number(v));
            if (num > total && !list.includes(num)) {
              list.push(num);
            }
          };

          // Step rounding based on standard payment increments
          const steps = [1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000, 500000];
          for (let step of steps) {
            const next = Math.ceil(total / step) * step;
            add(next);
          }

          // Standard Indonesian banknotes
          const standardBanknotes = [10000, 20000, 50000, 100000, 200000, 500000, 1000000];
          for (let note of standardBanknotes) {
            add(note);
          }

          // Ensure at least 3 suggestions exist
          if (list.length < 3) {
            const highest = list.length > 0 ? Math.max(...list) : total;
            add(highest + 50000);
            add(highest + 100000);
          }

          list.sort((a, b) => a - b);
          return list.slice(0, 3);
        },

        formatMoney(val) {
          if (typeof window.moneyFormat === 'function') {
            return window.moneyFormat(val);
          }
          const num = Number(val) || 0;
          return (window.lakasirCurrency ? window.lakasirCurrency + ' ' : 'IDR ') + new Intl.NumberFormat('id-ID').format(num);
        },

        formatShortcut(val) {
          const num = Number(val) || 0;
          return new Intl.NumberFormat('id-ID').format(num);
        },

        formatDisplayMoney(val) {
          const num = Number(val) || 0;
          return new Intl.NumberFormat('id-ID').format(num);
        },

        init() {
          this.selectedMethodId = this.cartDetail ? this.cartDetail['payment_method_id'] : null;
          if (this.isQris) {
            this.setExactPayment();
          }
          window.addEventListener('open-modal', (e) => {
            if (e.detail?.id === 'proceed-the-payment') {
              this.payedMoney = 0;
              this.displayValue = '';
              if (this.$refs.payedMoney) {
                this.$refs.payedMoney.value = '';
              }
              if (this.isQris) {
                this.setExactPayment();
              }
            }
          });
        },

        selectPaymentMethod(pm) {
          this.selectedMethodId = pm.id;
          if (this.$wire) {
            this.$wire.set('cartDetail.payment_method_id', pm.id);
          }
          if (pm.name.toLowerCase().includes('qris') || pm.is_wallet || pm.is_debit) {
            this.setExactPayment();
          }
        },

        setExactPayment() {
          const total = Number(this.subtotal) || 0;
          this.payedMoney = total;
          this.displayValue = total > 0 ? total.toString() : '';
          if (this.$refs.payedMoney) {
            this.$refs.payedMoney.value = total > 0 ? this.formatDisplayMoney(total) : '';
          }
        },

        shortcut(number) {
          const num = Number(number) || 0;
          this.payedMoney = num;
          this.displayValue = num.toString();
          if (this.$refs.payedMoney) {
            this.$refs.payedMoney.value = this.formatDisplayMoney(num);
          }
        },

        onInput(event) {
          let raw = event.target.value || '';
          let clean = raw.replace(/\D/g, '');
          let num = parseInt(clean, 10) || 0;
          this.payedMoney = num;
          this.displayValue = num > 0 ? num.toString() : '';
          event.target.value = num > 0 ? this.formatDisplayMoney(num) : '';
        },

        append(number) {
          if (number === 'no_changes') {
            this.setExactPayment();
            return;
          }
          if (number === 'clear') {
            this.displayValue = '';
            this.payedMoney = 0;
            if (this.$refs.payedMoney) {
              this.$refs.payedMoney.value = '';
            }
            return;
          }
          if (number === 'backspace') {
            this.displayValue = this.displayValue.slice(0, -1);
            let num = parseInt(this.displayValue, 10) || 0;
            this.payedMoney = num;
            if (this.$refs.payedMoney) {
              this.$refs.payedMoney.value = num > 0 ? this.formatDisplayMoney(num) : '';
            }
            return;
          }
          if (number === '000') {
            if (this.displayValue && this.displayValue !== '0') {
              this.displayValue += '000';
            }
          } else if (number === '0') {
            if (this.displayValue && this.displayValue !== '0') {
              this.displayValue += '0';
            }
          } else {
            if (this.displayValue === '0') {
              this.displayValue = number.toString();
            } else {
              this.displayValue += number.toString();
            }
          }
          let num = parseInt(this.displayValue, 10) || 0;
          this.payedMoney = num;
          if (this.$refs.payedMoney) {
            this.$refs.payedMoney.value = num > 0 ? this.formatDisplayMoney(num) : '';
          }
        },

        submitPayment() {
          const payed = this.isQris ? Number(this.subtotal) : (Number(this.payedMoney) || 0);
          const changes = this.isQris ? 0 : Math.max(0, payed - Number(this.subtotal));
          const methodId = this.selectedMethodId || (this.selectedPaymentMethod ? this.selectedPaymentMethod.id : null);

          if (this.$wire) {
            this.$wire.set('cartDetail.payment_method_id', methodId);
            this.$wire.set('cartDetail.payed_money', payed);
            this.$wire.set('cartDetail.money_changes', changes);
            this.$wire.proceedThePayment();
          }
        }
      };
    }

    window.detail = detail;
    if (typeof Alpine !== 'undefined') {
      Alpine.data('detail', detail);
    } else {
      document.addEventListener('alpine:init', () => {
        Alpine.data('detail', detail);
      });
    }
  </script>

  <x-filament::modal id="proceed-the-payment" width="5xl">
    <div x-data="detail()" class="my-2 grid gap-x-6 md:grid-cols-2">
      <form x-on:submit.prevent="submitPayment" class="space-y-3">
        <input type="hidden" wire:model="cartDetail.payment_method_id" />
        <input type="hidden" wire:model="cartDetail.payed_money" />
        <input type="hidden" wire:model="cartDetail.money_changes" />

        {{-- Payment Method Tabs --}}
        <div>
          <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5 block">
            {{ __('Payment Method') }}
          </label>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <template x-for="paymentMethod in paymentMethods" :key="paymentMethod.id">
              <button
                type="button"
                x-on:click="selectPaymentMethod(paymentMethod)"
                class="flex items-center justify-center gap-1.5 rounded-lg border py-2 px-3 text-sm font-semibold transition-all hover:scale-[1.02]"
                :class="(selectedPaymentMethod && selectedPaymentMethod.id == paymentMethod.id) ?
                  'bg-lakasir-primary text-white border-lakasir-primary shadow-sm' :
                  'bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 border-gray-200 dark:border-gray-700'">
                <span x-text="paymentMethod.name"></span>
              </button>
            </template>
          </div>
        </div>

        {{-- Customer Name Input --}}
        <x-filament::input.wrapper>
          <x-slot name="prefix">
            {{ __('Customer') }}
          </x-slot>
          <x-filament::input type="text" wire:model.blur="cartDetail.customer_name" placeholder="{{ __('Customer Name (Optional)') }}" />
        </x-filament::input.wrapper>

        {{-- Due Date if Credit --}}
        <x-filament::input.wrapper
          x-show="paymentMethods.filter((pm) => pm.is_credit)[0]?.id == (selectedPaymentMethod ? selectedPaymentMethod.id : null)"
          :valid="!$errors->has('due_date')">
          <x-slot name="prefix">
            {{ __('Due date') }}
          </x-slot>
          <x-filament::input type="date" wire:model="cartDetail.due_date" />
        </x-filament::input.wrapper>

        {{-- Total & Changes Summary Section --}}
        <div>
          @include('filament.tenant.pages.cashier.total')
          <div class="flex justify-between mt-3">
            <p class="font-bold">{{ __('Money changes') }}</p>
            <p class="font-bold text-lakasir-primary" x-text="formatMoney(moneyChanges)"></p>
          </div>
        </div>

        {{-- CASH PAYMENT SECTION: Display Input, Smart Suggestions, & Numpad --}}
        <div x-show="isCash" class="space-y-3 pt-1">
          @error('payed_money')
            <span class="error text-danger-500 text-sm font-medium">{{ $message }}</span>
          @enderror

          {{-- Custom / Keyboard Amount Input --}}
          <div class="relative flex items-center">
            <input id="display"
              class="@error('payed_money') 'border-danger-500' @enderror w-full rounded-lg border border-gray-300 bg-white p-2.5 pr-10 text-right text-xl font-bold text-gray-900 focus:border-lakasir-primary focus:ring-1 focus:ring-lakasir-primary outline-none dark:bg-gray-900 dark:border-gray-700 dark:text-white"
              :disabled="isTouchScreen"
              x-on:input="onInput($event)"
              x-ref="payedMoney"
              placeholder="0"
              inputMode="numeric">
            <button type="button"
              x-show="displayValue && displayValue !== '0'"
              x-on:click="append('clear')"
              class="absolute right-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
              <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
            </button>
          </div>

          {{-- Smart Suggestion Buttons: [Uang Pas] + [Pecahan Terdekat] --}}
          <div class="grid grid-cols-4 gap-2">
            <button type="button"
              class="rounded-lg bg-orange-100 hover:bg-orange-200 active:bg-orange-300 dark:bg-orange-950/40 dark:hover:bg-orange-900/60 text-lakasir-primary font-bold py-2.5 px-1 text-sm border border-orange-200 dark:border-orange-800/50 transition-all text-center truncate shadow-xs"
              x-on:click="setExactPayment">
              {{ __('Uang Pas') }}
            </button>
            <template x-for="val in suggestions" :key="val">
              <button type="button"
                class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 font-semibold py-2.5 px-1 text-sm border border-gray-200 dark:border-gray-700 transition-all text-center truncate shadow-xs"
                x-on:click="shortcut(val)"
                x-text="formatShortcut(val)">
              </button>
            </template>
          </div>

          {{-- Numpad Calculator with Clear Spacing / Margin --}}
          <div class="mt-4 pt-3 border-t border-gray-200/80 dark:border-gray-700/80">
            <div class="grid grid-cols-3 gap-2">
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(7)">7</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(8)">8</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(9)">9</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(4)">4</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(5)">5</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(6)">6</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(1)">1</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(2)">2</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(3)">3</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-base font-bold transition-all shadow-xs" x-on:click="append('000')">000</button>
              <button type="button" class="rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 text-xl font-semibold transition-all shadow-xs" x-on:click="append(0)">0</button>
              <button type="button" class="flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 active:bg-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100 py-2.5 transition-all shadow-xs" x-on:click="append('backspace')">
                <x-filament::icon icon="heroicon-o-backspace" class="h-5 w-5 text-gray-600 dark:text-gray-300" />
              </button>
            </div>
          </div>
        </div>

        {{-- QRIS PAYMENT SECTION: Minimalist Clean Display --}}
        <div x-show="isQris" class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50/70 dark:bg-gray-800/40 p-5 text-center space-y-1">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Non-Cash (QRIS)') }}</p>
          <p class="text-2xl font-bold text-lakasir-primary" x-text="formatMoney(subtotal)"></p>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Nominal pas otomatis terpilih tanpa uang kembalian') }}</p>
        </div>

        {{-- Action Buttons: Pay & Close --}}
        <div class="flex gap-2 pt-2">
          <button wire:loading.attr="disabled" type="button" x-on:click="submitPayment"
            class="flex flex-1 items-center justify-center gap-x-2 rounded-lg bg-lakasir-primary p-3 text-base font-bold text-white hover:bg-[#ff6611] transition-all shadow-sm">
            <div wire:loading>
              <x-filament::loading-indicator class="h-5 w-5" />
            </div>
            {{ __('Pay it') }}
          </button>
          <button wire:click="dispatch('close-modal', {id: 'proceed-the-payment'});" type="button"
            class="flex flex-1 items-center justify-center gap-x-2 rounded-lg bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 p-3 text-base font-semibold text-gray-800 dark:text-gray-200 transition-all">
            {{ __('Close') }}
          </button>
        </div>
      </form>
      <div class="hidden max-h-[80vh] overflow-y-scroll md:block">
        @if ($errors->any())
          @foreach ($errors->all() as $error)
            <p class="error w-full text-center text-lg text-danger-500">{{ $error }}</p>
          @endforeach
        @endif
        @include('filament.tenant.pages.cashier.items')
      </div>
    </div>
  </x-filament::modal>
  <x-filament::modal id="success-modal" width="xl" :close-by-clicking-away="false" :close-by-escaping="false">
    <div x-data="{
        changesAmount: 0,
        init() {
          const update = (s) => {
            if (s && s.money_changes != null) {
              this.changesAmount = Number(s.money_changes) || 0;
            }
          };
          window.addEventListener('selling-created', (e) => {
            const s = e.detail?.selling || e.detail?.[0]?.selling || e.detail;
            update(s);
          });
          if (this.$wire) {
            this.$wire.on('selling-created', (data) => {
              const s = data?.selling || data?.[0]?.selling || data;
              update(s);
            });
          }
          window.addEventListener('open-modal', (e) => {
            if (e.detail?.id === 'success-modal' && e.detail?.money_changes != null) {
              this.changesAmount = Number(e.detail.money_changes) || 0;
            }
          });
        },
        format(val) {
          if (typeof window.moneyFormat === 'function') {
            return window.moneyFormat(val);
          }
          const num = Number(val) || 0;
          return (window.lakasirCurrency ? window.lakasirCurrency + ' ' : 'IDR ') + new Intl.NumberFormat('id-ID').format(num);
        }
      }"
      class="flex flex-col items-center justify-center">
      <x-heroicon-o-check-circle style="color: rgb(34 197 94); width: 200px" />
      <p class="">@lang('Success')</p>
      <p class="text-3xl font-bold">
        @lang('Change'):
        <span id="changes" x-text="format(changesAmount)"></span>
      </p>
    </div>
    <x-slot name="footer">
      <div class="grid grid-cols-2 gap-x-2">
        <x-filament::button icon="heroicon-m-printer" id="printReceiptButton" type="button" x-on:click.prevent.stop="handleCashierPrintReceipt">
          {{ __('Print') }}
        </x-filament::button>
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', {id: 'success-modal'})">
          {{ __('Close') }}
        </x-filament::button>
      </div>
    </x-slot>
  </x-filament::modal>
  <x-filament::modal id="modal-selected-table" width="md">
    <x-slot name="heading">
      <div class="flex items-center gap-2">
        <x-heroicon-o-table-cells class="h-5 w-5 text-lakasir-primary" />
        <span>{{ __('Choose or Enter Table') }}</span>
      </div>
    </x-slot>

    <div class="space-y-4">
      {{-- Quick table selection grid --}}
      <div>
        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2 block">
          {{ __('Quick Table Select') }}
        </label>
        <div class="grid grid-cols-3 gap-2.5 max-h-56 overflow-y-auto pr-1">
          {{-- Option: No table / Take away --}}
          <button type="button"
            wire:click="selectTable(null)"
            class="flex items-center justify-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-medium transition-all hover:scale-[1.02] {{ empty($cartDetail['table_id']) ? 'border-lakasir-primary bg-lakasir-primary text-white shadow-sm' : 'border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
            <x-heroicon-o-shopping-bag class="h-4 w-4" />
            <span>{{ __('Take Away') }}</span>
          </button>

          {{-- Existing registered tables --}}
          @if($tableOption)
            @foreach ($tableOption as $table)
              <button type="button"
                wire:click="selectTable({{ $table->id }})"
                class="flex items-center justify-center rounded-lg border px-3 py-2.5 text-sm font-medium transition-all hover:scale-[1.02] {{ ($cartDetail['table_id'] ?? null) == $table->id ? 'border-lakasir-primary bg-lakasir-primary text-white shadow-sm font-bold' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                {{ $table->number }}
              </button>
            @endforeach
          @endif
        </div>
      </div>

      {{-- Custom table input section --}}
      <div class="pt-3 border-t border-gray-200 dark:border-gray-700" x-data="{ customNumber: '' }">
        <label class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5 block">
          {{ __('Or Enter Custom Table Name') }}
        </label>
        <div class="flex gap-2">
          <input type="text"
            x-model="customNumber"
            x-on:keydown.enter.prevent="if (customNumber.trim()) { $wire.saveCustomTable(customNumber.trim()); customNumber = ''; }"
            placeholder="{{ __('e.g. VIP-01, Table 12, Bar 2...') }}"
            class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-lakasir-primary focus:ring-1 focus:ring-lakasir-primary outline-none" />
          <x-filament::button type="button"
            x-on:click="if (customNumber.trim()) { $wire.saveCustomTable(customNumber.trim()); customNumber = ''; }">
            {{ __('Save') }}
          </x-filament::button>
        </div>
      </div>
    </div>
  </x-filament::modal>

  <x-filament::modal id="qr-scanner-modal" width="lg" :close-by-clicking-away="false"
    x-on:close-modal.window="if ($event.detail.id === 'qr-scanner-modal') { window.stopScanner(); }">
    <x-slot name="heading">
      {{ __('Scan Barcode with Camera') }}
    </x-slot>

    {{-- Main container with Alpine.js state management --}}
    <div x-data="{ isLoading: false }" x-ref="scannerContainer">

      {{-- Loading spinner (hidden by default) --}}
      <div x-show="isLoading" class="flex min-h-[300px] flex-col items-center justify-center text-center">
        <svg class="h-16 w-16 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none"
          viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
          </circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
          </path>
        </svg>
        <p class="mt-4 text-lg font-medium text-gray-600 dark:text-gray-300">
          Processing product...
        </p>
      </div>

      {{-- QR Scanner container (hidden when loading) --}}
      <div x-show="!isLoading">
        <div wire:ignore id="qr-reader" class="w-full"></div>
      </div>

    </div>

    <x-slot name="footer">
      <x-filament::button color="gray" x-on:click="$dispatch('close-modal', {id: 'qr-scanner-modal'})">
        {{ __('Close') }}
      </x-filament::button>
    </x-slot>
  </x-filament::modal>

  <style>
    /* html5-qrcode library button & control styling */
    #qr-reader__dashboard_section_csr button,
    #qr-reader__dashboard_section_swaplink {
      background-color: #f97316 !important;
      border: none !important;
      color: #fff !important;
      padding: 0.5rem 1.25rem !important;
      border-radius: 0.5rem !important;
      font-weight: 600 !important;
      font-size: 0.875rem !important;
      cursor: pointer !important;
      transition: background-color 0.2s ease !important;
      box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1) !important;
      margin: 0.25rem 0 !important;
    }

    #qr-reader__dashboard_section_csr button:hover,
    #qr-reader__dashboard_section_swaplink:hover {
      background-color: #ea580c !important;
    }

    #qr-reader__dashboard_section {
      padding: 0.75rem !important;
      margin-top: 0.5rem !important;
    }

    #qr-reader__dashboard_section_csr {
      display: flex !important;
      flex-direction: column !important;
      gap: 0.5rem !important;
      align-items: stretch !important;
    }

    #qr-reader__dashboard_section_csr select {
      width: 100% !important;
      padding: 0.5rem 0.75rem !important;
      border-radius: 0.5rem !important;
      border: 1px solid #d1d5db !important;
      background-color: #fff !important;
      color: #111827 !important;
      font-size: 0.875rem !important;
      outline: none !important;
      transition: border-color 0.2s ease !important;
    }

    #qr-reader__dashboard_section_csr select:focus {
      border-color: #f97316 !important;
      box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25) !important;
    }

    #qr-reader__scan_region {
      min-height: 200px !important;
      border-radius: 0.5rem !important;
      overflow: hidden !important;
    }

    #qr-reader {
      border: none !important;
    }

    #qr-reader__dashboard {
      padding: 0.5rem !important;
    }

    #qr-reader__status_line {
      font-size: 0.875rem !important;
      padding: 0.25rem 0.5rem !important;
    }

    /* Dark mode support */
    .dark #qr-reader__dashboard_section_csr select {
      background-color: #111827 !important;
      color: #f3f4f6 !important;
      border-color: #374151 !important;
    }

    .dark #qr-reader__dashboard_section_csr select:focus {
      border-color: #f97316 !important;
      box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25) !important;
    }

    .dark #qr-reader__dashboard_section {
      background-color: #1f2937 !important;
      border-color: #374151 !important;
    }

    .dark #qr-reader__dashboard {
      background-color: #111827 !important;
    }

    .dark #qr-reader__status_line {
      color: #d1d5db !important;
    }

    .dark #qr-reader img[alt="Info icon"] {
      filter: invert(1) !important;
    }

    /* Fix dark mode swap link: transparent background keeps text readable */
    .dark #qr-reader__dashboard_section_swaplink {
      background-color: transparent !important;
      color: #f97316 !important;
      text-decoration: underline !important;
      box-shadow: none !important;
      padding: 0 !important;
    }

    /* Selective field visibility in Edit Detail modal */
    /* When a show-field-* class is present, hide all field wrappers */
    div[class*="show-field-"] .fi-fo-field-wrp {
      display: none !important;
    }
    /* Then show only the field matching activeField */
    div.show-field-memberSelect .fi-fo-field-wrp[data-field="memberSelect"],
    div.show-field-memberSelect .fi-fo-field-wrp:has([data-field="memberSelect"]),
    div.show-field-customerNameInput .fi-fo-field-wrp[data-field="customerNameInput"],
    div.show-field-customerNameInput .fi-fo-field-wrp:has([data-field="customerNameInput"]),
    div.show-field-noteInput .fi-fo-field-wrp[data-field="noteInput"],
    div.show-field-noteInput .fi-fo-field-wrp:has([data-field="noteInput"]),
    div.show-field-voucherInput .fi-fo-field-wrp[data-field="voucherInput"],
    div.show-field-voucherInput .fi-fo-field-wrp:has([data-field="voucherInput"]),
    div.show-field-discountInput .fi-fo-field-wrp[data-field="discountInput"],
    div.show-field-discountInput .fi-fo-field-wrp:has([data-field="discountInput"]) {
      display: block !important;
    }
  </style>




</div>

@script()
  <script>
    window.lakasirCurrency = @js($currency);
    window.lakasirLocale = @js($locale);
    window.lakasirReceiptLogo = @js(\App\Models\Tenants\Setting::get('receipt_logo'));
    window.lakasirReceiptPaperWidth = @js(\App\Models\Tenants\Setting::get('receipt_paper_width', '58'));
    let selling = null;
    $wire.on('selling-created', (event) => {
      selling = event.selling || event[0]?.selling || event;
      window._lakasirLastSelling = selling;
      const changesVal = (selling && selling.money_changes != null) ? Number(selling.money_changes) : 0;

      $wire.dispatch('close-modal', {
        id: 'proceed-the-payment'
      });
      $wire.dispatch('open-modal', {
        id: 'success-modal',
        money_changes: changesVal
      });

      window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'proceed-the-payment' } }));
      window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'success-modal', money_changes: changesVal } }));
      window.dispatchEvent(new CustomEvent('selling-created', { detail: { selling: selling } }));
    });
    function formatReceiptMoney(number, showCurrency = false) {
      const num = Number(number) || 0;
      const formatted = new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      }).format(num);

      return showCurrency ? ('Rp ' + formatted) : formatted;
    }

    async function handleCashierPrintReceipt(event) {
      if (window._lakasirHandlingCashierReceipt || window._lakasirIsPrintingNow) {
        console.warn('Cashier receipt print already in progress, skipping duplicate invocation.');
        return;
      }
      window._lakasirHandlingCashierReceipt = true;

      const printReceiptBtn = document.getElementById("printReceiptButton");
      if (printReceiptBtn) {
        printReceiptBtn.style.pointerEvents = 'none';
        printReceiptBtn.style.opacity = '0.6';
      }

      const activeSelling = selling || window._lakasirLastSelling;
      let about = @js($about);
      const printerData = getPrinter();
      const showCurrency = Boolean(printerData?.show_currency ?? @js(\App\Models\Tenants\Setting::get('receipt_show_currency', false)));

      try {
        if (!printerData) {
          new FilamentNotification()
            .title('@lang('You should choose the printer first in printer setting')')
            .danger()
            .actions([
              new FilamentNotificationAction('Setting')
              .icon('heroicon-o-cog-6-tooth')
              .button()
              .url('/member/printer'),
            ])
            .send();
          return;
        }

        if (!activeSelling) {
          new FilamentNotification()
            .title('Data transaksi tidak ditemukan untuk dicetak.')
            .warning()
            .send();
          return;
        }

        const printer = new (window.Printer || Printer)(printerData);
        let printerAction = printer;
        const logo = printerData.logo || window.lakasirReceiptLogo;
        if (logo && printerData.driver !== 'bluetooth') {
          await printerAction.image(logo, printerData.paper_width || window.lakasirReceiptPaperWidth || 58);
        }
        printerAction.font('a');
        if (about != undefined && about != null) {
          printerAction.size(1)
            .align('center')
            .text(about.shop_name || '')
            .size(0)
            .text(about.shop_location || '');
          if (printerData.header != undefined && printerData.header) {
            printerAction.text(printerData.header);
          }
        }

        if (activeSelling.daily_order_number || activeSelling.formatted_daily_order_number) {
          const orderLabel = activeSelling.formatted_daily_order_number || ('Order #' + String(activeSelling.daily_order_number).padStart(3, '0'));
          printerAction
            .align('center')
            .size(1, 1)
            .style('bold')
            .text(orderLabel)
            .style('normal')
            .size(0, 0);
        }

        printerAction.align('left')
          .text('-------------------------------');

        printerAction.table(['@lang('Cashier')', activeSelling.user?.name || activeSelling.user?.cashier_name || '']);
        if (activeSelling.table != undefined && activeSelling.table != null) {
          printerAction.table(['@lang('Table')', String(activeSelling.table.number || '')]);
        }
        const paymentMethodName = activeSelling.payment_method?.name || activeSelling.paymentMethod?.name || 'Cash';
        printerAction.table(['@lang('Payment method')', paymentMethodName]);

        let customerDisplay = '@lang('General')';
        if (activeSelling.customer_name) {
          customerDisplay = activeSelling.customer_name;
        } else if (activeSelling.member && activeSelling.member.name) {
          customerDisplay = activeSelling.member.name;
        }
        printerAction.table(['@lang('Customer')', customerDisplay]);

        printerAction.text('-------------------------------');
        const details = activeSelling.selling_details || activeSelling.sellingDetails || [];
        if (Array.isArray(details)) {
          details.forEach(sellingDetail => {
            let price = Number(sellingDetail.price) || 0;
            let qty = Number(sellingDetail.qty) || 1;
            let unitPrice = price / qty;
            let productName = sellingDetail.product?.name || '';
            printerAction.table([productName, formatReceiptMoney(unitPrice, showCurrency) + ' x ' + qty.toString()]);
            if (sellingDetail.discount_price > 0) {
              price = price - Number(sellingDetail.discount_price);
              printerAction
                .align('right')
                .text(`(${formatReceiptMoney(sellingDetail.discount_price, showCurrency)})`);
            }
            printerAction
              .align('right')
              .text(formatReceiptMoney(price, showCurrency))
              .align('left');
          });
        }
        printerAction.text('-------------------------------');
        if ("@js(feature(SellingTax::class))" == 'true') {
          printerAction.table(['@lang('Tax')', `${activeSelling.tax ?? 0}%`])
            .table(['@lang('Tax price')', formatReceiptMoney(activeSelling.tax_price ?? 0, showCurrency)]);
        }
        printerAction
          .table(['@lang('Subtotal')', formatReceiptMoney(activeSelling.total_price ?? 0, showCurrency)]);
        if ("@js(feature(Discount::class))" == 'true') {
          printerAction
            .table(['@lang('Discount')',
              `(${formatReceiptMoney((activeSelling.total_discount_per_item ?? 0) + (activeSelling.discount_price ?? 0), showCurrency)})`
            ]);
        }
        printerAction
          .table(['@lang('Total price')', formatReceiptMoney(activeSelling.grand_total_price ?? 0, showCurrency)])
          .text('-------------------------------')
          .table(['@lang('Payed money')', formatReceiptMoney(activeSelling.payed_money ?? 0, showCurrency)])
          .table(['@lang('Change')', formatReceiptMoney(activeSelling.money_changes ?? 0, showCurrency)])
          .align('center');

        if (printerData.footer != undefined && printerData.footer) {
          printerAction.text(printerData.footer);
        }

        await printerAction
          .cut()
          .print();
      } catch (error) {
        console.error(error);
        if (typeof FilamentNotification !== 'undefined') {
          new FilamentNotification()
            .title('@lang('Gagal mencetak'): ' + (error.message || error))
            .danger()
            .send();
        }
      } finally {
        setTimeout(() => {
          window._lakasirHandlingCashierReceipt = false;
          if (printReceiptBtn) {
            printReceiptBtn.style.pointerEvents = '';
            printReceiptBtn.style.opacity = '';
          }
        }, 3000);
      }
    }

    window.handleCashierPrintReceipt = handleCashierPrintReceipt;
    const printReceiptBtn = document.getElementById("printReceiptButton");
    if (printReceiptBtn) {
      printReceiptBtn.onclick = handleCashierPrintReceipt;
    }

    Alpine.data('fullscreen', () => {
      return {
        isFullscreen: false,
        requestFullscreen() {
          if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
            isFullscreen = true;
          } else {
            document.exitFullscreen();
            isFullscreen = false;
          }
        }
      }
    });

    Alpine.data('cart', () => {
      return {
        add: (productId, amount) => {
          $wire.addCart(productId, {
            amount: amount ?? 0
          })
          console.log(productId, amount)
        }
      }
    })

    function handleOpenModal(event) {
      let data = event.detail || event;
      if (!data) return;

      // Initialize QR scanner when modal opens
      if (data.id === 'qr-scanner-modal') {
        // Create scanner instance only once (singleton pattern)
        if (!html5QrcodeScanner) {
          html5QrcodeScanner = new Html5QrcodeScanner(
            "qr-reader",
            {
              fps: 10,
              qrbox: { width: 300, height: 200 },
              rememberLastUsedCamera: true
            },
            false // verbose mode disabled
          );
        }
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
      }

      if (data.inputId != undefined) {
        let inputId = data.inputId;
        index = data.index;

        const setupInput = () => {
          input = document.querySelector('[data-field="' + inputId + '"]');
          if (input) {
            const focusEl = input.querySelector('input, textarea, select, [contenteditable]') || input;
            if (focusEl && typeof focusEl.focus === 'function') {
              focusEl.focus();
            }
          }
        };

        setupInput();
        setTimeout(setupInput, 100);
        setTimeout(setupInput, 300);
      }

      modalOpened = true;
    }

    function handleCloseModal(event) {
      input = undefined;
      modalOpened = false;
    }

    window.addEventListener('open-modal', handleOpenModal);
    $wire.on('open-modal', handleOpenModal);

    window.addEventListener('close-modal', handleCloseModal);
    $wire.on('close-modal', handleCloseModal);

    // QR Scanner global variables and functions
    let html5QrcodeScanner = null;
    let isScanningEnabled = true;

    /**
     * Handles successful barcode/QR code scan
     * @param {string} decodedText - The decoded string from the QR code or barcode
     */
    async function onScanSuccess(decodedText, decodedResult) {
      if (!isScanningEnabled) return;

      // Find Alpine.js component for state management
      const readerElement = document.getElementById('qr-reader');
      if (!readerElement) {
        console.error('Scanner reader element not found!');
        return;
      }

      const alpineContainer = readerElement.closest('[x-ref="scannerContainer"]');
      if (!alpineContainer || !alpineContainer._x_dataStack) {
        console.error('Could not find the Alpine.js scanner container.');
        return;
      }
      const alpineComponent = alpineContainer._x_dataStack[0];

      // Disable scanning and show loading spinner
      isScanningEnabled = false;
      alpineComponent.isLoading = true;

      console.log(`Scan result: ${decodedText}`);

      // Process product and wait for Livewire to complete
      await $wire.call('addCartUsingScanner', decodedText);

      // Hide loading spinner
      alpineComponent.isLoading = false;

      // Show success notification
      new FilamentNotification()
        .title('Product added')
        .success()
        .duration(3000)
        .send();

      // Re-enable scanning after cooldown period
      setTimeout(() => {
        isScanningEnabled = true;
      }, 1000);
    }

    /**
     * Handles scan failure (empty implementation)
     */
    function onScanFailure(error) {
      // Intentionally empty - failures are handled silently
    }

    /**
     * Safely stops the camera scanner
     */
    window.stopScanner = () => {
      if (html5QrcodeScanner && html5QrcodeScanner.getState() === Html5QrcodeScannerState.SCANNING) {
        html5QrcodeScanner.clear().then(() => {
          console.log('QR Code scanner stopped successfully.');
        }).catch(err => {
          // Ignore errors during rapid closing
        });
      }
    };
    // Physical barcode scanner support (keyboard input)
    document.addEventListener('keypress', (event) => {
      if (modalOpened || !scannerEnabled) {
        return;
      }

      if (barcodeTimeout) {
        clearTimeout(barcodeTimeout);
      }

      if (event.key === 'Enter') {
        console.log('Barcode scanned:', barcodeData);
        $wire.addCartUsingScanner(barcodeData);

        barcodeData = '';
        scannerEnabled = false;

        // Re-enable scanner after processing
        setTimeout(() => {
          scannerEnabled = true;
        }, 1000);
      } else {
        barcodeData += event.key;
      }

      // Clear barcode data if no input for 500ms
      barcodeTimeout = setTimeout(() => {
        barcodeData = '';
      }, 500);
    });
  </script>
@endscript
