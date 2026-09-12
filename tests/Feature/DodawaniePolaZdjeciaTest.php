<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Duży obszar wyboru zdjęcia — UI kit v2, etap D (ekran „Dodaj").
 *
 * POMIAR, KTÓRY OTWORZYŁ TĘ ZMIANĘ
 * `docs/design/kit-v2/html/08_mobile_add.html` rysuje wybór zdjęcia jako
 * duży, wyraźnie oznaczony obszar (ikona + „Dodaj zdjęcie" + pomoc) —
 * `PhotoPicker` z `docs/design/DESIGN_SYSTEM.md` §5. Przed tą zmianą trzy
 * miejsca w aplikacji („Dodaj zdjęcie", „Dodaj przepis" bez JavaScriptu
 * i kreator Livewire) miały w tym miejscu goły `<input type="file">`
 * w zwykłym bordowanym polu — bez ikony i bez tytułu.
 *
 * Ten plik NIE sprawdza jeszcze raz mechaniki zapisu minutnika ani zdjęcia
 * kroku — to w całości pokrywa `MinutnikIZdjecieKrokuTest`, który zostaje
 * zielony bez zmian. Sprawdza wyłącznie to, co jest w tej zmianie NOWE:
 * napis wewnątrz obszaru („Dodaj zdjęcie" / „Zmień zdjęcie", zależnie od
 * tego, czy pole ma już zdjęcie) i to, że obszar nie łamie zakazu `style="`
 * z CSP (AGENTS.md, `config/livewire.php` → `csp_safe`).
 */
class DodawaniePolaZdjeciaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_ekran_dodaj_zdjecie_pokazuje_duzy_obszar_wyboru_zdjecia(): void
    {
        $strona = $this->actingAs($this->user('basia'))
            ->get(route('posts.create'))
            ->assertOk();

        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1): „Dodaj
        // zdjęcie" jest też `<title>` tej strony, więc asercja na całej
        // odpowiedzi przechodziła po skasowaniu i nagłówka, i podpisu obszaru
        // wyboru pliku.
        $this->assertStringContainsString(
            'Dodaj zdjęcie',
            $this->trescEkranu((string) $strona->getContent()),
        );

        // KONTROLNA: nadal ten sam, jedyny plikowy input tego formularza —
        // atrybuty się nie zmieniły, zmienił się tylko wygląd wokół niego.
        $strona->assertSee('name="photos[]"', false);
        $strona->assertSee('aria-describedby="f-photos-help"', false);

        // NEGATYWNA: CSP jest wymuszające — żadnego atrybutu `style`.
        $strona->assertDontSee('style="', false);
    }

    public function test_nowy_przepis_bez_javascriptu_pokazuje_dodaj_zdjecie_a_nigdy_zmien(): void
    {
        $strona = $this->actingAs($this->user('basia'))
            ->get(route('recipes.create.simple'))
            ->assertOk();

        $tresc = $strona->getContent();

        // Nowy przepis nie ma jeszcze żadnego zdjęcia — ani gotowego dania,
        // ani kroku — więc obszar wyboru zdjęcia MUSI wszędzie zapraszać do
        // DODANIA, nigdy do ZMIANY czegoś, czego jeszcze nie ma.
        $this->assertSame(0, substr_count($tresc, 'Zmień zdjęcie'));
        $this->assertGreaterThanOrEqual(3, substr_count($tresc, 'Dodaj zdjęcie'));

        $strona->assertDontSee('style="', false);
    }

    public function test_edycja_przepisu_ze_zdjeciem_kroku_pokazuje_zmien_zdjecie_tylko_tam(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZJednymZdjeciemKroku($basia);

        $strona = $this->actingAs($basia)
            ->get(route('recipes.edit', $przepis->slug))
            ->assertOk();

        $tresc = $strona->getContent();

        // POZYTYWNA: krok, który ma już zdjęcie, zaprasza do ZMIANY.
        $this->assertSame(1, substr_count($tresc, 'Zmień zdjęcie'));

        // KONTROLNA: zdjęcie gotowego dania i skan kartki nie mają nic —
        // dla nich obszar dalej mówi „Dodaj zdjęcie", tak jak dla drugiego,
        // pustego kroku.
        $this->assertGreaterThanOrEqual(3, substr_count($tresc, 'Dodaj zdjęcie'));

        // Zachowanie sprzed zmiany zostaje: checkbox odpięcia i podgląd
        // miniatury są dalej na miejscu (MinutnikIZdjecieKrokuTest sprawdza
        // to samo dokładniej — tu tylko kontrola, że nic nie zniknęło).
        $strona->assertSee('Usuń to zdjęcie');
        $strona->assertSee('alt="Zdjęcie przy kroku 1"', false);

        $strona->assertDontSee('style="', false);
    }

    public function test_kreator_livewire_pokazuje_zmien_zdjecie_dopiero_po_wgraniu_zdjecia_kroku(): void
    {
        $basia = $this->user('basia');

        $komponent = Livewire::actingAs($basia)->test('recipe-wizard')
            ->set('title', 'Kotlet schabowy')
            ->set('ingredients.0.text', 'schab')
            ->set('steps.0.instruction', 'Rozbij mięso.')
            // Pole zdjęcia kroku żyje na kroku 3 („Przygotowanie") — na
            // kroku 1 jest tylko zdjęcie gotowego dania.
            ->set('step', 3);

        $komponent->assertSee('Dodaj zdjęcie')->assertDontSee('Zmień zdjęcie');

        $komponent->set('steps.0.photo', UploadedFile::fake()->image('kotlet.jpg', 800, 600));

        // Krok pierwszy ma teraz zdjęcie — jego obszar mówi „Zmień", nie
        // „Dodaj". Zdjęcie gotowego dania dalej go nie ma, więc „Dodaj
        // zdjęcie" zostaje widoczne obok.
        $komponent->assertSee('Zmień zdjęcie')->assertSee('Dodaj zdjęcie');
    }

    private function przepisZJednymZdjeciemKroku(User $autor): Recipe
    {
        $tytul = 'Kotlet z jednym zdjęciem kroku';

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => $tytul,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'schab']],
            'steps' => [
                ['instruction' => 'Rozbij mięso.', 'photo' => UploadedFile::fake()->image('rozbijanie.jpg', 400, 300)],
                ['instruction' => 'Usmaż kotlety.'],
            ],
        ])->assertSessionHasNoErrors();

        $przepis = Recipe::where('title', $tytul)->sole();

        // Zdjęcia pokazujemy wyłącznie w stanie `ready` (AGENTS.md §7) —
        // w teście zadanie w tle nie chodzi, więc doprowadzamy je ręcznie.
        Media::query()
            ->whereIn('id', $przepis->steps()->pluck('media_id')->filter())
            ->update(['status' => Media::STATUS_READY]);

        return $przepis;
    }
}
