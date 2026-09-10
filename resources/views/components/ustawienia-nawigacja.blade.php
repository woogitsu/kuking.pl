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

    GDZIE TEN KOMPONENT MA STAĆ NA STRONIE (zgłoszenie właściciela, Full HD)

    Na 1920×1080 formularz zajmował środek, a cała prawa połowa ekranu stała
    pusta, podczas gdy ten spis leżał POD formularzem — poza pierwszym
    ekranem. Poprawka NIE jest osobnym CSS-em dla ustawień: każda podstrona
    wkłada ten komponent do `<x-slot:rail>`, czyli do tej samej „prawej
    szyny", którą od dawna mają Start i Szukaj (`.app-rail` w `app.css`,
    slot `$rail` w `components/layout.blade.php`). Jedna reguła układu,
    jeden próg szerokości, żadnego nowego CSS-u specyficznego dla ustawień.

    `.app-rail` włącza się dopiero od 80rem (próg zmierzony pod kątem trzeciej
    kolumny w ogóle, nie tylko tego spisu — patrz komentarz przy nim
    w `app.css`): poniżej tego progu szyna ląduje POD treścią, w jednej
    kolumnie — telefon i tablet widzą DOKŁADNIE to, co widziały przed tą
    zmianą, łącznie z kolejnością.

    KOLEJNOŚĆ W DOM SIĘ NIE ZMIENIA. `<main>` (formularz) renderuje się
    w layoucie PRZED `<aside class="app-rail">` (ten spis) niezależnie od
    tego, w którym miejscu pliku podstrony stoi `<x-slot:rail>` — Blade
    wstawia zawartość slotu tam, gdzie ten slot stoi w LAYOUCIE, nie tam,
    gdzie stoi w źródle podstrony. Dlatego `Tab` i czytnik ekranu idą tak
    samo jak wcześniej: formularz, potem ten spis. Do przestawienia NIE użyto
    CSS-owego `order` — ono zmienia to, co widać, ale nie kolejność fokusu,
    i naprawiałoby wygląd kosztem klawiatury.
--}}

@php
    // 'topics' → 'tags' (D-021): ekran „Twoje tematy" ustąpił „Twoim tagom".
    // Trasa `settings.topics` już nie istnieje — usunięta razem z Tematami
    // w etapie 4/5, więc ta lista jest jedynym, spójnym źródłem ekranów.
    $ekrany = [
        'profile' => ['settings.profile', 'Profil', 'Imię, nazwa użytkownika, kilka słów o Tobie'],
        // Zdjęcie profilowe stoi na liście OSOBNO, zaraz po profilu, bo od tej
        // zmiany ma własny, krótki ekran — było szóstym polem w formularzu
        // profilu, czyli funkcją, do której trzeba się było przewinąć.
        'avatar' => ['settings.avatar', 'Zdjęcie profilowe', 'Dodaj, zmień albo usuń swoje zdjęcie'],
        'accessibility' => ['settings.accessibility', 'Czytelność', 'Wielkość tekstu i kontrast'],
        'tags' => ['settings.tags', 'Tagi', 'Co Cię interesuje w kuchni'],
        'email' => ['settings.email', 'Adres e-mail', 'Zobacz i zmień adres, na który przychodzi nowe hasło'],
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
