<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/** Regresja treści i samodzielności; geometrię CSS mierzy osobno przeglądarka. */
class SamodzielneEkranyMarkiTest extends TestCase
{
    public function test_awaria_renderuje_nowa_marke_bez_bazy_i_manifestu(): void
    {
        DB::shouldReceive('connection')->never();
        Vite::swap(new \Illuminate\Foundation\Vite);
        Vite::useCspNonce('proba-samodzielnej-strony');

        foreach (['500', '503'] as $code) {
            $html = view('errors.'.$code)->render();
            $dom = $this->document($html);
            $this->assertSame(1, $dom->query('//main/h1')->length);
            $this->assertSame('/', $dom->query('//main//a')->item(0)->getAttribute('href'));
            $this->assertSame('proba-samodzielnej-strony', $dom->query('//style')->item(0)->getAttribute('nonce'));
            $this->assertSame(0, $dom->query('//script | //link')->length);
            $this->assertBrand($dom->query('//style')->item(0)->textContent);
            $this->assertDoesNotMatchRegularExpression('/już o niej wiemy|są bezpieczne|Wrócimy dziś|za kwadrans/u', $html);
        }
    }

    public function test_offline_ma_droge_powrotu_i_obydwa_dotychczasowe_motywy(): void
    {
        $dom = $this->document(file_get_contents(public_path('offline.html')));
        $this->assertSame('Nie ma teraz połączenia z internetem', trim($dom->query('//main/h1')->item(0)->textContent));
        // #749: „Spróbuj ponownie” ponawia adres, który się nie wczytał
        // (pusty href = bieżący dokument), a strona główna ma własny napis.
        // Zachowanie z prawdziwym workerem: scripts/offline-ponowienie.test.mjs.
        $linki = $dom->query('//main//a');
        $this->assertSame('Spróbuj ponownie', trim($linki->item(0)->textContent));
        $this->assertSame('', $linki->item(0)->getAttribute('href'));
        $this->assertTrue($linki->item(0)->hasAttribute('href'));
        $this->assertSame('Przejdź na stronę główną', trim($linki->item(1)->textContent));
        $this->assertSame('/home', $linki->item(1)->getAttribute('href'));
        $this->assertSame(0, $dom->query('//script | //link')->length);
        $css = $dom->query('//style')->item(0)->textContent;
        $this->assertBrand($css);
        $this->assertStringContainsString('#222620', $css);
        $this->assertStringContainsString('#F4F5F1', $css);
    }

    public function test_eksport_dostaje_nowa_marke_bez_zasobow_sieciowych(): void
    {
        DB::shouldReceive('connection')->never();
        $html = view('exports.index', [
            'generatedAt' => now(), 'displayName' => 'Próba eksportu',
            'recipes' => [], 'postCount' => 0, 'cookedCount' => 0,
            'photoCount' => 0, 'photosStillProcessing' => 0,
            // Ten test pyta o markę arkusza, nie o treść ostrzeżeń — komplet
            // zer, żeby render miał wszystkie dane (#692).
            'photosRejected' => 0, 'photosDeleted' => 0,
            // Zeszyt bez cudzych przepisów — zdanie o ich ograniczeniu wtedy
            // nie wychodzi. Ten test pyta o markę arkusza, nie o treść zdania;
            // obie gałęzie warunku mierzy `EksportWygladObietnicePaczkiTest`.
            'savedOtherRecipeCount' => 0,
        ])->render();
        $dom = $this->document($html);
        $this->assertSame('Twoje dane z Kuking', trim($dom->query('//h1')->item(0)->textContent));
        $this->assertGreaterThan(0, $dom->query('//a[@href="dane.json"]')->length);
        $this->assertSame(0, $dom->query('//script | //link | //img')->length);
        $css = $dom->query('//style')->item(0)->textContent;
        $this->assertBrand($css);
        $this->assertDoesNotMatchRegularExpression('/url\s*\(|@import/i', $css);
        $this->assertStringContainsString('background: var(--tekst)', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
    }

    private function assertBrand(string $css): void
    {
        foreach (['#F3F4F1', '#151714', '#DDE0D8', '#BE3025', 'sans-serif', 'border-radius: 24px', ':focus-visible', 'min-height: 48px'] as $required) {
            $this->assertStringContainsString($required, $css);
        }
        $this->assertDoesNotMatchRegularExpression('/Georgia|Times New Roman|#FAF6F0|#2B241D|#B3401F/i', $css);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }
}
