<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use App\Support\MapaStrony;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `lastmod` przepisu w mapie strony = data zmiany TREŚCI (#1280).
 *
 * Mapa brała `recipes.updated_at`, który przesuwa też moderacja i zapis bez
 * zmian, a do tego siedziała w cache do sześciu godzin, więc prawdziwa
 * zmiana tytułu, opisu czy składnika nie była w niej widoczna. Teraz
 * `lastmod` to `tresc_zmieniona_at` (ta sama data co `dateModified`
 * w JSON-LD), a zmiana treści publicznego przepisu unieważnia mapę.
 *
 * NIKT tu nie czyści cache ręcznie: pierwszy odczyt zapamiętuje mapę,
 * zmiana idzie prawdziwą drogą (`PublishRecipe`), drugi odczyt musi ją widzieć.
 * Ta klasa nie czyta źródła aplikacji, tylko wynik XML.
 *
 * UWAGA: `travelTo` przesuwa też zegar cache — wszystkie zdarzenia muszą
 * zmieścić się w sześciu godzinach od pierwszego odczytu mapy, inaczej wpis
 * wygasa sam i test zieleniłby się bez działania haka.
 */
class MapaStronyLastmodPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const T_PUBLIKACJA = '2026-09-01 10:00:00';

    public function test_edycja_opisu_przesuwa_lastmod_w_zapamietanej_mapie(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->assertSame($this->atom(self::T_PUBLIKACJA), $this->lastmod($przepis));

        $this->travelTo(Carbon::parse('2026-09-01 11:00:00', 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosół na niedzielę, poprawiony.');

        $this->assertSame($this->atom('2026-09-01 11:00:00'), $this->lastmod($przepis));
    }

    public function test_zmiana_samego_skladnika_przesuwa_lastmod(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->lastmod($przepis);

        $this->travelTo(Carbon::parse('2026-09-01 12:00:00', 'UTC'));
        $this->zapisz($autorka, $przepis, skladnik: '2 kurczaki');

        $this->assertSame($this->atom('2026-09-01 12:00:00'), $this->lastmod($przepis));
    }

    public function test_zapis_bez_zmian_nie_przesuwa_lastmod_choc_przesuwa_updated_at(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $this->travelTo(Carbon::parse('2026-09-01 11:00:00', 'UTC'));
        $this->zapisz($autorka, $przepis, opis: 'Rosół na niedzielę, poprawiony.');
        $this->assertSame($this->atom('2026-09-01 11:00:00'), $this->lastmod($przepis));

        $this->travelTo(Carbon::parse('2026-09-01 13:00:00', 'UTC'));
        $this->zapisz($autorka, $przepis);

        // Kontrola dodatnia: zapis naprawdę dotknął wiersza.
        $this->assertTrue(Recipe::findOrFail($przepis->getKey())->updated_at->equalTo(Carbon::parse('2026-09-01 13:00:00', 'UTC')));
        $this->assertSame($this->atom('2026-09-01 11:00:00'), $this->lastmod($przepis));
    }

    public function test_przepis_bez_znanej_daty_zmiany_tresci_nie_ma_lastmod(): void
    {
        $this->travelTo(Carbon::parse(self::T_PUBLIKACJA, 'UTC'));
        $przepis = Recipe::factory()->create([
            'author_id' => User::factory()->create()->getKey(),
            'published_at' => now()->subDay(),
        ]);
        $this->assertNull($przepis->refresh()->tresc_zmieniona_at);

        $this->assertNull($this->lastmod($przepis), 'Nieznana data zmiany treści → brak `lastmod`, nie zgadywanie z `updated_at`.');
    }

    public function test_edycja_przepisu_prywatnego_nie_kasuje_zapamietanej_mapy(): void
    {
        [$autorka, $przepis] = $this->opublikowanyPrzepis();
        $prywatny = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'private',
            'published_at' => now(),
        ]);
        $this->lastmod($przepis);
        $this->assertTrue(cache()->has(MapaStrony::KLUCZ), 'Kontrola dodatnia: pierwszy odczyt zapamiętał mapę.');

        $prywatny->forceFill(['tresc_zmieniona_at' => now()->addDay(), 'title' => 'Inny tytuł'])->save();

        $this->assertTrue(cache()->has(MapaStrony::KLUCZ), 'Treść prywatna nie ma prawa przebudowywać mapy.');
    }

    private function atom(string $czas): string
    {
        return Carbon::parse($czas, 'UTC')->toAtomString();
    }

    private function lastmod(Recipe $przepis): ?string
    {
        $xml = simplexml_load_string((string) $this->get(route('sitemap'))->assertOk()->getContent());
        $this->assertNotFalse($xml);

        $adres = route('recipes.show', Recipe::findOrFail($przepis->getKey())->slug);
        foreach ($xml->url as $url) {
            if ((string) $url->loc === $adres) {
                return isset($url->lastmod) ? Carbon::parse((string) $url->lastmod)->utc()->toAtomString() : null;
            }
        }
        $this->fail('Przepisu nie ma w mapie — asercje niżej nic nie znaczą.');
    }

    /** @return array{0: User, 1: Recipe} */
    private function opublikowanyPrzepis(): array
    {
        $this->travelTo(Carbon::parse(self::T_PUBLIKACJA, 'UTC'));
        $autorka = User::factory()->create()->refresh();
        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);
        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Rosół babci Zofii', 'summary' => 'Rosół na niedzielę.', 'visibility' => 'public', 'source_type' => 'own', 'hero_media_id' => (string) $zdjecie->getKey()],
            ingredients: [['text' => '1 kurczak']],
            steps: [['instruction' => 'Zalej wodą i gotuj powoli.']],
            publish: true,
        );

        return [$autorka, $przepis->refresh()];
    }

    private function zapisz(User $autorka, Recipe $przepis, ?string $opis = null, string $skladnik = '1 kurczak'): void
    {
        $swiezy = Recipe::findOrFail($przepis->getKey());
        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: [
                'title' => $swiezy->title,
                'summary' => $opis ?? $swiezy->summary,
                'visibility' => 'public',
                'source_type' => 'own',
                'hero_media_id' => $swiezy->hero_media_id,
            ],
            ingredients: [['text' => $skladnik]],
            steps: $swiezy->steps()->get()->map(fn ($krok) => [
                'id' => $krok->getKey(),
                'instruction' => $krok->instruction,
            ])->all(),
            publish: true,
            existing: $swiezy,
            wersjaPoprawki: true,
        );
    }
}
