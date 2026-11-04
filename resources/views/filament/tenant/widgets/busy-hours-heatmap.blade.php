{{--
    Busy hours per day: a row per day (month for the year), a column per hour, a darker cell for more sales.
    Styles: resources/views/sales-card-styles.blade.php (.hm). Built by BusyHoursHeatmap.
--}}
<x-filament-widgets::widget class="hm">
    <section class="hm-box">
        <header class="hm-head">
            <b>{{ __('Busy hours per day') }}</b>
            <span>{{ $description }}</span>
        </header>

        @if ($empty)
            <p class="hm-empty">{{ __('No sales in this period') }}</p>
        @else
            <div class="hm-grid" style="--hm-cols: {{ count($hours) }}" role="table" aria-label="{{ __('Busy hours per day') }}">
                <div class="hm-corner"></div>
                @foreach ($hours as $hour)
                    <div class="hm-hour {{ $hour % 2 ? 'hm-hour--odd' : '' }}">{{ sprintf('%02d', $hour) }}</div>
                @endforeach

                @foreach ($rows as $row)
                    <div class="hm-label">{{ $row['label'] }}</div>
                    @foreach ($row['cells'] as $index => $cell)
                        <div class="hm-cell {{ $cell['count'] === 0 ? 'hm-cell--none' : '' }}"
                             style="--hm-level: {{ round($cell['level'], 3) }}"
                             title="{{ $row['label'] }} · {{ sprintf('%02d', $hours[$index]) }}.00: {{ $cell['count'] }} {{ mb_strtolower($unit) }}"><span>{{ $cell['count'] ?: '' }}</span></div>
                    @endforeach
                @endforeach
            </div>
            <footer class="hm-legend">
                <span>{{ __('Fewer') }}</span><i></i><span>{{ __('More') }} ({{ $max }})</span>
            </footer>
        @endif
    </section>
</x-filament-widgets::widget>
