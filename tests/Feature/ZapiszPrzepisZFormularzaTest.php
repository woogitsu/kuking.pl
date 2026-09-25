<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\ZapiszPrzepisZFormularza;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Akcja wyjęta z `RecipeController::store()` / `update()` (issue #970, krok 1).
 *
 * Trasy dalej pokrywają istniejące testy funkcjonalne; tu stoi to, co akcja
 * obiecuje sama z siebie, bez żądania HTTP: zdjęcia z formularza zamienia
 * na identyfikatory `media`, istniejących zdjęć i pochodzenia nie gubi,
 * a błąd zdjęcia kroku adresuje numerem WIERSZA FORMULARZA.
 */
class ZapiszPrzepisZFormularzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_nowy_przepis_bez_zdjec_idzie_do_publishrecipe(): void
    {
        $autor = $this->user('zapis970');

        $przepis = app(ZapiszPrzepisZFormularza::class)->handle(
            author: $autor,
            dane: $this->dane('Pierogi ruskie 970'),
            zdjecieGlowne: null,
            skan: null,
            zdjeciaKrokow: [],
            publish: true,
            ip: '127.0.0.1',
        );

        $this->assertTrue($przepis->wasRecentlyCreated);
        $this->assertTrue($przepis->isPublished());
        $this->assertSame($autor->getKey(), $przepis->author_id);
        $this->assertNull($przepis->hero_media_id);
        $this->assertNull($przepis->source_scan_media_id);
        $this->assertSame(['Ulep pierogi.'], $przepis->steps()->pluck('instruction')->all());
    }

    public function test_edycja_bez_plikow_zostawia_zdjecia_i_pochodzenie(): void
    {
        $autor = $this->user('edycja970');
        $glowne = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $kartka = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis = Recipe::factory()->draft()->create([
            'author_id' => $autor->getKey(),
            'hero_media_id' => $glowne->getKey(),
            'source_scan_media_id' => $kartka->getKey(),
            'source_type' => Recipe::SOURCE_FAMILY,
        ]);

        $zapisany = app(ZapiszPrzepisZFormularza::class)->handle(
            author: $autor,
            dane: $this->dane('Pierogi babci, poprawione'),
            zdjecieGlowne: null,
            skan: null,
            zdjeciaKrokow: [],
            publish: false,
            ip: null,
            existing: $przepis,
        );

        $zapisany->refresh();
        $this->assertSame($przepis->getKey(), $zapisany->getKey());
        $this->assertSame($glowne->getKey(), $zapisany->hero_media_id);
        $this->assertSame($kartka->getKey(), $zapisany->source_scan_media_id);
        $this->assertSame(Recipe::SOURCE_FAMILY, $zapisany->source_type);
    }

    public function test_zdjecie_trafia_tylko_do_kroku_z_trescia(): void
    {
        $autor = $this->user('kroki970');
        $dane = $this->dane('Kotlet 970');
        $dane['steps'] = [
            3 => $this->krok('Rozbij mięso.'),
            7 => $this->krok('   '),
        ];

        $przepis = app(ZapiszPrzepisZFormularza::class)->handle(
            author: $autor,
            dane: $dane,
            zdjecieGlowne: null,
            skan: null,
            zdjeciaKrokow: [
                3 => UploadedFile::fake()->image('rozbijanie.jpg', 400, 300),
                7 => UploadedFile::fake()->image('pusty-wiersz.jpg', 400, 300),
            ],
            publish: true,
            ip: null,
        );

        $kroki = $przepis->steps()->get();
        $this->assertCount(1, $kroki);
        $this->assertNotNull($kroki->first()->media_id);
        // Kontrola ujemna: pusty wiersz nie zostawił osieroconego zdjęcia.
        $this->assertSame(1, Media::query()->where('owner_id', $autor->getKey())->count());
    }

    public function test_blad_zdjecia_kroku_trafia_pod_numer_wiersza_formularza(): void
    {
        $autor = $this->user('blad970');
        $dane = $this->dane('Gulasz 970');
        $dane['steps'] = [4 => $this->krok('Pokrój mięso.')];

        try {
            app(ZapiszPrzepisZFormularza::class)->handle(
                author: $autor,
                dane: $dane,
                zdjecieGlowne: null,
                skan: null,
                zdjeciaKrokow: [4 => UploadedFile::fake()->create('pusty.jpg', 0, 'image/jpeg')],
                publish: true,
                ip: null,
            );
            $this->fail('Nieczytelne zdjęcie kroku musi zatrzymać zapis.');
        } catch (ValidationException $e) {
            $this->assertSame(['steps.4.photo'], array_keys($e->errors()));
            $this->assertSame(
                'Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.',
                $e->errors()['steps.4.photo'][0],
            );
        }

        $this->assertSame(0, Recipe::query()->where('author_id', $autor->getKey())->count());
    }

    /**
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: array<array-key, array<string, mixed>>}
     */
    private function dane(string $tytul): array
    {
        return [
            'recipe' => [
                'title' => $tytul,
                'summary' => null,
                'servings' => null,
                'prep_minutes' => null,
                'cook_minutes' => null,
                'difficulty' => null,
                'visibility' => 'public',
                'source_type' => null,
                'source_person' => null,
                'source_note' => null,
                'source_url' => null,
                'family_since_year' => null,
            ],
            'ingredients' => [['text' => 'mąka', 'group_name' => null, 'note' => null, 'no_amount' => false]],
            'steps' => [$this->krok('Ulep pierogi.')],
        ];
    }

    /** @return array<string, mixed> */
    private function krok(string $instrukcja): array
    {
        return ['id' => null, 'instruction' => $instrukcja, 'timer_minutes' => null, 'media_id' => null, 'remove_media' => false];
    }
}
