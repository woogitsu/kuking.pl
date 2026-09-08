{{--
    Pusty stan.

    Nigdy „Brak danych”. Zawsze: co tu będzie, dlaczego jest pusto i jeden
    wyraźny przycisk z TEKSTEM. Pusty stan nie może zawstydzać kogoś, kto
    jeszcze nic nie opublikował.
--}}
@props(['title', 'action' => null, 'href' => null, 'mark' => true])
<div class="empty-state">
    @if($mark)
        <img src="{{ asset('icons/kuking-mark.svg') }}" alt="" aria-hidden="true" width="72" height="72">
    @endif
    <p class="empty-state-title">{{ $title }}</p>
    @if(trim($slot) !== '')
        {{-- `empty-state-opis` zamiast utility `max-w-[34rem]`: sufit miary
             wiersza i stonowany kolor to jedna decyzja systemu projektowego,
             więc mają jedno miejsce. Do 8 września kolor brał się z kontenera
             i padał także na tytuł. --}}
        <p class="empty-state-opis">{{ $slot }}</p>
    @endif
    @if($action && $href)
        <a class="btn btn-primary" href="{{ $href }}">{{ $action }}</a>
    @endif
</div>
