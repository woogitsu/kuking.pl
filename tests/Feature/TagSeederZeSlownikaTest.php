<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Domain\Tags\TagSuggester;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagPromotion;
use App\Models\User;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `TagSeeder` na słowniku z plików (D-026) — co trafia do bazy i co robi ze
 * starymi tagami, których nowy słownik nie zna.
 *
 * TRZY PYTANIA, NA KTÓRE ODPOWIADA TEN PLIK
 *   1. Czy WSZYSTKO z plików trafiło do bazy — nazwy i aliasy, bez cichego
 *      gubienia wierszy (raport seedera mówi „0 odrzuconych", ale raport też
 *      trzeba czymś sprawdzić).
 *   2. Czy wpisanie ALIASU w formularzu prowadzi do tagu kanonicznego —
 *      bo to jest jedyny powód, dla którego 2439 aliasów w ogóle istnieje.
 *   3. Czy stary tag, który nowy słownik traktuje jako alias czegoś innego
 *      („marchewka" → „marchew"), zostaje SCALONY, a nie zdublowany — i czy
 *      scalenie NIE dotyka tagu, którego ktoś już użył albo utworzył sam.
 *
 * DLACZEGO PYTANIE 3 JEST NAJWAŻNIEJSZE
 * Poprzednia baza redakcyjna miała 43 takie nazwy. W bazie, na której stary
 * seeder już chodził, zaniechanie oznacza 43 pary żywych tagów na jedno
 * pojęcie — czyli dokładnie to rozsypanie taksonomii, przed którym ta baza
 * ma chronić („zakwas / na zakwasie / chleb zakwas / ZAKWAS — po miesiącu
 * nie ma czego obserwować"). Zaniechanie NIE jest tu neutralne, więc musi
 * być zmierzone.
 */
class TagSeederZeSlownikaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{tagi: int, aliasy: int} liczby policzone Z PLIKÓW, nie z bazy */
    private function zPlikow(): array
    {
        $tagi = 0;
        $aliasy = 0;

        foreach (['slownik-tagow.json', 'slownik-tagow-uzupelnienia.json'] as $plik) {
            $dane = json_decode(
                (string) file_get_contents(database_path('seeders/dane/'.$plik)),
                true, 512, JSON_THROW_ON_ERROR,
            );

            $this->assertIsArray($dane);
            $this->assertIsArray($dane['tagi'] ?? null);

            foreach ($dane['tagi'] as $tag) {
                $this->assertIsArray($tag);
                $tagi++;
                $aliasy += is_array($tag['aliasy'] ?? null) ? count($tag['aliasy']) : 0;
            }
        }

        return ['tagi' => $tagi, 'aliasy' => $aliasy];
    }

    private function zasiej(): void
    {
        $this->seed(TagSeeder::class);
    }

    private function tagStaryjBazy(string $nazwa, bool $seedowany = true): Tag
    {
        return Tag::create([
            'name' => $nazwa,
            'normalized_name' => Tag::znormalizujNazwe($nazwa),
            // Sufiks, żeby test nie zależał od tego, czy nowy słownik ma
            // wolny dokładnie ten slug — kolizja slugów jest sprawdzana
            // osobno, tu chodzi o nazwę.
            'slug' => Tag::slugDlaNazwy($nazwa).'-stary',
            'is_seeded' => $seedowany,
            'internal_category' => 'danie',
        ]);
    }

    private function wpisZTagiem(Tag $tag): Post
    {
        $post = Post::factory()->create([
            'author_id' => $this->user('kucharka')->getKey(),
            'body' => 'Obiad z tagiem',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    /**
     * KONTROLA. Wszystko z plików jest w bazie — co do wiersza. Liczby
     * pochodzą Z PLIKÓW, więc kolejna wersja słownika nie psuje tego testu,
     * ale wczytanie w połowie albo cicha utrata haseł już tak.
     */
    public function test_caly_slownik_trafia_do_bazy(): void
    {
        $oczekiwane = $this->zPlikow();

        $this->zasiej();

        $this->assertSame($oczekiwane['tagi'], Tag::query()->count(), 'Liczba tagów w bazie nie zgadza się z plikami słownika.');
        $this->assertSame($oczekiwane['tagi'], Tag::query()->where('is_seeded', true)->count(), 'Nie każdy tag ze słownika ma `is_seeded`.');

        // Aliasów w bazie może być WIĘCEJ niż w plikach — seeder dokłada
        // automatyczne transliteracje (SPEC §1.2). Mniej być nie może.
        $this->assertGreaterThanOrEqual($oczekiwane['aliasy'], TagAlias::query()->count(), 'Baza ma mniej aliasów niż pliki słownika.');

        // Punkty kontrolne z każdej warstwy słownika: potrawa, kategoria
        // `pamiec` (ta, dla której ten słownik powstał), dieta i tag
        // z pliku uzupełnień.
        foreach (['zupa', 'przepis po babci', 'bez glutenu', 'kapusta'] as $nazwa) {
            $this->assertTrue(
                Tag::query()->where('normalized_name', $nazwa)->exists(),
                "W bazie nie ma tagu „{$nazwa}”.",
            );
        }

        $this->assertSame(0, Tag::query()->where('status', '!=', Tag::STATUS_ACTIVE)->count(), 'Świeży słownik nie powinien mieć tagów nieaktywnych.');
    }

    /** Kategoria techniczna zapisuje się z pliku, nie zostaje pusta. */
    public function test_kategoria_techniczna_jest_zapisana(): void
    {
        $this->zasiej();

        $this->assertSame('potrawy', Tag::query()->where('normalized_name', 'zupa')->value('internal_category'));
        $this->assertSame('pamiec', Tag::query()->where('normalized_name', 'przepis po babci')->value('internal_category'));
        $this->assertSame('skladniki', Tag::query()->where('normalized_name', 'kapusta')->value('internal_category'));
        $this->assertSame(0, Tag::query()->whereNull('internal_category')->count());
    }

    /**
     * WŁAŚCIWY POMIAR pytania 2: wpisanie aliasu w formularzu wpisu daje tag
     * KANONICZNY, a nie nowy tag o nazwie aliasu.
     */
    public function test_wpisanie_aliasu_daje_tag_kanoniczny(): void
    {
        $this->zasiej();

        $przed = Tag::query()->count();

        $tagi = app(ResolveTagsForPost::class)->handle(['zupy']);

        $this->assertCount(1, $tagi);
        $this->assertSame('zupa', $tagi[0]->normalized_name, 'Alias „zupy” nie prowadzi do tagu „zupa”.');
        $this->assertSame($przed, Tag::query()->count(), 'Wpisanie aliasu utworzyło nowy tag.');
    }

    /** Podpowiedź znajduje tag przez alias — gałąź 2 rankingu z SPEC §1.5. */
    public function test_podpowiedz_znajduje_tag_przez_alias(): void
    {
        $this->zasiej();

        $nazwy = app(TagSuggester::class)->sugeruj('zupy')->pluck('normalized_name')->all();

        $this->assertContains('zupa', $nazwy, 'Podpowiedź nie znalazła „zupy” przez alias.');
    }

    /**
     * WŁAŚCIWY POMIAR pytania 3. Stary, pusty tag redakcyjny, którego nowy
     * słownik traktuje jako alias — zostaje SCALONY w tag kanoniczny.
     */
    public function test_stary_pusty_tag_zostaje_scalony_w_kanoniczny(): void
    {
        $stary = $this->tagStaryjBazy('marchewka');

        $this->zasiej();

        $stary->refresh();
        $marchew = Tag::query()->where('normalized_name', 'marchew')->firstOrFail();

        $this->assertSame(Tag::STATUS_MERGED, $stary->status, 'Stary tag „marchewka” nie został scalony.');
        $this->assertSame($marchew->getKey(), $stary->merged_into_tag_id);

        // Nazwa źródła zostaje aliasem celu (SPEC §1.8) — ktoś, kto zna
        // starą nazwę, nadal na nią trafia.
        $this->assertSame(
            $marchew->getKey(),
            TagAlias::query()->where('normalized_alias', 'marchewka')->value('tag_id'),
        );

        // Trzy drogi, którymi człowiek dociera do tagu, muszą po scaleniu
        // prowadzić do JEDNEGO miejsca.
        $this->assertSame('marchew', app(ResolveTagsForPost::class)->handle(['marchewka'])[0]->normalized_name);
        $this->assertContains('marchew', app(TagSuggester::class)->sugeruj('marchewka')->pluck('normalized_name')->all());
        $this->get('/tag/'.$stary->slug)->assertRedirect('/tag/'.$marchew->slug);

        // I stary tag nie może już być podpowiadany jako osobne pojęcie.
        $this->assertNotContains('marchewka', app(TagSuggester::class)->sugeruj('marchewka')->pluck('normalized_name')->all());
    }

    /**
     * KONTROLA DRUGIEJ STRONY — bez niej pomiar wyżej przechodziłby też
     * wtedy, gdyby seeder scalał WSZYSTKO, co znalazł.
     *
     * Tag, który ma oznaczony wpis, zostaje nietknięty: decyzja o scaleniu
     * czegoś, czego ludzie już używają, należy do człowieka.
     */
    public function test_tag_z_wpisem_nie_jest_scalany(): void
    {
        $stary = $this->tagStaryjBazy('burak');
        $wpis = $this->wpisZTagiem($stary);

        $this->zasiej();

        $stary->refresh();

        $this->assertSame(Tag::STATUS_ACTIVE, $stary->status, 'Seeder scalił tag, którego ktoś już użył.');
        $this->assertNull($stary->merged_into_tag_id);

        // Wpis nadal wisi na swoim tagu.
        $this->assertSame(
            [$stary->getKey()],
            DB::table('post_tags')->where('post_id', $wpis->getKey())->pluck('tag_id')->all(),
        );

        // Alias „burak" nie mógł powstać, bo prowadziłby do innego tagu niż
        // ten, który nosi tę nazwę — baza i tak by go nie przyjęła
        // (`UNIQUE(normalized_alias)` nie, ale sens by się rozjechał).
        $this->assertFalse(TagAlias::query()->where('normalized_alias', 'burak')->exists());

        // A tag kanoniczny ze słownika istnieje osobno — świadomie, bo to
        // jest ta cena zaniechania: dwa tagi na jedno pojęcie, dopóki
        // człowiek nie zdecyduje.
        $this->assertTrue(Tag::query()->where('normalized_name', 'buraki')->exists());
    }

    /** Tag utworzony przez CZŁOWIEKA (bez `is_seeded`) też nie jest scalany. */
    public function test_tag_utworzony_przez_czlowieka_nie_jest_scalany(): void
    {
        $wlasny = $this->tagStaryjBazy('schabowy', seedowany: false);

        $this->zasiej();

        $wlasny->refresh();

        $this->assertSame(Tag::STATUS_ACTIVE, $wlasny->status, 'Seeder scalił tag utworzony przez użytkownika.');
        $this->assertFalse($wlasny->is_seeded);
    }

    /** Tag na liście promowanych nie jest scalany — to jest wybór gospodarza. */
    public function test_tag_promowany_nie_jest_scalany(): void
    {
        $stary = $this->tagStaryjBazy('cytryna');
        TagPromotion::create(['tag_id' => $stary->getKey(), 'position' => 1]);

        $this->zasiej();

        $this->assertSame(Tag::STATUS_ACTIVE, $stary->refresh()->status);
    }

    /** Tag obserwowany przez kogokolwiek nie jest scalany. */
    public function test_tag_obserwowany_nie_jest_scalany(): void
    {
        $stary = $this->tagStaryjBazy('pomarańcza');
        $this->user('obserwatorka')->followedTags()->attach($stary->getKey(), ['created_at' => now()]);

        $this->zasiej();

        $this->assertSame(Tag::STATUS_ACTIVE, $stary->refresh()->status);
    }

    /**
     * Drugie uruchomienie nie tworzy ani nie zmienia niczego (`db:seed` bez
     * `migrate:fresh` na istniejącej bazie). Bez tego pomiaru seeder mógłby
     * przy każdym uruchomieniu dokładać aliasy albo scalać coś od nowa.
     */
    public function test_drugie_uruchomienie_nic_nie_zmienia(): void
    {
        $stary = $this->tagStaryjBazy('marchewka');

        $this->zasiej();

        $tagi = Tag::query()->count();
        $aliasy = TagAlias::query()->count();
        $scalone = Tag::query()->where('status', Tag::STATUS_MERGED)->count();

        $this->zasiej();

        $this->assertSame($tagi, Tag::query()->count(), 'Drugie uruchomienie dodało tagi.');
        $this->assertSame($aliasy, TagAlias::query()->count(), 'Drugie uruchomienie dodało aliasy.');
        $this->assertSame($scalone, Tag::query()->where('status', Tag::STATUS_MERGED)->count());
        $this->assertSame(1, $scalone, 'Kontrola: pierwsze uruchomienie miało scalić dokładnie jeden tag.');
        $this->assertSame(Tag::STATUS_MERGED, $stary->refresh()->status);
    }

    /**
     * Slug jest ustalany RAZ. Tag, który istniał wcześniej pod innym slugiem,
     * zachowuje go — adres jego strony mógł już zostać komuś wysłany.
     */
    public function test_seeder_nie_zmienia_slugu_istniejacego_tagu(): void
    {
        $istniejacy = Tag::create([
            'name' => 'zupa',
            'normalized_name' => 'zupa',
            'slug' => 'zupa-z-poczatku-serwisu',
            'is_seeded' => true,
        ]);

        $this->zasiej();

        $this->assertSame('zupa-z-poczatku-serwisu', $istniejacy->refresh()->slug);
    }

    /**
     * KONTROLA POMIARU. Konto z wpisem naprawdę istnieje i wpis naprawdę
     * jest widoczny — inaczej `test_tag_z_wpisem_nie_jest_scalany` mierzyłby
     * tag bez wpisu, czyli dokładnie ten przypadek, którego nie chce mierzyć.
     */
    public function test_kontrola_wpis_z_tagiem_naprawde_powstaje(): void
    {
        $tag = $this->tagStaryjBazy('burak');
        $wpis = $this->wpisZTagiem($tag);

        $this->assertTrue(DB::table('post_tags')->where('post_id', $wpis->getKey())->where('tag_id', $tag->getKey())->exists());
        $this->assertSame(User::STATUS_ACTIVE, $wpis->author->status);
    }
}
