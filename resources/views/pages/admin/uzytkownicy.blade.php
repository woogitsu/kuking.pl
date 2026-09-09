{{--
    Konta użytkowników — panel moderacji.

    TO JEST EKRAN DO PATRZENIA. Nie ma tu ani jednego przycisku, który
    zmienia czyjeś konto — uzasadnienie każdej z tych decyzji stoi w nagłówku
    `App\Http\Controllers\Admin\UzytkownicyController`. W skrócie: rolę nadaje
    wyłącznie `kuking:nadaj-role` z powłoki (D-039), a zawieszenie i blokada
    idą przez zgłoszenie, bo tam zapada POWÓD i od tamtej decyzji przysługuje
    odwołanie (DSA art. 20).

    ADRES E-MAIL NA LIŚCIE JEST W MASCE (`j***@wp.pl`), a w całości dopiero
    na karcie konta. To nie jest ostrożność na wyrost:

      * na liście widać go DWADZIEŚCIA PIĘĆ naraz, przy jednym spojrzeniu
        na cudzy ekran albo na zrzucie ekranu wklejonym do zgłoszenia;
      * moderator patrząc na listę odpowiada na pytanie „które to konto",
        a do tego wystarczy pierwsza litera i domena — `wp.pl` kontra `gmail.com`
        rozpoznaje się od razu (ten sam wzorzec i ta sama klasa
        `App\Support\AdresEmail`, której używa list ostrzegawczy o zmianie adresu);
      * pełny adres jest potrzebny wtedy, gdy naprawdę zajmujemy się JEDNĄ
        osobą — a wejście na kartę zostawia wpis w `audit_log`. Gdyby lista
        pokazywała wszystko w całości, dziennik odpowiadałby na pytanie
        „kto oglądał moje dane" mniej dokładnie niż dziś.

    DZIAŁA BEZ JAVASCRIPTU (AGENTS.md §5): szukanie i filtry to zwykły
    formularz `GET`, sortowanie to odnośniki w nagłówkach kolumn,
    stronicowanie to odnośniki Laravela. Na tej stronie nie ma ani jednego
    `<script>`.
--}}
@php
    /**
     * Adres tej samej listy z innym sortowaniem.
     *
     * Zachowuje WSZYSTKIE pozostałe parametry (szukanie, filtry), bo inaczej
     * kliknięcie w nagłówek kolumny kasowałoby to, czego moderator przed
     * chwilą szukał. `page` świadomie ODPADA: po zmianie sortowania strona
     * siódma pokazuje zupełnie inne konta, więc wracamy na pierwszą.
     */
    $adresSortowania = function (string $klucz) use ($sortuj, $kierunek): string {
        $parametry = request()->query();
        unset($parametry['page']);
        $parametry['sortuj'] = $klucz;
        $parametry['kierunek'] = ($sortuj === $klucz && $kierunek === 'desc') ? 'asc' : 'desc';

        return route('admin.users', $parametry);
    };

    /** Adres listy z wybraną zakładką stanu konta. */
    $adresStanu = function (string $stan): string {
        $parametry = request()->query();
        unset($parametry['page']);
        $parametry['status'] = $stan;

        return route('admin.users', $parametry);
    };

    /** `aria-sort` dla czytnika ekranu — strzałka obok napisu jest dla oka. */
    $ariaSort = fn (string $klucz): string => $sortuj !== $klucz
        ? 'none'
        : ($kierunek === 'desc' ? 'descending' : 'ascending');

    $strzalka = fn (string $klucz): string => $sortuj !== $klucz
        ? ''
        : ($kierunek === 'desc' ? '↓' : '↑');
@endphp

