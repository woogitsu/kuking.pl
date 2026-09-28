<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CZAS ZAMYKANIA (`drainingSeconds`) W DOKUMENTACH = TO, CO WYLICZA IaC (#2056)
 *
 * `docs/DEPLOYMENT.md` podawał dla roli `all` 30 s, choć `.railway/railway.ts`
 * daje jej `ZAMKNIECIE_Z_KOLEJKA_S` (130 s), a 30 s tylko serwisowi `web`
 * po rozdzieleniu. Runbooki przełączenia na trzy serwisy nadal pisały
 * „przez 30 s stary kontener `all`”. Liczby czytamy z `railway.ts`, nie
 * przepisujemy ich tutaj — zmiana stałej bez zmiany opisu oblewa test.
 *
 * Tabela wierszy (topologia, rola, sekundy) jest też sprawdzana na
 * skompilowanym grafie w `scripts/railway/iac.test.mjs`. Ten plik pilnuje
 * reszty: że runbook mówi, którą topologię IaC wylicza dla produkcji, że
 * odróżnia wyliczenie od stanu panelu i że runbooki przełączenia nie
 * wpisują na sztywno czasu drenowania starego kontenera `all`.
 *
 * @bez-kontroli-dodatniej Każda kotwica ma assertSame(1, preg_match) albo assertStringContainsString na zdaniu, którego dotyczy asercja ujemna, więc przeredagowany plik daje czerwień, nie cichą zieleń; kontrole ujemne (6 mutacji) wykonano ręcznie przy #2056.
 */
class CzasZamykaniaWDokumentachZgodnyZIacTest extends TestCase
{
    private function plik(string $sciezka): string
    {
        $pelna = dirname(__DIR__, 2).'/'.$sciezka;

        $this->assertFileExists($pelna, "Nie ma {$sciezka}.");

        return (string) file_get_contents($pelna);
    }

    /** @return array{all: int, web: int, produkcjaRozdzielona: bool} */
    private function wartosciIac(): array
    {
        $iac = $this->plik('.railway/railway.ts');

        $this->assertSame(1, preg_match('/^const ZAMKNIECIE_Z_KOLEJKA_S = (\d+);$/m', $iac, $kolejka),
            'Nie znalazłem `const ZAMKNIECIE_Z_KOLEJKA_S = …;` w railway.ts — test czyta zły kształt pliku.');
        $this->assertSame(1, preg_match('/^\s*drainingSeconds: splitServices \? (\d+) : ZAMKNIECIE_Z_KOLEJKA_S,$/m', $iac, $web),
            'Nie znalazłem wyboru `drainingSeconds: splitServices ? … : ZAMKNIECIE_Z_KOLEJKA_S` w railway.ts.');
        $this->assertSame(1, preg_match('/^const PRODUCTION_SPLIT_SERVICES = (true|false);$/m', $iac, $split),
            'Nie znalazłem `const PRODUCTION_SPLIT_SERVICES = …;` w railway.ts.');

        return ['all' => (int) $kolejka[1], 'web' => (int) $web[1], 'produkcjaRozdzielona' => $split[1] === 'true'];
    }

    #[Test]
    public function runbook_podaje_czasy_z_railway_ts_dla_obu_topologii(): void
    {
        $iac = $this->wartosciIac();
        $dokument = $this->plik('docs/DEPLOYMENT.md');

        $this->assertStringContainsString("| `splitServices=false` | `all` | {$iac['all']} s |", $dokument,
            "docs/DEPLOYMENT.md: rola `all` (bez rozdzielenia) ma w IaC {$iac['all']} s.");
        $this->assertStringContainsString("| `splitServices=true` | `web` | {$iac['web']} s |", $dokument,
            "docs/DEPLOYMENT.md: `web` po rozdzieleniu ma w IaC {$iac['web']} s.");
    }

    #[Test]
    public function runbook_mowi_ktora_topologie_iac_wylicza_dla_produkcji(): void
    {
        $iac = $this->wartosciIac();
        $dokument = $this->plik('docs/DEPLOYMENT.md');
        $flaga = $iac['produkcjaRozdzielona'] ? 'true' : 'false';

        $this->assertStringContainsString("`PRODUCTION_SPLIT_SERVICES = {$flaga}`", $dokument,
            "docs/DEPLOYMENT.md musi podać, że railway.ts ma `PRODUCTION_SPLIT_SERVICES = {$flaga}` — od tego zależy, który wiersz tabeli dotyczy produkcji.");
    }

    #[Test]
    public function runbook_odroznia_wyliczenie_iac_od_stanu_panelu_i_mowi_jak_go_sprawdzic(): void
    {
        $dokument = (string) preg_replace('/\s+/', ' ', $this->plik('docs/DEPLOYMENT.md'));

        $this->assertStringContainsString('wartość wyliczana przez IaC', $dokument,
            'docs/DEPLOYMENT.md musi mówić wprost, że tabela to wyliczenie IaC, nie potwierdzony stan produkcji.');
        $this->assertStringContainsString('Settings → Deploy', $dokument,
            'docs/DEPLOYMENT.md musi wskazać, gdzie w panelu Railway odczytać faktyczny czas zamykania.');
    }

    #[Test]
    public function runbooki_przelaczenia_nie_wpisuja_na_sztywno_czasu_drenowania_starego_all(): void
    {
        // Liczba przed „stary kontener `all`” była 30 s — to czas `web`
        // PO przełączeniu, a nie okno starego kontenera. Faktyczne okno
        // zależy od tego, co jest ustawione w panelu (#2056).
        foreach (['docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md', 'docs/infra/LISTA_KROKOW_ALFA.md'] as $sciezka) {
            $tekst = (string) preg_replace('/\s+/', ' ', $this->plik($sciezka));

            $this->assertStringContainsString('stary kontener `all`', $tekst,
                "{$sciezka}: nie ma już zdania o starym kontenerze `all` — test czyta zły fragment.");
            $this->assertDoesNotMatchRegularExpression('/\d+ s[^.]{0,40}stary kontener `all`/u', $tekst,
                "{$sciezka}: czas drenowania starego kontenera `all` wpisany na sztywno — odeślij do docs/DEPLOYMENT.md.");
        }
    }
}
