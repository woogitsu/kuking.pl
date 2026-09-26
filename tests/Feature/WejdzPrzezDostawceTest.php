<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WejsciePrzezDostawce\DostawcaWejscia;
use App\Domain\Security\WejsciePrzezDostawce\TozsamoscOdDostawcy;
use App\Domain\Security\WejsciePrzezDostawce\WejdzPrzezDostawce;
use App\Domain\Security\WejsciePrzezDostawce\WynikWejscia;
use App\Domain\Users\Actions\ZalozKonto;
use App\Facebook\DostawcaWejsciaFacebook;
use App\Facebook\TozsamoscFacebook;
use App\Google\DostawcaWejsciaGoogle;
use App\Google\TozsamoscGoogle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MACIERZ KONTRAKTOWA WSPÓLNEGO PRZYPADKU UŻYCIA (issue #1035).
 *
 * Każdy przypadek chodzi DWA RAZY — dla Google i dla Facebooka — na tej samej
 * klasie `WejdzPrzezDostawce`. Testy protokołów (PKCE, nonce i token Google;
 * `state`, Graph API i `appsecret_proof` Facebooka) zostają w
 * `LogowanieKontemGoogleTest` i `LogowanieKontemFacebookiemTest`, tak samo
 * jak testy HTTP obu dróg — tu jest wyłącznie to, co ma być wspólne.
 *
 * Różnice, które MAJĄ zostać różnicami (potwierdzenie adresu, dowód
 * połączenia), mają tu własne, jawnie rozdzielone testy.
 */
class WejdzPrzezDostawceTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTYFIKATOR = 'id-u-dostawcy-123';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** @return array<string, array{class-string<DostawcaWejscia>}> */
    public static function dostawcy(): array
    {
        return [
            'google' => [DostawcaWejsciaGoogle::class],
            'facebook' => [DostawcaWejsciaFacebook::class],
        ];
    }

    // ───────────────────────────── narzędzia ─────────────────────────────

    /** @param class-string<DostawcaWejscia> $klasa */
    private function wejscie(string $klasa): WejdzPrzezDostawce
    {
        return new WejdzPrzezDostawce(new $klasa);
    }

    /** Żądanie na tej samej sesji, którą widzi strażnik `Auth`. */
    private function zadanie(array $pola = []): Request
    {
        $request = Request::create('/', 'POST', $pola);
        $sesja = app('session')->driver();
        $sesja->start();
        $request->setLaravelSession($sesja);

        return $request;
    }

    private function tozsamosc(WejdzPrzezDostawce $wejscie, ?string $email = 'basia@example.test'): TozsamoscOdDostawcy
    {
        return new TozsamoscOdDostawcy(
            identyfikator: self::IDENTYFIKATOR,
            email: $email,
            emailPotwierdzony: $wejscie->dostawca()->potwierdzaAdres(),
            imie: 'Basia',
        );
    }

    private function konto(array $atrybuty = []): User
    {
        return $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => now(), ...$atrybuty]);
    }

    private function wpisyDziennika(string $akcja): int
    {
        return DB::table('audit_log')->where('action', $akcja)->count();
    }

    // ─────────────────────────── bramki wejścia ───────────────────────────

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_aktywne_konto_wchodzi_z_nowa_sesja_i_wpisem_w_dzienniku(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $basia = $this->konto();
        $request = $this->zadanie();
        $przed = $request->session()->getId();

        $this->assertSame(WynikWejscia::Wpuszczony, $wejscie->wpusc($request, $basia));

        $this->assertAuthenticatedAs($basia);
        $this->assertNotSame($przed, $request->session()->getId(), 'Wejście ma zregenerować sesję.');
        $this->assertSame(1, $this->wpisyDziennika($wejscie->dostawca()->akcjaWejscia()));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_konto_zawieszone_wchodzi(string $klasa): void
    {
        $basia = $this->konto();
        $basia->suspend(now()->addDays(3));

        $this->assertSame(WynikWejscia::Wpuszczony, $this->wejscie($klasa)->wpusc($this->zadanie(), $basia->refresh()));
        $this->assertAuthenticatedAs($basia);
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_konto_zamkniete_nie_wchodzi_i_nie_zostawia_wpisu(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $basia = $this->konto();
        $basia->ban();

        $this->assertSame(WynikWejscia::KontoZamkniete, $wejscie->wpusc($this->zadanie(), $basia->refresh()));
        $this->assertGuest();
        $this->assertSame(0, $this->wpisyDziennika($wejscie->dostawca()->akcjaWejscia()));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_konto_obslugi_serwisu_nie_wchodzi(string $klasa): void
    {
        $this->assertSame(WynikWejscia::KontoObslugi, $this->wejscie($klasa)->wpusc($this->zadanie(), $this->moderator()));
        $this->assertGuest();
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function konto_z_2fa_czeka_na_kod_zamiast_wejsc(string $klasa): void
    {
        $basia = $this->konto();
        $totp = app(TwoFactorAuthenticator::class);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        $request = $this->zadanie();
        $przed = $request->session()->getId();

        $this->assertSame(WynikWejscia::DrugiSkladnik, $this->wejscie($klasa)->wpusc($request, $basia->refresh()));
        $this->assertGuest();
        $this->assertSame($basia->getKey(), $request->session()->get('logowanie.2fa.user_id'));
        $this->assertNotSame($przed, $request->session()->getId());
    }

    // ─────────────────────────── stan w sesji ───────────────────────────

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_tozsamosc_wraca_z_sesji_z_potwierdzeniem_gwarantowanym_przez_dostawce(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $request = $this->zadanie();

        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));
        $odczyt = $wejscie->tozsamoscZSesji($request);

        $this->assertNotNull($odczyt);
        $this->assertSame(self::IDENTYFIKATOR, $odczyt->identyfikator);
        $this->assertSame('basia@example.test', $odczyt->email);
        $this->assertSame($wejscie->dostawca()->potwierdzaAdres(), $odczyt->emailPotwierdzony);
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_wygasla_tozsamosc_znika_z_sesji(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $request = $this->zadanie();
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));

        $this->travel($wejscie->dostawca()->waznoscDomknieciaMinut() + 1)->minutes();

        $this->assertNull($wejscie->tozsamoscZSesji($request));
        $this->assertNull($request->session()->get('wejscie_'.$wejscie->dostawca()->nazwa().'.tozsamosc'));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_wygasniecie_przed_zalozeniem_zachowuje_wpisane_imie_i_nazwe(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $request = $this->zadanie(['display_name' => 'Basia', 'username' => 'basia-z-podkarpacia']);
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));

        $this->travel($wejscie->dostawca()->waznoscDomknieciaMinut() + 1)->minutes();

        $this->assertNull($wejscie->tozsamoscDoZalozenia($request));

        // Ten sam człowiek wraca tym samym kontem u dostawcy (#850).
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));
        $ekran = $wejscie->ekranDomkniecia($request, $this->tozsamosc($wejscie));

        $this->assertSame('Basia', $ekran['proponowaneImie']);
        $this->assertSame('basia-z-podkarpacia', $ekran['proponowanaNazwa']);
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_zapomnienie_czysci_tozsamosc_i_wskazane_konto(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $request = $this->zadanie();
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));
        $wejscie->zapamietajKonto($request, $this->konto());

        $wejscie->zapomnij($request);

        $this->assertNull($wejscie->tozsamoscZSesji($request));
        $this->assertNull($wejscie->kontoZSesji($request));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_do_sesji_nie_trafia_potwierdzenie_inne_niz_gwarantuje_dostawca(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);

        $this->expectException(LogicException::class);

        $wejscie->zapamietaj($this->zadanie(), new TozsamoscOdDostawcy(
            identyfikator: self::IDENTYFIKATOR,
            email: 'basia@example.test',
            emailPotwierdzony: ! $wejscie->dostawca()->potwierdzaAdres(),
            imie: '',
        ));
    }

    // ─────────────────────────── łączenie konta ───────────────────────────

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_polaczenie_zapisuje_powiazanie_i_wpis(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $basia = $this->konto();

        $polaczone = $wejscie->polacz($this->zadanie(), $basia, $this->tozsamosc($wejscie));

        $this->assertNotNull($polaczone);
        $this->assertTrue($wejscie->dostawca()->kontoPowiazane(self::IDENTYFIKATOR)?->is($basia));
        $this->assertSame(1, $this->wpisyDziennika($wejscie->dostawca()->akcjaPolaczenia()));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_moderator_nie_polaczy_konta(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $moderator = $this->moderator();
        $tozsamosc = new TozsamoscOdDostawcy(self::IDENTYFIKATOR, $moderator->email, $wejscie->dostawca()->potwierdzaAdres(), '');

        $this->assertNull($wejscie->polacz($this->zadanie(), $moderator, $tozsamosc));
        $this->assertNull($wejscie->dostawca()->kontoPowiazane(self::IDENTYFIKATOR));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_konto_zamkniete_nie_polaczy_konta(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $basia = $this->konto();
        $basia->ban();

        $this->assertNull($wejscie->polacz($this->zadanie(), $basia->refresh(), $this->tozsamosc($wejscie)));
        $this->assertNull($wejscie->dostawca()->kontoPowiazane(self::IDENTYFIKATOR));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_tozsamosc_przypisana_innej_osobie_nie_laczy_sie_drugi_raz(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $ktosInny = $this->user('ktosinny', ['email' => 'ktos@example.test']);
        $wejscie->dostawca()->polacz($ktosInny, self::IDENTYFIKATOR);
        $basia = $this->konto();

        $this->assertNull($wejscie->polacz($this->zadanie(), $basia, $this->tozsamosc($wejscie)));
        $this->assertTrue($wejscie->dostawca()->kontoPowiazane(self::IDENTYFIKATOR)?->is($ktosInny));
    }

    /**
     * RÓŻNICA, KTÓRA MA ZOSTAĆ: przy Google dowodem jest zgodny, potwierdzony
     * u nas adres; przy Facebooku — samo bycie zalogowanym, adres nic nie
     * znaczy (D-069, D-098).
     */
    #[Test]
    public function test_dowod_polaczenia_rozni_sie_miedzy_dostawcami(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => null]);
        $innyAdres = new TozsamoscOdDostawcy(self::IDENTYFIKATOR, 'inny@example.test', true, '');
        $tenSamAdres = new TozsamoscOdDostawcy(self::IDENTYFIKATOR, 'basia@example.test', true, '');

        $google = $this->wejscie(DostawcaWejsciaGoogle::class);
        $this->assertFalse($google->wolnoPolaczyc($basia, $tenSamAdres), 'Google: adres niepotwierdzony u nas — reguła 2.');

        $basia->forceFill(['email_verified_at' => now()])->save();
        $this->assertFalse($google->wolnoPolaczyc($basia, $innyAdres), 'Google: inny adres — brak dowodu.');
        $this->assertTrue($google->wolnoPolaczyc($basia, $tenSamAdres));

        $facebook = $this->wejscie(DostawcaWejsciaFacebook::class);
        $this->assertTrue($facebook->wolnoPolaczyc($basia, new TozsamoscOdDostawcy(self::IDENTYFIKATOR, 'inny@example.test', false, '')));
        $this->assertTrue($facebook->wolnoPolaczyc($basia, new TozsamoscOdDostawcy(self::IDENTYFIKATOR, null, false, '')));
    }

    // ────────────────────────── domknięcie konta ──────────────────────────

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_nowe_konto_ma_potwierdzenie_adresu_tylko_od_dostawcy_ktory_je_daje(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $request = $this->zadanie();
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie, 'nowa@example.test'));
        $przed = $request->session()->getId();

        $konto = $wejscie->zalozKonto(
            $request,
            $this->tozsamosc($wejscie, 'nowa@example.test'),
            ['display_name' => 'Nowa', 'username' => 'nowaosoba'],
            app(ZalozKonto::class),
        );

        $this->assertNotNull($konto);
        $this->assertSame($wejscie->dostawca()->potwierdzaAdres(), $konto->user->email_verified_at !== null);
        $this->assertTrue($wejscie->dostawca()->kontoPowiazane(self::IDENTYFIKATOR)?->is($konto->user));
        $this->assertAuthenticatedAs($konto->user);
        $this->assertNotSame($przed, $request->session()->getId());
        $this->assertNull($wejscie->tozsamoscZSesji($request), 'Po założeniu konta tożsamość znika z sesji.');
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_konto_na_zajety_adres_nie_powstaje_a_stan_znika(string $klasa): void
    {
        $wejscie = $this->wejscie($klasa);
        $this->konto();
        $request = $this->zadanie();
        $wejscie->zapamietaj($request, $this->tozsamosc($wejscie));

        $this->assertNull($wejscie->zalozKonto(
            $request,
            $this->tozsamosc($wejscie),
            ['display_name' => 'Druga', 'username' => 'drugabasia'],
            app(ZalozKonto::class),
        ));

        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'basia@example.test')->count());
        $this->assertNull($wejscie->tozsamoscZSesji($request));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_nazwa_zastrzezona_nie_przechodzi_walidacji(string $klasa): void
    {
        $this->expectException(ValidationException::class);

        $this->wejscie($klasa)->daneDomkniecia($this->zadanie([
            'display_name' => 'Basia', 'username' => 'ądmin', 'age_confirmed' => '1', 'terms_accepted' => '1',
        ]));
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_nazwa_zajeta_nie_przechodzi_walidacji(string $klasa): void
    {
        $this->user('zajetanazwa');

        try {
            $this->wejscie($klasa)->daneDomkniecia($this->zadanie([
                'display_name' => 'Basia', 'username' => 'zajetanazwa', 'age_confirmed' => '1', 'terms_accepted' => '1',
            ]));
            $this->fail('Zajęta nazwa przeszła walidację.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors());
        }
    }

    #[Test]
    #[DataProvider('dostawcy')]
    public function test_podpowiedz_nazwy_pomija_nazwe_zajeta(string $klasa): void
    {
        $this->user('basia');
        $wejscie = $this->wejscie($klasa);

        $ekran = $wejscie->ekranDomkniecia($this->zadanie(), $this->tozsamosc($wejscie));

        $this->assertNotSame('basia', $ekran['proponowanaNazwa']);
        $this->assertSame('Basia', $ekran['proponowaneImie']);
    }

    /** Zamknięta rejestracja zamyka ekran domknięcia obu dróg tak samo. */
    #[Test]
    #[DataProvider('dostawcy')]
    public function test_zamknieta_rejestracja_zamyka_domkniecie(string $klasa): void
    {
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => 'klient-testowy',
            'kuking.google.sekret_klienta' => 'sekret-testowy',
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '1234567890123456',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
            'kuking.account.registration_open' => false,
        ]);
        $nazwa = (new $klasa)->nazwa();

        $this->get(route($nazwa.'.finish'))->assertRedirect(route('login'));
        $this->assertStringContainsString('chwilowo zamknięte', (string) session('status'));
        $this->post(route($nazwa.'.finish'))->assertStatus(503);
        $this->assertSame(0, User::count());
    }

    // ─────────────────────────── typ tożsamości ───────────────────────────

    #[Test]
    public function test_potwierdzony_adres_wymaga_adresu(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TozsamoscOdDostawcy(self::IDENTYFIKATOR, null, true, '');
    }

    #[Test]
    public function test_tozsamosc_wymaga_identyfikatora(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TozsamoscOdDostawcy('', 'basia@example.test', false, '');
    }

    #[Test]
    public function test_facebook_nigdy_nie_daje_potwierdzonego_adresu_a_google_przenosi_swoj(): void
    {
        $facebook = (new DostawcaWejsciaFacebook)->tozsamosc(new TozsamoscFacebook('fb-1', 'basia@example.test', 'Basia'));
        $google = (new DostawcaWejsciaGoogle)->tozsamosc(new TozsamoscGoogle('g-1', 'basia@example.test', true, 'Basia'));

        $this->assertFalse($facebook->emailPotwierdzony);
        $this->assertTrue($google->emailPotwierdzony);
    }
}
