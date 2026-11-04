{{-- The day picker of the dashboard card: previous day, the date, next day, and a way back to today. --}}
<div class="sc-pick">
    <button type="button" class="sc-pick-btn" wire:click="previousDay" aria-label="{{ __('Previous day') }}">‹</button>
    <b>{{ $card['title'] }}</b>
    <input type="date" class="sc-pick-date" value="{{ $picker['day'] }}" max="{{ $picker['max'] }}"
           wire:change="$set('day', $event.target.value)" aria-label="{{ __('Pick a day') }}">
    <button type="button" class="sc-pick-btn" wire:click="nextDay" @disabled($picker['is_today']) aria-label="{{ __('Next day') }}">›</button>
    @unless ($picker['is_today'])
        <button type="button" class="sc-pick-today" wire:click="today">{{ __('Back to today') }}</button>
    @endunless
</div>
