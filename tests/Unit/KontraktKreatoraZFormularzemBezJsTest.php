<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\KosztPrzepisu;
use App\Http\Requests\Recipes\ZapisPrzepisuRequest;
use App\Livewire\Forms\PrzepisForm;
use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1387, krok 6 — kontrakt: kreator (Livewire, wymaga JavaScriptu)
 * i formularz bez JavaScriptu (`ZapisPrzepisuRequest`) mają mówić TO SAMO
 * o tych samych polach.
 *
 * Kreator ma własne reguły w `KrokOPrzepisie` (żeby błąd trafił przy polu
 * w komponencie), więc dwie kopie mogą się rozjechać: jedna droga przyjęłaby
 * wartość, którą druga odrzuca, albo powiedziałaby człowiekowi coś innego.
 * Ten test przepuszcza te same wartości przez oba walidatory i porównuje
 * wynik oraz pierwsze zdanie błędu.
 *
 * OBJĘTE SĄ TYLKO POLA O TEJ SAMEJ SEMANTYCE: nazwa, opis, porcje, koszt
 * i oba czasy. Pola pochodzenia i widoczności różnią się celowo (na ekranie
 * bez JS `source_type` jest nieobowiązkowe, w kreatorze wymagane — patrz
 * komentarz w `KrokOPrzepisie`), więc nie należą do kontraktu.
 */
final class KontraktKreatoraZFormularzemBezJsTest extends TestCase
{
    /** Pola o tej samej semantyce w obu drogach. */
    private const POLA_WSPOLNE = ['title', 'summary', 'servings', 'estimated_cost_pln', 'prep_minutes', 'cook_minutes'];

    public function test_pola_wspolne_naleza_do_formularza_kreatora(): void
    {
        $this->assertSame([], array_diff(self::POLA_WSPOLNE, PrzepisForm::POLA));
        $this->assertSame([], array_diff(self::POLA_WSPOLNE, KrokOPrzepisie::POLA));
    }

    public function test_reguly_sa_te_same_z_wyjatkiem_bail_przy_porcjach(): void
    {
        $kreator = KrokOPrzepisie::reguly(null, null);
        $formularz = (new ZapisPrzepisuRequest)->rules();

        foreach (self::POLA_WSPOLNE as $pole) {
            // `bail` w kreatorze to tylko ŚWIADOMA różnica prezentacji: „cztery”
            // dostaje jedno zdanie (liczba), nie dwa. Nie zmienia tego, co przechodzi.
            $this->assertSame(
                $this->bezBail($formularz[$pole]),
                $this->bezBail($kreator[$pole]),
                "Reguły pola {$pole} rozjechały się między kreatorem a formularzem bez JS.",
            );
        }
    }

    public function test_komunikaty_pol_wspolnych_sa_te_same(): void
    {
        $formularz = (new ZapisPrzepisuRequest)->messages();
        $zbadane = 0;

        foreach (KrokOPrzepisie::KOMUNIKATY as $klucz => $tekst) {
            if (! in_array(explode('.', $klucz)[0], self::POLA_WSPOLNE, true)) {
                continue;
            }

            $this->assertArrayHasKey($klucz, $formularz, "Formularz bez JS nie ma komunikatu {$klucz}.");
            $this->assertSame($formularz[$klucz], $tekst, "Komunikat {$klucz} różni się między drogami.");
            $zbadane++;
        }

        $this->assertGreaterThan(15, $zbadane, 'Test nie zbadał komunikatów — zmieniła się struktura klucza?');
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function wartosci(): iterable
    {
        yield 'nazwa' => ['title', ['', '  ', 'Ro', 'Rosół', str_repeat('a', 180), str_repeat('a', 181)]];
        yield 'opis' => ['summary', ['', 'Na chłodne dni.', str_repeat('a', 2000), str_repeat('a', 2001)]];
        yield 'porcje' => ['servings', ['', '4', '1.25', '1.255', '0', '0.4', '0.5', '999', '1000', 'cztery', '-1']];
        yield 'koszt' => ['estimated_cost_pln', ['', '24', '24,50', '24 zł', '0', 'abc', '-3', '24,555', '99999999']];
        yield 'przygotowanie' => ['prep_minutes', ['', '0', '20', '-1', '10080', '10081', '1,5', '2.5', 'abc']];
        yield 'gotowanie' => ['cook_minutes', ['', '0', '90', '-1', '10080', '10081', '1,5', '2.5', 'abc']];
    }

    /**
     * @param  list<string>  $wartosci
     */
    #[DataProvider('wartosci')]
    public function test_te_same_wartosci_daja_ten_sam_werdykt_i_to_samo_zdanie(string $pole, array $wartosci): void
    {
        foreach ($wartosci as $wartosc) {
            [$kreatorFails, $kreatorZdanie] = $this->werdyktKreatora($pole, $wartosc);
            [$formularzFails, $formularzZdanie] = $this->werdyktFormularza($pole, $wartosc);

            $opis = "Pole {$pole}, wartość „{$wartosc}”";
            $this->assertSame($formularzFails, $kreatorFails, "{$opis}: drogi różnią się co do poprawności.");
            $this->assertSame($formularzZdanie, $kreatorZdanie, "{$opis}: drogi mówią człowiekowi co innego.");
        }
    }

    /** @return array{0: bool, 1: ?string} */
    private function werdyktKreatora(string $pole, string $wartosc): array
    {
        $pola = ['title' => 'Rosół'] + array_fill_keys(self::POLA_WSPOLNE, '');
        $pola['visibility'] = 'public';
        $pola['source_type'] = 'own';
        $pola[$pole] = $wartosc;

        $blad = KrokOPrzepisie::walidator($pola, null)->errors();

        return [$blad->has($pole), $blad->first($pole) ?: null];
    }

    /** @return array{0: bool, 1: ?string} */
    private function werdyktFormularza(string $pole, string $wartosc): array
    {
        // To, co robią middleware `TrimStrings` i `ConvertEmptyStringsToNull`
        // przed `ZapisPrzepisuRequest`.
        $wartosc = trim($wartosc);
        $dane = ['title' => 'Rosół', $pole => $wartosc === '' ? null : $wartosc];

        $zadanie = ZapisPrzepisuRequest::create('/dodaj/przepis', 'POST', $dane);
        $walidator = Validator::make($zadanie->validationData(), $zadanie->rules(), $zadanie->messages());
        $blad = $walidator->errors();

        // `validationData()` normalizuje koszt tak samo jak `KosztPrzepisu::normalizuj`
        // w kreatorze — gdyby ktoś to usunął, koszt „24,50” przestałby przechodzić.
        if ($pole === 'estimated_cost_pln' && $wartosc !== '') {
            $this->assertSame(KosztPrzepisu::normalizuj($wartosc), $zadanie->validationData()['estimated_cost_pln']);
        }

        return [$blad->has($pole), $blad->first($pole) ?: null];
    }

    /**
     * @param  list<mixed>  $reguly
     * @return list<mixed>
     */
    private function bezBail(array $reguly): array
    {
        return array_values(array_filter($reguly, static fn (mixed $regula): bool => $regula !== 'bail'));
    }
}
