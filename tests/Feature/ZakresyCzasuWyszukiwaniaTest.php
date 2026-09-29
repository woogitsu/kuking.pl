<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jawne progi czasu w wyszukiwarce przepisów (issue #1997).
 *
 * `?czas=15|30|60`, brak = bez limitu. Czas całkowity liczy ta sama reguła co
 * strona przepisu (`Recipe::totalMinutes()` / `scopeGotoweWCiagu()`): brak
 * któregoś czasu albo suma 0 to „nie wiem", a nie zero — taki przepis nie
 * trafia do żadnego progu. Stary adres `sekcja=szybkie` znaczy `czas=30`.
 */
class ZakresyCzasuWyszukiwaniaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{int, int, bool}> próg, czas przepisu, czy w wynikach */
    public static function granice(): array
    {
        return [
            '15 mieści się w 15' => [15, 15, true],
            '16 nie mieści się w 15' => [15, 16, false],
            '30 mieści się w 30' => [30, 30, true],
            '31 nie mieści się w 30' => [30, 31, false],
            '60 mieści się w 60' => [60, 60, true],
            '61 nie mieści się w 60' => [60, 61, false],
        ];
    }

    #[DataProvider('granice')]
    public function test_granica_kazdego_progu(int $prog, int $minuty, bool $wWynikach): void
    {
        $autor = $this->user('autor_zup');
        $graniczny = $this->przepis($autor, 'Zupa graniczna', 5, $minuty - 5);
        // Kontrola dodatnia w tym samym przebiegu: przepis na pewno w progu.
        $krotki = $this->przepis($autor, 'Zupa krótka', 5, 5);

        $odp = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => (string) $prog]);

        $this->assertSame($prog, $odp->viewData('maksMinut'));
        $klucze = $odp->viewData('recipes')->modelKeys();
        $this->assertContains($krotki->id, $klucze);
        $wWynikach
            ? $this->assertContains($graniczny->id, $klucze, "Przepis na {$minuty} min wypadł z progu {$prog}.")
            : $this->assertNotContains($graniczny->id, $klucze, "Przepis na {$minuty} min wszedł do progu {$prog}.");
    }

    /** Brak czasu to „nie wiem", nie zero — nie trafia do ŻADNEGO progu. */
    public function test_brakujacy_czas_nie_jest_zerem_w_zadnym_progu(): void
    {
        $autor = $this->user('autor_zup');
        $bezCzasow = [
            $this->przepis($autor, 'Zupa bez czasów', null, null)->id,
            $this->przepis($autor, 'Zupa bez gotowania', 10, null)->id,
            $this->przepis($autor, 'Zupa zero plus zero', 0, 0)->id,
        ];
        $znana = $this->przepis($autor, 'Zupa z jawnym zerem', 10, 0);

        foreach ([15, 30, 60] as $prog) {
            $klucze = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => (string) $prog])
                ->viewData('recipes')->modelKeys();
            $this->assertSame([], array_values(array_intersect($bezCzasow, $klucze)), "Próg {$prog} wziął przepis bez czasu.");
            $this->assertContains($znana->id, $klucze, "Jawne 0 to znany czas — próg {$prog} go zgubił.");
        }
    }

    public function test_bez_limitu_pokazuje_wszystko_i_to_jest_wybrany_chip(): void
    {
        $autor = $this->user('autor_zup');
        $wszystkie = [
            $this->przepis($autor, 'Zupa krótka', 5, 5)->id,
            $this->przepis($autor, 'Zupa całodniowa', 20, 300)->id,
            $this->przepis($autor, 'Zupa bez czasów', null, null)->id,
        ];

        $odp = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy']);

        $this->assertNull($odp->viewData('maksMinut'));
        $this->assertEqualsCanonicalizing($wszystkie, $odp->viewData('recipes')->modelKeys());
        $this->assertSame(['Bez limitu czasu'], $this->wybraneChipy($odp, 'czas-przepisu-etykieta'));
        $odp->assertDontSee('Adres ma czas, którego nie rozpoznajemy');
    }

    /** @return array<string, array{string}> */
    public static function nieprawidlowe(): array
    {
        return [
            'próg spoza listy' => ['czas=45'],
            'zero' => ['czas=0'],
            'ujemny' => ['czas=-15'],
            'tekst' => ['czas=abc'],
            'ułamek' => ['czas=15.0'],
            'tablica' => ['czas%5B%5D=15'],
            'olbrzymia liczba' => ['czas=99999999999999999999999'],
        ];
    }

    /**
     * Nieznana wartość: bez 500, bez cichego filtra „najbliższym progiem",
     * za to z jawną informacją i wybranym „Bez limitu czasu".
     */
    #[DataProvider('nieprawidlowe')]
    public function test_nieprawidlowa_wartosc_nie_filtruje_po_cichu(string $czas): void
    {
        $autor = $this->user('autor_zup');
        $wszystkie = [
            $this->przepis($autor, 'Zupa krótka', 5, 5)->id,
            $this->przepis($autor, 'Zupa na 50 minut', 20, 30)->id,
            $this->przepis($autor, 'Zupa całodniowa', 20, 300)->id,
        ];

        $odp = $this->get(route('search').'?q=zupa&sekcja=przepisy&'.$czas)->assertOk();

        $this->assertNull($odp->viewData('maksMinut'));
        $this->assertTrue($odp->viewData('czasNieznany'));
        $this->assertEqualsCanonicalizing($wszystkie, $odp->viewData('recipes')->modelKeys());
        $odp->assertSee('Adres ma czas, którego nie rozpoznajemy. Pokazujemy przepisy bez limitu czasu.');
        $this->assertSame(['Bez limitu czasu'], $this->wybraneChipy($odp, 'czas-przepisu-etykieta'));
        // Nieznana wartość nie wędruje dalej w odnośnikach.
        $this->assertStringNotContainsString('czas=', implode(' ', $this->linki($odp, 'Przepisy')));
    }

    public function test_wybor_jest_widoczny_i_odtwarzany_z_adresu(): void
    {
        $this->przepis($this->user('autor_zup'), 'Zupa krótka', 5, 5);

        $odp = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => '15']);

        $this->assertSame(['Do 15 minut'], $this->wybraneChipy($odp, 'czas-przepisu-etykieta'));
        $this->assertSame(['Przepisy'], $this->wybraneChipy($odp, null));
        $odp->assertSee('<h2 class="mt-6">Do 15 minut</h2>', false);
        // Nowa fraza wysłana formularzem zachowuje wybrany próg.
        $odp->assertSee('<input type="hidden" name="czas" value="15">', false);
        // Przejście do „Do 20 zł" zachowuje czas, do „Ludzie" i „Wszystko" — nie.
        $this->assertStringContainsString('czas=15', $this->jedenLink($odp, 'Do 20 zł'));
        $this->assertStringNotContainsString('czas=', $this->jedenLink($odp, 'Ludzie'));
        $this->assertStringNotContainsString('czas=', $this->jedenLink($odp, 'Wszystko'));
    }

    /** Próg wybrany na „Wszystko" przechodzi na „Przepisy" — ludzie nie mają czasu. */
    public function test_prog_na_wszystko_zaweza_do_przepisow_a_na_ludziach_nie_istnieje(): void
    {
        $this->przepis($this->user('autor_zup'), 'Zupa krótka', 5, 5);
        $this->user('zupa_osoba', ['display_name' => 'Zupa Osoba']);

        $wszystko = $this->szukaj(['q' => 'zupa']);
        $link = $this->jedenLink($wszystko, 'Do 30 minut');
        $this->assertStringContainsString('sekcja=przepisy', $link);
        $this->assertStringContainsString('czas=30', $link);

        $zCzasem = $this->szukaj(['q' => 'zupa', 'czas' => '30']);
        $this->assertSame('przepisy', $zCzasem->viewData('section'));
        $this->assertFalse($zCzasem->viewData('szukaLudzi'));

        $ludzie = $this->szukaj(['q' => 'zupa', 'sekcja' => 'ludzie', 'czas' => '30']);
        $this->assertNull($ludzie->viewData('maksMinut'));
        $ludzie->assertDontSee('Ile masz czasu?')->assertSee('Zupa Osoba');
    }

    public function test_pokaz_wiecej_i_powrot_niosa_prog(): void
    {
        $autor = $this->user('autor_zup');
        $wProgu = Recipe::factory()->count(21)->create([
            'author_id' => $autor->id, 'title' => 'Zupa szybka',
            'prep_minutes' => 5, 'cook_minutes' => 10, 'visibility' => 'public',
        ])->modelKeys();
        $this->przepis($autor, 'Zupa długa', 5, 11);

        $pierwsza = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => '15']);
        $this->assertCount(20, $pierwsza->viewData('recipes'));
        $dalej = $this->jedenLink($pierwsza, 'Pokaż więcej przepisów');
        $this->assertStringContainsString('czas=15', $dalej);

        $druga = $this->get($dalej)->assertOk();
        $this->assertSame(15, $druga->viewData('maksMinut'));
        $this->assertEqualsCanonicalizing($wProgu, $druga->viewData('recipes')->modelKeys());
        $this->assertSame(['Do 15 minut'], $this->wybraneChipy($druga, 'czas-przepisu-etykieta'));

        // Okno przesunięte (od_przepisu) i powrót do początku też go niosą.
        $duze = $this->szukaj(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => '15', 'ile' => '200', 'od_przepisu' => '200']);
        $this->assertStringContainsString('czas=15', $this->jedenLink($duze, 'Wróć do początku przepisów'));
    }

    /** Filtr czasu nie otwiera niczego, czego wyszukiwarka i tak nie pokazuje. */
    public function test_widocznosc_prywatne_i_blokady_bez_zmian(): void
    {
        $widz = $this->user('szukajaca');
        $autor = $this->user('autor_zup');
        $publiczny = $this->przepis($autor, 'Zupa publiczna', 5, 5);
        $prywatny = Recipe::factory()->create([
            'author_id' => $autor->id, 'title' => 'Zupa prywatna',
            'prep_minutes' => 5, 'cook_minutes' => 5, 'visibility' => 'private',
        ]);
        $zablokowane = [];
        foreach ([true, false] as $wychodzaca) {
            $inny = $this->user($wychodzaca ? 'blokowany_autor' : 'blokujacy_autor');
            Block::create([
                'blocker_id' => $wychodzaca ? $widz->id : $inny->id,
                'blocked_id' => $wychodzaca ? $inny->id : $widz->id,
            ]);
            $zablokowane[] = $this->przepis($inny, 'Zupa zablokowana', 5, 5)->id;
        }

        foreach ([null, '15', '30', '60'] as $czas) {
            $klucze = $this->actingAs($widz)
                ->get(route('search', array_filter(['q' => 'zupa', 'sekcja' => 'przepisy', 'czas' => $czas])))
                ->assertOk()->viewData('recipes')->modelKeys();
            $this->assertSame([$publiczny->id], $klucze, 'Próg '.($czas ?? 'brak').' zmienił widoczność.');
            $this->assertNotContains($prywatny->id, $klucze);
            $this->assertSame([], array_values(array_intersect($zablokowane, $klucze)));
        }
    }

    /** Stary adres sprzed #1997 działa: `sekcja=szybkie` = „Przepisy" + „Do 30 minut". */
    public function test_stary_adres_szybkie_to_prog_30(): void
    {
        $autor = $this->user('autor_zup');
        $w30 = $this->przepis($autor, 'Zupa na 30', 10, 20);
        $this->przepis($autor, 'Zupa na 31', 11, 20);
        $this->przepis($autor, 'Zupa bez czasów', null, null);

        $odp = $this->szukaj(['q' => 'zupa', 'sekcja' => 'szybkie']);

        $this->assertSame('przepisy', $odp->viewData('section'));
        $this->assertSame(30, $odp->viewData('maksMinut'));
        $this->assertSame([$w30->id], $odp->viewData('recipes')->modelKeys());
        $this->assertSame(['Do 30 minut'], $this->wybraneChipy($odp, 'czas-przepisu-etykieta'));
        $odp->assertDontSee('Adres ma czas, którego nie rozpoznajemy');
        // Jawny próg ma pierwszeństwo przed starym aliasem.
        $this->assertSame(15, $this->szukaj(['q' => 'zupa', 'sekcja' => 'szybkie', 'czas' => '15'])->viewData('maksMinut'));
    }

    public function test_pusty_stan_mowi_jaki_czas_i_co_dalej(): void
    {
        $odp = $this->szukaj(['q' => 'kartacze', 'sekcja' => 'przepisy', 'czas' => '15']);

        $odp->assertSee('Nie ma przepisu do „kartacze”, który zmieściłby się w kwadrans.', false)
            ->assertSee('Spróbuj dłuższego czasu albo „Bez limitu czasu”.', false);
        $this->szukaj(['q' => 'kartacze', 'sekcja' => 'przepisy', 'czas' => '60'])
            ->assertSee('zmieściłby się w godzinę.', false)
            ->assertDontSee('Spróbuj dłuższego czasu');
    }

    /** @param array<string, string> $parametry */
    private function szukaj(array $parametry): TestResponse
    {
        return $this->get(route('search', $parametry))->assertOk();
    }

    private function przepis(User $autor, string $tytul, ?int $przygotowanie, ?int $gotowanie): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->id,
            'title' => $tytul,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'prep_minutes' => $przygotowanie,
            'cook_minutes' => $gotowanie,
        ]);
    }

    private function xpath(TestResponse $odp): DOMXPath
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$odp->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);
        }

        return new DOMXPath($dom);
    }

    /**
     * Teksty chipów z `aria-current="page"` w jednym wierszu: zakresów
     * (`null`) albo nawigacji opisanej etykietą o danym id.
     *
     * @return list<string>
     */
    private function wybraneChipy(TestResponse $odp, ?string $etykieta): array
    {
        $nav = $etykieta === null
            ? '//main//nav[@aria-label="Co przeszukujemy"]'
            : '//main//nav[@aria-labelledby="'.$etykieta.'"]';
        $wynik = [];
        foreach ($this->xpath($odp)->query($nav.'//a[@aria-current="page"]') ?: [] as $a) {
            $wynik[] = trim($a->textContent);
        }

        return $wynik;
    }

    /** @return list<string> */
    private function linki(TestResponse $odp, string $tekst): array
    {
        $wynik = [];
        foreach ($this->xpath($odp)->query('//main//a') ?: [] as $a) {
            if ($a instanceof DOMElement && trim($a->textContent) === $tekst) {
                $wynik[] = $a->getAttribute('href');
            }
        }

        return $wynik;
    }

    private function jedenLink(TestResponse $odp, string $tekst): string
    {
        $linki = $this->linki($odp, $tekst);
        $this->assertCount(1, $linki, "Oczekiwany dokładnie jeden odnośnik „{$tekst}”.");

        return $linki[0];
    }
}
