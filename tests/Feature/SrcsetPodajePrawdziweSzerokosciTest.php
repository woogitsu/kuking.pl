<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `srcset` musi podawać PRAWDZIWE szerokości wariantów (audyt zewnętrzny
 * T30).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Komponent `x-photo` wpisywał deskryptory na sztywno:
 *
 *     srcset="… 320w, … 960w, … 1600w"
 *
 * czyli MAKSYMALNE krawędzie z `config('kuking.media.variants')`. Ale
 * `ProcessUploadedImage` skaluje przez `scaleDown()`, które **nigdy nie
 * powiększa** — i to jest świadoma decyzja („małe zdjęcie zostaje małe,
 * zamiast być rozmyte na siłę"). Dla zdjęcia 400×300 wszystkie trzy
 * warianty mają więc najwyżej 400 px szerokości, a `srcset` twierdził,
 * że jeden z nich ma 1600.
 *
 * CO Z TEGO WYNIKA DLA UŻYTKOWNIKA
 * Przeglądarka wybiera wariant na podstawie tych deskryptorów. Widząc
 * „1600w" przy szerokim widoku, pobiera plik, który ma 400 px, i rozciąga
 * go — użytkownik dostaje rozmyte zdjęcie ORAZ, przy okazji, pobiera
 * większy z trzech plików bez żadnego zysku. Kłamliwy deskryptor psuje
 * dokładnie ten mechanizm, dla którego `srcset` istnieje.
 *
 * Prawdziwe szerokości są zapisane w `metadata.variants[*].width`
 * i `Media::width($variant)` je zwraca — komponent używał tej metody
 * w atrybucie `width`, a w `srcset` obok niej wpisywał stałe.
 */
class SrcsetPodajePrawdziweSzerokosciTest extends TestCase
{
    use RefreshDatabase;

    private function zdjecie(int $szerokosc, int $wysokosc): Media
    {
        Storage::fake('testowy');
        config([
            'kuking.media.disk' => 'testowy',
            'kuking.media.public_disk' => 'testowy',
        ]);

        $media = app(StoreUploadedImage::class)->handle(
            owner: $this->user('kucharka'),
            file: UploadedFile::fake()->image('obiad.jpg', $szerokosc, $wysokosc),
            altText: 'Rosół',
        );

        (new ProcessUploadedImage((string) $media->getKey()))->handle();

        return $media->fresh();
    }

    private function srcset(Media $media): string
    {
        $html = Blade::render('<x-photo :media="$media" />', ['media' => $media]);

        preg_match('/srcset="([^"]*)"/', $html, $trafienie);

        return $trafienie[1] ?? '';
    }

    /**
     * KONTROLA. Duże zdjęcie faktycznie ma warianty w pełnych rozmiarach —
     * bez tego test niżej mógłby przechodzić dlatego, że warianty w ogóle
     * nie powstają.
     */
    public function test_duze_zdjecie_ma_warianty_w_pelnych_rozmiarach(): void
    {
        $media = $this->zdjecie(2000, 1500);

        $this->assertSame(320, $media->width('thumb'));
        $this->assertSame(960, $media->width('feed'));
        $this->assertSame(1600, $media->width('large'));

        $srcset = $this->srcset($media);

        $this->assertStringContainsString('320w', $srcset);
        $this->assertStringContainsString('960w', $srcset);
        $this->assertStringContainsString('1600w', $srcset);
    }

    /**
     * WŁAŚCIWY POMIAR. Zdjęcie mniejsze niż największy wariant.
     *
     * 400×300: `scaleDown` nie powiększa, więc `feed` i `large` mają po
     * 400 px, a `thumb` 320 px. `srcset` nie może twierdzić, że którykolwiek
     * ma 960 albo 1600.
     */
    public function test_male_zdjecie_nie_udaje_ze_ma_1600_pikseli(): void
    {
        $media = $this->zdjecie(400, 300);

        // Najpierw upewniamy się, co realnie powstało — inaczej nie wiadomo,
        // czego oczekiwać od `srcset`.
        $this->assertSame(320, $media->width('thumb'), 'Wariant `thumb` nie ma 320 px.');
        $this->assertSame(400, $media->width('feed'), 'Wariant `feed` został powiększony, choć `scaleDown` nie powinien.');
        $this->assertSame(400, $media->width('large'), 'Wariant `large` został powiększony, choć `scaleDown` nie powinien.');

        $srcset = $this->srcset($media);

        $this->assertNotSame('', $srcset, 'Komponent nie wygenerował `srcset` — test nie mierzy tego, co myśli.');

        $this->assertStringNotContainsString(
            '960w',
            $srcset,
            "`srcset` twierdzi, że wariant ma 960 px, a ma 400. Przeglądarka pobierze go przy szerokim widoku i rozciągnie. srcset: {$srcset}",
        );

        $this->assertStringNotContainsString(
            '1600w',
            $srcset,
            "`srcset` twierdzi, że wariant ma 1600 px, a ma 400. srcset: {$srcset}",
        );

        $this->assertStringContainsString('400w', $srcset, '`srcset` nie podaje prawdziwej szerokości wariantu.');
    }

    /**
     * Deskryptory muszą się ZGADZAĆ z atrybutem `width` tego samego
     * obrazka. Wcześniej `width` brał prawdziwą wartość z
     * `Media::width()`, a `srcset` obok niego wpisywał stałą — dwa
     * sprzeczne opisy tego samego pliku w jednym znaczniku.
     */
    public function test_srcset_zgadza_sie_z_atrybutem_width(): void
    {
        $media = $this->zdjecie(500, 500);

        $html = Blade::render('<x-photo :media="$media" />', ['media' => $media]);

        preg_match('/\bwidth="(\d+)"/', $html, $szerokoscAtrybutu);
        preg_match('/srcset="([^"]*)"/', $html, $srcset);

        $this->assertNotEmpty($szerokoscAtrybutu, 'Brak atrybutu `width`.');

        // Wariant domyślny to `feed`; jego szerokość musi wystąpić w srcset.
        $this->assertStringContainsString(
            $szerokoscAtrybutu[1].'w',
            $srcset[1] ?? '',
            'Atrybut `width` i `srcset` opisują ten sam plik dwiema różnymi liczbami.',
        );
    }
}
