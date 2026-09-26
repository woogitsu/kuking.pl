{{--
    Pusty stan „Świeżo z Kuking" (issue #1807) — nigdy bez wyjścia.

    Rozróżnia dwie sytuacje, bo każda ma inne wyjście:
      * „nic nowego" — w serwisie naprawdę nic nie ma do pokazania;
      * „część ukrywasz" — widz sam kogoś zablokował (od #1810 także ukrył),
        więc pusta lista może być skutkiem JEGO decyzji. Wtedy prowadzimy
        do listy, na której może to cofnąć (AGENTS.md §8: jawne polecenie
        widza zawsze z listą do cofnięcia).

    `ileUkrywasz` liczy wyłącznie blokady ZROBIONE przez widza
    (`DiscoverFeed::ileUkrywa()`). Blokada, którą ktoś odciął widza, nie jest
    jego decyzją i ten ekran nie może jej zdradzać — dlatego też nie ma tu
    liczby, tylko zdanie.

    Trzy drogi dalej, każda z tekstem: lista ukrytych (gdy dotyczy), tablica
    „kuKINGi na dziś" (stoi na tym samym ekranie, obok albo nad listą)
    i „Dodaj wpis". Gość zamiast „Dodaj wpis" dostaje zaproszenie do konta —
    `/dodaj/zdjecie` wymaga logowania, a martwy odnośnik to nie wyjście.
--}}
@props(['ileUkrywasz' => 0])
<div class="empty-state" data-pusty-stan-odkrywania="{{ $ileUkrywasz > 0 ? 'ukrywasz' : 'nic-nowego' }}">
    <img src="{{ asset('icons/kuking-mark.svg') }}" alt="" aria-hidden="true" width="72" height="72">
    @if($ileUkrywasz > 0)
        <p class="empty-state-title">Nic więcej do pokazania</p>
        <p class="empty-state-opis">
            Część osób ukrywasz, więc ich wpisów tu nie widać. To widzisz tylko Ty.
            Możesz to zmienić na liście ukrytych osób.
        </p>
    @else
        {{-- Tekst z `docs/brand/COPY_STYLE.md` §6 „pusty feed" — dosłownie
             (pilnuje `OdkrywaniePustyStanTest`). --}}
        <p class="empty-state-title">Jeszcze nic tu nie ma</p>
        <p class="empty-state-opis">Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.</p>
    @endif
    <div class="flex flex-wrap gap-3 justify-center">
        @auth
            <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj wpis</a>
        @else
            <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto i pokaż swoje</a>
        @endauth
        @if($ileUkrywasz > 0)
            <a class="btn btn-secondary" href="{{ route('settings.privacy') }}#zablokowane">Zobacz, kogo ukrywasz</a>
        @endif
        <a class="btn btn-secondary" href="#kuking-na-dzis">Przejdź do tablicy na dziś</a>
    </div>
</div>
