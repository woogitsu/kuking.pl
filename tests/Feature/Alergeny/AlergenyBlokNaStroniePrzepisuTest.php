<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Moderation\PriorytetSprawy;
use App\Domain\Recipes\Alergeny\Alergen;
use App\Domain\Recipes\Alergeny\OznaczAlergenyPrzepisu;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Blok „Alergeny” na stronie przepisu, słownictwo i powód zgłoszenia (#1902, D-333).
 *
 * KONTROLA UJEMNA (ręcznie): (1) zamiana „nie sprawdzono” na pusty tekst w `alergeny/blok.blade.php`
 * oblewa `test_przepis_bez_oznaczenia_mowi_nie_sprawdzono`; (2) pokazanie listy przy `needs_review`
 * (warunek `alergenyZdeklarowane()` zastąpiony czymkolwiek szerszym) oblewa
 * `test_stan_do_przegladu_nie_pokazuje_listy_czytelnikowi`; (3) dopisanie słowa „bezpieczne” do którejkolwiek
 * etykiety oblewa `test_zadna_strona_funkcji_nie_zawiera_zakazanych_slow`.
 */
final class AlergenyBlokNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** Słowa i zwroty, których interfejs tej funkcji nie używa nigdy (decyzja właściciela z 30.09.2026). */
    private const ZAKAZANE = ['bezpieczn', 'dla alergików', 'bez alergenów', 'bezglutenow', 'wolny od', 'wolne od', 'gwarancj', 'zdrowe', 'nie wykryto', 'wykryto'];

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.alergeny.wlaczone' => true]);
        $this->autor = $this->user('autorka_bloku');
    }

    /** @param  list<string>  $kody */
    private function przepis(string $stan, array $kody = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => 'Naleśniki bloku']);
        $przepis->ingredients()->create(['ingredient_text' => '2 jajka', 'position' => 0]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Usmaż.']);

        if ($stan !== 'unchecked') {
            $przepis->forceFill(['allergen_status' => $stan, 'allergens' => $kody, 'allergens_declared_at' => now()])->save();
        }

        return $przepis->refresh();
    }

    /** Sam blok — wycięty po identyfikatorze, żeby nie łapać słów z reszty strony (pułapka 1). */
    private function blok(string $html): string
    {
        $this->assertSame(1, preg_match('/<section class="notice" id="alergeny".*?<\/section>/s', $html, $m), 'Na stronie przepisu nie ma bloku alergenów.');

        return preg_replace('/\s+/', ' ', strip_tags($m[0]));
    }

    private function stronaPrzepisu(Recipe $przepis): string
    {
        return (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
    }

    public function test_zdeklarowane_alergeny_sa_wymienione_z_zastrzezeniem_o_etykietach(): void
    {
        $tekst = $this->blok($this->stronaPrzepisu($this->przepis('declared', ['milk', 'gluten', 'eggs'])));

        $this->assertStringContainsString('Alergeny według autora: gluten, jaja, mleko.', $tekst);
        $this->assertStringContainsString('To zaznaczenie autora, nie badanie.', $tekst);
        $this->assertStringContainsString('przeczytaj etykiety', $tekst);
    }

    public function test_zdeklarowana_pusta_lista_nie_mowi_ze_przepis_jest_czysty(): void
    {
        $tekst = $this->blok($this->stronaPrzepisu($this->przepis('declared', [])));

        $this->assertStringContainsString('Autor nie zaznaczył żadnego z 14 alergenów.', $tekst);
        $this->assertStringContainsString('nie zapewnienie, że ich tam nie ma', $tekst);
        $this->assertStringContainsString('Przeczytaj etykiety gotowych produktów.', $tekst);
    }

    public function test_przepis_bez_oznaczenia_mowi_nie_sprawdzono(): void
    {
        $tekst = $this->blok($this->stronaPrzepisu($this->przepis('unchecked')));

        $this->assertStringContainsString('Alergeny: nie sprawdzono.', $tekst);
        $this->assertStringContainsString('nie wiemy, czy nadaje się dla osoby z alergią', $tekst);
    }

    public function test_stan_do_przegladu_nie_pokazuje_listy_czytelnikowi(): void
    {
        $przepis = $this->przepis('needs_review', ['milk']);

        $tekst = $this->blok($this->stronaPrzepisu($przepis));

        $this->assertStringContainsString('Alergeny: nie sprawdzono.', $tekst);
        $this->assertStringNotContainsString('mleko', $tekst, 'Lista z nieaktualnej deklaracji wyszła do czytelnika.');
        $this->assertStringNotContainsString('Zmieniono składniki', $tekst, 'Komunikat dla autora widzi też gość.');
    }

    public function test_autor_przy_stanie_do_przegladu_widzi_komunikat_i_przycisk_do_formularza(): void
    {
        $przepis = $this->przepis('needs_review', ['milk']);

        $html = (string) $this->actingAs($this->autor)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $tekst = $this->blok($html);

        $this->assertStringContainsString('Zmieniono składniki po zaznaczeniu alergenów. Sprawdź listę jeszcze raz.', $tekst);
        $this->assertStringContainsString('Sprawdź alergeny', $tekst);
        $this->assertStringContainsString(route('recipes.edit', $przepis->slug).'#f-alergeny', $html);
    }

    public function test_obca_osoba_nie_widzi_przycisku_edycji_alergenow(): void
    {
        $przepis = $this->przepis('unchecked');

        $tekst = $this->blok((string) $this->actingAs($this->user('czytelnik_bloku'))->get(route('recipes.show', $przepis->slug))->assertOk()->getContent());

        $this->assertStringNotContainsString('Oznacz alergeny', $tekst);
    }

    public function test_blok_ma_tekst_w_pelnych_zdaniach_bez_koloru_jako_jedynego_sygnalu(): void
    {
        foreach (['unchecked' => [], 'declared' => ['milk']] as $stan => $kody) {
            $html = $this->stronaPrzepisu($this->przepis($stan, $kody));

            preg_match('/<section class="notice" id="alergeny".*?<\/section>/s', $html, $m);
            $this->assertStringContainsString('<h3 id="alergeny-naglowek"', $m[0]);
            $this->assertStringContainsString('aria-labelledby="alergeny-naglowek"', $m[0]);
            $this->assertStringNotContainsString('<svg', $m[0], 'Blok nie polega na ikonie.');
            $this->assertStringNotContainsString('title="', $m[0], 'Blok nie chowa treści w podpowiedzi przy najechaniu.');
        }
    }

    public function test_przy_wylaczonej_fladze_nie_ma_bloku(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);

        $html = $this->stronaPrzepisu($this->przepis('declared', ['milk']));

        $this->assertStringNotContainsString('id="alergeny"', $html);
        $this->assertStringNotContainsString('Alergeny według autora', $html);
    }

    public function test_alergeny_nie_trafiaja_do_danych_strukturalnych_json_ld(): void
    {
        // JSON-LD `Recipe` emituje dopiero przepis ze zdjęciem głównym (#1005).
        $przepis = $this->przepis('declared', ['milk', 'gluten']);
        $przepis->forceFill(['hero_media_id' => Media::factory()->create(['owner_id' => $this->autor->getKey()])->getKey()])->save();
        $html = $this->stronaPrzepisu($przepis);

        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], 'Strona przepisu nie ma JSON-LD — test niczego by nie sprawdzał.');
        foreach ($m[1] as $json) {
            $this->assertDoesNotMatchRegularExpression('/allerg|alerg|suitableForDiet|gluten|milk|mleko/i', $json);
        }
    }

    public function test_zadna_strona_funkcji_nie_zawiera_zakazanych_slow(): void
    {
        $this->przepis('declared', ['milk']);
        $przepisNiesprawdzony = $this->przepis('unchecked');
        $zDoPrzegladu = $this->przepis('needs_review', ['eggs']);

        $strony = [
            'przepis zdeklarowany' => $this->blok($this->stronaPrzepisu(Recipe::query()->where('allergen_status', 'declared')->firstOrFail())),
            'przepis niesprawdzony' => $this->blok($this->stronaPrzepisu($przepisNiesprawdzony)),
            'wyszukiwarka z filtrem' => (string) $this->get(route('search', ['q' => 'nalesniki', 'bez' => ['milk']]))->getContent(),
            'wyszukiwarka pusty wynik' => (string) $this->get(route('search', ['q' => 'cosnieistniejacego', 'bez' => ['milk', 'gluten']]))->getContent(),
            'wyszukiwarka, zakres przepisów z filtrem' => (string) $this->get(route('search', ['q' => 'nalesniki', 'sekcja' => 'przepisy', 'bez' => ['eggs']]))->getContent(),
            'formularz szczegółów' => (string) $this->actingAs($this->autor)->get(route('recipes.edit', $zDoPrzegladu->slug))->getContent(),
            'kreator, krok 2' => Livewire::actingAs($this->autor)->test('recipe-wizard', ['recipeId' => $zDoPrzegladu->getKey()])->set('step', 2)->html(),
        ];

        // Komponent pól renderowany wprost, w każdym stanie i obu trybach (kreator
        // / zwykły formularz), z podpowiedziami i przyciskiem ponownego potwierdzenia.
        foreach (['unchecked', 'declared', 'needs_review'] as $stan) {
            foreach ([false, true] as $wire) {
                $strony["pola.blade.php, stan {$stan}, wire ".($wire ? 'tak' : 'nie')] = Blade::render(
                    '<x-alergeny.pola :wire="$wire" :wybrane="$wybrane" :potwierdzone="$potwierdzone" :stan="$stan" :podpowiedzi="$podpowiedzi" :przycisk-przegladu="$przycisk" />',
                    [
                        'wire' => $wire,
                        'wybrane' => ['milk', 'gluten'],
                        'potwierdzone' => $stan === 'declared',
                        'stan' => $stan,
                        'podpowiedzi' => ['gluten' => ['mąka pszenna'], 'milk' => ['mleko']],
                        'przycisk' => $stan === 'needs_review',
                        'errors' => new ViewErrorBag,
                    ],
                );
            }
        }

        // Formularza zgłoszenia nie skanujemy w całości: ma starszy powód „Niebezpieczna porada kulinarna”,
        // niezwiązany z tą funkcją. Nasz powód sprawdza `test_etykiety_alergenow_i_komunikaty_akcji_nie_zawieraja_zakazanych_slow`.
        $this->assertCount(13, $strony);

        // Kontrola dodatnia skanu: strony wyszukiwarki NAPRAWDĘ mają filtr, a pola
        // NAPRAWDĘ się wyrenderowały — inaczej skan przechodziłby na pustych stronach.
        foreach (['wyszukiwarka z filtrem', 'wyszukiwarka pusty wynik', 'wyszukiwarka, zakres przepisów z filtrem'] as $klucz) {
            $this->assertStringContainsString('id="filtr-alergenow"', $strony[$klucz], "[$klucz] Brak filtra alergenów — skan niczego by nie sprawdził.");
        }
        foreach ($strony as $nazwa => $html) {
            if (str_starts_with($nazwa, 'pola.blade.php')) {
                $this->assertStringContainsString('id="f-alergeny"', $html, "[$nazwa] Komponent pól nie wyrenderował się.");
            }
        }

        foreach ($strony as $nazwa => $html) {
            $tekst = mb_strtolower(html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html)), ENT_QUOTES));
            foreach (self::ZAKAZANE as $slowo) {
                $this->assertStringNotContainsString($slowo, $tekst, "[$nazwa] Ekran „{$nazwa}” używa słowa „{$slowo}”, którego ta funkcja nie używa nigdy.");
            }
        }
    }

    public function test_etykiety_alergenow_i_komunikaty_akcji_nie_zawieraja_zakazanych_slow(): void
    {
        $teksty = [
            OznaczAlergenyPrzepisu::KOMUNIKAT_POTWIERDZ,
            Report::REASONS_PRZEPIS['allergen_label'],
        ];
        foreach (Alergen::cases() as $alergen) {
            $teksty[] = $alergen->etykieta();
            $teksty[] = $alergen->nazwa();
        }

        $this->assertGreaterThan(15, count($teksty));
        foreach ($teksty as $tekst) {
            foreach (self::ZAKAZANE as $slowo) {
                $this->assertStringNotContainsString($slowo, mb_strtolower($tekst), "„{$tekst}” zawiera „{$slowo}”.");
            }
        }
    }

    public function test_formularz_zgloszenia_przepisu_ma_powod_bledne_oznaczenie_alergenow_tylko_przy_fladze(): void
    {
        $przepis = $this->przepis('declared', ['milk']);
        $zglaszajaca = $this->user('zglaszajaca');

        $html = (string) $this->actingAs($zglaszajaca)->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))->assertOk()->getContent();
        $this->assertStringContainsString('value="allergen_label"', $html);
        $this->assertStringContainsString('Błędne oznaczenie alergenów', $html);

        // Powód jest tylko dla przepisów: formularz zgłoszenia wpisu go nie ma.
        $this->assertArrayNotHasKey('allergen_label', Report::powodyDla('post'));
        $this->assertArrayNotHasKey('allergen_label', Report::powodyDla('comment'));

        // I tylko przy włączonej fladze.
        config(['kuking.alergeny.wlaczone' => false]);
        $this->assertArrayNotHasKey('allergen_label', Report::powodyDla('recipe'));
        $html = (string) $this->actingAs($zglaszajaca)->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))->getContent();
        $this->assertStringNotContainsString('allergen_label', $html);
    }

    public function test_zgloszenie_z_powodem_alergenow_trafia_do_kolejki_jako_pilne_na_dzis(): void
    {
        $przepis = $this->przepis('declared', ['milk']);
        $zglaszajaca = $this->user('zglaszajaca_druga');

        $this->actingAs($zglaszajaca)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $przepis->slug]), ['reason' => 'allergen_label', 'details' => 'Jest masło, a mleka nie zaznaczono.'])
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::query()->sole();
        $this->assertSame('allergen_label', $zgloszenie->reason);
        $this->assertSame('Błędne oznaczenie alergenów', $zgloszenie->reasonLabel());
        $this->assertSame(PriorytetSprawy::P1, PriorytetSprawy::dla($zgloszenie));
    }

    public function test_powodu_alergenow_nie_da_sie_uzyc_na_innym_celu_ani_przy_wylaczonej_fladze(): void
    {
        $przepis = $this->przepis('declared', ['milk']);
        $zglaszajaca = $this->user('zglaszajaca_trzecia');

        // Przy wyłączonej fladze powód jest nieznany.
        config(['kuking.alergeny.wlaczone' => false]);
        $this->actingAs($zglaszajaca)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $przepis->slug]), ['reason' => 'allergen_label'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, Report::query()->count());
    }
}
