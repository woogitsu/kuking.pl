<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Jobs\PurgePublicMediaCache;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Skasowane zdjęcie znika także z cache CDN-u (audyt G-03).
 *
 * DLACZEGO SAMO SKASOWANIE PLIKU NIE WYSTARCZA
 * Cloudflare ostrzega wprost w dokumentacji spójności R2: przy włączonym cache
 * na własnej domenie skasowany obiekt BYWA DALEJ SERWOWANY z cache aż do
 * wygaśnięcia albo wypchnięcia. Usunięcie pliku z bucketu nie jest więc
 * usunięciem go z internetu.
 *
 * Dla miniatury to niedogodność. Dla wymazania konta po karencji, żądania
 * z RODO, decyzji moderacyjnej albo zdjęcia wgranego przez pomyłkę to jest
 * awaria prywatności: serwis mówi „skasowane", a plik nadal się otwiera pod
 * tym samym adresem — i nikt się o tym nie dowie, bo wygląda to identycznie
 * jak działające kasowanie.
 */
class CzyszczenieCacheCdnTest extends TestCase
{
    use RefreshDatabase;

    private function zdjecieZWariantami(): Media
    {
        Storage::fake('publiczne');

        $media = Media::factory()->create([
            'disk' => 'publiczne',
            'variants_disk' => 'publiczne',
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik_thumb.webp'],
                'feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp'],
            ]],
        ]);

        Storage::disk('publiczne')->put($media->object_key, 'oryginal');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('publiczne')->put($wariant['key'], 'wariant');
        }

        return $media;
    }

    public function test_kasowanie_zdjecia_zleca_wyczyszczenie_adresow_wariantow(): void
    {
        Queue::fake();

        $media = $this->zdjecieZWariantami();

        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($media));

        Queue::assertPushed(PurgePublicMediaCache::class, function (PurgePublicMediaCache $job) use ($media): bool {
            // DWA ADRESY NA WARIANT, czyli cztery przy dwóch wariantach
            // (audyt W7-02):
            //
            //   * adres pliku w buckecie — istnieje już tylko dla dysków ze
            //     starą, własną domeną (`r2_legacy`); tu udaje go dysk
            //     testowy,
            //   * adres TRASY `media.show` — dzisiejszy adres zdjęcia.
            //
            // Oryginału nie ma w żadnym z nich: leży w buckecie bez domeny,
            // więc nie ma go też w cache.
            $this->assertCount(4, $job->adresy);

            foreach ($job->adresy as $adres) {
                $this->assertStringNotContainsString('incoming/', $adres);
            }

            foreach (['thumb', 'feed'] as $nazwa) {
                $this->assertContains(
                    route('media.show', ['media' => $media->getKey(), 'wariant' => $nazwa]),
                    $job->adresy,
                    'Brak adresu trasy dla wariantu '.$nazwa.'. Po W7-02 to jest adres, '.
                    'pod którym zdjęcie naprawdę się otwiera.',
                );
            }

            return true;
        });
    }

    public function test_adresy_zbierane_sa_przed_skasowaniem_plikow(): void
    {
        // Po skasowaniu pliku nie ma już z czego zbudować jego adresu.
        // Gdyby kolejność się odwróciła, zadanie dostawałoby pustą listę
        // i czyszczenie byłoby pozorne — testy kasowania nadal by przechodziły.
        Queue::fake();

        $media = $this->zdjecieZWariantami();
        $spodziewane = [];

        foreach ($media->metadata['variants'] as $nazwa => $wariant) {
            $spodziewane[] = Storage::disk('publiczne')->url($wariant['key']);
            $spodziewane[] = route('media.show', ['media' => $media->getKey(), 'wariant' => $nazwa]);
        }

        (new KasujZdjecie)->jesliNieuzywane($media);

        Queue::assertPushed(
            PurgePublicMediaCache::class,
            function (PurgePublicMediaCache $job) use ($spodziewane): bool {
                $dostane = $job->adresy;
                sort($dostane);
                sort($spodziewane);

                $this->assertSame($spodziewane, $dostane);

                return true;
            },
        );
    }

    public function test_dysk_bez_publicznego_adresu_nie_wywraca_kasowania(): void
    {
        // Dysk oryginałów świadomie nie ma `url` (audyt G-01), a lokalny dysk
        // testowy też go mieć nie musi. `url()` rzuca wtedy wyjątek — i to
        // jest poprawne. Kasowanie zdjęcia nie może się przez to nie udać.
        Queue::fake();
        Storage::fake('bez_url');

        $media = Media::factory()->create([
            'disk' => 'bez_url',
            'variants_disk' => 'bez_url',
            'metadata' => ['variants' => ['feed' => ['key' => 'media/x_feed.webp']]],
        ]);

        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($media));
        $this->assertDatabaseMissing('media', ['id' => $media->getKey()]);
    }

    public function test_zadanie_wysyla_adresy_do_cloudflare(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        config([
            'kuking.media.cdn_purge.zone_id' => 'zona123',
            'kuking.media.cdn_purge.token' => 'tajny-token',
        ]);

        (new PurgePublicMediaCache([
            'https://cdn.kuking.pl/media/a_feed.webp',
            'https://cdn.kuking.pl/media/a_thumb.webp',
            // Duplikat — ma zniknąć, żeby nie zjadać limitu 30 adresów.
            'https://cdn.kuking.pl/media/a_feed.webp',
        ]))->handle();

        Http::assertSent(function ($request): bool {
            $this->assertStringContainsString('zona123', $request->url());
            $this->assertSame('Bearer tajny-token', $request->header('Authorization')[0]);
            $this->assertCount(2, $request['files']);

            return true;
        });
    }

    public function test_brak_konfiguracji_jest_glosny_a_nie_cichy(): void
    {
        // Lokalnie i w testach czyszczenie jest wyłączone i to jest w porządku.
        // Na produkcji brak konfiguracji znaczy, że skasowane zdjęcia dalej się
        // otwierają — a wygląda to identycznie jak działające czyszczenie.
        // Bez tego wpisu w logu nikt by się nie dowiedział.
        $log = Log::spy();

        Http::fake();

        config(['kuking.media.cdn_purge.zone_id' => '', 'kuking.media.cdn_purge.token' => '']);

        (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->handle();

        Http::assertNothingSent();
        $log->shouldHaveReceived('warning')->once();
    }

    public function test_odmowa_cloudflare_konczy_sie_bledem_a_nie_cisza(): void
    {
        // `return` przy błędzie znaczyłby, że kolejka uzna zadanie za wykonane,
        // a zdjęcie zostanie w cache. Wyjątek daje ponowienia i ślad
        // w `failed_jobs`.
        Http::fake(['*' => Http::response(['errors' => []], 403)]);

        config([
            'kuking.media.cdn_purge.zone_id' => 'zona123',
            'kuking.media.cdn_purge.token' => 'tajny-token',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('403');

        (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->handle();
    }

    public function test_po_wyczerpaniu_prob_w_logu_zostaja_konkretne_adresy(): void
    {
        // Bez nich nie da się dokończyć czyszczenia ręcznie — a przy wymazaniu
        // konta ktoś to dokończyć musi.
        $log = Log::spy();

        (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))
            ->failed(new RuntimeException('Cloudflare nie odpowiada.'));

        $log->shouldHaveReceived('error')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                return str_contains($wiadomosc, 'cache CDN')
                    && $kontekst['adresy'] === ['https://cdn.kuking.pl/media/a_feed.webp'];
            },
        );
    }
}
