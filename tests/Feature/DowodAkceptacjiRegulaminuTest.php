<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WejsciePrzezDostawce\DostawcaWejscia;
use App\Domain\Security\WejsciePrzezDostawce\TozsamoscOdDostawcy;
use App\Domain\Security\WejsciePrzezDostawce\WejdzPrzezDostawce;
use App\Domain\Users\Actions\ZalozKonto;
use App\Domain\Zgody\WersjaDokumentu;
use App\Domain\Zgody\ZapiszAkceptacjeRegulaminu;
use App\Domain\Zgody\ZmianaRegulaminu;
use App\Facebook\DostawcaWejsciaFacebook;
use App\Google\DostawcaWejsciaGoogle;
use App\Http\Support\ZadanieHttp;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Trwały dowód akceptacji regulaminu przy rejestracji (#2217).
 *
 * Dowód leży w `dziennik_zgod` (D-072), nie w osobnym mechanizmie: cel
 * `regulamin`, wersja OBOWIĄZUJĄCA w chwili akceptacji (D-327), moment
 * i droga rejestracji. Powstaje w tej samej transakcji co konto, dla każdej
 * drogi (hasło, Google, Facebook).
 *
 * @bez-kontroli-dodatniej Plik nie czyta kodu źródłowego: sprawdza zapisy w bazie i zachowanie migracji wczytanej przez require; kontrola dodatnia stoi w tym samym pliku.
 */
final class DowodAkceptacjiRegulaminuTest extends TestCase
{
    use RefreshDatabase;

    private const PLIK_MIGRACJI = 'migrations/2026_09_29_234500_dziennik_zgod_akceptacja_regulaminu.php';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /** @return array<string, string> */
    private function dane(string $nazwa = 'nowaosoba'): array
    {
        return [
            'display_name' => 'Nowa Osoba', 'username' => $nazwa,
            'email' => $nazwa.'@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ];
    }

    private function akceptacje(User $osoba)
    {
        return WpisZgody::query()
            ->where('user_id', $osoba->getKey())
            ->where('cel', WpisZgody::CEL_REGULAMIN)
            ->orderBy('id')
            ->get();
    }

    private function migracja(): object
    {
        return require database_path(self::PLIK_MIGRACJI);
    }

    // -----------------------------------------------------------------
    // 1. Nowe konto ma dowód: wersja, czas, kanał
    // -----------------------------------------------------------------

    public function test_rejestracja_formularzem_zapisuje_wersje_regulaminu_czas_i_kanal(): void
    {
        $this->travelTo('2026-09-30 10:15:00');
        config()->set('kuking.zgody.wersja_regulaminu', '2026-09-26');

        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));

        $osoba = User::query()->where('email', 'nowaosoba@example.test')->firstOrFail();
        $wpisy = $this->akceptacje($osoba);

        $this->assertCount(1, $wpisy);
        $this->assertSame(WpisZgody::UDZIELONA, $wpisy[0]->czynnosc);
        $this->assertSame(WpisZgody::ZRODLO_REJESTRACJA_HASLO, $wpisy[0]->zrodlo);
        $this->assertSame('2026-09-26', $wpisy[0]->wersja_regulaminu);
        $this->assertSame(WersjaDokumentu::polityka()->obowiazujaca(), $wpisy[0]->wersja_polityki);
        $this->assertTrue($wpisy[0]->wystapilo_at->equalTo(now()));
    }

    /** @return array<string, array{class-string<DostawcaWejscia>, string}> */
    public static function dostawcy(): array
    {
        return [
            'google' => [DostawcaWejsciaGoogle::class, WpisZgody::ZRODLO_REJESTRACJA_GOOGLE],
            'facebook' => [DostawcaWejsciaFacebook::class, WpisZgody::ZRODLO_REJESTRACJA_FACEBOOK],
        ];
    }

    /** @param class-string<DostawcaWejscia> $klasa */
    #[Test]
    #[DataProvider('dostawcy')]
    public function test_domkniecie_konta_przez_dostawce_zapisuje_ten_sam_dowod(string $klasa, string $zrodlo): void
    {
        $wejscie = new WejdzPrzezDostawce(new $klasa);
        $request = Request::create('/', 'POST');
        $sesja = app('session')->driver();
        $sesja->start();
        $request->setLaravelSession($sesja);
        $zadanie = ZadanieHttp::z($request);

        $tozsamosc = new TozsamoscOdDostawcy(
            identyfikator: 'id-u-dostawcy-123',
            email: 'basia@example.test',
            emailPotwierdzony: $wejscie->dostawca()->potwierdzaAdres(),
            imie: 'Basia',
        );
        $wejscie->zapamietaj($zadanie, $tozsamosc);

        $konto = $wejscie->zalozKonto(
            $zadanie,
            $tozsamosc,
            ['display_name' => 'Basia', 'username' => 'basiaoauth'],
            app(ZalozKonto::class),
        );

        $this->assertNotNull($konto);
        $wpisy = $this->akceptacje($konto->user);
        $this->assertCount(1, $wpisy);
        $this->assertSame($zrodlo, $wpisy[0]->zrodlo);
        $this->assertSame(config('kuking.zgody.wersja_regulaminu'), $wpisy[0]->wersja_regulaminu);
    }

    // -----------------------------------------------------------------
    // 2. Atomowość: konto i dowód razem albo wcale
    // -----------------------------------------------------------------

    public function test_awaria_zapisu_dowodu_wycofuje_zalozenie_konta(): void
    {
        $odmowa = null;

        try {
            app(ZalozKonto::class)->handle(
                email: 'bez.dowodu@example.test',
                displayName: 'Bez Dowodu',
                username: 'bezdowodu',
                zrodloAkceptacji: 'nieznana_droga',
                haslo: 'zielonapietruszkarano',
            );
        } catch (\InvalidArgumentException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Konto z nieznaną drogą akceptacji powstało.');
        $this->assertSame(0, User::query()->where('email', 'bez.dowodu@example.test')->count());
        $this->assertSame(0, DB::table('profiles')->where('username', 'bezdowodu')->count());
        $this->assertSame(0, WpisZgody::query()->where('cel', WpisZgody::CEL_REGULAMIN)->count());
    }

    // -----------------------------------------------------------------
    // 3. Zamknięcie paska to nie akceptacja
    // -----------------------------------------------------------------

    public function test_zamkniecie_paska_o_zmianie_regulaminu_nie_jest_akceptacja(): void
    {
        $osoba = $this->user('stara_konto', ['created_at' => '2026-08-01 10:00:00']);

        app(ZmianaRegulaminu::class)->zamknij($osoba);

        $this->assertSame(ZmianaRegulaminu::wersja(), $osoba->fresh()->terms_notice_dismissed_version);
        $this->assertCount(0, $this->akceptacje($osoba), 'Pasek zamknięty, a dowód akceptacji powstał.');
        $this->assertSame(0, WpisZgody::query()->where('user_id', $osoba->getKey())->count());
    }

    public function test_konto_sprzed_zmiany_nie_ma_dowodu_i_nikt_go_nie_dopisuje_po_cichu(): void
    {
        $stare = User::factory()->create();

        // Konto z fabryki nie przeszło przez rejestrację: to jest „brak dowodu”,
        // a nie akceptacja z datą utworzenia konta (#2217, backfill celowo pusty).
        $this->assertCount(0, $this->akceptacje($stare));
    }

    // -----------------------------------------------------------------
    // 4. Nowsza wersja nie nadpisuje historii
    // -----------------------------------------------------------------

    public function test_nowsza_wersja_regulaminu_dopisuje_wiersz_a_nie_nadpisuje_historii(): void
    {
        config()->set('kuking.zgody.wersja_regulaminu', '2026-09-26');

        $this->post(route('register'), $this->dane('pierwsza'))->assertRedirect(route('onboarding.interests'));
        $pierwsza = User::query()->where('email', 'pierwsza@example.test')->firstOrFail();

        // Kolejna wersja (drobna, obowiązuje od publikacji).
        config()->set('kuking.zgody.wersja_regulaminu', '2026-10-15');
        $this->travelTo('2026-10-16 09:00:00');

        app(ZapiszAkceptacjeRegulaminu::class)->handle($pierwsza, WpisZgody::ZRODLO_REJESTRACJA_HASLO);

        $this->post(route('logout'));
        $this->post(route('register'), $this->dane('druga'))->assertRedirect(route('onboarding.interests'));
        $druga = User::query()->where('email', 'druga@example.test')->firstOrFail();

        $this->assertSame(['2026-09-26', '2026-10-15'], $this->akceptacje($pierwsza)->pluck('wersja_regulaminu')->all());
        $this->assertSame(['2026-10-15'], $this->akceptacje($druga)->pluck('wersja_regulaminu')->all());
    }

    public function test_wpisu_akceptacji_nie_da_sie_zmienic_ani_skasowac(): void
    {
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $wpis = WpisZgody::query()->where('cel', WpisZgody::CEL_REGULAMIN)->firstOrFail();

        try {
            $wpis->update(['wersja_regulaminu' => '1999-01-01']);
            $this->fail('Model pozwolił zmienić wpis akceptacji.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('dziennik_zgod')->where('id', $wpis->id)->update(['wersja_regulaminu' => '1999-01-01']);
    }

    // -----------------------------------------------------------------
    // 5. Baza pilnuje kształtu dowodu
    // -----------------------------------------------------------------

    public function test_baza_odrzuca_akceptacje_bez_wersji_regulaminu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('dziennik_zgod')->insert([
            'user_id' => User::factory()->create()->getKey(), 'cel' => 'regulamin', 'czynnosc' => 'udzielona',
            'zrodlo' => 'rejestracja_haslo', 'wersja_polityki' => '2026-09-10',
        ]);
    }

    public function test_baza_odrzuca_wersje_regulaminu_przy_innym_celu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('dziennik_zgod')->insert([
            'user_id' => User::factory()->create()->getKey(), 'cel' => 'tygodniowy_digest', 'czynnosc' => 'udzielona',
            'zrodlo' => 'ustawienia', 'wersja_polityki' => '2026-09-10', 'wersja_regulaminu' => '2026-09-26',
        ]);
    }

    public function test_baza_odrzuca_wycofanie_akceptacji_regulaminu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('dziennik_zgod')->insert([
            'user_id' => User::factory()->create()->getKey(), 'cel' => 'regulamin', 'czynnosc' => 'wycofana',
            'zrodlo' => 'rejestracja_haslo', 'wersja_polityki' => '2026-09-10', 'wersja_regulaminu' => '2026-09-26',
        ]);
    }

    // -----------------------------------------------------------------
    // 6. Rollback odmawia przy dowodach (D-088), przechodzi bez nich
    // -----------------------------------------------------------------

    public function test_cofniecie_migracji_odmawia_gdy_sa_akceptacje_regulaminu(): void
    {
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie skasowało kolumnę mimo zapisanych dowodów akceptacji.');
        $this->assertStringContainsString('1 zapisów', $odmowa->getMessage());
        $this->assertStringContainsString('akceptacje_regulaminu.csv', $odmowa->getMessage());
        $this->assertSame(1, WpisZgody::query()->where('cel', 'regulamin')->count());
    }

    public function test_kontrola_dodatnia_bez_akceptacji_cofa_sie_i_wraca(): void
    {
        WpisZgody::create([
            'user_id' => User::factory()->create()->getKey(),
            'cel' => WpisZgody::CEL_TYGODNIOWY_DIGEST,
            'czynnosc' => WpisZgody::UDZIELONA,
            'zrodlo' => WpisZgody::ZRODLO_USTAWIENIA,
            'wersja_polityki' => '2026-09-10',
        ]);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('dziennik_zgod', 'wersja_regulaminu'));
        // Stary wiersz o digeście przeżył cofnięcie.
        $this->assertSame(1, DB::table('dziennik_zgod')->count());

        $this->migracja()->up();

        $this->assertTrue(Schema::hasColumn('dziennik_zgod', 'wersja_regulaminu'));
        $this->assertSame(1, DB::table('dziennik_zgod')->count());
    }
}
