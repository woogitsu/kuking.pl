<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387, krok 1 — reguły i komunikaty kroku „o przepisie” wydzielone
 * z komponentu `recipe-wizard` do `KrokOPrzepisie`.
 *
 * Testy chodzą BEZ renderowania kreatora i bez bazy: klasa dostaje surowe
 * wartości pól i zwraca walidator. Każdy komunikat z `KOMUNIKATY` ma tu
 * wejście, które go wywołuje — komunikat bez takiego wejścia byłby martwy,
 * a zmieniona reguła (np. inny próg) oblewa dokładnie ten przypadek.
 */
final class KrokOPrzepisieKreatoraTest extends TestCase
{
    /** @return array<string, mixed> */
    private function poprawnePola(): array
    {
        return [
            'title' => 'Rosół babci Zofii',
            'summary' => '',
            'servings' => '',
            'prep_minutes' => '',
            'cook_minutes' => '',
            'difficulty' => '',
            'visibility' => 'public',
            'source_type' => 'own',
            'source_person' => '',
            'source_note' => '',
            'source_url' => '',
            'family_since_year' => '',
        ];
    }

    public function test_poprawne_pola_przechodza(): void
    {
        $walidator = KrokOPrzepisie::walidator($this->poprawnePola(), null);

        $this->assertFalse($walidator->fails(), implode(' | ', $walidator->errors()->all()));
    }

    public function test_komplet_wypelnionych_pol_w_granicach_przechodzi(): void
    {
        $walidator = KrokOPrzepisie::walidator([
            'title' => 'Pierogi',
            'summary' => 'Z kapustą i grzybami.',
            'servings' => '0.5',
            'prep_minutes' => '0',
            'cook_minutes' => '10080',
            'difficulty' => 'hard',
            'visibility' => 'followers',
            'source_type' => 'family',
            'source_person' => 'od mamy',
            'source_note' => 'Na Wigilię.',
            'source_url' => 'https://example.com/pierogi',
            'family_since_year' => '1850',
        ], null);

        $this->assertFalse($walidator->fails(), implode(' | ', $walidator->errors()->all()));
    }

    public function test_normalizacja_przycina_tytul_i_zamienia_puste_na_null(): void
    {
        $dane = KrokOPrzepisie::dane([
            'title' => '   ',
            'summary' => "  Zupa \n",
            'servings' => ' ',
            'visibility' => 'private',
            'source_type' => 'own',
        ]);

        $this->assertSame('', $dane['title'], 'Tytuł jest przycinany, ale nie zamienia się w null.');
        $this->assertSame('Zupa', $dane['summary']);
        $this->assertNull($dane['servings']);
        $this->assertNull($dane['family_since_year'], 'Brak pola to null, nie wyjątek.');
        $this->assertSame('private', $dane['visibility']);
        $this->assertSame(KrokOPrzepisie::POLA, array_keys($dane), 'Normalizacja zwraca dokładnie pola kroku.');
    }

