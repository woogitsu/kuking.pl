<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Operacja na produkcji nie dostaje tokenu staginu (issue #2255).
 *
 * Job `operate` w `.github/workflows/deploy.yml` wybierał token wyrażeniem
 * `environment == 'production' && secrets.RAILWAY_TOKEN_PRODUCTION
 *  || secrets.RAILWAY_TOKEN_STAGING`. W wyrażeniach GitHub Actions `a && b || c`
 * nie jest operatorem trójargumentowym: przy PUSTYM (brakującym, niedostępnym
 * dla środowiska) sekrecie produkcji `&&` daje fałsz, a `||` podaje token
 * staginu. Kontrola `-z "$RAILWAY_TOKEN"` przechodziła, a `railway ssh
 * --environment production` szło z cudzym tokenem.
 *
 * GitHub Actions nie da się uruchomić z testu, więc test OBLICZA to wyrażenie
 * małym interpreterem podzbioru składni Actions (`==`, `&&`, `||`, nawiasy,
 * literały, `secrets.*`, `github.event.inputs.environment`) z semantyką
 * z dokumentacji: `&&`/`||` zwracają operand, nie wartość logiczną; pusty
 * napis, `false`, `0` i `null` są fałszywe; brakujący sekret to pusty napis;
 * `==` na napisach nie rozróżnia wielkości liter. Czego test NIE dowodzi:
 * że sekrety w repozytorium istnieją — tylko że brak właściwego nie jest
 * zastępowany cudzym i że workflow wtedy staje.
 */
class TokenRailwayBezZapasowegoSrodowiskaTest extends TestCase
{
    private const SCIEZKA = '.github/workflows/deploy.yml';

    private function workflow(): string
    {
        $sciezka = base_path(self::SCIEZKA);
        $this->assertFileExists($sciezka, 'Nie ma '.self::SCIEZKA.'. Jeśli plik przeniesiono, popraw ścieżkę tutaj.');

        return (string) file_get_contents($sciezka);
    }

    /** Wyrażenie spod kotwicy `&railway_token`, bez `${{ }}`. */
    private function wyrazenieTokenu(): string
    {
        $this->assertSame(
            1,
            preg_match('/RAILWAY_TOKEN:\s*&railway_token\s*\$\{\{(.*?)\}\}/s', $this->workflow(), $trafienie),
            'Job operate nie definiuje już tokenu Railway pod kotwicą &railway_token — popraw strażnika #2255 razem z workflow.',
        );

        return trim($trafienie[1]);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function przypadki(): iterable
    {
        $oba = ['RAILWAY_TOKEN_PRODUCTION' => 'tok-produkcja', 'RAILWAY_TOKEN_STAGING' => 'tok-staging'];

        yield 'produkcja z obydwoma sekretami' => ['production', $oba, 'tok-produkcja'];
        yield 'staging z obydwoma sekretami' => ['staging', $oba, 'tok-staging'];
        yield 'produkcja BEZ swojego sekretu' => ['production', ['RAILWAY_TOKEN_STAGING' => 'tok-staging'], ''];
        yield 'produkcja z pustym sekretem' => ['production', ['RAILWAY_TOKEN_PRODUCTION' => '', 'RAILWAY_TOKEN_STAGING' => 'tok-staging'], ''];
        yield 'staging BEZ swojego sekretu' => ['staging', ['RAILWAY_TOKEN_PRODUCTION' => 'tok-produkcja'], ''];
        yield 'nieznane środowisko' => ['preview', $oba, ''];
    }

    /** @param  array<string, string>  $sekrety */
    #[Test]
    #[DataProvider('przypadki')]
    public function token_pochodzi_wylacznie_z_sekretu_wybranego_srodowiska(string $srodowisko, array $sekrety, string $oczekiwany): void
    {
        $wynik = $this->oblicz($this->wyrazenieTokenu(), $srodowisko, $sekrety);

        $this->assertSame(
            $oczekiwany,
            $wynik,
            "Dla environment={$srodowisko} wyrażenie tokenu Railway daje „{$wynik}”, a powinno „{$oczekiwany}”. "
            .'Brak sekretu wybranego środowiska ma dać PUSTY token (workflow staje), nigdy token innego środowiska (#2255).',
        );
    }

    #[Test]
    public function kazdy_krok_z_tokenem_uzywa_tej_samej_definicji(): void
    {
        $wiersze = preg_split('/\r\n|\n|\r/', $this->workflow()) ?: [];
        $uzycia = 0;

        foreach ($wiersze as $numer => $wiersz) {
            if (! preg_match('/^\s*RAILWAY_TOKEN:\s*(.*)$/', $wiersz, $m)) {
                continue;
            }
            $uzycia++;
            $this->assertMatchesRegularExpression(
                '/^(&railway_token\s*\$\{\{|\*railway_token\s*$)/',
                $m[1],
                self::SCIEZKA.':'.($numer + 1).' — RAILWAY_TOKEN poza kotwicą &railway_token. Własna kopia wyrażenia '
                .'może znów podać token innego środowiska (#2255).',
            );
        }

        $this->assertGreaterThanOrEqual(4, $uzycia, 'Job operate ma mniej niż cztery kroki z tokenem Railway — sprawdź, czy strażnik czyta właściwy plik.');
    }

    #[Test]
    public function pusty_token_zatrzymuje_operacje_przed_pierwszym_poleceniem_railway(): void
    {
        $workflow = $this->workflow();
        $kontrola = strpos($workflow, 'RAILWAY_TOKEN: &railway_token');
        $this->assertNotFalse($kontrola);

        $blokUruchom = substr($workflow, $kontrola, 2500);
        $this->assertMatchesRegularExpression(
            '/if \[ -z "\$\{RAILWAY_TOKEN:-\}" \]; then.*?exit 1.*?fi\s*\n\s*railway status/s',
            $blokUruchom,
            'Krok z definicją tokenu nie przerywa operacji przy pustym RAILWAY_TOKEN przed `railway status` (#2255).',
        );

        $pierwszeUzycie = strpos($workflow, 'RAILWAY_TOKEN: *railway_token');
        $this->assertNotFalse($pierwszeUzycie);
        $this->assertLessThan($pierwszeUzycie, $kontrola, 'Kontrola tokenu stoi PO kroku, który już używa tokenu Railway.');
    }

    #[Test]
    public function interpreter_zna_pulapke_starego_wyrazenia(): void
    {
        // Kontrola samego interpretera: stary kształt MA dać token staginu
        // dla produkcji bez sekretu — inaczej zielony wynik wyżej nic nie znaczy.
        $stare = "github.event.inputs.environment == 'production' && secrets.RAILWAY_TOKEN_PRODUCTION || secrets.RAILWAY_TOKEN_STAGING";

        $this->assertSame('tok-staging', $this->oblicz($stare, 'production', ['RAILWAY_TOKEN_STAGING' => 'tok-staging']));
        $this->assertSame('tok-produkcja', $this->oblicz($stare, 'PRODUCTION', ['RAILWAY_TOKEN_PRODUCTION' => 'tok-produkcja']));
        $this->assertSame('false', $this->oblicz("github.event.inputs.environment == 'x' && secrets.A", 'y', []));
    }

    // ---------------------------------------------------------------------
    //  Interpreter podzbioru wyrażeń GitHub Actions.
    // ---------------------------------------------------------------------

    /** @var list<array{0: string, 1: string}> */
    private array $tokeny = [];

    private int $poz = 0;

    /** @var array<string, string> */
    private array $sekrety = [];

    private string $srodowisko = '';

    /**
     * Wynik tak, jak trafia do zmiennej środowiskowej kroku: `false` jako
     * napis „false”, `null` jako pusty napis.
     *
     * @param  array<string, string>  $sekrety
     */
    private function oblicz(string $wyrazenie, string $srodowisko, array $sekrety): string
    {
        $this->tokeny = $this->tokenizuj($wyrazenie);
        $this->poz = 0;
        $this->sekrety = $sekrety;
        $this->srodowisko = $srodowisko;

        $wartosc = $this->lub();
        if ($this->poz !== count($this->tokeny)) {
            throw new RuntimeException('Interpreter nie zrozumiał wyrażenia tokenu: '.$wyrazenie);
        }

        return match (true) {
            $wartosc === null => '',
            $wartosc === true => 'true',
            $wartosc === false => 'false',
            default => (string) $wartosc,
        };
    }

    /** @return list<array{0: string, 1: string}> */
    private function tokenizuj(string $wyrazenie): array
    {
        preg_match_all("/\s*(?:(\|\||&&|==|!=|\(|\))|'((?:[^']|'')*)'|([A-Za-z_][A-Za-z0-9_.\-]*))/", $wyrazenie, $m, PREG_SET_ORDER);
        $zlaczone = '';
        $tokeny = [];
        foreach ($m as $t) {
            $zlaczone .= $t[0];
            if (($t[1] ?? '') !== '') {
                $tokeny[] = ['op', $t[1]];
            } elseif (isset($t[3])) {
                // Ostatnia grupa: bez dopasowania PHP jej nie zwraca, a z dopasowaniem jest niepusta.
                $tokeny[] = ['id', $t[3]];
            } else {
                $tokeny[] = ['str', str_replace("''", "'", $t[2])];
            }
        }
        if (trim($zlaczone) !== trim($wyrazenie)) {
            throw new RuntimeException('Interpreter nie zna składni w wyrażeniu tokenu: '.$wyrazenie);
        }

        return $tokeny;
    }

    private function jest(string $op): bool
    {
        if (($this->tokeny[$this->poz] ?? null) === ['op', $op]) {
            $this->poz++;

            return true;
        }

        return false;
    }

    private function prawdziwe(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === 0);
    }

    private function lub(): mixed
    {
        $lewy = $this->oraz();
        while ($this->jest('||')) {
            $prawy = $this->oraz();
            $lewy = $this->prawdziwe($lewy) ? $lewy : $prawy;
        }

        return $lewy;
    }

    private function oraz(): mixed
    {
        $lewy = $this->porownanie();
        while ($this->jest('&&')) {
            $prawy = $this->porownanie();
            $lewy = $this->prawdziwe($lewy) ? $prawy : $lewy;
        }

        return $lewy;
    }

    private function porownanie(): mixed
    {
        $lewy = $this->prosty();
        if ($this->jest('==')) {
            return strcasecmp((string) $lewy, (string) $this->prosty()) === 0;
        }
        if ($this->jest('!=')) {
            return strcasecmp((string) $lewy, (string) $this->prosty()) !== 0;
        }

        return $lewy;
    }

    private function prosty(): mixed
    {
        if ($this->jest('(')) {
            $v = $this->lub();
            if (! $this->jest(')')) {
                throw new RuntimeException('Niedomknięty nawias w wyrażeniu tokenu.');
            }

            return $v;
        }

        [$rodzaj, $tekst] = $this->tokeny[$this->poz++] ?? throw new RuntimeException('Urwane wyrażenie tokenu.');

        if ($rodzaj === 'str') {
            return $tekst;
        }
        if ($rodzaj !== 'id') {
            throw new RuntimeException("Nieoczekiwany operator „{$tekst}” w wyrażeniu tokenu.");
        }

        return match (true) {
            $tekst === 'github.event.inputs.environment', $tekst === 'inputs.environment' => $this->srodowisko,
            str_starts_with($tekst, 'secrets.') => $this->sekrety[substr($tekst, 8)] ?? '',
            $tekst === 'true' => true,
            $tekst === 'false' => false,
            $tekst === 'null' => null,
            default => throw new RuntimeException("Interpreter nie zna kontekstu „{$tekst}” — dopisz go świadomie."),
        };
    }
}
