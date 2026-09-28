<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #1461 — dokumenty prywatności mówią o awatarach to samo co kod.
 *
 * Po D-240 `AvatarSettingsController` przestał zlecać `PrzeanalizujAwatar`,
 * a samo zadanie zostało pustym no-opem dla zleceń sprzed wdrożenia. Dwa
 * utrzymywane dokumenty (`docs/legal/SYGNALY_AUTOMATU.md`, `docs/DATABASE.md`)
 * dalej opisywały ten przepływ jako aktywny. Przegląd zgodności mógł z nich
 * wyczytać, że awatary nadal wychodzą do OpenAI, a następna osoba — „naprawić”
 * pusty job według dokumentacji i cofnąć D-240.
 *
 * ŹRÓDŁO PRAWDY TO KOD, NIE ZMIENNA ŚRODOWISKOWA. Test czyta tokeny PHP
 * kontrolera i zadania (komentarze się nie liczą) i z nich ustala, czy
 * analiza awatara jest dziś włączona. Od tego stanu zależy, co dokumenty
 * MUSZĄ, a czego NIE WOLNO im mówić. Nie porównujemy całych akapitów —
 * tylko twierdzenia, które rozstrzygają sprawę.
 *
 * Opis dawnego przepływu wolno zostawić w bloku jawnie oznaczonym jako
 * historia: `<!-- historia: … D-240 … -->` … `<!-- /historia -->`. Znacznik
 * musi nazywać D-240, żeby „historia” nie stała się furtką bez powodu.
 *
 * Kontrola dodatnia: `scripts/kontrole-negatywne-alfa08.py` (dopisanie
 * zlecenia w kontrolerze i przywrócenie dawnego zdania w DATABASE.md).
 */
final class DokumentacjaAwataraZgodnaZKodemTest extends TestCase
{
    private const KONTROLER = 'app/Http/Controllers/Settings/AvatarSettingsController.php';

    private const ZADANIE = 'app/Jobs/PrzeanalizujAwatar.php';

    private const DOKUMENTY = [
        'docs/legal/SYGNALY_AUTOMATU.md',
        'docs/DATABASE.md',
    ];

    /** Twierdzenie, które każdy z dokumentów musi zawierać, gdy analiza jest wyłączona. */
    private const TWIERDZENIE_WYLACZONA = '/`AvatarSettingsController`\s+nie\s+zleca\s+`PrzeanalizujAwatar`/u';

    /** Twierdzenia o AKTYWNEJ analizie — poza blokiem historii zakazane, gdy kod jej nie zleca. */
    private const TWIERDZENIA_AKTYWNE = [
        'kontroler zleca zadanie' => '/(?<!nie\s)zleca\s+`PrzeanalizujAwatar`/u',
        'model ocenia awatar' => '/model\s+ocenia\s+(?:je|awatar|zdjęcie\s+profilowe)\b/u',
        'zadanie czeka na warianty' => '/Zadanie\s+CZEKA\s+na\s+warianty/u',
    ];

    public function test_kod_nie_zleca_analizy_awatara_a_testy_prywatnosci_tego_wymagaja(): void
    {
        // Ten test opisuje stan D-240. Gdy kod znów zleca analizę, zapali —
        // i dobrze: wtedy trzeba poprawić dokumenty i dopisać zgodę (§9.1).
        $this->assertFalse(
            $this->kontrolerZleca(),
            'AvatarSettingsController znów odwołuje się do PrzeanalizujAwatar. D-240 wymaga osobnej decyzji '
            .'(cel zgody, ekran zgody, sprawdzenie przed wysyłką) — i poprawy docs/legal/SYGNALY_AUTOMATU.md §9 '
            .'oraz docs/DATABASE.md.',
        );
        $this->assertTrue($this->zadanieJestPuste(), 'PrzeanalizujAwatar::handle() przestało być puste.');

        $moderacja = $this->plik('tests/Feature/ModeracjaZdjeciaProfilowegoTest.php');
        $this->assertStringContainsString('Queue::assertNotPushed(PrzeanalizujAwatar::class)', $moderacja);

        $granica = $this->plik('tests/Feature/GranicaWysylkiDoOpenAiTest.php');
        $this->assertMatchesRegularExpression(
            '/dispatch_sync\(new PrzeanalizujAwatar\([^;]*;\s*\n\s*\n\s*Http::assertNothingSent\(\);/u',
            $granica,
            'Test granicy wysyłki przestał uruchamiać historyczne zadanie i sprawdzać brak żądania HTTP.',
        );
    }

