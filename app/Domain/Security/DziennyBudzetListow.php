<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Logging\KanalyAlarmowe;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ile listów z linkiem do logowania wolno jeszcze wysłać DZISIAJ (issue #25).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE, SKORO SĄ JUŻ LIMITY ZAPYTAŃ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo tamte chronią przed czym innym. `limits.login_link` liczy po adresie IP,
 * `login_link.limit_na_adres` — po adresie e-mail. Oba pilnują POJEDYNCZEGO
 * nadużycia i oba są w porządku wtedy, gdy pięciuset ludzi zachowuje się
 * zupełnie normalnie: każdy prosi o swój jeden list, żaden limit nie zostaje
 * przekroczony, a serwis wysyła pięćset listów z puli trzystu.
 *
 * EmailLabs na planie darmowym daje **300 listów na dobę na CAŁY serwis**
 * (D-047). Z tego samego wiadra idą potwierdzenia rejestracji, przypomnienia
 * hasła, powiadomienia o „Ugotowałem" i decyzje moderacyjne. Właściciel
 * spodziewa się fali migracyjnej z Garnek.pl — setek kont zakładanych w kilka
 * dni, przez ludzi, dla których logowanie linkiem jest drogą PODSTAWOWĄ.
 * Bez tego licznika pierwszą rzeczą, która przestaje działać w takim dniu,
 * jest POTWIERDZENIE REJESTRACJI: nowi ludzie nie wchodzą w ogóle, a przyczyna
 * siedzi kilka warstw dalej i nie ma jak jej zobaczyć.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO DZIEŃ KALENDARZOWY, A NIE OKNO 24 GODZIN LIMITERA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo dostawca liczy dobę, a nie okno przesuwne od pierwszego listu.
 * `RateLimiter` z opóźnieniem 24 h zaczynałby odliczać od pierwszej wysyłki
 * i po tygodniu byłby przesunięty względem licznika, którego naprawdę
 * pilnujemy. Klucz niesie więc datę i wygasa sam.
 *
 * CZEGO TA KLASA NIE WIE: ile listów wysłały INNE części serwisu. Nie ma
 * jednego licznika całej poczty i celowo go tu nie budujemy — to byłby drugi
 * pomiar tej samej rzeczy, obok tego, który prowadzi dostawca. Ten licznik
 * pilnuje wyłącznie WŁASNEGO sufitu tej jednej funkcji, żeby nie zjadła
 * cudzego kawałka wiadra. Prawdziwy stan puli pokazuje panel EmailLabs.
 *
 * CACHE, NIE BAZA: to jest licznik, nie dowód. Jego zgubienie (restart
 * kontenera z pamięciowym sterownikiem cache) kosztuje najwyżej tyle, że
 * budżet zaczyna się liczyć od nowa — czyli awaria wychodzi w stronę
 * „wyślemy więcej listów", nie „zamkniemy komuś drzwi". Przy `CACHE_STORE`
 * ustawionym na `database` (tak chodzi produkcja) licznik przeżywa restart.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  SUFIT JEST TWARDY, A NIE SUGESTIĄ (D-076)
 * ────────────────────────────────────────────────────────────────────────
 *
 * O wysyłce rozstrzyga JEDNA metoda: `sprobujZarezerwowac()`. Zajmuje
 * miejsce albo odmawia, w jednej atomowej operacji, pod blokadą
 * `Cache::lock()`. `zostalo()` i `jestMiejsce()` zostają, ale są ODCZYTEM
 * — do pokazania człowiekowi, do diagnostyki i do oszacowania rozmiaru
 * paczki. Para „sprawdź, a potem zajmij" nie jest atomowa i pod obciążeniem
 * przepuszcza listy ponad sufitem (audyt MAIL-01/RACE-03) — pełne
 * wyprowadzenie przy `sprobujZarezerwowac()`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KLASA JEST WSPÓLNA DLA KILKU FUNKCJI (issue #11, D-057)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Powstała dla logowania linkiem (issue #25), ale tygodniowe podsumowanie
 * zderzyło się z DOKŁADNIE tym samym limitem i z tym samym wiadrem 300
 * listów na dobę. Drugi licznik obok tego rozjechałby się przy pierwszej
 * zmianie sufitu — a w tym projekcie rozjazd dwóch kopii tej samej reguły
 * jest usterką, nie niedogodnością (ta sama lekcja co `kuking.media_disk`
 * i martwy wpis `limits.upload` w `config/kuking.php`).
 *
 * Dlatego klasa dostała DWA parametry: nazwę funkcji (wchodzi do klucza
 * w cache, więc każda funkcja ma własny licznik) i klucz konfiguracji
 * z jej sufitem. Oba mają wartości domyślne z logowania linkiem, więc
 * `app(DziennyBudzetListow::class)` zachowuje się tak jak wcześniej —
 * z tym samym kluczem cache i tą samą konfiguracją. Do nowych funkcji
 * są nazwane wytwórnie niżej, żeby literówka w nazwie funkcji nie tworzyła
 * po cichu trzeciego, pustego licznika.
 *
 * SUFITY MUSZĄ SIĘ ZMIEŚCIĆ POD 300 I NIKT TEGO NIE POLICZY ZA NAS.
 * Ta klasa pilnuje JEDNEJ funkcji i nie wie nic o pozostałych — gdyby każda
 * dostała po 120, trzy funkcje przekroczyłyby limit dostawcy, a pierwszą
 * rzeczą, która by wtedy przestała działać, jest potwierdzenie rejestracji.
 * Podział całego wiadra stoi w `config/kuking.php` (`poczta`) i pilnuje go
 * `PodzialLimituPocztyTest`.
 */
final class DziennyBudzetListow
{
    private const PREFIKS = 'poczta:budzet:';

    /**
     * Klucz blokady, pod którą chodzi para „sprawdź i zajmij".
     *
     * Osobny od klucza licznika i BEZ daty w nazwie: blokada żyje sekundy,
     * a nie dobę, i nie ma czego rozdzielać na dni. Gdyby nosiła datę,
     * dwa żądania trafiające na przełom północy zajmowałyby dwa RÓŻNE
     * liczniki pod dwiema RÓŻNYMI blokadami — czyli dokładnie ten wyścig,
     * którego się tu pozbywamy, tylko raz na dobę i nie do odtworzenia.
     */
    private const PREFIKS_BLOKADY = 'poczta:budzet:blokada:';

    /**
     * Jak długo blokada wygasa sama, gdy proces ją trzymający padnie.
     *
     * W środku blokady dzieją się dwie operacje na cache i nic więcej —
     * żadnej wysyłki, żadnego zapytania do dostawcy. Dziesięć sekund jest
     * więc kilkaset razy dłuższe niż potrzeba, i to jest celowe: ta liczba
     * ma znaczenie tylko w jednym przypadku — kontener padł z blokadą
     * w ręku. Wtedy sufit odblokowuje się sam po dziesięciu sekundach,
     * zamiast zatrzymać całą pocztę do końca doby.
     */
    private const BLOKADA_SEKUND = 10;

    /**
     * Jak długo czekamy na blokadę, zanim ODMÓWIMY wysyłki.
     *
     * Odmowa, nie „wyślij na wszelki wypadek": przekroczony budżet
     * u dostawcy odbija się na wszystkich listach serwisu (także na
     * potwierdzeniu rejestracji, które sufitu nie ma i mieć nie może),
     * a jedna niewysłana wiadomość odbija się na jednej osobie, która
     * dostaje uczciwy komunikat i klika drugi raz.
     *
     * Dwie sekundy, bo prawdziwy ścisk trwa tu milisekundy (w środku są
     * dwie operacje na cache), a po drugiej stronie tego czekania stoi
     * człowiek patrzący w formularz.
     */
    private const CZEKANIE_SEKUND = 2;

    public function __construct(
        private readonly string $funkcja = 'link-logowania',
        private readonly string $kluczKonfiguracji = 'kuking.login_link.dzienny_budzet',
    ) {}

    /** Logowanie linkiem e-mail (issue #25) — domyślne zachowanie tej klasy. */
    public static function dlaLinkuLogowania(): self
    {
        return new self;
    }

    /** Tygodniowe podsumowanie od gospodarza (issue #11, D-057). */
    public static function dlaPodsumowania(): self
    {
        return new self('podsumowanie-tygodnia', 'kuking.digest.dzienny_limit');
    }

    /**
     * Zaproszenia do założenia konta — adres BEZ konta (D-085).
     *
     * TEN SUFIT LEŻY WEWNĄTRZ SUFITU LOGOWANIA LINKIEM, a nie obok niego,
     * i jest jedynym takim licznikiem w serwisie. Zaproszenie zajmuje miejsce
     * w OBU: najpierw w `login_link.dzienny_budzet` (120 — bo to ta sama
     * droga i ta sama pula poczty), a potem tutaj (40). Suma z sekcji
     * `poczta` w `config/kuking.php` nie zmienia się więc ani o jeden list,
     * i `PodzialLimituPocztyTest` mówi o tym wprost osobną asercją.
     *
     * Po co drugi licznik, skoro pierwszy już jest: dopóki adres bez konta
     * nie generował wysyłki, automat wpisujący wymyślone adresy nie potrafił
     * wysłać ani jednej wiadomości. Teraz potrafi — a limit po IP (5/60 min)
     * przepuszcza z jednego łącza 120 próśb na dobę, czyli dokładnie tyle, ile
     * ma cały budżet logowania linkiem. Bez tego niższego sufitu jeden
     * sprawca zamykałby na dobę WEJŚCIE NA KONTO wszystkim, dla których jest
     * ono drogą podstawową (D-056).
     */
    public static function dlaZaproszenDoRejestracji(): self
    {
        return new self('zaproszenie-do-rejestracji', 'kuking.login_link.zaproszenia.dzienny_sufit');
    }

    /**
     * Ile miejsca zostało w dzisiejszym budżecie — ODCZYT, NIE POZWOLENIE.
     *
     * Budżet ustawiony na zero albo mniej znaczy „ta funkcja nie wysyła dziś
     * nic" i jest poprawną, świadomą konfiguracją (awaryjne odcięcie poczty
     * bez wyłączania całej drogi — ludzie z ważnym linkiem w skrzynce nadal
     * się nim zalogują).
     *
     * DO CZEGO TEGO WOLNO UŻYWAĆ, A DO CZEGO NIE (D-076)
     * Wolno: pokazać liczbę człowiekowi, wypisać ją w diagnostyce, dobrać
     * treść komunikatu, oszacować ROZMIAR PACZKI do wysłania (tak robi
     * `kuking:wyslij-podsumowania`, żeby nie pobierać z bazy stu odbiorców,
     * gdy zostało pięć miejsc).
     *
     * NIE WOLNO: rozstrzygać na tej podstawie, czy KONKRETNY list wychodzi.
     * Ta metoda tylko CZYTA, a między odczytem i zapisem mieści się drugie
     * żądanie, które przeczyta to samo. Przy budżecie 120 i zużyciu 119 dwa
     * równoległe żądania widziały wolne miejsce, oba wysyłały i oba
     * inkrementowały — 121 listów przy suficie 120 (audyt MAIL-01/RACE-03).
     * Decyzję o wysyłce podejmuje wyłącznie `sprobujZarezerwowac()`.
     */
    public function zostalo(): int
    {
        return max(0, $this->budzet() - $this->zuzyte());
    }

    /** Odczyt do pokazania i do diagnostyki — obostrzenia jak w `zostalo()`. */
    public function jestMiejsce(): bool
    {
        return $this->zostalo() > 0;
    }

    /**
     * Zajmij jedno miejsce ALBO odmów — jedna operacja, nie dwie (D-076).
     *
     * ────────────────────────────────────────────────────────────────────
     *  PO CO TO ISTNIEJE, SKORO `Cache::increment` JEST ATOMOWY
     * ────────────────────────────────────────────────────────────────────
     *
     * Bo atomowy jest POJEDYNCZY krok, a sufit łamie się na PARZE kroków.
     * Kod przed tą zmianą wyglądał tak (`LoginLinkController`, dziesięć
     * linii między jednym i drugim):
     *
     *     if (! $budzet->jestMiejsce()) { odmów; }   // czyta 119 ze 120
     *     ...
     *     $budzet->zajmij();                         // zapisuje 120
     *
     * Dwa żądania w tej samej milisekundzie czytają oba 119, oba widzą
     * wolne miejsce, oba wysyłają i oba inkrementują. Licznik pokazuje 121
     * przy suficie 120 i nikt się o tym nie dowie, dopóki nie odbije się
     * o limit dostawcy — a wtedy przestaje działać POTWIERDZENIE
     * REJESTRACJI, bo ono czerpie z tego samego wiadra i sufitu nie ma.
     *
     * Nie da się tego naprawić sprawdzeniem PO inkrementacji: wiadomość
     * jest wtedy już zakolejkowana, przekroczenie już nastąpiło, a cofnąć
     * listu z drogi nie umiemy.
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO BLOKADA Z CACHE, A NIE `UPDATE ... WHERE used < limit`
     * ────────────────────────────────────────────────────────────────────
     *
     * Warunkowy `UPDATE` we własnej tabeli też byłby poprawny i byłby
     * atomowy bez żadnej blokady. Kosztowałby jednak nową tabelę, migrację,
     * wpis w `docs/DATABASE.md`, rollback i sprzątanie starych wierszy —
     * czyli DRUGI mechanizm obok tego, który już mamy. Zasada projektu jest
     * odwrotna: żadnych nowych mechanizmów bez zmierzonej potrzeby.
     *
     * Sterownik cache w tym projekcie to `database` (`config/cache.php`,
     * `.env.example`), więc `Cache::lock()` jest tu blokadą PRAWDZIWĄ:
     * współdzieloną między procesami i trwałą, opartą o tabelę
     * `cache_locks` (migracja `0001_01_01_000002_create_cache_table`).
     * To jest ta sama tabela w tej samej bazie, do której poszedłby własny
     * warunkowy `UPDATE` — z tą różnicą, że nie musimy jej pisać.
     *
     * UWAGA NA STEROWNIK `array`: tam blokada działa tylko w obrębie
     * JEDNEGO procesu. W testach to wystarcza i jest zamierzone, ale
     * gdyby ktoś kiedyś ustawił `CACHE_STORE=array` na produkcji, ten
     * sufit przestałby być sufitem — pilnuje tego
     * `AtomowaRezerwacjaBudzetuTest`.
     *
     * @return bool `true` = miejsce zajęte, list może wyjść. `false` =
     *              nic nie zajęto i list NIE MOŻE wyjść (budżet wyczerpany
     *              albo nie udało się zdobyć blokady).
     */
    public function sprobujZarezerwowac(): bool
    {
        try {
            $zajete = (bool) Cache::lock($this->kluczBlokady(), self::BLOKADA_SEKUND)
                ->block(self::CZEKANIE_SEKUND, function (): bool {
                    if (! $this->jestMiejsce()) {
                        return false;
                    }

                    $this->zajmij();

                    return true;
                });
        } catch (LockTimeoutException) {
            // ODMOWA, NIE WYSYŁKA „NA WSZELKI WYPADEK" — uzasadnienie przy
            // `CZEKANIE_SEKUND`. Wołający ma powiedzieć człowiekowi, co
            // zrobić, a nie wypuścić list poza sufitem.
            return false;
        }

        // OSTRZEŻENIE O KOŃCZĄCEJ SIĘ PULI STOI TUTAJ, PO ODDANIU BLOKADY,
        // I TO NIE JEST KOSMETYKA (issue #234 spotkane z D-076).
        //
        // Pierwsza wersja tej poprawki wołała `ostrzezZawczasu()` z wnętrza
        // `zajmij()`. Wtedy `zajmij()` nie chodziło jeszcze pod blokadą —
        // dziś chodzi, bo jest drugim krokiem atomowej rezerwacji. Wpis
        // `Log::error` w środku blokady wydłużyłby sekcję krytyczną
        // o zapis do dziennika i o `Cache::add()`, a przy włączonym kanale
        // `blad_webhook` (D-041) także o żądanie HTTP z limitem 3 sekund.
        // Przy `CZEKANIE_SEKUND` = 2 znaczyłoby to, że JEDNO ostrzeżenie
        // o kończącej się puli odmawia wysyłki wszystkim żądaniom, które
        // w tym czasie czekają na blokadę — czyli sygnał „zaraz zabraknie
        // listów" sam zabierałby listy. Dokładnie ta klasa pomyłki, którą
        // opisuje komentarz przy `BLOKADA_SEKUND`: pod blokadą mają być
        // dwie operacje na cache i nic więcej.
        if ($zajete) {
            $this->ostrzezZawczasu();
        }

        return $zajete;
    }

    /**
     * Oddaj miejsce zajęte rezerwacją, z której nic nie wyszło.
     *
     * ISTNIEJE PO TO, ŻEBY REZERWACJA NIE ZŁAMAŁA STARSZEJ REGUŁY.
     * Sufit musi być zajmowany PRZED wysyłką (inaczej nie jest sufitem),
     * ale przy logowaniu linkiem list wychodzi tylko wtedy, gdy na podanym
     * adresie NAPRAWDĘ jest konto. Bez oddawania miejsca automat wpisujący
     * nieistniejące adresy wyczerpywałby dobowy budżet w kilka minut, nie
     * wysławszy ani jednego listu — czyli dokładnie ta usterka, przed którą
     * broni `test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu`.
     *
     * Nie wołaj tego do „zwalniania" miejsca po liście, który POSZEDŁ.
     * Ta metoda jest wycofaniem NIEUŻYTEJ rezerwacji i niczym więcej.
     *
     * Nieudane zdobycie blokady zostawia licznik zawyżony o jeden i tak
     * ma być: pomyłka idzie wtedy w stronę „wyślemy o jeden list mniej",
     * a nie w stronę przekroczenia limitu dostawcy.
     */
    public function zwolnij(): void
    {
        try {
            Cache::lock($this->kluczBlokady(), self::BLOKADA_SEKUND)
                ->block(self::CZEKANIE_SEKUND, function (): void {
                    // Licznik nie może zejść pod zero. Zdarza się to
                    // wtedy, gdy klucz wygasł albo zmienił się dzień
                    // między rezerwacją i oddaniem miejsca — wtedy nie ma
                    // czego oddawać, bo licznik i tak liczy od nowa.
                    if ($this->zuzyte() < 1) {
                        return;
                    }

                    Cache::decrement($this->klucz());
                });
        } catch (LockTimeoutException) {
            // Świadomie pusto — patrz ostatni akapit opisu metody.
        }
    }

    /**
     * BEZWARUNKOWE zajęcie jednego miejsca — nie sprawdza sufitu.
     *
     * TO NIE JEST METODA DO PODEJMOWANIA DECYZJI O WYSYŁCE (D-076). Jest
     * jednym z dwóch kroków rezerwacji i chodzi w środku blokady założonej
     * przez `sprobujZarezerwowac()` — tam jest jej jedyne właściwe miejsce.
     * Wołanie jej wprost jest poprawne tylko wtedy, gdy list wychodzi
     * ŚWIADOMIE PONAD sufitem i ma się jedynie policzyć (list próbny
     * `kuking:wyslij-podsumowania --tylko`, wypuszczany ręcznie przez
     * właściciela). Wszędzie indziej wołaj `sprobujZarezerwowac()`.
     *
     * BUDŻET ZAJMUJE SIĘ ZA LIST, KTÓRY NAPRAWDĘ WYCHODZI. Gdyby licznik
     * ruszał przy każdym WYSŁANIU FORMULARZA, byle automat wpisujący
     * nieistniejące adresy wyczerpałby dobowy budżet w kilka minut
     * i zamknął drogę wszystkim prawdziwym ludziom, nie wysławszy ani
     * jednego listu. Przed samym zalewaniem formularza broni
     * `limits.login_link` i `login_link.limit_na_adres`. Rezerwacja stoi
     * PRZED wysyłką, więc tę regułę utrzymuje oddanie nieużytego miejsca
     * przez `zwolnij()` — patrz tam.
     *
     * TYGODNIOWE PODSUMOWANIE ZAJMUJE MIEJSCE PRZY WSTAWIENIU DO KOLEJKI,
     * a nie po doręczeniu — i to nie jest niekonsekwencja (D-057). Tam nie ma
     * formularza ani automatu: listę odbiorców składa harmonogram z kont,
     * które mają zgodę i potwierdzony adres, więc nie istnieje nikt, kto
     * mógłby wyczerpać budżet fałszywymi żądaniami. Za to istnieje ryzyko
     * odwrotne: sto dwadzieścia listów wstawionych do kolejki, z których
     * połowa odbije się o limit dostawcy i przepadnie w `failed_jobs` —
     * sprawdzone, worker ma `--tries=3 --backoff=10,60,300`, więc wszystkie
     * trzy próby mieszczą się w sześciu minutach tej samej doby. Sufit musi
     * więc powstrzymać wysyłkę PRZED wstawieniem, bo po wstawieniu jest już
     * za późno na cokolwiek.
     *
     * TU NIE MA OSTRZEŻENIA O KOŃCZĄCEJ SIĘ PULI (issue #234), choć to jest
     * pierwsze miejsce, w które się prosi. Ta metoda chodzi w środku blokady
     * z `sprobujZarezerwowac()`, a ostrzeżenie zapisuje dziennik i (przy
     * włączonym kanale) dzwoni na webhook — czyli robi w sekcji krytycznej
     * rzeczy trwające dłużej niż cała reszta rezerwacji razem. Ostrzeżenie
     * stoi więc w `sprobujZarezerwowac()`, PO oddaniu blokady; pełne
     * wyprowadzenie jest tam. Skutek uboczny, przyjęty świadomie: droga
     * listu próbnego `--tylko`, która woła `zajmij()` wprost, nie ostrzega
     * o niczym. I tak nie powinna — ten list wychodzi ŚWIADOMIE ponad
     * sufitem, przy właścicielu patrzącym w konsolę, a stan puli wypisuje
     * mu `php artisan kuking:sprawdz-poczte`.
     */
    public function zajmij(): void
    {
        $klucz = $this->klucz();

        // `add` zakłada klucz z terminem ważności tylko wtedy, gdy go
        // jeszcze nie ma — bez tego `increment` na nieistniejącym kluczu
        // zakłada wpis BEZ terminu i licznik zostaje na zawsze.
        Cache::add($klucz, 0, now()->addDays(2));
        Cache::increment($klucz);
    }

    /**
     * OSTRZEŻENIE, ŻE SUFIT SIĘ KOŃCZY — zanim listy zaczną odbijać się
     * od limitu dostawcy (issue #234).
     *
     * PO CO, SKORO SUFIT I TAK ZATRZYMA WYSYŁKĘ
     * Bo sufit chroni CUDZY kawałek wiadra, a nie tę funkcję. Gdy zostaje
     * zero, logowanie linkiem przestaje wysyłać listy — i to jest zachowanie
     * poprawne, ale dla człowieka po drugiej stronie nie do odróżnienia od
     * awarii. Issue #234 prosi wprost: przejście na płatny plan u dostawcy
     * ma dać się zrobić DZIEŃ WCZEŚNIEJ, nie w dniu, w którym drzwi się
     * zamykają. Ten wpis jest jedynym wyprzedzającym sygnałem, jaki mamy.
     *
     * `error`, NIE `warning`, I TO JEST WYBÓR — ale SAM POZIOM NIKOGO NIE
     * BUDZI, i to trzeba było sprawdzić, zamiast założyć.
     * Pierwsza wersja tego komentarza twierdziła, że poziom `error`
     * decyduje o tym, czy wpis „pójdzie na webhook błędów (D-041) i do
     * Sentry". SPRAWDZONE W KODZIE 10 września 2026: nieprawda. Kanał
     * `blad_webhook` nie jest częścią stosu domyślnego (`LOG_STACK=single`
     * w `.env.example`), a woła się do niego JAWNIE — z `bootstrap/app.php`
     * przy raportowaniu wyjątku i z `App\Domain\Contact\DzwonekOperatora`.
     * Sentry'ego nie ma w `composer.json` wcale (D-041,
     * `docs/infra/INFRA_DECISION.md`). Zwykłe `Log::error()` trafiało więc
     * wyłącznie do pliku na serwerze — czyli ostrzeżenie „zaraz zabraknie
     * listów" było dokładnie tym cichym sygnałem, którego issue #234 tępi.
     *
     * Dlatego wpis idzie w DWA miejsca: pełny kontekst do dziennika serwera
     * (`Log::error`, zostaje na serwerze) i jedno krótkie zdanie na kanał
     * `blad_webhook`, jeśli właściciel ustawił `LOG_BLAD_WEBHOOK_URL`.
     * Bez tego adresu kanał jest martwy i nie robi nic — ten sam warunek co
     * w `bootstrap/app.php`. Gdy webhook nie odpowie, `WebhookBleduHandler`
     * połyka błąd wysyłki świadomie (patrz jego komentarz), ale wpis
     * w dzienniku serwera zostaje — czyli fakt ostrzeżenia nie ginie razem
     * z niedodzwonieniem się.
     *
     * RAZ NA DOBĘ NA FUNKCJĘ. Klucz z datą i `Cache::add()` (który zakłada
     * wpis tylko wtedy, gdy go nie ma) pilnują, żeby przy sufitcie 120 listów
     * nie powstało dwadzieścia cztery razy to samo zdanie. Powtarzający się
     * alarm uczy się ignorować — a wtedy nie ma alarmu.
     *
     * W WPISIE SĄ WYŁĄCZNIE LICZBY. Żadnego adresu, żadnej nazwy konta:
     * ten dziennik wychodzi na webhook, nad którym nie mamy kontroli
     * (AGENTS.md §7, audyt A6-01).
     */
    private function ostrzezZawczasu(): void
    {
        $budzet = $this->budzet();
        $prog = (int) config('kuking.poczta.prog_ostrzezenia_procent', 80);

        if ($budzet < 1 || $prog < 1 || $prog >= 100) {
            return;
        }

        $zuzyte = $this->zuzyte();

        if ($zuzyte * 100 < $budzet * $prog) {
            return;
        }

        // Klucz osobny od licznika, żeby odhaczenie ostrzeżenia nie miało
        // wpływu na sam budżet.
        if (Cache::add($this->kluczOstrzezenia(), 1, now()->addDays(2)) !== true) {
            return;
        }

        Log::error('Poczta: dobowy sufit listów jest prawie wyczerpany.', [
            'funkcja' => $this->funkcja,
            'zuzyte' => $zuzyte,
            'budzet' => $budzet,
            'zostalo' => max(0, $budzet - $zuzyte),
            'prog_procent' => $prog,
            'co_zrobic' => 'Gdy sufit się skończy, ta funkcja przestanie wysyłać listy (a nie zacznie ich gubić). '
                .'Jeśli to się powtarza, przejdź na płatny plan u dostawcy — docs/decyzje/POCZTA.md §4.',
        ]);

        if (! KanalyAlarmowe::jakikolwiekWlaczony()) {
            return;
        }

        // JEDNO ZDANIE, SAME LICZBY I NAZWA FUNKCJI. Kontekstu tu nie ma
        // celowo: `WebhookBleduHandler` bierze z rekordu bez wyjątku sam
        // `message`, więc cokolwiek dołożonego w `context` i tak by nie
        // wyszło — a gdyby kiedyś wyszło, wychodziłoby do usługi, nad którą
        // nie mamy kontroli (AGENTS.md §7, audyt A6-01).
        KanalyAlarmowe::zadzwon(sprintf(
            'Poczta: sufit „%s" zużyty w %d%% (%d z %d). Gdy się skończy, ta funkcja przestanie wysyłać listy.',
            $this->funkcja,
            (int) floor($zuzyte * 100 / $budzet),
            $zuzyte,
            $budzet,
        ));
    }

    public function zuzyte(): int
    {
        return (int) Cache::get($this->klucz(), 0);
    }

    public function budzet(): int
    {
        return (int) config($this->kluczKonfiguracji, 0);
    }

    private function klucz(): string
    {
        return self::PREFIKS.$this->funkcja.':'.now()->format('Y-m-d');
    }

    private function kluczBlokady(): string
    {
        return self::PREFIKS_BLOKADY.$this->funkcja;
    }

    /** Klucz „już dziś ostrzegałem o tej funkcji". Osobny od licznika. */
    private function kluczOstrzezenia(): string
    {
        return self::PREFIKS.'ostrzezenie:'.$this->funkcja.':'.now()->format('Y-m-d');
    }
}
