<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TozsamoscZewnetrzna;
use App\Support\Facebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * „Ten człowiek odebrał nam dostęp na Facebooku" (issue #259).
 *
 * Facebook woła `POST /wejdz/facebook/odebranie-dostepu`, gdy ktoś usunie
 * naszą aplikację w swoich ustawieniach Facebooka. Bez tej drogi nie
 * dowiadywaliśmy się o tym wcale: wiersz powiązania zostawał w bazie jakby
 * nic się nie stało, a człowiek dowiadywał się z nieudanego logowania,
 * którego nikt mu nie tłumaczył.
 *
 * DWIE RZECZY SĄ TU WAŻNIEJSZE NIŻ SAM ZAPIS
 *
 *  1. Żądanie przychodzi BEZ SESJI i BEZ TOKENU CSRF, bo woła je serwer,
 *     nie przeglądarka. Wpuszczenie czegokolwiek bez podpisu znaczyłoby, że
 *     dowolny obcy wyłącza ludziom wejście na konto jednym POST-em.
 *  2. Wiersza NIE KASUJEMY. Kto wszedł wyłącznie kontem Facebooka i nigdy
 *     nie ustawił hasła, straciłby jedyną drogę wejścia — przez kliknięcie
 *     w ustawieniach Facebooka, którego skutków nikt mu nie zapowiedział.
 */
class OdebranieDostepuFacebookaTest extends TestCase
{
    use RefreshDatabase;

    private const SEKRET = 'sekret-testowy';

