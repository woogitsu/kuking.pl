<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\Alergeny\Alergen;
use App\Domain\Recipes\Alergeny\SlownikAlergenow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Słownik podpowiedzi alergenów (etap 2 z #1902, D-333): nazwy niejednoznaczne,
 * fleksja, „bez”. Podpowiedź to tylko podpowiedź — test pilnuje, że słownik
 * nie myli się w STRONĘ fałszywej podpowiedzi przy znanych pułapkach, i że nie
 * zgaduje tam, gdzie brakuje wzorca (pusty wynik, bez komunikatu).
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `'/^kokos/'` z wykluczeń mleka oblewa
 * przypadek „mleko kokosowe”; usunięcie `PRZECZENIE` oblewa „masło bez laktozy”.
 */
final class SlownikAlergenowTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function przypadki(): array
    {
        return [
            // --- Podstawy i fleksja ---
            'mąka' => ['2 szklanki mąki', ['gluten']],
            'mąka pszenna' => ['mąka pszenna typ 550', ['gluten']],
            'jajka' => ['3 jajka', ['eggs']],
            'jajek dopełniacz' => ['kilka jajek', ['eggs']],
            'żółtko' => ['2 żółtka', ['eggs']],
            'śmietanką' => ['zalej śmietanką', ['milk']],
            'mleko' => ['szklanka mleka', ['milk']],
            'ser' => ['200 g sera żółtego', ['milk']],
            'serem' => ['zapiecz z serem', ['milk']],
            'masło' => ['łyżka masła', ['milk']],
            'maślanka' => ['kubek maślanki', ['milk']],
            'twaróg' => ['250 g twarogu', ['milk']],
            'łosoś' => ['filet z łososia', ['fish']],
            'krewetki' => ['10 krewetek', ['crustaceans']],
            'małże' => ['500 g małży', ['molluscs']],
            'tofu' => ['kostka tofu', ['soy']],
            'sezam' => ['łyżka sezamu', ['sesame']],
            'tahini' => ['pasta tahini', ['sesame']],
            'gorczyca' => ['łyżeczka gorczycy', ['mustard']],
            'musztarda' => ['łyżka musztardy', ['mustard']],
            'łubin' => ['mąka z łubinu', ['lupin', 'gluten']],
            'migdały' => ['garść migdałów', ['nuts']],
            'orzechy włoskie' => ['orzechy włoskie', ['nuts']],
            'orzeszki' => ['orzeszki solone', ['peanuts']],
            'seler' => ['kawałek selera', ['celery']],
            'wino' => ['pół szklanki czerwonego wina', ['sulphites']],

            // --- Trudne nazwy: nie to, co sugeruje pierwsze słowo ---
            'mleko kokosowe' => ['puszka mleka kokosowego', []],
            'mleko sojowe' => ['szklanka mleka sojowego', ['soy']],
            'mleko migdałowe' => ['mleko migdałowe', ['nuts']],
            'mleko ryżowe' => ['mleko ryżowe', []],
            'masło orzechowe' => ['2 łyżki masła orzechowego', ['peanuts']],
            'masło kakaowe' => ['masło kakaowe', []],
            'orzech muszkatołowy' => ['szczypta orzecha muszkatołowego', []],
            'gałka muszkatołowa' => ['gałka muszkatołowa', []],
            'orzech kokosowy' => ['orzech kokosowy', []],
            'mąka ryżowa' => ['mąka ryżowa', []],
            'mąka kukurydziana' => ['łyżka mąki kukurydzianej', []],
            'kasza gryczana' => ['kasza gryczana', []],
            'kasza jaglana' => ['kasza jaglana', []],
            'kasza manna' => ['3 łyżki kaszy manny', ['gluten']],
            'makaron ryżowy' => ['makaron ryżowy', []],
            'makaron jajeczny' => ['makaron jajeczny', ['gluten', 'eggs']],
            'olej sezamowy' => ['łyżka oleju sezamowego', ['sesame']],
            'sól selerowa' => ['sól selerowa', ['celery']],
            'ocet winny' => ['łyżka octu winnego', ['sulphites']],
            'ocet jabłkowy' => ['łyżka octu jabłkowego', []],
            'sos sojowy' => ['3 łyżki sosu sojowego', ['gluten', 'soy']],
            'sos sojowy bezglutenowy' => ['sos sojowy bezglutenowy', ['soy']],
            'winogrona to nie wino' => ['garść winogron', []],
            'ser nie seler' => ['ser feta', ['milk']],
            'maka bezglutenowa' => ['mąka bezglutenowa', []],
            'mleko owsiane' => ['mleko owsiane', ['gluten']],
            'śmietanka kokosowa' => ['śmietanka kokosowa', []],
            'jogurt sojowy' => ['jogurt sojowy', ['soy']],

            // --- „Bez” i puste ---
            'bez mleka' => ['herbata bez mleka', []],
            'masło bez laktozy to nadal masło' => ['masło bez laktozy', ['milk']],
            'sól' => ['sól do smaku', []],
            'pusty' => ['', []],
            'sama liczba' => ['2', []],
        ];
    }

    /** @param  list<string>  $oczekiwane */
    #[DataProvider('przypadki')]
    public function test_podpowiedz_dla_nazwy(string $skladnik, array $oczekiwane): void
    {
        $kody = array_keys(SlownikAlergenow::podpowiedzi([$skladnik]));
        sort($kody);
        sort($oczekiwane);

        $this->assertSame($oczekiwane, $kody, "Składnik „{$skladnik}”: zła podpowiedź słownika.");
    }

    public function test_wynik_niesie_fragment_skladnika_i_zachowuje_kolejnosc_slownika(): void
    {
        $wynik = SlownikAlergenow::podpowiedzi(['szklanka mleka', '2 jajka', 'mąka pszenna', 'mleko w proszku']);

        $this->assertSame(['gluten', 'eggs', 'milk'], array_keys($wynik));
        $this->assertSame(['szklanka mleka', 'mleko w proszku'], $wynik['milk']);
        $this->assertSame(['2 jajka'], $wynik['eggs']);
    }

    public function test_brak_podpowiedzi_to_pusta_tablica_a_nie_komunikat(): void
    {
        $this->assertSame([], SlownikAlergenow::podpowiedzi([]));
        $this->assertSame([], SlownikAlergenow::podpowiedzi(['woda', 'sól', 'pieprz']));
    }

    public function test_wynik_zawiera_tylko_kody_ze_slownika_alergenow(): void
    {
        $wynik = SlownikAlergenow::podpowiedzi(['mąka', 'jajka', 'mleko', 'ryba', 'krewetki', 'tofu', 'migdały', 'seler']);

        foreach (array_keys($wynik) as $kod) {
            $this->assertContains($kod, Alergen::kody());
        }
    }
}
