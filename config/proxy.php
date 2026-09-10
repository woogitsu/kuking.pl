<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Zaufanie do nagłówków od proxy (ustalenie W7-01 / SEC-01)
|--------------------------------------------------------------------------
|
| To jest konfiguracja INFRASTRUKTURY, nie produktu — dlatego stoi tutaj,
| a nie w `config/kuking.php` (tamten plik trzyma reguły produktu i sam to
| o sobie mówi w nagłówku).
|
| Cały mechanizm opisuje komentarz klasy
| `App\Http\Middleware\NormalizeForwardedFor`. Tutaj jest tylko liczba
| i sposób, w jaki się ją MIERZY — bo wpisanie jej z pamięci albo „na oko"
| jest dokładnie tym błędem, przed którym ten plik ma chronić.
|
*/

return [

    /*
     * ILE WPISÓW W `X-Forwarded-For` DOPISUJE NASZA WŁASNA INFRASTRUKTURA.
     *
     * Nie „ile mamy proxy" i nie „ile przeskoków robi pakiet" — dokładnie
     * tyle, ile POZYCJI w tym nagłówku pojawia się bez udziału klienta.
     * Każde proxy dopisuje na KONIEC listy adres tego, od kogo dostało
     * żądanie. Klient może dopisać dowolnie dużo wpisów, ale zawsze
     * NA POCZĄTKU — więc liczenie od prawej strony jest jedyną operacją
     * na tym nagłówku, której klient nie potrafi przesunąć.
     *
     * Przy `1` adresem klienta jest OSTATNI wpis nagłówka, przy `2`
     * przedostatni, i tak dalej. `0` znaczy „nie ufaj temu nagłówkowi
     * wcale" — wtedy liczy się wyłącznie adres połączenia TCP.
     *
     * JAK TO ZMIERZYĆ, ZAMIAST ZGADYWAĆ (Blok B krok 6
     * z `docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`):
     *
     *   1. Wejść na produkcyjny adres przez Cloudflare zwykłą przeglądarką
     *      albo `curl` BEZ własnego `X-Forwarded-For`.
     *   2. Sprawdzić, ile wpisów ma nagłówek, który dotarł do aplikacji.
     *   3. Ta liczba to jest ta wartość.
     *
     * CZEGO NIE ROBIĆ: nie podnosić tej liczby „z zapasem". Za MAŁA
     * wartość jest niegroźna — aplikacja zobaczy adres własnego proxy,
     * czyli jeden wspólny adres dla wielu osób: limity zrobią się zbyt
     * ostre, ale NIKT nie podszyje się pod cudzy adres. Za DUŻA wartość
     * przesuwa odczyt w obszar wypełniany przez klienta i przywraca
     * dokładnie tę dziurę, którą ten plik zamyka.
     *
     * DOMYŚLNIE 1, bo to jest minimum zgodne z każdą topologią, w której
     * przed aplikacją stoi cokolwiek: samo Cloudflare dopisuje adres
     * odwiedzającego tuż przed originem. Czy brzeg Railway dopisuje DRUGI
     * wpis (adres Cloudflare), z kontenera rozstrzygnąć się nie da —
     * dokumentacja Railway nie wspomina o tym nagłówku ani słowem
     * (`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md` §6). Do czasu pomiaru
     * zostaje wartość bezpieczna, nie wygodna.
     */
    'zaufane_przeskoki' => (int) env('KUKING_ZAUFANE_PRZESKOKI', 1),

    /*
     * DODATKOWE HOSTY, POD KTÓRYMI WOLNO ODPYTYWAĆ SERWIS (ustalenie S2, D-071).
     *
     * Pełna lista dozwolonych hostów żyje w `App\Support\ZaufaneHosty`
     * i tam stoi uzasadnienie każdego wpisu. Tutaj jest tylko ZAWÓR: rzeczy,
     * których nie da się przewidzieć z repozytorium.
     *
     * DOMYŚLNIE PUSTE — I TO JEST WARUNEK, NIE PREFERENCJA. Serwis musi
     * wstawać bez tej zmiennej: gdyby jej brak blokował ruch, każdy deploy
     * na środowisko, w którym ktoś zapomniał ją ustawić, kończyłby się
     * niedostępnym serwisem. Lista domyślna (kanoniczny host, host
     * z `APP_URL`, host healthchecku Railwaya, pętla zwrotna) jest
     * kompletna dla produkcji, staginu, preview i uruchomienia lokalnego.
     *
     * KIEDY TEGO UŻYĆ — jeden realny scenariusz: Railway zmienia host,
     * z którego odpytuje `/health`, i deploy zaczyna padać na „healthcheck
     * failed with status 400". Naprawa przez kod wymagałaby WDROŻENIA,
     * a wdrożenie stoi właśnie na tym healthchecku. Wtedy w panelu Railwaya:
     *
     *     KUKING_ZAUFANE_HOSTY=nowy-host.railway.app
     *
     * Kilka hostów rozdziela się przecinkiem. Wartości są nazwami hostów,
     * NIE wyrażeniami regularnymi i nie adresami z protokołem — `kuking.pl`,
     * nie `https://kuking.pl` i nie `*.kuking.pl`. Zakotwiczenie wzorca
     * robi `ZaufaneHosty::wzorce()`, więc gwiazdka wpisana tutaj nie zadziała
     * jako wieloznacznik, tylko jako znak w nazwie hosta.
     */
    'dodatkowe_hosty' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KUKING_ZAUFANE_HOSTY', '')),
    ), static fn (string $host): bool => $host !== '')),

];
