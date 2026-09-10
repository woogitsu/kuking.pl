<?php

declare(strict_types=1);

namespace App\Domain\Security;

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
     * Czy zostało jeszcze miejsce w dzisiejszym budżecie.
     *
     * Budżet ustawiony na zero albo mniej znaczy „ta funkcja nie wysyła dziś
     * nic" i jest poprawną, świadomą konfiguracją (awaryjne odcięcie poczty
     * bez wyłączania całej drogi — ludzie z ważnym linkiem w skrzynce nadal
     * się nim zalogują).
     */
    public function zostalo(): int
    {
        return max(0, $this->budzet() - $this->zuzyte());
    }

    public function jestMiejsce(): bool
    {
        return $this->zostalo() > 0;
    }

    /**
     * Zajmij jedno miejsce w budżecie — wołane DOPIERO wtedy, gdy list
     * naprawdę poszedł.
     *
     * Kolejność ma znaczenie i jest tu odwrotna niż przy zwykłym limicie
     * zapytań: gdyby licznik ruszał przy każdym WYSŁANIU FORMULARZA, byle
     * automat wpisujący nieistniejące adresy wyczerpałby dobowy budżet
     * w kilka minut i zamknął drogę wszystkim prawdziwym ludziom, nie
     * wysławszy ani jednego listu. Przed samym zalewaniem formularza broni
     * `limits.login_link` i `login_link.limit_na_adres`.
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

        $this->ostrzezZawczasu();
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
     * `error`, NIE `warning`, I TO JEST WYBÓR
     * Poziom decyduje o tym, czy wpis pójdzie na webhook błędów (D-041)
     * i do Sentry — czyli czy właściciel ma szansę dowiedzieć się BEZ
     * zaglądania w dziennik. `warning` byłby uprzejmy i niewidoczny, a to
     * jest dokładnie ta klasa usterki, którą issue #234 tępi.
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

    /** Klucz „już dziś ostrzegałem o tej funkcji". Osobny od licznika. */
    private function kluczOstrzezenia(): string
    {
        return self::PREFIKS.'ostrzezenie:'.$this->funkcja.':'.now()->format('Y-m-d');
    }
}
