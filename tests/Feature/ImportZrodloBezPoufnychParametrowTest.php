<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\Url\PublicznyAdresZrodla;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #2229: adres importowanej strony trafia do `recipes.source_url`,
 * a stamtąd na publiczną stronę przepisu i do każdej migawki historii
 * wersji. Parametry spoza listy zgód — `token=`, `sid=`, `email=` — nie mogą
 * tam dojść, a parametr wskazujący stronę (`?p=42`) musi zostać.
 */
final class ImportZrodloBezPoufnychParametrowTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = 'https://przepisy.example.pl/sernik?token=SECRET123&sid=SESJA987'
        .'&email=jan%40poczta.example&utm_source=fb&p=42#komentarze';

    public function test_lista_zgod_zostawia_strone_i_zdejmuje_reszte(): void
    {
        $this->assertSame('https://przepisy.example.pl/sernik?p=42', PublicznyAdresZrodla::z(self::ADRES));
        $this->assertSame(
            'https://przepisy.example.pl/?page_id=7&lang=pl',
            PublicznyAdresZrodla::z('https://przepisy.example.pl/?page_id=7&auth=x&lang=pl'),
        );
        $this->assertSame('https://przepisy.example.pl/sernik', PublicznyAdresZrodla::z('https://przepisy.example.pl/sernik'));
        $this->assertSame(
            'https://przepisy.example.pl:8443/a/b',
            PublicznyAdresZrodla::z('https://jan:haslo@przepisy.example.pl:8443/a/b?Token=x'),
            'Dane logowania z adresu nie trafiają do źródła.',
        );
        // Dozwolona nazwa nie przepuszcza wartości, która nie jest prostym identyfikatorem.
        $this->assertSame('https://przepisy.example.pl/x', PublicznyAdresZrodla::z('https://przepisy.example.pl/x?id=jan%40poczta.example'));
        $this->assertSame('https://przepisy.example.pl/x', PublicznyAdresZrodla::z('https://przepisy.example.pl/x?id='.str_repeat('a', 65)));
    }

    public function test_opublikowany_przepis_z_importu_nie_pokazuje_sekretu_ani_w_stronie_ani_w_historii(): void
    {
        $autor = $this->user('hania');
        $recipe = $this->szkic($autor, self::ADRES);

        $this->assertSame('https://przepisy.example.pl/sernik?p=42', $recipe->source_url);
        $this->assertSame('https://przepisy.example.pl/sernik?p=42', PrzepisZImportu::query()->findOrFail($recipe->getKey())->source_url);

        $opublikowany = $this->opublikuj($autor, $recipe);

        $strona = $this->get(route('recipes.show', $opublikowany))->assertOk()->getContent();
        // Kontrola dodatnia: adres źródła naprawdę jest na stronie.
        $this->assertStringContainsString('https://przepisy.example.pl/sernik?p=42', $strona);
        foreach (['SECRET123', 'SESJA987', 'poczta.example', 'utm_source'] as $sekret) {
            $this->assertStringNotContainsString($sekret, $strona);
        }

        $migawki = RecipeVersion::query()->where('recipe_id', $recipe->getKey())->get();
        $this->assertGreaterThan(0, $migawki->count(), 'Publikacja powinna zapisać migawkę wersji.');
        foreach ($migawki as $migawka) {
            $this->assertSame('https://przepisy.example.pl/sernik?p=42', $migawka->snapshot['source_url'] ?? null);
        }
        $this->assertSame(0, DB::table('recipe_versions')->where('snapshot', 'like', '%SECRET123%')->count());

        $historia = $this->get(route('recipes.history.version', [$opublikowany, $migawki->max('version_number')]))->assertOk()->getContent();
        $this->assertStringContainsString('https://przepisy.example.pl/sernik?p=42', $historia);
        $this->assertStringNotContainsString('SECRET123', $historia);
    }

    public function test_szkic_sprzed_poprawki_z_pelnym_adresem_w_pochodzeniu_publikuje_sie_czysty(): void
    {
        $autor = $this->user('basia');
        $recipe = $this->szkic($autor, 'https://przepisy.example.pl/sernik');

        // Wiersz zapisany przed #2229: pełny adres w pochodzeniu i w przepisie.
        PrzepisZImportu::query()->whereKey($recipe->getKey())->update(['source_url' => self::ADRES]);
        Recipe::query()->whereKey($recipe->getKey())->update(['source_url' => self::ADRES]);

        $opublikowany = $this->opublikuj($autor, $recipe->fresh());

        $this->assertSame('https://przepisy.example.pl/sernik?p=42', $opublikowany->fresh()->source_url);
        $this->assertSame(0, DB::table('recipe_versions')->where('recipe_id', $recipe->getKey())->where('snapshot', 'like', '%SECRET123%')->count());
        $this->assertSame(1, DB::table('recipe_versions')->where('recipe_id', $recipe->getKey())->where('snapshot', 'like', '%sernik?p=42%')->count());
    }

    private function szkic(User $autor, string $adres): Recipe
    {
        return app(ZapiszSzkicZImportu::class)->handle(
            $autor,
            PrzepisZImportu::ZRODLO_URL,
            'json_ld',
            new OdczytanyPrzepis(tytul: 'Sernik babci', skladniki: ['1 kg twarogu'], kroki: ['Piekę godzinę.']),
            $adres,
        );
    }

    private function opublikuj(User $autor, Recipe $recipe): Recipe
    {
        return app(PublishRecipe::class)->handle(
            $autor,
            ['title' => $recipe->title, 'visibility' => 'public', 'sprawdzilem_odczyt' => true],
            [['text' => '1 kg twarogu']],
            [['instruction' => 'Ucieram ser z jajkami i piekę godzinę.']],
            publish: true,
            existing: $recipe,
        );
    }
}
