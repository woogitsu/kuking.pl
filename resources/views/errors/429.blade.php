@php
    // Laravel podaje w nagłówku `Retry-After` liczbę SEKUND. Dla człowieka
    // „za 300 s" nie znaczy nic, więc zaokrąglamy w górę do pełnych minut —
    // w górę, bo obietnica „za 1 minutę" złamana o 20 sekund jest gorsza
    // niż uczciwe „za 2 minuty".
    $sekundy = null;

    if (isset($exception) && method_exists($exception, 'getHeaders')) {
        $sekundy = $exception->getHeaders()['Retry-After'] ?? null;
    }

    $minuty = is_numeric($sekundy) ? max(1, (int) ceil(((int) $sekundy) / 60)) : null;
@endphp

{{--
    429 — za dużo prób (issue #81).

    Najczęściej trafia tu osoba, która trzy razy wpisała hasło z pamięci
    i za czwartym razem zobaczyłaby po angielsku „Too Many Requests".
    Tekst ma powiedzieć jedno: to minie samo i nic się nie stało.
--}}
<x-layout title="Za dużo prób" :noindex="true">
    <h1>Za dużo prób</h1>

    <p class="mb-5">
        To samo działanie powtórzyło się kilka razy pod rząd, więc Kuking robi
        krótką przerwę.
        @if($minuty)
            Spróbuj ponownie za {{ $minuty }} min.
        @else
            Spróbuj ponownie za kilka minut.
        @endif
    </p>

    <p class="mb-5">
        Nic się nie zepsuło i nic nie przepadło — ta przerwa mija sama.
        Klikanie „odśwież" jej nie skróci.
    </p>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ auth()->check() ? route('home') : route('landing') }}">Strona główna</a>
        <a class="btn btn-quiet" href="{{ route('help') }}">Pomoc</a>
    </div>
</x-layout>
