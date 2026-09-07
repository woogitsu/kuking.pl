<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Słownik tagów v1.1 — uzupełnienie zamówione po pierwszym wdrożeniu
 * (`database/seeders/dane/slownik-tagow-v1.1.json`).
 *
 * CO TA WERSJA WNOSI I DLACZEGO POTRZEBUJE WŁASNEGO TESTU
 * Zmierzona słabość słownika v1.0: był bogaty w szczegóły („krem z
 * brokułów"), a ubogi w POJĘCIA NADRZĘDNE, pod które człowiek sięga jako
 * pierwsze — „warzywa", „mięso", „nabiał", „dania rybne". Do tego brakowało
 * trzech technik, które ludzie realnie stosują i nazwą: `sous vide`,
 * `fermentacja`, `konfitowanie`.
 *
 * Plik v1.1 wprowadza JEDNĄ RZECZ, KTÓREJ POPRZEDNIE PLIKI NIE MIAŁY:
 * klucz `nowe_aliasy` — aliasy do tagów, które już istnieją. To osobna
 * operacja niż dodanie tagu i seeder musiał się jej nauczyć; bez tego
 * 37 aliasów do 32 istniejących tagów wczytałoby się jako zero i seeder
 * zaraportowałby sukces. Dlatego test mierzy właśnie je, a nie same nowe
 * nazwy — te wczytałyby się starą ścieżką.
 */
class SlownikTagowV11Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{tagi: list<array{nazwa: string, kategoria: string, aliasy: list<string>}>, nowe_aliasy: list<array{tag: string, aliasy: list<string>}>} */
    private function plikV11(): array
    {
        /** @var array{tagi: list<array{nazwa: string, kategoria: string, aliasy: list<string>}>, nowe_aliasy: list<array{tag: string, aliasy: list<string>}>} $dane */
        $dane = json_decode(
            (string) file_get_contents(database_path('seeders/dane/slownik-tagow-v1.1.json')),
            true, 512, JSON_THROW_ON_ERROR,
        );

        return $dane;
    }

    /**
     * TO JEST POMIAR. Aliasy z klucza `nowe_aliasy` muszą być w bazie
     * i muszą prowadzić do TEGO tagu, przy którym stoją w pliku.
     */
    public function test_aliasy_do_istniejacych_tagow_trafiaja_do_bazy(): void
    {
        $plik = $this->plikV11();

        $this->assertNotEmpty($plik['nowe_aliasy'], 'Bez tej sekcji test nie ma czego mierzyć.');

        $this->seed(TagSeeder::class);

        $brakujace = [];
        $poddane = 0;

        foreach ($plik['nowe_aliasy'] as $wpis) {
            $tag = Tag::query()->where('normalized_name', Tag::znormalizujNazwe($wpis['tag']))->first();

            $this->assertNotNull($tag, "Tag docelowy „{$wpis['tag']}” nie istnieje — plik v1.1 wskazuje na nazwę, której nie ma w słowniku.");

            foreach ($wpis['aliasy'] as $alias) {
                $poddane++;

                $wBazie = TagAlias::query()
                    ->where('normalized_alias', Tag::znormalizujNazwe($alias))
                    ->first();

                if ($wBazie === null) {
                    $brakujace[] = "{$alias} (miał prowadzić do „{$wpis['tag']}”)";

                    continue;
                }

                if ($wBazie->tag_id !== $tag->getKey()) {
                    $brakujace[] = "{$alias} prowadzi do innego tagu niż „{$wpis['tag']}”";
                }
            }
        }

        $this->assertGreaterThan(30, $poddane, 'Za mało aliasów w pomiarze — plik jest nie ten.');
        $this->assertSame([], $brakujace, 'Aliasy z sekcji `nowe_aliasy` nie trafiły do bazy albo prowadzą do złego tagu.');
    }

    /** Nowe pojęcia nadrzędne i trzy zamówione techniki są w bazie. */
    public function test_nowe_pojecia_i_techniki_sa_w_bazie(): void
    {
        $this->seed(TagSeeder::class);

        foreach ([
            'warzywa' => 'skladniki',
            'mięso' => 'skladniki',
            'nabiał' => 'skladniki',
            'dania rybne' => 'potrawy',
            'sous vide' => 'przygotowanie',
            'fermentacja' => 'przygotowanie',
            'konfitowanie' => 'przygotowanie',
        ] as $nazwa => $kategoria) {
            $tag = Tag::query()->where('normalized_name', Tag::znormalizujNazwe($nazwa))->first();

            $this->assertNotNull($tag, "W bazie nie ma tagu „{$nazwa}”.");
            $this->assertSame($kategoria, $tag->internal_category, "Tag „{$nazwa}” ma nie tę kategorię.");
        }
    }

    /**
     * KONTROLA Z DRUGIEJ STRONY. Dołożenie trzeciego pliku nie mogło
     * wyrzucić niczego, co było w v1.0 — ani tagu, ani aliasu.
     */
    public function test_v11_nie_zabiera_niczego_z_poprzedniej_wersji(): void
    {
        $this->seed(TagSeeder::class);

        // Punkty kontrolne z każdej warstwy v1.0: potrawa, kategoria
        // `pamiec`, dieta, tag z pliku uzupełnień i alias v1.0.
        foreach (['zupa', 'przepis po babci', 'bez glutenu', 'kapusta', 'żurek'] as $nazwa) {
            $this->assertTrue(
                Tag::query()->where('normalized_name', Tag::znormalizujNazwe($nazwa))->exists(),
                "V1.1 zabrała tag „{$nazwa}” z poprzedniej wersji.",
            );
        }

        $this->assertTrue(
            TagAlias::query()->where('normalized_alias', 'zurek')->exists(),
            'V1.1 zabrała alias „zurek” z poprzedniej wersji.',
        );

        $this->assertSame(0, Tag::query()->where('status', '!=', Tag::STATUS_ACTIVE)->count(), 'Świeży słownik nie powinien mieć tagów nieaktywnych.');
    }

    /**
     * I to, po co aliasy w ogóle istnieją: wpisanie aliasu w formularzu
     * prowadzi do tagu kanonicznego, a nie tworzy drugiego tagu na to samo.
     */
    public function test_wpisanie_nowego_aliasu_w_formularzu_prowadzi_do_tagu_kanonicznego(): void
    {
        $this->seed(TagSeeder::class);

        $autor = $this->user('kucharka');
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Ziemniaki z koperkiem',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $przed = Tag::query()->count();

        $tagi = app(ResolveTagsForPost::class)->handle(['pyra', 'zupki', 'jajo']);

        // `post_tags` ma UNIQUE(post_id, position) — kolejność tagów pod
        // wpisem jest danymi, nie przypadkiem, więc pozycję trzeba podać.
        foreach (array_values($tagi) as $pozycja => $tag) {
            $post->tags()->attach($tag->getKey(), ['position' => $pozycja]);
        }

        $nazwy = $post->tags()->pluck('normalized_name')->all();

        sort($nazwy);
        $this->assertSame(['jajka', 'ziemniaki', 'zupa'], $nazwy, 'Alias z v1.1 nie doprowadził do tagu kanonicznego.');

        $this->assertSame($przed, Tag::query()->count(), 'Wpisanie aliasu utworzyło nowy tag zamiast trafić w istniejący.');
    }
}
