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
 * `dateModified` w JSON-LD przepisu — tylko prawdziwa data zmiany treści (#2014).
 *
 * `recipes.updated_at` przesuwa moderacja, zmiana widoczności i zapis bez
 * zmian; wersje powstają przy każdym „Zapisz" z publikacją, a autozapis
 * zmienia treść bez wersji. Źródłem jest więc `recipes.tresc_zmieniona_at`,
 * ustawiane wyłącznie przez `PublishRecipe` przy realnej zmianie treści
 * albo zdjęć, a przy pierwszej publikacji równe `published_at`.
 *
 * Wszystko idzie prawdziwymi drogami: `PublishRecipe` (tak jak formularz
 * i kreator), trasy moderacji, `travelTo`. JSON-LD czytamy z HTML-u
 * i dekodujemy, nie szukamy podciągu.
 *
 * @bez-kontroli-dodatniej Jedyny test tekstowy czyta specyfikację w docs/seo, nie źródło aplikacji; kontrola dodatnia stoi w nim (wiersz `datePublished` musi się znaleźć), a kontrola ujemna (stary wiersz mapowania) wykonana ręcznie przy #2014.
 */
class RecipeJsonLdBezNiepewnejDatyModyfikacjiTest extends TestCase
{
    use RefreshDatabase;

    private const T_PUBLIKACJA = '2026-09-01 10:00:00';

    private const T_ZMIANA_TRESCI = '2026-09-05 10:00:00';

    public function test_pierwsza_publikacja_podaje_date_publikacji(): void
    {
        [, $przepis] = $this->opublikowanyPrzepis();

        $this->assertTrue($przepis->tresc_zmieniona_at?->equalTo($przepis->published_at));
        $dane = $this->recipeJsonLd($przepis);
        $this->assertSame('2026-09-01', $dane['datePublished'] ?? null);
        $this->assertSame('2026-09-01', $dane['dateModified'] ?? null);
    }

    public function test_poprawki_szkicu_przed_pierwsza_publikacja_nie_sa_modyfikacja(): void
    {
        // Szkic → poprawka szkicu (zmiana treści) → pierwsza publikacja.
        $this->travelTo(Carbon::parse(self::T_PUBLIKACJA, 'UTC'));
        $autorka = $this->user('zofia2014');
        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $szkic = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Rosol babci Zofii', 'summary' => 'Pierwszy zarys.', 'visibility' => 'public', 'source_type' => 'own', 'hero_media_id' => (string) $zdjecie->getKey()],
            ingredients: [['text' => '1 kurczak']],
            steps: [['instruction' => 'Zalej woda i gotuj powoli.']],
            publish: false,
        );

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $szkic, opis: 'Rosol na niedziele.', publish: false);
        // Kontrola dodatnia: poprawka szkicu była zmianą treści i zostawiła ślad.
        $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $szkic);

        $this->travelTo(Carbon::parse('2026-09-07 10:00:00', 'UTC'));
        $this->zapisz($autorka, $szkic, publish: true);

        $przepis = Recipe::findOrFail($szkic->getKey());
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->status);
        $this->assertTrue($przepis->published_at->equalTo(Carbon::parse('2026-09-07 10:00:00', 'UTC')));
        $this->assertTrue($przepis->tresc_zmieniona_at?->equalTo($przepis->published_at), 'Pierwsza publikacja ma zrównać datę zmiany treści z datą publikacji.');
        $dane = $this->recipeJsonLd($przepis);
        $this->assertSame('2026-09-07', $dane['datePublished'] ?? null);
        $this->assertSame($dane['datePublished'], $dane['dateModified'] ?? null);
    }

    public function test_zmiana_tresci_podaje_date_tej_zmiany(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosol na niedziele, poprawiony.');

        $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $przepis);
        $dane = $this->recipeJsonLd($przepis);
        $this->assertSame('2026-09-01', $dane['datePublished'] ?? null);
        $this->assertSame('2026-09-05', $dane['dateModified'] ?? null);
        $this->assertSame('Rosol na niedziele, poprawiony.', $dane['description'] ?? null);
    }

    public function test_zmiana_samego_zdjecia_glownego_przesuwa_date(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $noweZdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $this->zapisz($autorka, $przepis, zdjecie: (string) $noweZdjecie->getKey());

        $this->assertSame((string) $noweZdjecie->getKey(), (string) Recipe::findOrFail($przepis->getKey())->hero_media_id, 'Zdjęcie się nie zmieniło — test mierzyłby nie to.');
        $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $przepis);
        $this->assertSame('2026-09-05', $this->recipeJsonLd($przepis)['dateModified'] ?? null);
    }

    public function test_zmiana_samego_zdjecia_kroku_przesuwa_date(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $zdjecieKroku = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $this->zapisz($autorka, $przepis, zdjecieKroku: (string) $zdjecieKroku->getKey());

        $this->assertSame((string) $zdjecieKroku->getKey(), (string) Recipe::findOrFail($przepis->getKey())->steps()->value('media_id'), 'Zdjęcie kroku się nie przypięło — test mierzyłby nie to.');
        $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $przepis);
    }

    public function test_autozapis_kreatora_tez_przesuwa_date(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosol z autozapisu.', publish: false, wersjaPoprawki: false);

        // Autozapis nie zostawia wersji — właśnie dlatego wersje nie mogły być źródłem.
        $this->assertSame(1, $przepis->versions()->count());
        $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $przepis);
    }

    public function test_zapis_bez_zmian_widocznosc_i_moderacja_nie_przesuwaja_daty(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosol na niedziele, poprawiony.');

        $zdarzenia = [
            '2026-09-10 10:00:00' => fn () => $this->zapisz($autorka, $przepis),
            '2026-09-11 10:00:00' => fn () => $this->zapisz($autorka, $przepis, widocznosc: 'followers'),
            '2026-09-12 10:00:00' => fn () => $this->zapisz($autorka, $przepis, widocznosc: 'public'),
            '2026-09-13 10:00:00' => fn () => $this->ukryjIPrzywroc($przepis),
        ];

        foreach ($zdarzenia as $kiedy => $zdarzenie) {
            $this->travelTo(Carbon::parse($kiedy, 'UTC'));
            $zdarzenie();

            // Kontrola dodatnia: zdarzenie naprawdę zapisało wiersz przepisu.
            $this->assertTrue(Recipe::findOrFail($przepis->getKey())->updated_at->equalTo(Carbon::parse($kiedy, 'UTC')), "Zdarzenie z {$kiedy} nie zapisało przepisu — test mierzyłby nie to.");
            $this->assertDataZmiany(self::T_ZMIANA_TRESCI, $przepis, "Zdarzenie z {$kiedy} nie zmienia treści, a przesunęło datę.");
        }

        $this->assertSame(Recipe::STATUS_PUBLISHED, Recipe::findOrFail($przepis->getKey())->status);
        $this->assertSame('2026-09-05', $this->recipeJsonLd($przepis)['dateModified'] ?? null);
    }

    public function test_przepis_bez_daty_zmiany_nie_podaje_pola(): void
    {
        // Przepis sprzed kolumny: `tresc_zmieniona_at` = NULL.
        $przepis = Recipe::factory()->zeZdjeciem()->create(['published_at' => Carbon::parse(self::T_PUBLIKACJA, 'UTC')]);
        $this->assertNull($przepis->refresh()->tresc_zmieniona_at);

        $dane = $this->recipeJsonLd($przepis);
        $this->assertSame('2026-09-01', $dane['datePublished'] ?? null);
        $this->assertArrayNotHasKey('dateModified', $dane);
    }

    public function test_data_sprzed_publikacji_nie_jest_podawana(): void
    {
        $przepis = Recipe::factory()->zeZdjeciem()->create(['published_at' => Carbon::parse(self::T_PUBLIKACJA, 'UTC')]);
        $przepis->forceFill(['tresc_zmieniona_at' => Carbon::parse('2026-08-31 10:00:00', 'UTC')])->save();

        $dane = $this->recipeJsonLd($przepis);
        $this->assertSame('2026-09-01', $dane['datePublished'] ?? null);
        $this->assertArrayNotHasKey('dateModified', $dane);
    }

    public function test_przepis_niepubliczny_nie_wystawia_recipe_ani_daty(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        // Kontrola dodatnia: ten sam przepis publicznie ma datę zmiany.
        $this->assertArrayHasKey('dateModified', $this->recipeJsonLd($przepis));

        $this->travelTo(Carbon::parse(self::T_ZMIANA_TRESCI, 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosol na niedziele, poprawiony.', widocznosc: 'private');
        $this->assertNotNull(Recipe::findOrFail($przepis->getKey())->tresc_zmieniona_at);

        // Autorka widzi swój prywatny przepis; danych strukturalnych nie ma
        // żadnych, więc nie ma też żadnej daty.
        $this->actingAs($autorka);
        $this->assertSame([], $this->blokiJsonLd($przepis));
    }

    public function test_specyfikacja_mapuje_date_modified_na_date_zmiany_tresci(): void
    {
        $specyfikacja = file_get_contents(base_path('docs/seo/SEO_TECHNICAL.md'));
        $this->assertIsString($specyfikacja);
        $this->assertStringContainsString('| `datePublished` | `recipes.published_at` |', $specyfikacja, 'Nie znaleziono tabeli mapowania — test mierzyłby nie to.');

        preg_match('/^\| `dateModified` \|(.*)$/m', $specyfikacja, $wiersz);
        $this->assertNotEmpty($wiersz, 'Mapowanie musi mówić, skąd bierze się `dateModified`.');
        $this->assertStringContainsString('`recipes.tresc_zmieniona_at`', $wiersz[1] ?? '');
        $this->assertStringNotContainsString('recipes.updated_at`', $wiersz[1] ?? '');
        $this->assertStringNotContainsString('MAX(recipe_versions.created_at)', $wiersz[1] ?? '');
    }

    /** @return array{0: User, 1: Recipe} */
    private function opublikowanyPrzepis(): array
    {
        $this->travelTo(Carbon::parse(self::T_PUBLIKACJA, 'UTC'));
        $autorka = $this->user('zofia2014');
        // Gotowe zdjęcie, bez którego `Recipe` w JSON-LD nie powstaje (#1005).
        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Rosol babci Zofii', 'summary' => 'Rosol na niedziele.', 'visibility' => 'public', 'source_type' => 'own', 'hero_media_id' => (string) $zdjecie->getKey()],
            ingredients: [['text' => '1 kurczak']],
            steps: [['instruction' => 'Zalej woda i gotuj powoli.']],
            publish: true,
        );
        $this->assertSame((string) $zdjecie->getKey(), (string) $przepis->hero_media_id, 'Zdjęcie nie przypięło się przy publikacji.');

        return [$autorka, $przepis->refresh()];
    }

    private function zapisz(
        User $autorka,
        Recipe $przepis,
        ?string $opis = null,
        ?string $zdjecie = null,
        ?string $zdjecieKroku = null,
        bool $publish = true,
        bool $wersjaPoprawki = true,
        string $widocznosc = 'public',
    ): void {
        $swiezy = Recipe::findOrFail($przepis->getKey());
        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: [
                'title' => $swiezy->title,
                'summary' => $opis ?? $swiezy->summary,
                'visibility' => $widocznosc,
                'source_type' => 'own',
                'hero_media_id' => $zdjecie ?? $swiezy->hero_media_id,
            ],
            ingredients: [['text' => '1 kurczak']],
            steps: $swiezy->steps()->get()->map(fn ($krok) => array_filter([
                'id' => $krok->getKey(),
                'instruction' => $krok->instruction,
                'media_id' => $zdjecieKroku,
            ]))->all(),
            publish: $publish,
            existing: $swiezy,
            wersjaPoprawki: $wersjaPoprawki,
        );
    }

    private function assertDataZmiany(string $oczekiwana, Recipe $przepis, string $komunikat = ''): void
    {
        $zapisana = Recipe::findOrFail($przepis->getKey())->tresc_zmieniona_at;
        $this->assertNotNull($zapisana, $komunikat);
        $this->assertSame(Carbon::parse($oczekiwana, 'UTC')->toIso8601String(), $zapisana->utc()->toIso8601String(), $komunikat);
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

    /** @return array<string, mixed> */
    private function recipeJsonLd(Recipe $przepis): array
    {
        $dane = collect($this->blokiJsonLd($przepis))->firstWhere('@type', 'Recipe');
        $this->assertIsArray($dane, 'Publiczny przepis z gotowym zdjęciem musi wystawić `Recipe`.');

        return $dane;
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
