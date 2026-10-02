<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** #2580: znak porównania w źródle nie jest początkiem znacznika HTML. */
final class ImportLiteralnePorownanieTest extends TestCase
{
    private function strona(string $tekst): string
    {
        return '<script type="application/ld+json">'.json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Próba odczytu',
            'recipeIngredient' => ['100 g mąki'],
            'recipeInstructions' => [
                $tekst,
                ['@type' => 'HowToStep', 'text' => $tekst],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'</script>';
    }

    #[Test]
    public function test_porownanie_liczbowe_zostaje_w_calym_kroku_tekstowym_i_howtostep(): void
    {
        foreach ([
            'Keep below &lt;80 C for 10 min.',
            'Keep below &amp;lt;80 C for 10 min.',
            'Keep below &amp;lt; 80 C for 10 min.',
        ] as $wejscie) {
            $oczekiwane = str_contains($wejscie, 'lt; 80')
                ? 'Keep below < 80 C for 10 min.'
                : 'Keep below <80 C for 10 min.';
            $przepis = (new ParserJsonLdPrzepisu)->odczytaj($this->strona($wejscie));

            $this->assertNotNull($przepis, 'IMPORT_2580_POROWNANIE_NIE_UCINA');
            $this->assertSame([$oczekiwane, $oczekiwane], $przepis->kroki,
                'IMPORT_2580_POROWNANIE_NIE_UCINA: '.$wejscie);
        }
    }

    #[Test]
    public function test_podzialy_i_prawdziwe_znaczniki_nadal_sa_odczytywane_bez_trzeciego_dekodowania(): void
    {
        $this->assertSame(
            ['Mix.', 'Bake below <80 C for 10 min.'],
            ParserJsonLdPrzepisu::wiersze('Mix.&amp;lt;br&amp;gt;&amp;lt;b&amp;gt;Bake below &amp;lt;80 C for 10 min.&amp;lt;/b&amp;gt;'),
            'IMPORT_2580_GRANICE_I_MARKUP',
        );
        $this->assertSame(
            ['Mix.&lt;br&gt;Bake below &lt;80 C.'],
            ParserJsonLdPrzepisu::wiersze('Mix.&amp;amp;lt;br&amp;amp;gt;Bake below &amp;amp;lt;80 C.'),
            'IMPORT_2580_TYLKO_DWIE_WARSTWY',
        );
        $this->assertSame('Bake below <80 C.', ParserJsonLdPrzepisu::tekst('<b>Bake below &lt;80 C.</b>'));
    }
}
