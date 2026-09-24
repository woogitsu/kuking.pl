<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\ZalegleCzyszczeniaCdn;
use App\Jobs\PurgePublicMediaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Brak konfiguracji Cloudflare nie gubi adresów do wyczyszczenia (#959).
 *
 * Do tego issue `PurgePublicMediaCache` bez `CLOUDFLARE_ZONE_ID` albo
 * `CLOUDFLARE_PURGE_TOKEN` pisał ostrzeżenie i kończył się SUKCESEM. Po
 * uzupełnieniu zmiennych nikt nie wiedział, które skasowane zdjęcia dalej
 * siedzą w cache — przy wymazaniu konta albo decyzji moderacyjnej to jest
 * obietnica usunięcia, która zależy od tego, czy ktoś przeczytał log.
 *
 * Najważniejszy jest tu test „od końca do końca": zadanie bez konfiguracji
 * → konfiguracja uzupełniona → komenda z harmonogramu wysyła TE SAME adresy.
 * Kontrola ujemna: przywrócenie `Log::warning(...); return;` bez odłożenia
 * wywraca go na asercji liczby odłożonych adresów.
 */
class ZalegleCzyszczenieCdnTest extends TestCase
{
    use RefreshDatabase;

    private const ADRESY = [
        'https://kuking.pl/zdjecia/1/feed',
        'https://kuking.pl/zdjecia/1/thumb',
    ];

    private function produkcja(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    private function konfiguracja(?string $zona, ?string $token): void
    {
        config([
            'kuking.media.cdn_purge.zone_id' => $zona,
            'kuking.media.cdn_purge.token' => $token,
        ]);
    }

    /** @return list<string> */
    private function odlozone(): array
    {
        return DB::table(ZalegleCzyszczeniaCdn::TABELA)->orderBy('adres')->pluck('adres')->all();
    }

    /** @return array<string, array{?string, ?string}> */
    public static function brakiKonfiguracji(): array
    {
        return [
            'brak strefy' => [null, 'tajny-token'],
            'brak tokenu' => ['zona123', ''],
            'brak obu' => ['', null],
        ];
    }

    #[DataProvider('brakiKonfiguracji')]
    public function test_na_produkcji_brak_konfiguracji_odklada_adresy(?string $zona, ?string $token): void
    {
        Http::fake();
        $this->produkcja();
        $this->konfiguracja($zona, $token);

        // Kasowanie zdjęcia nie może się wywrócić: zadanie kończy się
        // bez wyjątku — ale adresy zostają.
        (new PurgePublicMediaCache([...self::ADRESY, self::ADRESY[0]]))->handle();

        Http::assertNothingSent();
        $this->assertSame(self::ADRESY, $this->odlozone());
    }

    public function test_poza_produkcja_brak_konfiguracji_jest_jawnym_wylaczeniem(): void
    {
        // Lokalnie i w testach nie ma CDN-u: bez sieci, bez tabeli, z wpisem.
        $log = Log::spy();
        Http::fake();
        $this->konfiguracja('', '');

        (new PurgePublicMediaCache(self::ADRESY))->handle();

        Http::assertNothingSent();
        $this->assertSame([], $this->odlozone());
        $log->shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $wiadomosc, array $kontekst): bool => $kontekst['odlozone'] === false,
        );
    }

    public function test_po_uzupelnieniu_konfiguracji_zalegle_adresy_sa_czyszczone(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        $this->produkcja();

        $this->konfiguracja('', '');
        (new PurgePublicMediaCache(self::ADRESY))->handle();
        $this->assertCount(2, $this->odlozone());

        // Bez konfiguracji komenda nic nie wysyła i niczego nie kasuje.
        $this->assertSame(0, Artisan::call('kuking:wyczysc-zalegle-cdn'));
        Http::assertNothingSent();
        $this->assertCount(2, $this->odlozone());

        $this->konfiguracja('zona123', 'tajny-token');
        $this->assertSame(0, Artisan::call('kuking:wyczysc-zalegle-cdn'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $wyslane = $request['files'];
            sort($wyslane);

            return str_contains($request->url(), 'zona123') && $wyslane === self::ADRESY;
        });

        $this->assertSame([], $this->odlozone());
        $this->assertStringContainsString('Wyczyszczono z cache CDN 2 adresy.', Artisan::output());
    }

    public function test_odmowa_cloudflare_zostawia_zalegle_adresy_na_nastepny_przebieg(): void
    {
        Http::fake(['*' => Http::response(['success' => false], 200)]);
        $this->konfiguracja('zona123', 'tajny-token');
        ZalegleCzyszczeniaCdn::odloz(self::ADRESY);

        $odmowa = null;

        try {
            Artisan::call('kuking:wyczysc-zalegle-cdn');
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Komenda przemilczała odmowę Cloudflare.');
        $this->assertSame(self::ADRESY, $this->odlozone());
    }

    public function test_wiecej_niz_partia_czysci_sie_w_jednym_przebiegu(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        $this->konfiguracja('zona123', 'tajny-token');

        $adresy = array_map(
            static fn (int $i): string => "https://kuking.pl/zdjecia/{$i}/feed",
            range(1, ZalegleCzyszczeniaCdn::PARTIA + 5),
        );
        ZalegleCzyszczeniaCdn::odloz($adresy);

        Artisan::call('kuking:wyczysc-zalegle-cdn');

        $this->assertSame(0, ZalegleCzyszczeniaCdn::ile());
        // 300 adresów = 10 żądań po 30, reszta = jedno żądanie.
        Http::assertSentCount(11);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function odpowiedziBezPotwierdzenia(): array
    {
        return [
            'success: false' => [['success' => false, 'errors' => []]],
            'bez pola success' => [['result' => ['id' => 'x']]],
        ];
    }

    /** @param array<string, mixed> $tresc */
    #[DataProvider('odpowiedziBezPotwierdzenia')]
    public function test_2xx_bez_success_true_jest_bledem(array $tresc): void
    {
        Http::fake(['*' => Http::response($tresc, 200)]);
        $this->konfiguracja('zona123', 'tajny-token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie potwierdził');

        (new PurgePublicMediaCache(self::ADRESY))->handle();
    }

    public function test_odrzucona_druga_partia_wywraca_zadanie(): void
    {
        Http::fakeSequence()
            ->push(['success' => true], 200)
            ->push(['success' => false], 200);
        $this->konfiguracja('zona123', 'tajny-token');

        $adresy = array_map(
            static fn (int $i): string => "https://kuking.pl/zdjecia/{$i}/feed",
            range(1, 31),
        );

        $odmowa = null;

        try {
            (new PurgePublicMediaCache($adresy))->handle();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Odrzucona druga partia przeszła jako sukces.');
        Http::assertSentCount(2);
    }

    public function test_po_wyczerpaniu_prob_adresy_czekaja_na_ponowienie(): void
    {
        (new PurgePublicMediaCache(self::ADRESY))->failed(new RuntimeException('Cloudflare nie odpowiada.'));

        $this->assertSame(self::ADRESY, $this->odlozone());
    }

    public function test_adres_wzgledny_nie_blokuje_reszty(): void
    {
        ZalegleCzyszczeniaCdn::odloz(['/storage/media/a_feed.webp', self::ADRESY[0]]);

        $this->assertSame([self::ADRESY[0]], $this->odlozone());
    }

    public function test_health_swieci_dopoki_sa_zalegle_adresy(): void
    {
        Artisan::call('storage:link');

        // KONTROLA UJEMNA: pusta tabela — sonda milczy.
        $this->get('/health')->assertJsonPath('checks.cdn_zalegle.ok', true);

        ZalegleCzyszczeniaCdn::odloz(self::ADRESY);

        $odpowiedz = $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.cdn_zalegle.ok', false)
            ->assertJsonPath('checks.cdn_zalegle.error', 'czyszczenie_cdn_zalegle');

        // Publicznie sam kod — bez liczby, adresów i nazwy tabeli.
        $tresc = (string) $odpowiedz->getContent();
        $this->assertStringNotContainsString('kuking.pl/zdjecia', $tresc);
        $this->assertStringNotContainsString('zalegle_czyszczenia_cdn', $tresc);
    }

    public function test_cofniecie_migracji_odmawia_przy_zaleglych_adresach(): void
    {
        ZalegleCzyszczeniaCdn::odloz([self::ADRESY[0]]);

        $migracja = require $this->app->databasePath('migrations/2026_09_24_100000_utworz_zalegle_czyszczenia_cdn.php');

        // Odmowa poza blokiem `try` (D-133).
        $odmowa = null;

        try {
            $migracja->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie skasowało listę zaległych czyszczeń.');
        $this->assertStringContainsString('1 adres.', $odmowa->getMessage());
        $this->assertSame(1, ZalegleCzyszczeniaCdn::ile());

        DB::table(ZalegleCzyszczeniaCdn::TABELA)->delete();
        $migracja->down();
        $this->assertFalse(Schema::hasTable(ZalegleCzyszczeniaCdn::TABELA));
        $migracja->up();
    }
}