    private const FB_ID = '10221234567890123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '1234567890123456',
            'kuking.facebook.sekret_klienta' => self::SEKRET,
        ]);
    }

    /**
     * Buduje `signed_request` tak, jak robi to Facebook.
     *
     * @param  array<string, mixed>  $tresc
     */
    private function podpisane(array $tresc, ?string $sekret = null): string
    {
        $dane = rtrim(strtr(base64_encode((string) json_encode($tresc)), '+/', '-_'), '=');

        $podpis = hash_hmac('sha256', $dane, $sekret ?? self::SEKRET, true);

        return rtrim(strtr(base64_encode($podpis), '+/', '-_'), '=').'.'.$dane;
    }

    /** @param array<string, mixed> $nadpisania */
    private function trescDomyslna(array $nadpisania = []): array
    {
        return array_merge([
            'algorithm' => Facebook::ALGORYTM_PODPISU,
            'user_id' => self::FB_ID,
            'issued_at' => time(),
        ], $nadpisania);
    }

    private function znacznik(string $identyfikator = self::FB_ID): ?string
    {
        return DB::table('tozsamosci_zewnetrzne')
            ->where('identyfikator', $identyfikator)
            ->value('dostep_odebrany_at');
    }

    #[Test]
    public function test_poprawne_powiadomienie_usypia_powiazanie_i_nie_kasuje_go(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna()),
        ])->assertOk();

        $this->assertNotNull($this->znacznik(), 'Powiązanie miało zostać oznaczone jako uśpione.');

        // NAJWAŻNIEJSZE: wiersz NADAL JEST. Skasowanie zabrałoby wejście
        // komuś, kto nie ma hasła — i zrobiłoby to bez ostrzeżenia.
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 1);
        $this->assertTrue($basia->refresh()->hasFacebookConnected());
        $this->assertTrue($basia->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK));
    }

    /**
     * ŻĄDANIE IDZIE BEZ TOKENU CSRF I MA PRZEJŚĆ — bo woła je serwer
     * Facebooka, nie przeglądarka. To jest sprawdzenie wpisu w `bootstrap/
     * app.php`; bez niego prawdziwe powiadomienia dostawałyby 419
     * i nikt by tego nie zauważył, bo Facebook nie krzyczy.
     */
    #[Test]
    public function test_dziala_bez_tokenu_csrf(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $this->withMiddleware()
            ->post(route('facebook.deauthorize'), [
                'signed_request' => $this->podpisane($this->trescDomyslna()),
            ])
            ->assertOk();

        $this->assertNotNull($this->znacznik());
    }

    #[Test]
    public function test_zly_podpis_niczego_nie_zmienia(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(), 'nie-nasz-sekret'),
        ])->assertStatus(400);

        $this->assertNull($this->znacznik(),
            'Bez poprawnego podpisu dowolny obcy wyłączałby ludziom wejście na konto jednym POST-em.');
    }

    #[Test]
    public function test_inny_algorytm_jest_odrzucany_mimo_poprawnego_podpisu(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        // Podpis LICZYMY POPRAWNIE, naszym sekretem — odrzucenie ma wynikać
        // wyłącznie z pola `algorithm`. Inaczej ten test przechodziłby także
        // bez sprawdzania algorytmu i nic by nie mierzył.
        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['algorithm' => 'HMAC-SHA1'])),
        ])->assertStatus(400);

        $this->assertNull($this->znacznik());
    }

    #[Test]
    public function test_smieci_nie_wywracaja_serwera(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        foreach (['', 'bez-kropki', 'a.b.c', '....', '%%%.%%%', 'AAAA.BBBB'] as $smiec) {
            $this->post(route('facebook.deauthorize'), ['signed_request' => $smiec])
                ->assertStatus(400);
        }

        // Brak pola w ogóle — to samo.
        $this->post(route('facebook.deauthorize'))->assertStatus(400);

        $this->assertNull($this->znacznik());
    }

    #[Test]
    public function test_brak_user_id_jest_odrzucany(): void
    {
        $tresc = $this->trescDomyslna();
        unset($tresc['user_id']);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($tresc),
        ])->assertStatus(400);
    }

    /**
     * Powiadomienie o kimś, kogo u nas nie ma, kończy się 200 — nie 404.
     *
     * Może dotyczyć osoby, która kliknęła „Wejdź kontem Facebooka", rozmyśliła
     * się na ekranie domknięcia konta, a potem posprzątała listę aplikacji
     * u siebie. Z punktu widzenia Facebooka wiadomość została przyjęta,
     * a 4xx liczyłoby się tam jako nieudane dostarczenie.
     */
    #[Test]
    public function test_nieznany_identyfikator_konczy_sie_spokojnym_dwiescie(): void
    {
        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['user_id' => '90900000000000001'])),
        ])->assertOk();

        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);
    }

    /**
     * Powiadomienie nie rusza powiązań, których nie dotyczy.
     */
    #[Test]
    public function test_powiazanie_z_google_zostaje_nietkniete(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');
        $basia->connectFacebook(self::FB_ID);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna()),
        ])->assertOk();

        $this->assertNull(
            DB::table('tozsamosci_zewnetrzne')->where('dostawca', 'google')->value('dostep_odebrany_at'),
            'Odebranie dostępu na Facebooku nie ma nic wspólnego z powiązaniem z Google.',
        );
    }

    /**
     * EKRAN BEZPIECZEŃSTWA MÓWI PRAWDĘ I MÓWI, CO ZROBIĆ.
     *
     * Bez tego stanu ekran pokazywałby „połączone" komuś, kogo Facebook już
     * nie wpuszcza — bo wiersz powiązania nadal istnieje.
     */
    #[Test]
    public function test_ekran_bezpieczenstwa_pokazuje_stan_i_droge_wyjscia(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna()),
        ])->assertOk();

        $html = $this->actingAs($basia->refresh())->get(route('settings.security'))->assertOk()->getContent();

        $this->assertStringContainsString('Facebook przestał nas wpuszczać', (string) $html);

        // Przycisk MUSI prowadzić do istniejącej trasy — żadnego martwego
        // przycisku (D-053).
        $this->assertStringContainsString(route('facebook.start'), (string) $html);

        // I nie może jednocześnie twierdzić, że wszystko jest w porządku.
        $this->assertStringNotContainsString('możesz logować się przyciskiem „Wejdź kontem Facebooka', (string) $html);
    }
}
