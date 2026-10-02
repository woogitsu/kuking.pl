<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\KlientLuna;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Models\WpisZgody;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpisBleduZdjeciaOcrTest extends TestCase
{
    use RefreshDatabase;

    private User $osoba;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.import.zrodla.zdjecie' => true,
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        ]);
        $this->osoba = $this->user('kartka_opis_bledu');
        app(PrzestawZgodeNaOdczytAi::class)->handle($this->osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
        Http::fake();
    }

    #[Test]
    public function bez_bledu_pole_ma_tylko_pomoc_a_nie_pusty_odnosnik_do_komunikatu(): void
    {
        $html = $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertOk()->getContent();
        [$xpath, $pole] = $this->pole($html);

        $this->assertFalse($pole->hasAttribute('aria-invalid'));
        $this->assertSame(['f-zdjecie-help'], $this->identyfikatoryOpisu($pole));
        $this->assertSame(1, $xpath->query('//*[@id="f-zdjecie-help"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="f-zdjecie-error"]')->length);
        Http::assertNothingSent();
    }

    #[Test]
    public function brak_pliku_i_zly_format_wiaza_widoczny_blad_z_opisem_pola_po_http(): void
    {
        foreach ([
            'brak pliku' => [],
            'niedozwolony format' => ['zdjecie' => UploadedFile::fake()->create('kartka.txt', 1, 'text/plain')],
        ] as $przypadek => $dane) {
            $this->actingAs($this->osoba)->from(route('import.zdjecie'))
                ->post(route('import.zlec'), $dane)
                ->assertRedirect(route('import.zdjecie'))
                ->assertSessionHasErrors('zdjecie');

            $html = $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertOk()->getContent();
            [$xpath, $pole] = $this->pole($html);

            $this->assertSame('true', $pole->getAttribute('aria-invalid'), $przypadek);
            $this->assertSame(
                ['f-zdjecie-help', 'f-zdjecie-error'],
                $this->identyfikatoryOpisu($pole),
                'OCR_2586_BLEDNY_OPIS_POLA',
            );
            $this->assertSame(1, $xpath->query('//*[@id="f-zdjecie-help"]')->length);
            $bledy = $xpath->query('//*[@id="f-zdjecie-error"]');
            $this->assertSame(1, $bledy->length);
            $blad = $bledy->item(0);
            $this->assertInstanceOf(DOMElement::class, $blad);
            $this->assertStringContainsString('field-error', $blad->getAttribute('class'));
            $this->assertNotSame('', trim($blad->textContent));
            if ($przypadek === 'brak pliku') {
                $this->assertSame(
                    'Wybierz zdjęcie kartki albo strony zeszytu. Połóż kartkę na stole przy oknie i zrób zdjęcie z góry.',
                    trim($blad->textContent),
                );
            }

            $linki = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]//a[@href="#f-zdjecie"]');
            $this->assertSame(1, $linki->length, 'Podsumowanie musi prowadzić do pola pliku.');
            $link = $linki->item(0);
            $this->assertInstanceOf(DOMElement::class, $link);
            $this->assertSame(trim($blad->textContent), trim($link->textContent));
            $this->assertSame(1, $xpath->query('//*[@id="f-zdjecie-etykieta"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="f-zdjecie-tytul"]')->length);
            $this->assertSame('f-zdjecie-etykieta f-zdjecie-tytul', $pole->getAttribute('aria-labelledby'));
        }

        $this->assertSame(0, ImportPrzepisu::query()->count());
        Http::assertNothingSent();
    }

    /** @return array{DOMXPath, DOMElement} */
    private function pole(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $pola = $xpath->query('//input[@type="file" and @id="f-zdjecie"]');
        $this->assertSame(1, $pola->length);
        $pole = $pola->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole);

        return [$xpath, $pole];
    }

    /** @return list<string> */
    private function identyfikatoryOpisu(DOMElement $pole): array
    {
        return preg_split('/\s+/', trim($pole->getAttribute('aria-describedby'))) ?: [];
    }
}
