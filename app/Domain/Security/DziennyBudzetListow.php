<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

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
            return (bool) Cache::lock($this->kluczBlokady(), self::BLOKADA_SEKUND)
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
}
