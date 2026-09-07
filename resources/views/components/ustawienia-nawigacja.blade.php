@props(['aktywne' => null])

{{--
    Spis wszystkich ekranów ustawień, ten sam na każdym z nich.

    DLACZEGO TO POWSTAŁO
    W nawigacji jest JEDEN wpis „Ustawienia" i prowadzi na „Czytelność".
    Reszta ekranów była osiągalna wyłącznie przez odnośniki wpisywane ręcznie
    na dole poszczególnych stron — a te były przypisane losowo: „Czytelność"
    wymieniała trzy inne ekrany, „Prywatność" dwa, a „Profil", „Tematy",
    „Twoje dane" i „Bezpieczeństwo" ani jednego. Z tych czterech dało się
    wyjść tylko przyciskiem „wstecz" w przeglądarce.

    Dla osoby, która nie traktuje przeglądarki jak przedłużenia ręki, ślepy
    zaułek w ustawieniach kończy się tak samo jak zepsuty link: rezygnacją
    (docs/UX_50_PLUS.md).

    Lista jest tu JEDNA, wspólna dla wszystkich ekranów — nowy ekran ustawień
    dopisuje się w jednym miejscu i od razu widzą go wszystkie pozostałe.
    Bieżący ekran zostaje na liście jako zwykły tekst, nie znika: znikająca
    pozycja przesuwa resztę i za każdym wejściem układ wygląda inaczej.
--}}

@php
    // 'topics' → 'tags' (D-021): ekran „Twoje tematy" ustąpił „Twoim tagom".
    // Trasa `settings.topics` już nie istnieje — usunięta razem z Tematami
    // w etapie 4/5, więc ta lista jest jedynym, spójnym źródłem ekranów.
    $ekrany = [
        'profile' => ['settings.profile', 'Profil', 'Nazwa, zdjęcie, kilka słów o Tobie'],
        'accessibility' => ['settings.accessibility', 'Czytelność', 'Wielkość tekstu i kontrast'],
        'tags' => ['settings.tags', 'Tagi', 'Co Cię interesuje w kuchni'],
        'security' => ['settings.security', 'Bezpieczeństwo', 'Zmiana hasła, wylogowanie z innych urządzeń'],
        'two_factor' => ['settings.two_factor.edit', 'Weryfikacja dwuetapowa', 'Drugi krok przy logowaniu — kod z telefonu'],
        'privacy' => ['settings.privacy', 'Prywatność', 'Kto widzi Twoje treści, zablokowane osoby'],
        'data' => ['settings.data', 'Twoje dane', 'Pobranie danych i usunięcie konta'],
    ];
@endphp

<nav class="card ustawienia-nawigacja" aria-label="Wszystkie ustawienia">
    <h2 class="ustawienia-nawigacja-tytul">Wszystkie ustawienia</h2>

    <ul class="ustawienia-nawigacja-lista">
        @foreach($ekrany as $klucz => [$trasa, $nazwa, $opis])
            <li class="ustawienia-nawigacja-pozycja">
                @if($klucz === $aktywne)
                    {{-- Bieżący ekran nie jest odnośnikiem do samego siebie.
                         `aria-current` mówi to czytnikowi ekranu, a pogrubienie
                         osobie patrzącej — jedno nie zastępuje drugiego. --}}
                    <strong aria-current="page">{{ $nazwa }}</strong>
                    <span class="ustawienia-nawigacja-tu">(tu jesteś)</span>
                @else
                    <a href="{{ route($trasa) }}">{{ $nazwa }}</a>
                @endif
                <span class="ustawienia-nawigacja-opis">{{ $opis }}</span>
            </li>
        @endforeach
    </ul>
</nav>
