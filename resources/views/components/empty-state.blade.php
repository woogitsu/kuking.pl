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
        <p class="max-w-[34rem]">{{ $slot }}</p>
    @endif
    @if($action && $href)
        <a class="btn btn-primary" href="{{ $href }}">{{ $action }}</a>
    @endif
</div>