    public function test_tytul_z_samych_spacji_to_brak_tytulu(): void
    {
        $walidator = KrokOPrzepisie::walidator(['title' => '    '] + $this->poprawnePola(), null);

        $this->assertSame(
            [KrokOPrzepisie::KOMUNIKATY['title.required']],
            $walidator->errors()->get('title'),
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function wejsciaDlaKomunikatow(): array
    {
        return [
            'title.required' => ['title', '', 'title.required'],
            'title.min' => ['title', 'Zu', 'title.min'],
            'title.max' => ['title', str_repeat('a', 181), 'title.max'],
            'summary.max' => ['summary', str_repeat('a', 2001), 'summary.max'],
            'servings.numeric' => ['servings', 'cztery', 'servings.numeric'],
            'servings.min' => ['servings', '0.4', 'servings.min'],
            'servings.max' => ['servings', '1000', 'servings.max'],
            'servings.decimal' => ['servings', '1.255', 'servings.decimal'],
            'prep_minutes.integer' => ['prep_minutes', '1.5', 'prep_minutes.integer'],
            'prep_minutes.min' => ['prep_minutes', '-1', 'prep_minutes.min'],
            'prep_minutes.max' => ['prep_minutes', '10081', 'prep_minutes.max'],
            'cook_minutes.integer' => ['cook_minutes', 'dużo', 'cook_minutes.integer'],
            'cook_minutes.min' => ['cook_minutes', '-5', 'cook_minutes.min'],
            'cook_minutes.max' => ['cook_minutes', '10081', 'cook_minutes.max'],
            'visibility.required' => ['visibility', '', 'visibility.required'],
            'visibility.in' => ['visibility', 'everyone', 'visibility.in'],
            'source_type.required' => ['source_type', '', 'source_type.required'],
            'source_type.in' => ['source_type', 'book', 'source_type.in'],
            'source_person.max' => ['source_person', str_repeat('a', 121), 'source_person.max'],
            'source_note.max' => ['source_note', str_repeat('a', 2001), 'source_note.max'],
            'source_url.url' => ['source_url', 'ftp://example.com/plik', 'source_url.url'],
            'family_since_year.integer' => ['family_since_year', '1974.5', 'family_since_year.integer'],
            'family_since_year.min' => ['family_since_year', '1849', 'family_since_year.min'],
            'family_since_year.max' => ['family_since_year', '2101', 'family_since_year.max'],
        ];
    }

    #[DataProvider('wejsciaDlaKomunikatow')]
    public function test_kazdy_komunikat_ma_wejscie_ktore_go_wywoluje(string $pole, string $wartosc, string $klucz): void
    {
        $walidator = KrokOPrzepisie::walidator([$pole => $wartosc] + $this->poprawnePola(), null);

        $this->assertTrue($walidator->fails(), "Wartość {$wartosc} w polu {$pole} przeszła walidację.");
        $this->assertSame(
            [KrokOPrzepisie::KOMUNIKATY[$klucz]],
            $walidator->errors()->get($pole),
        );
        $this->assertSame([$pole], $walidator->errors()->keys(), 'Błąd jednego pola nie może zaczepić o inne.');
    }

    public function test_kazdy_komunikat_ma_przypadek_testowy_i_dotyczy_pola_kroku(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(self::wejsciaDlaKomunikatow()),
            array_keys(KrokOPrzepisie::KOMUNIKATY),
            'Nowy komunikat w KrokOPrzepisie potrzebuje wejścia w wejsciaDlaKomunikatow().',
        );

        foreach (array_keys(KrokOPrzepisie::KOMUNIKATY) as $klucz) {
            $this->assertContains(explode('.', $klucz)[0], KrokOPrzepisie::POLA, "Komunikat {$klucz} dotyczy pola spoza kroku.");
        }
    }

    public function test_niezmieniony_dawny_adres_z_bazy_nie_blokuje_zapisu(): void
    {
        // #900: adres zapisany kiedyś w bazie (np. ftp://) nie blokuje edycji.
        $pola = ['source_url' => ' ftp://example.com/stary '] + $this->poprawnePola();

        $this->assertFalse(KrokOPrzepisie::walidator($pola, 'ftp://example.com/stary')->fails());
    }

    public function test_zmieniony_adres_musi_byc_http_lub_https(): void
    {
        $pola = ['source_url' => 'ftp://example.com/nowy'] + $this->poprawnePola();

        $walidator = KrokOPrzepisie::walidator($pola, 'ftp://example.com/stary');

        $this->assertSame([KrokOPrzepisie::KOMUNIKATY['source_url.url']], $walidator->errors()->get('source_url'));
    }

    public function test_nowy_przepis_nie_ma_wyjatku_dla_adresu(): void
    {
        $pola = ['source_url' => 'ftp://example.com/plik'] + $this->poprawnePola();

        $this->assertTrue(KrokOPrzepisie::walidator($pola, null)->fails());
    }
}
