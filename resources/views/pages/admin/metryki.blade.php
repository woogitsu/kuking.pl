{{--
    Metryki doboru bez profilowania (issue #1814, D-283).

    Same agregaty z istniejących tabel (`App\Domain\Analytics\MetrykiDoboru`):
    bez nazw osób, bez odnośników do wpisów, bez logu wyświetleń, bez ukryć
    i reakcji. Progi pochodzą z `kuking.metryki` i są materiałem do decyzji
    właściciela o regułach doboru (D-275) — ekran niczego nie przełącza.

    KOMPOZYCJA PANELU (#492, #581)
    Ekran powstał (#1814) po porcie panelu do marki i został w układzie sprzed
    niego. Trzy rzeczy, które trzeba było przenieść:
     - uwagi pod kartami („Rozmowa o rankingu ma sens dopiero przy wszystkich
       trzech naraz”, wyjaśnienie wskaźnika zastępczego) szły w `<p class="meta">`,
       czyli drobnym szarym piśmie 16 px, choć to jest treść, którą
       administrator czyta, żeby dobrze odczytać liczby. Teraz `.panel-liczby`
       (18 px razy skala tekstu, tylko wyciszone kolorem);
     - lista progów to główny wynik ekranu — dostaje odstępy zamiast
       wypunktowania z domyślnymi wcięciami przeglądarki;
     - tabela „Różnych autorów dziennie” była gołym `<table>` bez obwódek,
       bez nagłówka odróżnionego od danych i bez podpisu dla czytnika ekranu.
       Teraz `.tabela-dni` (marka-panel.css): dzień jest nagłówkiem wiersza,
       liczba stoi po prawej, tekst może się łamać, więc przy 320 px i dużym
       piśmie tabela nie wypycha strony w bok.
    Ekran nie ma formularza, więc ma wyłącznie karty treści (ROLE_KART.md).
--}}
@php
    $procent = fn (?float $p): string => $p === null ? 'za mało danych' : number_format($p, 1, ',', ' ').'%';
    $progi = $m['progi'];
    $srednia = $m['autorzy_dziennie']['srednia_28_dni'];
    $bez = $m['bez_pierwszej_strony_7_dni'];
@endphp
<x-layout title="Metryki doboru — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Metryki doboru" />

    <h1>Metryki doboru</h1>

    <p>Liczby z tabel, które już istnieją. Nie ma tu nazw osób ani wpisów, logu wyświetleń,
        ukryć ani reakcji „Smakowicie wygląda”. Przekroczenie progu niczego samo nie zmienia —
        to powód, żeby wrócić do rozmowy o regułach doboru (D-275, D-283).</p>

    <section class="card" aria-labelledby="metryki-progi" data-metryka="progi">
        <h2 class="mt-0 text-title-sm" id="metryki-progi">Progi rewizji</h2>
        <ul class="stack-tight list-none p-0">
            <li data-prog="autorzy" data-osiagniety="{{ $srednia >= $progi['autorow_dziennie'] ? 'tak' : 'nie' }}">
                Różnych autorów dziennie (średnia z 28 dni): <strong>{{ number_format($srednia, 1, ',', ' ') }}</strong>
                — próg {{ $progi['autorow_dziennie'] }}: {{ $srednia >= $progi['autorow_dziennie'] ? 'osiągnięty' : 'jeszcze nie' }}.
            </li>
            <li data-prog="tygodnie" data-osiagniety="{{ $m['tygodnie_danych'] >= $progi['tygodni_danych'] ? 'tak' : 'nie' }}">
                Pełnych tygodni danych: <strong>{{ $m['tygodnie_danych'] }}</strong>
                — próg {{ $progi['tygodni_danych'] }}: {{ $m['tygodnie_danych'] >= $progi['tygodni_danych'] ? 'osiągnięty' : 'jeszcze nie' }}.
            </li>
            <li data-prog="pierwsza-strona" data-osiagniety="{{ $bez['procent'] !== null && $bez['procent'] > $progi['odsetek_bez_pierwszej_strony'] ? 'tak' : 'nie' }}">
                Autorów praktycznie bez pierwszej strony „Świeżo z <x-kuking-word />”: <strong>{{ $procent($bez['procent']) }}</strong>
                — próg powyżej {{ number_format($progi['odsetek_bez_pierwszej_strony'], 0, ',', ' ') }}%:
                {{ $bez['procent'] !== null && $bez['procent'] > $progi['odsetek_bez_pierwszej_strony'] ? 'przekroczony' : 'nie' }}.
            </li>
        </ul>
        <p class="panel-liczby">Rozmowa o rankingu ma sens dopiero przy wszystkich trzech naraz.</p>
    </section>

    <section class="card" aria-labelledby="metryki-pierwsza-strona" data-metryka="pierwsza-strona">
        <h2 class="mt-0 text-title-sm" id="metryki-pierwsza-strona">Pierwsza strona „Świeżo z <x-kuking-word />”</h2>
        <p>
            Autorów z publicznym wpisem sprzed 1–8 dni: {{ $bez['mianownik'] }}.
            Ich wpisy stały na pierwszej stronie łącznie krócej niż {{ $bez['prog_minut'] }} minut u
            <strong>{{ $bez['licznik'] }}</strong> z nich ({{ $procent($bez['procent']) }}).
        </p>
        <p class="panel-liczby">Wskaźnik zastępczy bez logu wyświetleń (D-283): pierwsza strona to najnowszy wpis
            każdej z {{ $bez['miejsc_na_stronie'] }} osób, które publikowały ostatnio, więc czas na niej
            wynika z samych godzin publikacji.</p>
    </section>

    <section class="card" aria-labelledby="metryki-ludzie" data-metryka="ludzie">
        <h2 class="mt-0 text-title-sm" id="metryki-ludzie">Pierwsze wpisy i powroty</h2>
        @php($odp = $m['pierwsze_wpisy_z_odpowiedzia_24h'])
        @php($pon = $m['autorzy_ponownie_28_dni'])
        <p>Pierwsze wpisy z odpowiedzią człowieka w 24 godziny (ostatnie 30 dni):
            <strong>{{ $procent($odp['procent']) }}</strong> ({{ $odp['licznik'] }} z {{ $odp['mianownik'] }}).</p>
        <p>Autorzy, którzy opublikowali ponownie w 28 dni od pierwszego wpisu:
            <strong>{{ $procent($pon['procent']) }}</strong> ({{ $pon['licznik'] }} z {{ $pon['mianownik'] }}).</p>
    </section>

    <section class="card" aria-labelledby="metryki-rownosc" data-metryka="rownosc">
        <h2 class="mt-0 text-title-sm" id="metryki-rownosc">Równość autorów i tagi (ostatnie 30 dni)</h2>
        @php($top = $m['udzial_najaktywniejszych_10_procent'])
        @php($tagi = $m['publiczne_z_tagiem'])
        <p>{{ $top['najaktywniejszych'] }} najaktywniejszych z {{ $top['autorow'] }} autorów (10%) napisało
            <strong>{{ $procent($top['procent']) }}</strong> z {{ $top['wpisow'] }} publicznych wpisów.</p>
        <p>Publiczne wpisy z co najmniej jednym tagiem: <strong>{{ $procent($tagi['procent']) }}</strong>
            ({{ $tagi['licznik'] }} z {{ $tagi['mianownik'] }}; od {{ number_format($progi['publiczne_z_tagiem'], 0, ',', ' ') }}% wolno ukrywać tagi).</p>
    </section>

    <section class="card" aria-labelledby="metryki-dni" data-metryka="dni">
        <h2 class="mt-0 text-title-sm" id="metryki-dni">Różnych autorów dziennie</h2>
        <table class="tabela-dni">
            <caption class="visually-hidden">Liczba różnych autorów publicznych wpisów w kolejnych dniach, od najnowszego.</caption>
            <thead><tr><th scope="col">Dzień</th><th scope="col">Autorów</th></tr></thead>
            <tbody>
                @foreach(array_reverse($m['autorzy_dziennie']['dni'], true) as $dzien => $ile)
                    <tr><th scope="row">{{ \App\Support\Czas::data(\Carbon\CarbonImmutable::parse($dzien, \App\Support\Czas::strefa()), 'j F Y') }}</th><td>{{ $ile }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layout>
