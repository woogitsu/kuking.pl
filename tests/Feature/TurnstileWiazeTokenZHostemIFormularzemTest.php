<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use App\Turnstile\WynikTurnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresja #992: `success=true` z Siteverify przechodził bez względu na to,
 * na jakim hoście i dla którego formularza wystawiono token.
 *
 * Kontrola dodatnia: `scripts/kontrole-negatywne-alfa08.py` zdejmuje
 * z `KlientTurnstile` porównanie hosta, a osobno porównanie akcji — każda
 * z tych mutacji ma oblać odpowiedni test niżej.
 */
class TurnstileWiazeTokenZHostemIFormularzemTest extends TestCase
{
    use RefreshDatabase;

    private const KLUCZ_PUBLICZNY = '1x00000000000000000000AA';

    private const SEKRET = '1x0000000000000000000000000000000AA';

    private const TOKEN = 'token-od-widgetu-992';

    private MockInterface $dziennik;

    /** @var array<string, string> adres formularza => oczekiwane `data-action` */
    private const WIDGETY = [
        '/register' => 'rejestracja',
        '/login' => 'logowanie',
        '/nie-pamietam-hasla' => 'odzyskanie_hasla',
        '/logowanie/link' => 'logowanie_linkiem',
        '/cofnij-usuniecie-konta' => 'cofniecie_usuniecia',
        '/napisz-do-nas' => 'kontakt',
        '/zglos-nielegalna-tresc' => 'zgloszenie_nielegalnej_tresci',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://kuking.pl',
            'kuking.turnstile.klucz_publiczny' => self::KLUCZ_PUBLICZNY,
            'kuking.turnstile.sekret' => self::SEKRET,
            'kuking.turnstile.hosty_stagingu' => [],
        ]);
    }

    public function test_kazdy_z_siedmiu_widgetow_wysyla_wlasciwa_akcje(): void
    {
        config(['mail.default' => 'smtp']);

        foreach (self::WIDGETY as $adres => $akcja) {
            $this->get($adres)->assertOk()
                ->assertSee('data-action="'.$akcja.'"', escape: false);
        }

        $this->assertEqualsCanonicalizing(array_keys(Turnstile::miejsca()), array_values(self::WIDGETY));
    }

    public function test_akcje_mieszcza_sie_w_ograniczeniach_turnstile(): void
    {
        foreach (array_keys(Turnstile::miejsca()) as $miejsce) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', Turnstile::akcja($miejsce));
        }
    }

    public function test_produkcyjny_host_i_wlasciwa_akcja_przechodza(): void
    {
        $this->assertSame(WynikTurnstile::Przeszedl, $this->sprawdz(['hostname' => 'kuking.pl', 'action' => 'logowanie'], 'logowanie'));
    }

    public function test_skonfigurowany_staging_przechodzi_a_nieskonfigurowany_nie(): void
    {
        $odpowiedz = ['hostname' => 'staging.kuking.pl', 'action' => 'kontakt'];

        $this->assertSame(WynikTurnstile::Odrzucony, $this->sprawdz($odpowiedz, 'kontakt'));

        config(['kuking.turnstile.hosty_stagingu' => ['staging.kuking.pl']]);

        $this->assertSame(WynikTurnstile::Przeszedl, $this->sprawdz($odpowiedz, 'kontakt'));
    }

    /** @return iterable<string, array{string}> */
    public static function obceHosty(): iterable
    {
        yield 'inna domena' => ['attacker.test'];
        yield 'nasza nazwa jako przedrostek' => ['kuking.pl.attacker.test'];
        yield 'origin Railwaya' => ['kuking-pl-production.up.railway.app'];
        yield 'localhost' => ['localhost'];
    }

    #[DataProvider('obceHosty')]
    public function test_host_spoza_listy_jest_odrzucany(string $host): void
    {
        $this->dziennik = Log::spy();

        $this->assertSame(WynikTurnstile::Odrzucony, $this->sprawdz(['hostname' => $host, 'action' => 'logowanie'], 'logowanie'));

        $this->assertOdmowaZKodem('host_spoza_listy');
    }

    public function test_host_zadania_nie_poszerza_listy(): void
    {
        // Żądanie przyszło na host spoza listy (tu: pętla zwrotna, którą
        // `ZaufaneHosty` wpuszcza) — lista dalej pochodzi z konfiguracji.
        $this->get('http://localhost/login')->assertOk();

        $this->assertSame(['kuking.pl'], Turnstile::dozwoloneHosty());
        $this->assertSame(WynikTurnstile::Odrzucony, $this->sprawdz(['hostname' => 'localhost', 'action' => 'logowanie'], 'logowanie'));
    }

    public function test_akcja_innego_formularza_jest_odrzucana(): void
    {
        $this->dziennik = Log::spy();

        $this->assertSame(
            WynikTurnstile::Odrzucony,
            $this->sprawdz(['hostname' => 'kuking.pl', 'action' => 'logowanie'], 'zgloszenie_nielegalnej_tresci'),
        );

        $this->assertOdmowaZKodem('inna_akcja');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function brakujacePola(): iterable
    {
        yield 'bez hostname' => [['action' => 'logowanie'], 'brak_hosta'];
        yield 'pusty hostname' => [['hostname' => '', 'action' => 'logowanie'], 'brak_hosta'];
        yield 'bez action' => [['hostname' => 'kuking.pl'], 'brak_akcji'];
        yield 'action nie-łańcuch' => [['hostname' => 'kuking.pl', 'action' => ['logowanie']], 'brak_akcji'];
    }

    /** @param array<string, mixed> $pola */
    #[DataProvider('brakujacePola')]
    public function test_brak_wymaganych_pol_jest_odrzucany(array $pola, string $kod): void
    {
        $this->dziennik = Log::spy();

        $this->assertSame(WynikTurnstile::Odrzucony, $this->sprawdz($pola, 'logowanie'));

        $this->assertOdmowaZKodem($kod);
    }

    /**
     * Na ekranie: token z logowania nie zakłada konta, a człowiek dostaje
     * ten sam komunikat co przy tokenie odrzuconym przez Cloudflare —
     * nie przechodzi jak przy awarii (D-050).
     */
    public function test_token_z_innego_formularza_nie_zaklada_konta(): void
    {
        Http::fake([KlientTurnstile::ADRES => Http::response([
            'success' => true, 'hostname' => 'kuking.pl', 'action' => 'logowanie',
        ])]);

        $this->from('/register')->post('/register', [
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'email' => 'basia@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
            Turnstile::POLE => self::TOKEN,
        ])->assertRedirect('/register')
            ->assertSessionHasErrors([Turnstile::POLE => Turnstile::komunikatOdrzucenia('rejestracja')]);

        $this->assertNull(User::findByLogin('basia@example.com'));
    }

    /**
     * Kontrola dodatnia w tym samym pliku: awaria Cloudflare dalej przepuszcza
     * (D-050) — więc odmowa wyżej naprawdę wynika z kontekstu, a nie z tego,
     * że klient odrzuca wszystko.
     */
    public function test_awaria_cloudflare_dalej_przepuszcza(): void
    {
        Http::fake([KlientTurnstile::ADRES => Http::response('Bad gateway', 502)]);

        $this->assertSame(WynikTurnstile::Nierozstrzygniety, app(KlientTurnstile::class)->sprawdz(self::TOKEN, 'logowanie'));
    }

    /** @param array<string, mixed> $pola */
    private function sprawdz(array $pola, string $akcja): WynikTurnstile
    {
        Http::fake([KlientTurnstile::ADRES => Http::response(['success' => true, 'challenge_ts' => '2026-09-24T10:00:00Z', ...$pola])]);

        return app(KlientTurnstile::class)->sprawdz(self::TOKEN, $akcja, '203.0.113.7');
    }

    /**
     * Odmowa z zamkniętym kodem, bez gałęzi „nie wiem" (ta pisze `warning`
     * albo `error`). Kontekst to DOKŁADNIE dwa klucze — więc token, adres IP
     * ani host z odpowiedzi nie mają którędy wejść do dziennika.
     */
    private function assertOdmowaZKodem(string $kod): void
    {
        $this->dziennik->shouldHaveReceived('info')->once()->withArgs(
            static fn (string $wiadomosc, array $kontekst): bool => array_keys($kontekst) === ['powod', 'miejsce']
                && $kontekst['powod'] === $kod,
        );
        $this->dziennik->shouldNotHaveReceived('warning');
        $this->dziennik->shouldNotHaveReceived('error');
    }
}