<x-layout title="Użytkownicy — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Użytkownicy" />

    <h1>Użytkownicy</h1>

    <p class="meta">
        Lista kont do wglądu. Roli nie zmienia się stąd ani znikąd w przeglądarce —
        robi to polecenie <span class="kod-do-przepisania">kuking:nadaj-role</span> z powłoki.
        Zawieszenie i blokada zapadają przy <a href="{{ route('admin.reports') }}">zgłoszeniu</a>,
        razem z powodem, od którego wolno się odwołać.
    </p>

    <nav class="tabs" aria-label="Stan konta">
        <a class="tab" href="{{ $adresStanu('wszystkie') }}"
           @if($filtry['status'] === 'wszystkie') aria-current="page" @endif>Wszystkie ({{ $liczniki['wszystkie'] }})</a>
        @foreach(\App\Models\User::ETYKIETY_STATUSU as $wartosc => $etykieta)
            <a class="tab" href="{{ $adresStanu($wartosc) }}"
               @if($filtry['status'] === $wartosc) aria-current="page" @endif>{{ $etykieta }} ({{ $liczniki[$wartosc] }})</a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('admin.users') }}" class="filtry-kont">
        {{-- Sortowanie i zakładka stanu przeżywają wyszukiwanie. Bez tych
             dwóch pól szukanie cicho wracałoby do ustawień domyślnych. --}}
        <input type="hidden" name="sortuj" value="{{ $sortuj }}">
        <input type="hidden" name="kierunek" value="{{ $kierunek }}">
        <input type="hidden" name="status" value="{{ $filtry['status'] }}">

        <x-field
            name="szukaj"
            label="Szukaj konta"
            :value="$filtry['szukaj']"
            help="Nazwa widoczna, nazwa konta albo adres e-mail. Wystarczy fragment."
        />

        <div class="filtr-data">
            <x-field name="od" label="Zarejestrowane od" type="date" :value="request()->query('od')" />
        </div>

        <div class="filtr-data">
            <x-field name="do" label="Zarejestrowane do" type="date" :value="request()->query('do')" />
        </div>

        <div class="filtry-kont-akcje">
            <label class="choice">
                <input type="checkbox" name="bez_wpisow" value="1" @checked($filtry['bez_wpisow'])>
                Tylko konta bez wpisów
            </label>

            <button type="submit" class="btn btn-primary">Pokaż</button>

            @if($filtry['szukaj'] !== '' || $filtry['bez_wpisow'] || request()->query('od') || request()->query('do'))
                <a class="btn btn-secondary" href="{{ route('admin.users') }}">Wyczyść</a>
            @endif
        </div>
    </form>

    {{-- „Kto przyszedł dzisiaj" to pytanie o powitanie, nie o podejrzenie.
         Zdanie stoi tu świadomie: przy przejściu grupy z Garnek.pl będą dni
         z kilkunastoma rejestracjami z jednego łącza (koło gospodyń, biblioteka,
         jedno domowe Wi-Fi) i nikt nie ma prawa czytać tego jako sygnału. --}}
    <p class="meta">
        Filtr po dacie służy do witania nowych osób. Kilkanaście kont z jednego dnia
        to zwykle jedna grupa, która przyszła razem.
    </p>

    @if($uzytkownicy->total() === 0)
        <p class="card">
            @if($filtry['szukaj'] !== '')
                Nie znaleźliśmy konta pasującego do „{{ $filtry['szukaj'] }}”. Spróbuj krótszego fragmentu
                nazwy albo adresu.
            @else
                Tu nic nie ma.
            @endif
        </p>
    @else
        {{-- `tabindex="0"` — kontener przewijany musi dać się złapać
             klawiaturą (WCAG 2.1.1). `role="region"` z podpisem, żeby czytnik
             ekranu powiedział, co to za obszar. --}}
        <div class="tabela-kont-przewijanie" tabindex="0" role="region" aria-label="Lista kont">
            <table class="tabela-kont">
                <caption class="visually-hidden">
                    Konta użytkowników, {{ $uzytkownicy->total() }} pozycji, strona
                    {{ $uzytkownicy->currentPage() }} z {{ $uzytkownicy->lastPage() }}.
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="naglowek-staly">Osoba</span></th>
                        <th scope="col"><span class="naglowek-staly">Adres e-mail</span></th>
                        <th scope="col" aria-sort="{{ $ariaSort('rejestracja') }}">
                            <a href="{{ $adresSortowania('rejestracja') }}">Zarejestrowane {{ $strzalka('rejestracja') }}</a>
                        </th>
                        <th scope="col"><span class="naglowek-staly">Stan konta</span></th>
                        <th scope="col"><span class="naglowek-staly">Rola</span></th>
                        <th scope="col" aria-sort="{{ $ariaSort('wpisy') }}">
                            <a href="{{ $adresSortowania('wpisy') }}">Wpisy {{ $strzalka('wpisy') }}</a>
                        </th>
                        <th scope="col" aria-sort="{{ $ariaSort('aktywnosc') }}">
                            <a href="{{ $adresSortowania('aktywnosc') }}">Ostatnio tutaj {{ $strzalka('aktywnosc') }}</a>
                        </th>
                        <th scope="col"><span class="naglowek-staly">Dwa etapy</span></th>
                        <th scope="col"><span class="naglowek-staly">Karta</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($uzytkownicy as $uzytkownik)
                        <tr>
                            <td class="kolumna-osoba">
                                {{ $uzytkownik->displayName() }}
                                @if($uzytkownik->profile)
                                    {{-- ODNOŚNIK DO PROFILU PUBLICZNEGO TYLKO WTEDY, GDY TEN PROFIL
                                         ISTNIEJE DLA KOGOKOLWIEK. Konto zamknięte (zablokowane,
                                         w trakcie usuwania, wymazane) nie jest widoczne jako osoba
                                         — `User::jestWidocznyJakoOsoba()` — więc odnośnik
                                         prowadziłby moderatora prosto w 403. --}}
                                    @if($uzytkownik->jestWidocznyJakoOsoba())
                                        <a class="drobne" href="{{ route('profile.show', $uzytkownik->profile->username) }}">
                                            &commat;{{ $uzytkownik->profile->username }}
                                        </a>
                                    @else
                                        <span class="drobne">&commat;{{ $uzytkownik->profile->username }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                {{-- Konto zanonimizowane NIE MA JUŻ ADRESU, który cokolwiek znaczy:
                                     `EraseAccountData` wstawia w to miejsce adres techniczny.
                                     Pokazywanie go tutaj sugerowałoby, że dane wciąż są (D-022). --}}
                                @if($uzytkownik->isErased())
                                    <span class="drobne">dane wymazane</span>
                                @else
                                    {{ \App\Support\AdresEmail::maska($uzytkownik->email) }}
                                @endif
                            </td>
                            <td>{{ \App\Support\Czas::data($uzytkownik->created_at, 'j F Y') }}</td>
                            <td>
                                <span class="stan-konta {{ [
                                    \App\Models\User::STATUS_ACTIVE => 'stan-konta-aktywne',
                                    \App\Models\User::STATUS_SUSPENDED => 'stan-konta-uwaga',
                                    \App\Models\User::STATUS_PENDING_DELETE => 'stan-konta-uwaga',
                                    \App\Models\User::STATUS_BANNED => 'stan-konta-ciezki',
                                    \App\Models\User::STATUS_ERASED => 'stan-konta-ciezki',
                                ][$uzytkownik->status] ?? '' }}">{{ $uzytkownik->statusLabel() }}</span>
                                @if($uzytkownik->status_expires_at)
                                    <span class="drobne">do {{ \App\Support\Czas::data($uzytkownik->status_expires_at, 'j F Y') }}</span>
                                @endif
                            </td>
                            <td>{{ $uzytkownik->roleLabel() }}</td>
                            <td>{{ $uzytkownik->wpisow_count }}</td>
                            <td>
                                @if($uzytkownik->ostatnio_widziany_at)
                                    {{ \App\Support\Czas::data($uzytkownik->ostatnio_widziany_at, 'j F Y') }}
                                @else
                                    <span class="drobne">nigdy</span>
                                @endif
                            </td>
                            <td>{{ $uzytkownik->hasTwoFactorConfirmed() ? 'Tak' : 'Nie' }}</td>
                            <td>
                                {{-- Nazwa osoby w treści odnośnika, nie samo „Otwórz": dziewięć
                                     jednakowych „Otwórz" pod rząd jest dla czytnika ekranu listą
                                     bez znaczenia. Widoczny napis zostaje krótki. --}}
                                <a href="{{ route('admin.users.show', $uzytkownik) }}">
                                    Otwórz<span class="visually-hidden"> kartę konta {{ $uzytkownik->displayName() }}</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6">{{ $uzytkownicy->links() }}</div>
    @endif
</x-layout>