    public function test_dokumenty_opisuja_stan_analizy_awatara_zgodnie_z_kodem(): void
    {
        $zleca = $this->kontrolerZleca() || ! $this->zadanieJestPuste();
        $bledy = [];

        foreach (self::DOKUMENTY as $sciezka) {
            $tresc = $this->bezHistorii($this->plik($sciezka));
            $mowiWylaczona = preg_match(self::TWIERDZENIE_WYLACZONA, $tresc) === 1;

            if ($zleca) {
                if ($mowiWylaczona) {
                    $bledy[] = $sciezka.': twierdzi, że kontroler nie zleca PrzeanalizujAwatar, a kod zleca.';
                }

                continue;
            }

            if (! $mowiWylaczona) {
                $bledy[] = $sciezka.': brak twierdzenia „`AvatarSettingsController` nie zleca `PrzeanalizujAwatar`”.';
            }

            if (! str_contains($tresc, 'no-op')) {
                $bledy[] = $sciezka.': nie mówi, że PrzeanalizujAwatar jest no-opem.';
            }

            if (preg_match('/zgod[ayęzi]/u', $tresc) !== 1) {
                $bledy[] = $sciezka.': nie opisuje granicy zgody przed ponownym włączeniem.';
            }

            foreach (self::TWIERDZENIA_AKTYWNE as $opis => $wzor) {
                if (preg_match($wzor, $tresc) === 1) {
                    $bledy[] = $sciezka.': poza blokiem historii stoi twierdzenie „'.$opis.'”.';
                }
            }
        }

        $this->assertSame([], $bledy, "Dokumenty rozjechały się z kodem awatarów (D-240, #1461):\n".implode("\n", $bledy));
    }

    public function test_blok_historii_musi_nazywac_d240_i_byc_domkniety(): void
    {
        foreach (self::DOKUMENTY as $sciezka) {
            $tresc = $this->plik($sciezka);
            preg_match_all('/<!-- historia:([^>]*)-->/u', $tresc, $otwarcia);

            $this->assertSame(
                count($otwarcia[0]),
                substr_count($tresc, '<!-- /historia -->'),
                $sciezka.': liczba otwarć i zamknięć bloku historii się nie zgadza.',
            );

            foreach ($otwarcia[1] as $powod) {
                $this->assertStringContainsString('D-240', $powod, $sciezka.': blok historii bez powodu D-240.');
            }
        }
    }

    public function test_kontrola_metody_filtr_historii_i_wzorce_widza_to_co_maja(): void
    {
        // KONTROLA METODY (`docs/PULAPKI_TESTOW.md` §2): bez niej zielony wynik
        // mógłby znaczyć, że wzorzec niczego nie łapie albo filtr wycina wszystko.
        $stare = 'Kontroler zapisuje zdjęcie i dopiero potem zleca `PrzeanalizujAwatar`.';

        $this->assertMatchesRegularExpression(self::TWIERDZENIA_AKTYWNE['kontroler zleca zadanie'], $stare);
        $this->assertDoesNotMatchRegularExpression(
            self::TWIERDZENIA_AKTYWNE['kontroler zleca zadanie'],
            '`AvatarSettingsController` nie zleca `PrzeanalizujAwatar`.',
        );
        $this->assertMatchesRegularExpression(
            self::TWIERDZENIA_AKTYWNE['model ocenia awatar'],
            'model ocenia je po przetworzeniu (`PrzeanalizujAwatar`)',
        );

        $zHistoria = "przed\n<!-- historia: przed D-240 -->\n{$stare}\n<!-- /historia -->\npo";
        $this->assertSame("przed\n\npo", $this->bezHistorii($zHistoria));

        $this->assertFalse($this->zlecaWKodzie("<?php\n// zlecaliśmy `PrzeanalizujAwatar`\n"));
        $this->assertTrue($this->zlecaWKodzie("<?php\nPrzeanalizujAwatar::dispatch(\$id);\n"));
    }

    private function kontrolerZleca(): bool
    {
        return $this->zlecaWKodzie($this->plik(self::KONTROLER));
    }

    /** Czy nazwa zadania pada w KODZIE (poza komentarzami i napisami). */
    private function zlecaWKodzie(string $zrodlo): bool
    {
        foreach (\PhpToken::tokenize($zrodlo) as $token) {
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && str_ends_with($token->text, 'PrzeanalizujAwatar')) {
                return true;
            }
        }

        return false;
    }

    private function zadanieJestPuste(): bool
    {
        $zrodlo = $this->plik(self::ZADANIE);

        // KONTROLA METODY: bez metody `handle` asercja niżej badałaby pusty tekst.
        $this->assertMatchesRegularExpression('/function\s+handle\(\)\s*:\s*void/u', $zrodlo);

        preg_match('/function\s+handle\(\)\s*:\s*void\s*\{(.*?)\n    \}/su', $zrodlo, $cialo);
        $kod = array_filter(
            \PhpToken::tokenize('<?php '.($cialo[1] ?? 'x;')),
            fn (\PhpToken $t) => ! $t->isIgnorable() && ! $t->is(T_OPEN_TAG),
        );

        return $kod === [];
    }

    private function bezHistorii(string $tresc): string
    {
        return (string) preg_replace('/<!-- historia:.*?<!-- \/historia -->/su', '', $tresc);
    }

    private function plik(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));
        $this->assertIsString($tresc, 'Brak pliku '.$sciezka);
        $this->assertNotSame('', $tresc, 'Pusty plik '.$sciezka);

        return $tresc;
    }
}
