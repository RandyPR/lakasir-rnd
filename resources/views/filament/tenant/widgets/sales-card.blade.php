{{--
    The compact sales card: net sales - cost of goods (HPP) = profit, with the number of sales.
    Wide cards show one thin strip; narrow ones (a phone) show the roomier phone version. The switch is a container
    query, so it follows the width of the card and not of the window (the sidebar takes space too).
    Styles: resources/views/sales-card-styles.blade.php. $card is built by BuildsSalesCard.
--}}
<x-filament-widgets::widget class="sc">
    @if ($card)
        @php
            $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '–'];
            $today = $card['kind'] === 'today';
        @endphp

        {{-- Wide: one strip --}}
        <section class="sc-strip {{ $today ? 'sc-strip--today' : '' }} sc-wide">
            <header class="sc-head">
                <b>{{ $card['title'] }}</b>
                <span class="sc-head-meta">
                    @if ($card['meta'])
                        <span>{{ $card['meta'] }}</span>
                    @endif
                    @if ($today && $card['date'])
                        <span>{{ $card['date'] }}@if ($card['as_of']) · {{ $card['as_of'] }}@endif</span>
                    @endif
                    @if ($today && $card['sales'] && $card['discount'] !== null)
                        <span>{{ __('Discount') }} <strong>{{ $card['discount'] }}</strong></span>
                    @endif
                </span>
            </header>

            <div class="sc-body">
                @if ($card['revenue'])
                    <div class="sc-seg">
                        <div class="sc-label">{{ __('Net sales') }} <i>({{ __('after discount') }})</i></div>
                        <div class="sc-value">{{ $card['omzet'] }}</div>
                        <div class="sc-sub">
                            @if ($card['omzet_delta']['dir'] !== 'none')
                                <span class="sc-{{ $card['omzet_delta']['dir'] }}">{{ $arrow[$card['omzet_delta']['dir']] }} {{ $card['omzet_delta']['text'] }}</span>
                            @endif
                            @if ($today && $card['vs'] && $card['omzet_delta']['dir'] !== 'none')
                                {{ $card['vs'] }}
                            @endif
                        </div>
                    </div>
                    <div class="sc-op" aria-hidden="true">−</div>
                    <div class="sc-seg">
                        <div class="sc-label">{{ __('Cost of goods') }} <i>({{ __('cost price of goods sold') }})</i></div>
                        <div class="sc-value">{{ $card['hpp'] }}</div>
                        <div class="sc-sub">
                            @if ($card['hpp_share'])
                                {{ $card['hpp_share'] }} {{ __('of net sales') }}
                            @endif
                        </div>
                    </div>
                    <div class="sc-op" aria-hidden="true">=</div>
                    <div class="sc-seg sc-seg--profit">
                        <div class="sc-label">{{ __('Profit') }} <i>({{ __('after cost of goods') }})</i></div>
                        <div class="sc-value">{{ $card['laba'] }}</div>
                        <div class="sc-sub">
                            @if ($card['laba_delta']['dir'] !== 'none')
                                <span class="sc-{{ $card['laba_delta']['dir'] }}">{{ $arrow[$card['laba_delta']['dir']] }} {{ $card['laba_delta']['text'] }}</span>
                            @endif
                            @if ($card['margin'])
                                @if ($card['laba_delta']['dir'] !== 'none') · @endif{{ __('margin :percent', ['percent' => $card['margin']]) }}
                            @endif
                        </div>
                    </div>
                @endif

                @if ($card['sales'])
                    @if ($card['revenue'])
                        <div class="sc-rule" aria-hidden="true"></div>
                    @endif
                    <div class="sc-seg sc-seg--count">
                        <div class="sc-label">{{ __('Transactions') }}</div>
                        <div class="sc-value">{{ $card['transactions'] }}</div>
                        <div class="sc-sub">
                            @if ($card['transactions_delta']['dir'] !== 'none')
                                <span class="sc-{{ $card['transactions_delta']['dir'] }}">{{ $arrow[$card['transactions_delta']['dir']] }} {{ $card['transactions_delta']['text'] }}</span> ·
                            @endif
                            @if ($card['revenue'])
                                {{ __('average :amount', ['amount' => $card['average']]) }}
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            @if ($card['warning'] && $card['warning']['count'] > 0)
                <div class="sc-alert">
                    <b>⚠ {{ __('Sold without a cost price') }}:</b>
                    {{ implode(', ', $card['warning']['names']) }}@if ($card['warning']['count'] > count($card['warning']['names'])) (+{{ $card['warning']['count'] - count($card['warning']['names']) }})@endif
                    — {{ __('The profit shown is too high') }}.
                </div>
            @endif
        </section>

        {{-- Narrow: the roomy phone version --}}
        <section class="sc-phone {{ $today ? 'sc-phone--today' : '' }}">
            <div class="sc-top">
                <b>{{ $card['title'] }}</b>
                <span>
                    @if ($today && $card['date'])
                        {{ $card['date'] }}<span class="sc-hide-small">@if ($card['as_of']) · {{ $card['as_of'] }}@endif</span>
                    @elseif ($card['meta'])
                        {{ $card['meta'] }}
                    @endif
                </span>
            </div>

            @if ($card['revenue'] && $today)
                <div class="sc-p-label">{{ __('Net sales') }} <i>({{ __('after discount') }})</i></div>
                <div class="sc-p-hero">{{ $card['omzet'] }}</div>
                <div class="sc-p-delta">
                    @if ($card['omzet_delta']['dir'] !== 'none')
                        <span class="sc-{{ $card['omzet_delta']['dir'] }}">{{ $arrow[$card['omzet_delta']['dir']] }} {{ $card['omzet_delta']['text'] }}</span>
                    @endif
                    @if ($card['omzet_delta']['dir'] !== 'none')
                        {{ $card['vs'] }}
                    @endif
                </div>
                <div class="sc-p-two">
                    <div>
                        <div class="sc-p-k">{{ __('Cost of goods') }}</div>
                        <div class="sc-p-v">{{ $card['hpp'] }}</div>
                        <div class="sc-p-s">@if ($card['hpp_share']){{ $card['hpp_share'] }} {{ __('of net sales') }}@endif</div>
                    </div>
                    <div class="sc-p-profit">
                        <div class="sc-p-k">
                            {{ __('Profit') }}
                            @if ($card['laba_delta']['dir'] !== 'none')
                                <span class="sc-{{ $card['laba_delta']['dir'] }}">{{ $arrow[$card['laba_delta']['dir']] }} {{ $card['laba_delta']['text'] }}</span>
                            @endif
                        </div>
                        <div class="sc-p-v">{{ $card['laba'] }}</div>
                        <div class="sc-p-s">@if ($card['margin']){{ __('margin :percent', ['percent' => $card['margin']]) }}@endif</div>
                    </div>
                </div>
            @elseif ($card['revenue'])
                <div class="sc-p-rows">
                    <div class="sc-p-row">
                        <span>{{ __('Net sales') }}</span><b>{{ $card['omzet'] }}</b>
                        <em>@if ($card['omzet_delta']['dir'] !== 'none')<span class="sc-{{ $card['omzet_delta']['dir'] }}">{{ $arrow[$card['omzet_delta']['dir']] }} {{ $card['omzet_delta']['text'] }}</span>@endif</em>
                    </div>
                    <div class="sc-p-row">
                        <span>− {{ __('Cost of goods') }}</span><b>{{ $card['hpp'] }}</b>
                        <em>{{ $card['hpp_share'] }}</em>
                    </div>
                    <div class="sc-p-row sc-p-row--profit">
                        <span>= {{ __('Profit') }}</span><b>{{ $card['laba'] }}</b>
                        <em>@if ($card['laba_delta']['dir'] !== 'none')<span class="sc-{{ $card['laba_delta']['dir'] }}">{{ $arrow[$card['laba_delta']['dir']] }} {{ $card['laba_delta']['text'] }}</span>@endif</em>
                    </div>
                </div>
            @endif

            @if ($card['sales'])
                <div class="sc-p-foot">
                    {{ __('Transactions') }} {{ $card['transactions'] }}
                    @if ($today && $card['discount'] !== null)
                        · {{ __('Discount') }} {{ $card['discount'] }}
                    @elseif ($card['revenue'])
                        · {{ __('average :amount', ['amount' => $card['average']]) }}
                    @endif
                </div>
            @endif

            @if ($card['warning'] && $card['warning']['count'] > 0)
                <div class="sc-alert">
                    <b>⚠ {{ __('Sold without a cost price') }}:</b>
                    {{ implode(', ', $card['warning']['names']) }}@if ($card['warning']['count'] > count($card['warning']['names'])) (+{{ $card['warning']['count'] - count($card['warning']['names']) }})@endif
                </div>
            @endif
        </section>
    @endif
</x-filament-widgets::widget>
