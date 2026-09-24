<?php

declare(strict_types=1);

namespace App\Domain\Security;

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
 * OD 20 WRZEŚNIA 2026 TA KLASA WIE TAKŻE, ILE LISTÓW WYSŁAŁ CAŁY SERWIS.
 * Wcześniej stało tu, że wspólnego licznika całej poczty „celowo nie
 * budujemy". Pomiar to przewrócił: `/nie-pamietam-hasla` nie miał ani sufitu
 * na adres, ani budżetu poczty, więc jeden sprawca z jednego adresu IP
 * (`limits.password_reset` = 5 na 10 minut, czyli 720 próśb na dobę) wysyłał
 * listy na 300 RÓŻNYCH adresów i opróżniał całą pulę 300/dobę w około
 * 70 minut. Ponawianie potwierdzenia adresu (`verification_resend` = 6 na
 * minutę, bez sufitu dobowego) robiło to samo z jednego niepotwierdzonego
 * konta w około 50 minut. Pierwszą rzeczą, która wtedy przestawała działać,
 * było POTWIERDZENIE REJESTRACJI i LOGOWANIE LINKIEM — czyli wejście dla
 * nowych ludzi. Patrz `wspolny()` niżej.
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

    /**
     * KLASY PILNOŚCI — czyli KOLEJNOŚĆ WYGASZANIA, gdy pula się kończy.
     *
     * Nazwa klasy wskazuje próg w `kuking.poczta.progi_wygaszania`: ile
     * listów z całej puli ta klasa ma zostawić NIETKNIĘTYCH. Wyższy próg =
     * gaśnie wcześniej. Pełne wyprowadzenie liczb stoi w `config/kuking.php`,
     * sekcja `poczta` — tutaj są tylko nazwy, żeby literówka w łańcuchu nie
     * tworzyła po cichu czwartej klasy z progiem zero (czyli klasy, która
     * gasłaby OSTATNIA, bo nieznany klucz czyta się jako 0).
     */
    public const KLASA_PODSUMOWANIE = 'podsumowanie';

    public const KLASA_ZWYKLA = 'zwykla';

    public const KLASA_WEJSCIE = 'wejscie';

    /** @var list<string> */
    public const KLASY = [self::KLASA_PODSUMOWANIE, self::KLASA_ZWYKLA, self::KLASA_WEJSCIE];

    /** Nazwa funkcji wspólnego licznika — JEDEN klucz w cache na cały serwis. */
    private const FUNKCJA_WSPOLNA = 'cala-poczta';

    /**
     * KONSTRUKTOR JEST PRYWATNY, A WEJŚCIE PROWADZI PRZEZ WYTWÓRNIE.
     *
     * Do 20 września 2026 był publiczny i miał wartości domyślne z logowania
     * linkiem, więc `app(DziennyBudzetListow::class)` dawało licznik tej
     * funkcji — i tak właśnie brał go `LoginLinkController`. Po dołożeniu
     * licznika WSPÓLNEGO to przestało być bezpieczne: kontener wstrzyknąłby
     * obiekt BEZ nadrzędnego licznika, czyli sufit własny działałby dalej,
     * a wspólnej puli ten list by nie zajął. Byłby to najgorszy rodzaj
     * usterki — niewidoczny, bo wszystko wygląda na policzone.
     */
    private function __construct(
        private readonly string $funkcja,
        private readonly string $kluczKonfiguracji,
        private readonly ?self $nadrzedny = null,
        private readonly ?string $klasa = null,
    ) {}

    /**
     * Doby, w których TEN obiekt zajął miejsca, od najstarszej do najnowszej
     * (issue #1061).
     *
     * PO CO: `zwolnij()` wyliczał klucz licznika z `now()` w chwili ZWROTU.
     * Rezerwacja zrobiona o 23:59:59 i oddana po północy zdejmowała więc
     * miejsce z NOWEJ doby — tej, w której ktoś inny zdążył już wysłać list —
     * a stara doba zostawała zawyżona. Licznik nowej doby pokazywał o jeden
     * list mniej, niż wyszło, i sufit przepuszczał jeden list ponad limit.
     *
     * DLACZEGO LISTA, A NIE JEDNA DATA: `kuking:wyslij-podsumowania`
     * rezerwuje jednym obiektem dziesiątki miejsc w pętli i oddaje co
     * któreś — zawsze to, które zajął przed chwilą. `zwolnij()` zdejmuje więc
     * OSTATNIĄ rezerwację i trafia nią w jej własną dobę. Data zapamiętana
     * w konstruktorze by tu nie wystarczyła: pętla komendy może przejść przez
     * północ.
     *
     * Obiekt, któremu odmówiono, nie ma tu nic — i jego `zwolnij()` nie
     * zdejmie przez to miejsca zajętego przez kogoś innego.
     *
     * @var list<string>
     */
    private array $dobyRezerwacji = [];

    /**
     * WSPÓLNY LICZNIK CAŁEJ POCZTY — jedno miejsce decyzji „komu gasimy
     * pierwszemu" (decyzja właściciela z 20 września 2026).
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO ROZSZERZENIE TEJ KLASY, A NIE WARSTWA NAD NIĄ
     * ────────────────────────────────────────────────────────────────────
     *
     * Bo licznik zagnieżdżony w drugim liczniku jest tu już od D-085:
     * zaproszenie do rejestracji zajmuje miejsce w DWÓCH sufitach naraz —
     * najpierw w budżecie logowania linkiem, potem we własnym. Wspólny
     * licznik jest tą samą konstrukcją o jedno piętro wyżej, a nie nowym
     * mechanizmem. Osobna warstwa oznaczałaby drugą implementację atomowej
     * rezerwacji (blokada, okno czekania, oddawanie nieużytego miejsca,
     * ostrzeganie zawczasu) — czyli drugą kopię reguły, która w tym
     * repozytorium jest usterką, nie niedogodnością.
     *
     * ────────────────────────────────────────────────────────────────────
     *  CO ROBI PRÓG, SKORO SUFITEM JEST LIMIT DOSTAWCY
     * ────────────────────────────────────────────────────────────────────
     *
     * Sam wspólny licznik NIE dokłada ochrony przed przekroczeniem 300 —
     * tego pilnuje dostawca i tak. Dokłada coś innego i o to chodziło:
     * KOLEJNOŚĆ. Bez niego pulę zjada ten, kto był pierwszy, a odrzucony
     * list PRZEPADA (worker ma trzy próby w sześć minut). Z nim o tym, co
     * gaśnie jako pierwsze, decydujemy my, a nie przypadek — i klasa
     * `wejscie` (potwierdzenie rejestracji, logowanie linkiem) sięga po
     * OSTATNI list doby, bo bez niej nikt tu nie wejdzie.
     *
     * KAŻDA KLASA MA WŁASNY PRÓG, ALE WSZYSTKIE JEDEN LICZNIK. Nazwa
     * funkcji jest dla wszystkich ta sama (`cala-poczta`), więc klucz
     * w cache i klucz blokady są jedne — inaczej trzy klasy liczyłyby trzy
     * różne „całe poczty" i żadna nie widziałaby pozostałych.
     */
    public static function wspolny(string $klasa): self
    {
        return new self(
            self::FUNKCJA_WSPOLNA,
            'kuking.poczta.limit_dostawcy_dobowy',
            klasa: in_array($klasa, self::KLASY, true) ? $klasa : self::KLASA_ZWYKLA,
        );
    }

    /**
     * Logowanie linkiem e-mail (issue #25).
     *
     * Klasa `wejscie`: dla części osób jest to JEDYNA droga na konto, więc
     * gaśnie jako ostatnia — razem z potwierdzeniem rejestracji.
     */
    public static function dlaLinkuLogowania(): self
    {
        return new self(
            'link-logowania',
            'kuking.login_link.dzienny_budzet',
            self::wspolny(self::KLASA_WEJSCIE),
        );
    }

    /**
     * Potwierdzenie adresu e-mail — przy rejestracji i przy ponowieniu
     * (`App\Domain\Security\WyslijPotwierdzenieAdresu`).
     *
     * WŁASNEGO SUFITU NIE MA I MIEĆ NIE BĘDZIE: to jest list, bez którego
     * nowe konto nie potwierdzi adresu, więc jego jedynym ograniczeniem jest
     * wspólna pula — i w niej stoi na samym końcu kolejki do wygaszenia.
     * Przed zalewaniem tej drogi broni `limits.verification_resend` i to, że
     * trzeba mieć konto; wspólny licznik pilnuje tylko tego, żeby jedno
     * niepotwierdzone konto nie wypaliło puli całemu serwisowi.
     */
    public static function dlaPotwierdzeniaAdresu(): self
    {
        return self::wspolny(self::KLASA_WEJSCIE);
    }

    /**
     * Przypomnienie hasła (`/nie-pamietam-hasla`).
     *
     * KLASA `zwykla`, NIE `wejscie`, I TO JEST CAŁA TREŚĆ TEJ WYTWÓRNI.
     * Przypomnienie hasła też jest drogą powrotu na konto, więc odruch każe
     * postawić je obok potwierdzenia rejestracji. Pomiar mówi co innego:
     * prośbę o ten list składa KTOKOLWIEK Z ZEWNĄTRZ, na CUDZY adres, bez
     * konta i bez dowodu, że ten adres do niego należy — czyli jest to
     * dokładnie ta droga, którą zmierzony sprawca opróżniał pulę. Gdyby
     * dostała próg zero, dzieliłaby ostatnie listy doby z listem, który ma
     * chronić, i oba gasłyby razem. Zostaje więc nad rezerwą transakcyjną:
     * gaśnie wcześniej i tych ostatnich listów nie dotyka.
     */
    public static function dlaOdzyskaniaHasla(): self
    {
        return self::wspolny(self::KLASA_ZWYKLA);
    }

    /**
     * Listy wysyłane ręcznie przez moderatora albo przez zadanie w tle
     * (odpowiedź z „Napisz do nas", decyzje moderacyjne, eksport danych).
     *
     * Klasa `zwykla` — zostawia nietkniętą rezerwę transakcyjną.
     */
    public static function dlaListuObslugi(): self
    {
        return self::wspolny(self::KLASA_ZWYKLA);
    }

    /**
     * Alarm o pilnym zgłoszeniu od CZŁOWIEKA (`AlarmujOPilnymZgloszeniu`, D-236).
     *
     * WŁASNY SUFIT DOBOWY I KLASA `wejscie` — JEDNO BEZ DRUGIEGO BYŁOBY BŁĘDEM.
     *
     * Klasa `zwykla` wyglądała na oczywistą (to list moderacyjny), ale ją
     * wypala zalanie `/nie-pamietam-hasla` z jednego łącza (D-239). Wtedy
     * sprawca, który chce, żeby zgłoszenie „dotyczy dziecka" przeleżało noc
     * bez listu, miałby na to gotowy przepis. Alarm sięga więc po ostatnie
     * listy doby razem z wejściem na konto.
     *
     * Za to własny sufit (`moderation.alarm_czlowieka.dzienny_sufit`) mówi,
     * ILE najwyżej z tych ostatnich listów może zabrać: kategorię wybiera
     * zgłaszający, więc bez sufitu to on decydowałby, ile listów logowania
     * dziś nie wyjdzie. Konstrukcja jest ta sama co przy podsumowaniu —
     * licznik w liczniku, jedna atomowa rezerwacja na oba.
     */
    public static function dlaAlarmuModeracji(): self
    {
        return new self(
            'alarm-pilnego-zgloszenia',
            'kuking.moderation.alarm_czlowieka.dzienny_sufit',
            self::wspolny(self::KLASA_WEJSCIE),
        );
    }

    /**
     * Tygodniowe podsumowanie od gospodarza (issue #11, D-057).
     *
     * Klasa `podsumowanie`: gaśnie PIERWSZE. Podsumowanie, które nie doszło,
     * jest niczym; potwierdzenie rejestracji, które nie doszło, kończy komuś
     * przygodę z serwisem, zanim się zaczęła.
     */
    public static function dlaPodsumowania(): self
    {
        return new self(
            'podsumowanie-tygodnia',
            'kuking.digest.dzienny_limit',
            self::wspolny(self::KLASA_PODSUMOWANIE),
        );
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
        // NADRZĘDNEGO LICZNIKA TU NIE MA I TO NIE JEST PRZEOCZENIE.
        // Ta droga wychodzi WYŁĄCZNIE spod rezerwacji logowania linkiem
        // (`LoginLinkController::send` rezerwuje pierwsze, `WyslijLinkDoLogowania`
        // schodzi tutaj dopiero wtedy, gdy na adresie nie ma konta), a tamta
        // rezerwacja zajęła już miejsce we WSPÓLNEJ puli. Drugi rodzic
        // liczyłby JEDEN list DWA razy — czyli pula kończyłaby się o połowę
        // za wcześnie i to dokładnie na drodze wejścia dla nowych ludzi.
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
    public function zostalo(?string $doba = null): int
    {
        return max(0, $this->budzet() - $this->zuzyte($doba));
    }

    /** Odczyt do pokazania i do diagnostyki — obostrzenia jak w `zostalo()`. */
    public function jestMiejsce(): bool
    {
        return $this->zostaloLacznie() > 0;
    }

    /**
     * Ile z tego, co zostało, wolno ruszyć TEJ KLASIE listów.
     *
     * Różnica wobec `zostalo()` jest cała w progu wygaszania: przy pulI 300,
     * zużyciu 80 i progu klasy `podsumowanie` (240) `zostalo()` mówi 220,
     * a ta metoda — zero. Obie liczby są prawdziwe i obie są potrzebne:
     * pierwsza opisuje wiadro, druga mówi, czy TEN list z niego wyjdzie.
     */
    public function zostaloWTejKlasie(?string $doba = null): int
    {
        return max(0, $this->zostalo($doba) - $this->prog());
    }

    /**
     * Ile listów wolno jeszcze wysłać tą drogą, licząc RAZEM z nadrzędnym
     * licznikiem — odczyt, obostrzenia jak w `zostalo()`.
     *
     * Bierze mniejszą z dwóch liczb, bo wąskim gardłem bywa raz sufit własny
     * (tygodniowe podsumowanie: 60), a raz wspólna pula (dzień, w którym
     * reszta serwisu wysłała już 250 listów). Do oszacowania ROZMIARU PACZKI
     * potrzebna jest ta mniejsza — inaczej `kuking:wyslij-podsumowania`
     * pobrałoby z bazy sześćdziesięciu odbiorców, żeby na piątym odbić się
     * od wspólnego progu.
     */
    public function zostaloLacznie(): int
    {
        $wlasne = $this->zostaloWTejKlasie();

        return $this->nadrzedny === null ? $wlasne : min($wlasne, $this->nadrzedny->zostaloLacznie());
    }

    /** Czy miejsce jest w TYM liczniku danej doby — bez pytania nadrzędnego. */
    private function jestWlasneMiejsce(string $doba): bool
    {
        return $this->zostaloWTejKlasie($doba) > 0;
    }

    /**
     * Próg wygaszania TEJ klasy listów: ile z puli ma zostać nietknięte.
     *
     * Licznik bez klasy (sufit własny funkcji) ma próg zero — czyli zachowuje
     * się dokładnie tak jak przed 20 września 2026.
     */
    private function prog(): int
    {
        if ($this->klasa === null) {
            return 0;
        }

        return max(0, (int) config('kuking.poczta.progi_wygaszania.'.$this->klasa, 0));
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
        // NAJPIERW LICZNIK NADRZĘDNY, POTEM WŁASNY — i nigdy odwrotnie.
        // Odwrotna kolejność zajmowałaby miejsce w suficie funkcji także
        // wtedy, gdy wspólna pula i tak odmówi, więc dzień z wyczerpaną pulą
        // wypalałby dodatkowo sufity wszystkich funkcji po kolei. Miejsce
        // zajęte u rodzica wraca niżej, gdy własna rezerwacja się nie uda.
        if ($this->nadrzedny !== null && ! $this->nadrzedny->sprobujZarezerwowac()) {
            return false;
        }

        try {
            $zajete = (bool) Cache::lock($this->kluczBlokady(), self::BLOKADA_SEKUND)
                ->block(self::CZEKANIE_SEKUND, function (): bool {
                    // WŁASNE miejsce, nie łączne: o miejsce u rodzica
                    // spytaliśmy wyżej i już je zajęliśmy. Pytanie o nie
                    // drugi raz — pod cudzą blokadą — byłoby braniem dwóch
                    // blokad naraz w ustalonej kolejności bez żadnej
                    // potrzeby.
                    //
                    // DOBA WYZNACZONA RAZ, DLA SPRAWDZENIA I ZAJĘCIA NARAZ
                    // (#1061). Dwa osobne `now()` mogłyby wypaść po dwóch
                    // stronach północy: sprawdzenie wczorajszego licznika,
                    // a zajęcie dzisiejszego.
                    $doba = self::dzisiaj();

                    if (! $this->jestWlasneMiejsce($doba)) {
                        return false;
                    }

                    $this->zajmijWlasne($doba);

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
        if (! $zajete) {
            // WŁASNY SUFIT ODMÓWIŁ, WIĘC LIST NIE WYJDZIE — a miejsce zajęte
            // u rodzica musi wrócić do wspólnej puli. Bez tego wyczerpany
            // sufit jednej funkcji (albo ścisk na jej blokadzie) zjadałby
            // listy wszystkim pozostałym, nie wysławszy ani jednego.
            $this->nadrzedny?->zwolnij();

            return false;
        }

        $this->ostrzezZawczasu();

        return true;
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
        // ZWROT IDZIE DO DOBY, W KTÓREJ MIEJSCE ZAJĘTO, nie do dzisiejszej
        // (#1061) — pełne wyprowadzenie przy `$dobyRezerwacji`. Brak wpisu
        // znaczy, że ten obiekt niczego nie zajął (albo już wszystko oddał),
        // więc nie ma czego oddawać — ani tu, ani u rodzica.
        $doba = array_pop($this->dobyRezerwacji);

        if ($doba === null) {
            return;
        }

        try {
            Cache::lock($this->kluczBlokady(), self::BLOKADA_SEKUND)
                ->block(self::CZEKANIE_SEKUND, function () use ($doba): void {
                    // Licznik nie może zejść pod zero. Zdarza się to
                    // wtedy, gdy klucz tamtej doby zdążył wygasnąć — wtedy
                    // nie ma czego oddawać.
                    if ($this->zuzyte($doba) < 1) {
                        return;
                    }

                    Cache::decrement($this->klucz($doba));
                });
        } catch (LockTimeoutException) {
            // Świadomie pusto — patrz ostatni akapit opisu metody.
        }

        // ...I TO SAMO U RODZICA. Rezerwacja szła z góry na dół, oddawanie
        // idzie z dołu do góry — inaczej list, który nie wyszedł, zostawałby
        // policzony we wspólnej puli na zawsze.
        $this->nadrzedny?->zwolnij();
    }

    /**
     * BEZWARUNKOWE zajęcie jednego miejsca — nie sprawdza sufitu.
     *
     * TO NIE JEST METODA DO PODEJMOWANIA DECYZJI O WYSYŁCE (D-076). Drugi
     * krok rezerwacji (`zajmijWlasne()`) chodzi w środku blokady założonej
     * przez `sprobujZarezerwowac()`, a ta metoda jest jego wersją BEZ
     * blokady i Z rodzicem — zajmuje miejsce także we wspólnej puli.
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
     * pierwsze miejsce, w które się prosi. Jej krok `zajmijWlasne()` chodzi
     * w środku blokady z `sprobujZarezerwowac()`, a ostrzeżenie zapisuje dziennik i (przy
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
        // LIST PONAD SUFITEM LICZY SIĘ TAKŻE WE WSPÓLNEJ PULI. Bez tego list
        // próbny `--tylko` wychodził, a wspólny licznik całej poczty o nim nie
        // wiedział — czyli „musi się POLICZYĆ, żeby nie zniknął z rachunku
        // wiadra" (komentarz w `kuking:wyslij-podsumowania`) było prawdą
        // tylko dla sufitu własnego funkcji.
        $this->nadrzedny?->zajmij();

        $this->zajmijWlasne(self::dzisiaj());
    }

    /**
     * Krok „zajmij" W TYM JEDNYM liczniku, bez rodzica.
     *
     * Osobno od `zajmij()`, bo w `sprobujZarezerwowac()` rodzic zajął już
     * swoje miejsce własną rezerwacją — wołanie tam `zajmij()` policzyłoby
     * jeden list dwa razy we wspólnej puli.
     */
    private function zajmijWlasne(string $doba): void
    {
        $klucz = $this->klucz($doba);

        // `add` zakłada klucz z terminem ważności tylko wtedy, gdy go
        // jeszcze nie ma — bez tego `increment` na nieistniejącym kluczu
        // zakłada wpis BEZ terminu i licznik zostaje na zawsze.
        Cache::add($klucz, 0, now()->addDays(2));
        Cache::increment($klucz);

        $this->dobyRezerwacji[] = $doba;
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

        if (blank(config('logging.channels.blad_webhook.url'))) {
            return;
        }

        // JEDNO ZDANIE, SAME LICZBY I NAZWA FUNKCJI. Kontekstu tu nie ma
        // celowo: `WebhookBleduHandler` bierze z rekordu bez wyjątku sam
        // `message`, więc cokolwiek dołożonego w `context` i tak by nie
        // wyszło — a gdyby kiedyś wyszło, wychodziłoby do usługi, nad którą
        // nie mamy kontroli (AGENTS.md §7, audyt A6-01).
        Log::channel('blad_webhook')->error(sprintf(
            'Poczta: sufit „%s" zużyty w %d%% (%d z %d). Gdy się skończy, ta funkcja przestanie wysyłać listy.',
            $this->funkcja,
            (int) floor($zuzyte * 100 / $budzet),
            $zuzyte,
            $budzet,
        ));
    }

    /** @param  string|null  $doba  `Y-m-d`; bez niej — dzisiejsza doba. */
    public function zuzyte(?string $doba = null): int
    {
        return (int) Cache::get($this->klucz($doba), 0);
    }

    public function budzet(): int
    {
        return (int) config($this->kluczKonfiguracji, 0);
    }

    private function klucz(?string $doba = null): string
    {
        return self::PREFIKS.$this->funkcja.':'.($doba ?? self::dzisiaj());
    }

    private static function dzisiaj(): string
    {
        return now()->format('Y-m-d');
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
