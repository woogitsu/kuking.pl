<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TozsamoscZewnetrzna;
use App\Support\Facebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * TABLICA ZAMIAST TEKSTU TO 400, A NIE OSTRZEŻENIE CZY 500 (issue #1344).
     *
     * `signed_request[]=x` daje w PHP tablicę. Rzutowanie jej na tekst przed
     * sprawdzeniem typu emituje „Array to string conversion", a przy
     * ostrzeżeniach zamienianych na wyjątki publiczny adres odpowiadał 500.
     * Test idzie z włączonym middleware, bo tak woła ten adres świat.
     */
    #[Test]
    public function test_tablica_zamiast_podpisu_konczy_sie_spokojnym_czterysta(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $poprawny = $this->podpisane($this->trescDomyslna());

        $ksztalty = [
            'jednoelementowa' => [$poprawny],
            'zagniezdzona' => [['a' => $poprawny]],
            'z kluczem' => ['podpis' => 'x', 'dane' => 'y'],
        ];

        $dziennik = Log::spy();

        foreach ($ksztalty as $nazwa => $wartosc) {
            $this->withMiddleware()
                ->post(route('facebook.deauthorize'), ['signed_request' => $wartosc])
                ->assertStatus(400);

            $this->assertNull($this->znacznik(), "Kształt „{$nazwa}” nie ma prawa niczego zmienić.");
        }

        // Odrzucenie nie wkłada treści żądania do dziennika.
        $dziennik->shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc, array $kontekst = []): bool => $kontekst === []
                && ! str_contains($wiadomosc, $poprawny),
        )->times(count($ksztalty));

        // Kontrola dodatnia: ten sam podpis podany jako tekst działa.
        $this->withMiddleware()
            ->post(route('facebook.deauthorize'), ['signed_request' => $poprawny])
            ->assertOk();

        $this->assertNotNull($this->znacznik());
    }

    /**
     * STARY PODPIS NIE USYPIA POWIĄZANIA PO PONOWNEJ ZGODZIE (issue #1025).
     *
     * Kolejność zdarzeń z zgłoszenia: Facebook powiadamia o odebraniu
     * dostępu, człowiek znów wchodzi kontem Facebooka (znacznik gaśnie),
     * po czym TO SAMO, poprawnie podpisane powiadomienie przychodzi drugi
     * raz. Nie może nadpisać nowszej decyzji człowieka.
     */
    #[Test]
    public function test_stary_podpis_nie_usypia_powiazania_po_ponownej_zgodzie(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');

        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $stary = $this->podpisane($this->trescDomyslna(['issued_at' => Carbon::now()->addMinutes(5)->getTimestamp()]));

        Carbon::setTestNow('2026-09-20 10:10:00');

        $this->post(route('facebook.deauthorize'), ['signed_request' => $stary])->assertOk();
        $this->assertNotNull($this->znacznik(), 'Kontrola wstępna: świeże powiadomienie usypia.');

        // Ponowne powiadomienie przed ponowną zgodą: idempotentne 200.
        $this->post(route('facebook.deauthorize'), ['signed_request' => $stary])->assertOk();
        $this->assertNotNull($this->znacznik());

        Carbon::setTestNow('2026-09-20 11:00:00');

        // Człowiek wraca przez ekran zgody Facebooka.
        $basia->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);
        $this->assertNull($this->znacznik());

        Carbon::setTestNow('2026-09-20 11:05:00');

        $this->post(route('facebook.deauthorize'), ['signed_request' => $stary])->assertOk();

        $this->assertNull($this->znacznik(),
            'Powiadomienie wystawione przed ponowną zgodą nie może znów uśpić powiązania.');
        $this->assertFalse($basia->fresh()->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK));

        // Kontrola dodatnia: powiadomienie wystawione PO ponownej zgodzie działa.
        $nowy = $this->podpisane($this->trescDomyslna(['issued_at' => Carbon::now()->getTimestamp()]));

        $this->post(route('facebook.deauthorize'), ['signed_request' => $nowy])->assertOk();

        $this->assertNotNull($this->znacznik(), 'Świeże powiadomienie po ponownej zgodzie ma usypiać.');
    }

    /**
     * Powiadomienie spóźnione względem zwykłego wejścia kontem Facebooka
     * (znacznika jeszcze nie było) też jest nieaktualne — granica zgody
     * zapisuje się przy każdym wejściu, nie tylko po uśpieniu.
     */
    #[Test]
    public function test_spoznione_powiadomienie_sprzed_zwyklego_wejscia_nic_nie_zmienia(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');

        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $wydane = Carbon::now()->addHour()->getTimestamp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $basia->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['issued_at' => $wydane])),
        ])->assertOk();

        $this->assertNull($this->znacznik());
    }

    /**
     * Wiadomość starsza od założenia powiązania (a ponownej zgody nie było)
     * — granicą jest `connected_at`.
     */
    #[Test]
    public function test_powiadomienie_sprzed_polaczenia_nic_nie_zmienia(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['issued_at' => time() - 86400])),
        ])->assertOk();

        $this->assertNull($this->znacznik());
    }

    /**
     * POLITYKA `issued_at`: brak, zły typ i wartość z przyszłości to 400 —
     * tak samo jak zły podpis. Wiek sam w sobie nie odrzuca (rozstrzyga
     * granica zgody, test wyżej); mała rozbieżność zegarów przechodzi.
     */
    #[Test]
    public function test_polityka_pola_issued_at(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $bezPola = $this->trescDomyslna();
        unset($bezPola['issued_at']);

        $odrzucane = [
            'brak' => $bezPola,
            'tekst' => $this->trescDomyslna(['issued_at' => (string) time()]),
            'ułamek' => $this->trescDomyslna(['issued_at' => time() + 0.5]),
            'null' => $this->trescDomyslna(['issued_at' => null]),
            'zero' => $this->trescDomyslna(['issued_at' => 0]),
            'z przyszłości' => $this->trescDomyslna(['issued_at' => time() + 3600]),
        ];

        foreach ($odrzucane as $nazwa => $tresc) {
            $this->post(route('facebook.deauthorize'), ['signed_request' => $this->podpisane($tresc)])
                ->assertStatus(400);

            $this->assertNull($this->znacznik(), "`issued_at` ({$nazwa}) nie ma prawa niczego zmienić.");
        }

        // Kontrola dodatnia: zegar Facebooka minutę do przodu to nie atak.
        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['issued_at' => time() + 60])),
        ])->assertOk();

        $this->assertNotNull($this->znacznik());
    }

    /**
     * Zły podpis na wiadomości „nowszej" od zgody nadal nic nie zmienia —
     * świeży `issued_at` nie zastępuje podpisu.
     */
    #[Test]
    public function test_zly_podpis_ze_swiezym_issued_at_jest_odrzucany(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);
        $basia->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

        $this->post(route('facebook.deauthorize'), [
            'signed_request' => $this->podpisane($this->trescDomyslna(['issued_at' => time() + 10]), 'nie-nasz-sekret'),
        ])->assertStatus(400);

        $this->assertNull($this->znacznik());
    }
}
