<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\KluczeLimitow;
use App\Support\MaskaAdresuIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `sessions.ip_address` trzyma ZGRUBNY adres, nie dokładny (RZ-01).
 *
 * CO TEN PLIK SPRAWDZA NAPRAWDĘ
 * Nie „czy istnieje klasa maskująca" — tylko czy przy ZWYKŁYM ŻĄDANIU HTTP,
 * przez prawdziwy sterownik sesji `database` i prawdziwy middleware, do bazy
 * wchodzi adres obcięty. Test uderzający w samą klasę pomocniczą byłby zielony
 * także wtedy, gdyby nikt tej klasy nie podłączył — a dokładnie tak wygląda
 * usterka, której tu pilnujemy.
 *
 * KONTROLA DODATNIA JEST W KAŻDYM TEŚCIE, NIE OBOK
 * „Kolumna nie zawiera pełnego adresu" jest zielone także wtedy, gdy kolumna
 * jest PUSTA — a pustka to inna decyzja (opcja C raportu RZ-01), której nikt
 * nie podjął. Dlatego każda asercja o maskowaniu stoi w parze z asercją, że
 * zamaskowany adres NAPRAWDĘ tam JEST, w oczekiwanej postaci.
 */
class SesjaZapisujeTylkoZgrubnyAdresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * STEROWNIK SESJI PRZESTAWIAMY PRZED STARTEM APLIKACJI, NIE W TRAKCIE.
     *
     * `phpunit.xml` ustawia `SESSION_DRIVER=array`, więc bez tego cały plik
     * sprawdzałby sterownik, którego na produkcji nie ma (`config/session.php`
     * ma `database` jako wartość domyślną, a `.env.example` i `docker/php.ini`
     * mówią to samo).
     *
     * DLACZEGO NIE `config([...])` PLUS `forgetInstance('session')`
     * Bo to dawało test zielony z FAŁSZYWEGO powodu — a właściwie czerwony:
     * `Session::extend()` zapisuje domknięcie w POLU `SessionManager`,
     * a `forgetInstance()` buduje menedżera od nowa, już bez niego. Test
     * sprawdzałby wtedy gołego `DatabaseSessionHandler` Laravela i pokazywał
     * pełny adres także po poprawnie wykonanej zmianie. Zmierzone —
     * ta wersja tego pliku naprawdę powstała i naprawdę była czerwona.
     *
     * Przestawienie zmiennej środowiskowej przed `parent::setUp()` idzie
     * dokładnie tą samą drogą co produkcja: `env()` → `config/session.php`
     * → `AppServiceProvider::boot()` → pierwsze żądanie.
     */
    protected function setUp(): void
    {
        $this->sterownikSesji('database');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->sterownikSesji('array');
    }

    private function sterownikSesji(string $sterownik): void
    {
        putenv('SESSION_DRIVER='.$sterownik);
        $_ENV['SESSION_DRIVER'] = $sterownik;
        $_SERVER['SESSION_DRIVER'] = $sterownik;
    }

    /**
     * Kontrola, że przestawienie wyżej NAPRAWDĘ zadziałało.
     *
     * Bez niej każdy test w tym pliku byłby zielony przy sterowniku `array`:
     * tabela `sessions` zostałaby pusta, a `assertCount(1, ...)` obleje
     * z komunikatem o sesji, nie o sterowniku. Ta asercja mówi wprost, co
     * jest nie tak.
     */
    private function wlaczSesjeWBazie(): void
    {
        $this->assertSame(
            'database',
            config('session.driver'),
            'Test miał chodzić na sterowniku `database` (tym z produkcji), a chodzi na innym.',
        );
    }

    /** @return list<object> */
    private function wierszeSesji(): array
    {
        return DB::table('sessions')->get()->all();
    }

    public function test_zadanie_z_adresu_ipv4_zapisuje_w_bazie_podsiec_a_nie_dokladny_adres(): void
    {
        $this->wlaczSesjeWBazie();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->get('/')
            ->assertSuccessful();

        $wiersze = $this->wierszeSesji();

        // Bez tego cały test byłby zielony przy sesji, która w ogóle nie
        // trafiła do bazy — czyli przy sprawdzaniu niczego.
        $this->assertCount(1, $wiersze, 'Żądanie nie zapisało sesji w bazie — test sprawdza co innego, niż zakłada.');

        // KONTROLA DODATNIA: zgrubny adres NAPRAWDĘ się zapisał.
        $this->assertSame('203.0.113.0', $wiersze[0]->ip_address);

        // I dopiero teraz to, o co chodzi: dokładnego adresu tam nie ma.
        $this->assertNotSame('203.0.113.7', $wiersze[0]->ip_address);
    }

    public function test_zadanie_z_adresu_ipv6_zapisuje_prefiks_48_bitow(): void
    {
        $this->wlaczSesjeWBazie();

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1234:5678:9abc:def0:1234:5678'])
            ->get('/')
            ->assertSuccessful();

        $wiersze = $this->wierszeSesji();

        $this->assertCount(1, $wiersze, 'Żądanie nie zapisało sesji w bazie — test sprawdza co innego, niż zakłada.');
        // KONTROLA DODATNIA: zostaje prefiks sieci, a nie pustka ani `::`.
        $this->assertSame('2001:db8:1234::', $wiersze[0]->ip_address);
    }

    /**
     * Kolumna `varchar(45)` musi pomieścić każdą postać, jaką ta maska zwraca.
     * Najdłuższa to pełny IPv4 z wyzerowanym oktetem, więc zapas jest duży —
     * ale to jest granica schematu i ma być sprawdzona, a nie założona.
     */
    #[DataProvider('adresyIWyniki')]
    public function test_maska_zostawia_zgrubna_informacje_zamiast_pustki(?string $adres, ?string $oczekiwany): void
    {
        $wynik = MaskaAdresuIp::zgrubny($adres);

        $this->assertSame($oczekiwany, $wynik);

        if ($oczekiwany === null) {
            return;
        }

        $this->assertLessThanOrEqual(45, strlen($wynik), 'Wynik nie mieści się w `sessions.ip_address varchar(45)`.');

        // Adres, który po maskowaniu ma wyjść IDENTYCZNY (np. `10.0.0.0`),
        // jest w tej tablicy świadomie — pokazuje, że maska nie dokłada nic
        // od siebie. Dla wszystkich pozostałych żądamy, żeby coś UBYŁO;
        // bez tego warunku ten test przeszedłby także dla maski, która
        // zwraca wejście nietknięte.
        if ($oczekiwany !== $adres) {
            $this->assertNotSame($adres, $wynik, 'Maska oddała adres nietknięty.');
        }
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function adresyIWyniki(): array
    {
        return [
            'IPv4 — ostatni oktet na zero' => ['203.0.113.7', '203.0.113.0'],
            'IPv4 — oktet już zerowy zostaje zerowy' => ['10.0.0.0', '10.0.0.0'],
            'IPv4 — maksymalna długość zapisu' => ['255.255.255.255', '255.255.255.0'],
            'IPv6 — obcięcie do /48' => ['2001:db8:1234:5678:9abc:def0:1234:5678', '2001:db8:1234::'],
            'IPv6 — zapis skrócony' => ['2a00:1450:4001:80f::200e', '2a00:1450:4001::'],
            'IPv6 — adres IPv4 w przebraniu dostaje maskę IPv4' => ['::ffff:203.0.113.7', '203.0.113.0'],
            'brak adresu' => [null, null],
            'pusty łańcuch' => ['', null],
            'śmieć, który nie jest adresem' => ['nie-adres', null],
        ];
    }

    /**
     * PUŁAPKA, KTÓREJ TEN PLIK PILNUJE OSOBNO — i to jest jego druga funkcja.
     *
     * Maskowanie dotyczy WYŁĄCZNIE tego, co ląduje w `sessions`. Kusi, żeby
     * „ujednolicić" je z `KluczeLimitow::adres()`, bo tam też jest adres IP.
     * Nie wolno: koszyk C limitera liczy próby logowania NA MIEJSCE, więc
     * po zamaskowaniu jedna osoba atakująca wyczerpałaby limit za maksymalnie
     * 254 sąsiadów z tej samej podsieci /24 (a przy CGNAT operatora
     * komórkowego — za znacznie więcej).
     *
     * Asercja mówi to jednym zdaniem: dwa różne adresy z jednej podsieci mają
     * MIEĆ różne koszyki.
     */
    public function test_maskowanie_sesji_nie_scala_koszykow_limitera_logowania(): void
    {
        $klucze = new KluczeLimitow;

        $this->assertNotSame(
            $klucze->adres('203.0.113.7'),
            $klucze->adres('203.0.113.9'),
            'Dwa różne adresy z jednej podsieci /24 dostały ten sam koszyk limitera. '
            .'Maskowanie weszło do KluczeLimitow::adres() — a tam nie wolno go wpuścić: '
            .'jedna osoba atakująca zablokowałaby wtedy logowanie sąsiadom.',
        );

        // Kontrola dodatnia dla tej samej asercji: ten sam adres nadal daje
        // ten sam koszyk, więc powyższe „różne" nie bierze się z losowości.
        $this->assertSame($klucze->adres('203.0.113.7'), $klucze->adres('203.0.113.7'));
    }
}
