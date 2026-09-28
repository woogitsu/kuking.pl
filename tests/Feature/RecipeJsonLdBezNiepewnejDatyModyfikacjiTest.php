<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `dateModified` w JSON-LD przepisu — dopiero z wiarygodnego źródła (#2014).
 *
 * `docs/seo/SEO_TECHNICAL.md` mapował `dateModified` na `recipes.updated_at`
 * „albo `MAX(recipe_versions.created_at)`". Żadne z nich nie jest dziś datą
 * zmiany TREŚCI, a Google traktuje dane strukturalne niezgodne z treścią
 * strony jako naruszenie wytycznych (`sd-policies`, SEO_TECHNICAL.md §2):
 *
 * - `updated_at` przesuwa ukrycie i przywrócenie przez moderację, zmiana
 *   widoczności i zapis bez zmian (`PublishRecipe` zawsze podbija
 *   `content_revision`);
 * - wersja powstaje przy KAŻDYM „Zapisz" z publikacją, także bez zmiany,
 *   a autozapis kreatora zmienia opublikowaną treść bez wersji.
 *
 * Dwa pierwsze testy mierzą te przesłanki na prawdziwych akcjach. Jeśli
 * któryś zacznie oblewać, źródło mogło stać się wiarygodne — wtedy wróć do
 * decyzji w SEO_TECHNICAL.md, zamiast kasować test.
 *
 * @bez-kontroli-dodatniej Jedyny test tekstowy czyta specyfikację w docs/seo, nie źródło aplikacji; kontrola dodatnia stoi w nim (wiersz `datePublished` musi się znaleźć), a kontrola ujemna (przywrócony stary wiersz mapowania) wykonana ręcznie przy #2014.
 */
class RecipeJsonLdBezNiepewnejDatyModyfikacjiTest extends TestCase
{
    use RefreshDatabase;

    private const T_PUBLIKACJA = '2026-09-01 10:00:00';

    private const T_ZMIANA_TRESCI = '2026-09-05 10:00:00';

    private const T_ZDARZENIE_BEZ_TRESCI = '2026-09-10 10:00:00';

    public function test_updated_at_przesuwa_moderacja_bez_zmiany_tresci(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->zmienTresc($autorka, $przepis, 'Rosol na niedziele, poprawiony.');
        $przedModeracja = Recipe::findOrFail($przepis->getKey());
        $this->assertTrue($przedModeracja->updated_at->equalTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC')));

        $this->travelTo(Carbon::parse(self::T_ZDARZENIE_BEZ_TRESCI, 'UTC'));
        $this->ukryjIPrzywroc($przepis);

        $poModeracji = Recipe::findOrFail($przepis->getKey());
        $this->assertSame(Recipe::STATUS_PUBLISHED, $poModeracji->status, 'Przywrócenie nie wróciło przepisu — test mierzyłby nie to.');
        $this->assertSame($przedModeracja->summary, $poModeracji->summary);
        $this->assertTrue(
            $poModeracji->updated_at->equalTo(Carbon::parse(self::T_ZDARZENIE_BEZ_TRESCI, 'UTC')),
            'Przesłanka #2014: `updated_at` przesuwa się przy moderacji bez zmiany treści.',
        );
    }

    public function test_wersje_przepisu_nie_sa_rejestrem_zmian_tresci(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->assertSame(1, $przepis->versions()->count());

        // „Zapisz" bez żadnej zmiany: nowa wersja, choć treść ta sama.
        $this->travelTo(Carbon::parse(self::T_ZDARZENIE_BEZ_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, 'Rosol na niedziele.', publish: true);
        $this->assertSame(2, $przepis->versions()->count(), 'Przesłanka #2014: zapis bez zmiany tworzy wersję.');

        // Autozapis kreatora na opublikowanym przepisie: treść zmieniona
        // publicznie, wersji brak.
        $this->travelTo(Carbon::parse('2026-09-12 10:00:00', 'UTC'));
        $this->zapisz($autorka, $przepis, 'Rosol z autozapisu.', publish: false, wersjaPoprawki: false);
        $this->assertSame('Rosol z autozapisu.', Recipe::findOrFail($przepis->getKey())->summary);
        $this->assertSame(2, $przepis->versions()->count(), 'Przesłanka #2014: autozapis zmienia treść bez wersji.');
    }

    public function test_publiczny_przepis_po_zmianie_tresci_i_moderacji_nie_podaje_niepewnej_daty_modyfikacji(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->zmienTresc($autorka, $przepis, 'Rosol na niedziele, poprawiony.');
        $this->travelTo(Carbon::parse(self::T_ZDARZENIE_BEZ_TRESCI, 'UTC'));
        $this->ukryjIPrzywroc($przepis);

        $dane = collect($this->blokiJsonLd($przepis))->firstWhere('@type', 'Recipe');

        // Kontrola dodatnia: blok jest i niesie datę publikacji — inaczej brak
        // `dateModified` niżej niczego by nie dowodził.
        $this->assertIsArray($dane, 'Publiczny przepis z gotowym zdjęciem musi wystawić `Recipe`.');
        $this->assertSame('2026-09-01', $dane['datePublished'] ?? null);
        $this->assertSame('Rosol na niedziele, poprawiony.', $dane['description'] ?? null);
        $this->assertArrayNotHasKey('dateModified', $dane, 'Bez wiarygodnej daty zmiany treści pole ma nie powstać (#2014).');
    }

    public function test_przepis_niepubliczny_nie_wystawia_recipe_ani_daty(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        // Kontrola dodatnia: ten sam przepis publicznie wystawia `Recipe`.
        $this->assertContains('Recipe', array_column($this->blokiJsonLd($przepis), '@type'));

        $this->zmienTresc($autorka, $przepis, 'Rosol na niedziele, poprawiony.', widocznosc: 'private');

        // Autorka widzi swój prywatny przepis; danych strukturalnych nie ma
        // żadnych, więc nie ma też żadnej daty.
        $this->actingAs($autorka);
        $this->assertSame([], $this->blokiJsonLd($przepis));
    }

    public function test_specyfikacja_nie_mapuje_date_modified_na_niewiarygodne_zrodlo(): void
    {
        $specyfikacja = file_get_contents(base_path('docs/seo/SEO_TECHNICAL.md'));
        $this->assertIsString($specyfikacja);
        $this->assertStringContainsString('| `datePublished` | `recipes.published_at` |', $specyfikacja, 'Nie znaleziono tabeli mapowania — test mierzyłby nie to.');

        preg_match('/^\| `dateModified` \|(.*)$/m', $specyfikacja, $wiersz);
        $this->assertNotEmpty($wiersz, 'Mapowanie musi mówić, co z `dateModified` — także że go nie emitujemy.');
        $this->assertStringNotContainsString('recipes.updated_at`', $wiersz[1] ?? '');
        $this->assertStringNotContainsString('MAX(recipe_versions.created_at)', $wiersz[1] ?? '');
    }

    /** @return array{0: User, 1: Recipe} */
    private function opublikowanyPrzepis(): array
    {
        $this->travelTo(Carbon::parse(self::T_PUBLIKACJA, 'UTC'));
        $autorka = $this->user('zofia2014');
        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Rosol babci Zofii', 'summary' => 'Rosol na niedziele.', 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => '1 kurczak']],
            steps: [['instruction' => 'Zalej woda i gotuj powoli.']],
            publish: true,
        );
        // Gotowe zdjęcie, bez którego `Recipe` w JSON-LD nie powstaje (#1005).
        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $przepis->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();

        return [$autorka, $przepis->refresh()];
    }

    private function zmienTresc(User $autorka, Recipe $przepis, string $opis, string $widocznosc = 'public'): void
    {
        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, $opis, publish: true, widocznosc: $widocznosc);
    }

    private function zapisz(User $autorka, Recipe $przepis, string $opis, bool $publish, bool $wersjaPoprawki = true, string $widocznosc = 'public'): void
    {
        $swiezy = Recipe::findOrFail($przepis->getKey());
        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => $swiezy->title, 'summary' => $opis, 'visibility' => $widocznosc, 'source_type' => 'own', 'hero_media_id' => $swiezy->hero_media_id],
            ingredients: [['text' => '1 kurczak']],
            steps: $swiezy->steps()->get()->map(fn ($krok) => ['id' => $krok->getKey(), 'instruction' => $krok->instruction])->all(),
            publish: $publish,
            existing: $swiezy,
            wersjaPoprawki: $wersjaPoprawki,
        );
    }

    private function ukryjIPrzywroc(Recipe $przepis): void
    {
        $zgloszenie = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'recipe',
            'target_id' => (string) $przepis->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'harassment',
                'user_message' => 'Sprawdzamy ten przepis.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(Recipe::STATUS_HIDDEN, Recipe::findOrFail($przepis->getKey())->status);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $zgloszenie), [
                'reason_code' => 'pomylka',
                'user_message' => 'Przepis wrócił.',
            ])
            ->assertSessionHasNoErrors();

        auth()->logout();
    }

    /** @return list<array<string, mixed>> */
    private function blokiJsonLd(Recipe $przepis): array
    {
        $html = $this->get(route('recipes.show', Recipe::findOrFail($przepis->getKey())))->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $bloki);

        return array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $bloki[1],
        );
    }
}
