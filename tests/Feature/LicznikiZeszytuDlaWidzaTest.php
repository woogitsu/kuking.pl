<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Liczby wokół zeszytu mówią o tym, co ta osoba może OTWORZYĆ.
 *
 * #1297 — publiczny zeszyt podawał każdemu zalogowanemu widzowi dokładną liczbę zapisów,
 * których ten nie widzi. To metadana o prywatnej aktywności właściciela;
 * teraz dostaje ją wyłącznie właściciel.
 *
 * #1319 — karta zeszytu i „Ostatnio zapisane" liczyły zapowiedź przepisu,
 * który wnętrze zeszytu już ukrywało (przepis schowany, usunięty, autor
 * przepisu zbanowany). Karta mówiła „1 wpis", szyna dawała odnośnik,
 * który `PostPolicy::view()` kończy odmową.
 *
 * #1377 (komentarz w #1319 z 23.09) — bramka przepisu dotyczy wyłącznie
 * CZYSTEJ zapowiedzi. Wpis z własnym tekstem albo zdjęciem, który wskazuje
 * niedostępny przepis, zostaje we wnętrzu, na karcie i w „Ostatnio
 * zapisane" (Policy go wpuszcza), a jego karta nie pokazuje przepisu.
 */
final class LicznikiZeszytuDlaWidzaTest extends TestCase
{
    use RefreshDatabase;

    private const NIEDOSTEPNE = 'dla Ciebie dostępn';

    public function test_obcy_widz_publicznego_zeszytu_nie_dostaja_liczby_ukrytych_zapisow(): void
    {
        [$wlasciciel, $autor, $zeszyt] = $this->scena('public');
        $publiczny = $this->przepis($zeszyt, $autor);
        $prywatnyPrzepis = $this->przepis($zeszyt, $autor, 'private');
        $prywatnyWpis = $this->wpis($zeszyt, $autor, 'private');
        $usuniety = $this->przepis($zeszyt, $autor);
        $usuniety->delete();
        $sekrety = [$prywatnyPrzepis->title, $prywatnyWpis->body, $usuniety->title];

        $this->zeszytGosciowi($zeszyt);
        foreach ([$this->user(), $this->user()] as $widz) {
            $odpowiedz = $this->zeszytOczami($widz, $zeszyt);
            $tekst = $this->tekst($odpowiedz->getContent());
            $this->assertStringContainsString($publiczny->title, $tekst);
            $this->assertStringNotContainsString(self::NIEDOSTEPNE, $tekst);
            $this->assertStringNotContainsString('data-niedostepne-zapisy', $odpowiedz->getContent());
            $this->assertSame(0, $odpowiedz->viewData('niewidoczne'));
            foreach ($sekrety as $sekret) {
                $this->assertStringNotContainsString($sekret, $tekst);
            }
        }

        // Kontrola dodatnia: właściciel nie traci informacji o zachowanych
        // zapisach. Własny zeszyt, cudze treści — prywatny przepis i wpis
        // autora oraz przepis usunięty miękko to trzy niedostępne.
        $tekst = $this->tekst($this->zeszytOczami($wlasciciel, $zeszyt)->getContent());
        $this->assertStringContainsString($publiczny->title, $tekst);
        $this->assertStringContainsString('3 zapisy nie są dla Ciebie dostępne', $tekst);
    }

    public function test_publiczny_zeszyt_z_samymi_ukrytymi_zapisami_ma_dla_obcego_zwykly_pusty_stan(): void
    {
        [$wlasciciel, $autor, $zeszyt] = $this->scena('public');
        $this->przepis($zeszyt, $autor, 'private');
        $this->wpis($zeszyt, $autor, 'private');

        $this->zeszytGosciowi($zeszyt);
        foreach ([$this->user(), $this->user()] as $widz) {
            $tekst = $this->tekst($this->zeszytOczami($widz, $zeszyt)->getContent());
            $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $tekst);
            $this->assertStringNotContainsString(self::NIEDOSTEPNE, $tekst);
        }

        $tekst = $this->tekst($this->zeszytOczami($wlasciciel, $zeszyt)->getContent());
        $this->assertStringContainsString('2 zapisy nie są dla Ciebie dostępne', $tekst);
        $this->assertStringNotContainsString('W tym zeszycie nic jeszcze nie ma', $tekst);
    }

    /**
     * Zapowiedź przepisu A należy do B, zapisuje ją C. Każdy z czterech
     * sposobów, w jaki przepis przestaje być dostępny, ma ją zdjąć z karty
     * i z „Ostatnio zapisane" — a zapis w bazie zostaje.
     */
    public function test_zapowiedz_niedostepnego_przepisu_znika_z_karty_i_ostatnio_zapisanych(): void
    {
        $scenariusze = [
            'przepis prywatny' => fn (Recipe $p, User $a) => $p->update(['visibility' => 'private']),
            'przepis usunięty' => fn (Recipe $p, User $a) => $p->delete(),
            'autor przepisu zbanowany' => fn (Recipe $p, User $a) => $a->forceFill(['status' => User::STATUS_BANNED])->save(),
            'autor przepisu kasuje konto' => fn (Recipe $p, User $a) => $a->forceFill(['status' => User::STATUS_PENDING_DELETE])->save(),
        ];

        foreach ($scenariusze as $nazwa => $schowaj) {
            $autorPrzepisu = $this->user();
            $autorWpisu = $this->user();
            $zapisujacy = $this->user();
            $zeszyt = Collection::create(['owner_id' => $zapisujacy->id, 'name' => 'Zeszyt '.Str::uuid(), 'visibility' => 'private']);
            $przepis = Recipe::factory()->create(['author_id' => $autorPrzepisu->id, 'visibility' => 'public']);
            $zapowiedz = Post::factory()->create(['author_id' => $autorWpisu->id, 'recipe_id' => $przepis->id, 'body' => null]);
            $zeszyt->posts()->attach($zapowiedz->id);

            // Kontrola dodatnia: widoczna zapowiedź jest liczona i pokazana.
            [$liczba, $odnosniki] = $this->karta($zapisujacy, $zeszyt);
            $this->assertSame(1, $liczba, $nazwa);
            $this->assertContains($zapowiedz->url(), $odnosniki, $nazwa);

            $schowaj($przepis, $autorPrzepisu);

            [$liczba, $odnosniki] = $this->karta($zapisujacy, $zeszyt);
            $this->assertSame(0, $liczba, $nazwa.': karta liczy zapowiedź');
            $this->assertNotContains($zapowiedz->url(), $odnosniki, $nazwa.': szyna daje odnośnik');
            $this->assertDatabaseHas('collection_items', ['collection_id' => $zeszyt->id, 'post_id' => $zapowiedz->id]);
            // Karta i wnętrze liczą to samo: jeden niedostępny zapis.
            $this->assertStringContainsString('1 zapis nie jest dla Ciebie dostępny', $this->tekst(
                $this->actingAs($zapisujacy)->get(route('collections.index'))->assertOk()->getContent(),
            ), $nazwa);
            $this->assertSame(1, $this->zeszytOczami($zapisujacy, $zeszyt)->viewData('niewidoczne'), $nazwa);
        }
    }

    public function test_niedostepna_zapowiedz_nie_zajmuje_miejsca_w_pieciu_ostatnich(): void
    {
        $autor = $this->user();
        $zapisujacy = $this->user();
        $zeszyt = Collection::create(['owner_id' => $zapisujacy->id, 'name' => 'Pięć', 'visibility' => 'private']);

        $zwykle = [];
        for ($i = 0; $i < 5; $i++) {
            $zwykle[] = $wpis = Post::factory()->create(['author_id' => $autor->id]);
            $zeszyt->posts()->attach($wpis->id, ['created_at' => now()->subDays(10 - $i)]);
        }
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'private']);
        $zapowiedz = Post::factory()->create(['author_id' => $this->user()->id, 'recipe_id' => $przepis->id, 'body' => null]);
        $zeszyt->posts()->attach($zapowiedz->id, ['created_at' => now()]);

        [$liczba, $odnosniki] = $this->karta($zapisujacy, $zeszyt);
        $this->assertSame(5, $liczba);
        $this->assertNotContains($zapowiedz->url(), $odnosniki);
        foreach ($zwykle as $wpis) {
            $this->assertContains($wpis->url(), $odnosniki);
        }
    }

    public function test_autor_widzi_zapowiedz_wlasnego_prywatnego_przepisu_w_swoim_zeszycie(): void
    {
        $autor = $this->user();
        $zeszyt = Collection::create(['owner_id' => $autor->id, 'name' => 'Moje', 'visibility' => 'private']);
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'private']);
        $zapowiedz = Post::factory()->create(['author_id' => $autor->id, 'recipe_id' => $przepis->id, 'body' => null]);
        $zeszyt->posts()->attach($zapowiedz->id);

        [$liczba, $odnosniki] = $this->karta($autor, $zeszyt);
        $this->assertSame(1, $liczba);
        $this->assertContains($zapowiedz->url(), $odnosniki);
        $this->assertSame(1, $this->zeszytOczami($autor, $zeszyt)->viewData('posts')->total());
    }

    /** @return array<string, array{string}> */
    public static function niedostepnosciPrzepisu(): array
    {
        return [
            'prywatny' => ['private'],
            'tylko dla obserwujących' => ['followers'],
            'ukryty przez moderację' => ['hidden'],
            'usunięty' => ['soft-delete'],
            'autor przepisu zbanowany' => ['banned'],
        ];
    }

    /**
     * Para w jednym zeszycie: czysta zapowiedź i wpis z własną treścią,
     * obie wskazują ten sam przepis A, obie napisał B, zapisuje C.
     */
    #[DataProvider('niedostepnosciPrzepisu')]
    public function test_wpis_z_wlasna_trescia_zostaje_w_zeszycie_gdy_czysta_zapowiedz_znika(string $jak): void
    {
        $autorPrzepisu = $this->user();
        $autorWpisu = $this->user();
        $zapisujacy = $this->user();
        $zeszyt = Collection::create(['owner_id' => $zapisujacy->id, 'name' => 'Para', 'visibility' => 'private']);
        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->id,
            'visibility' => 'public',
            'title' => 'Sekretna Zupa Szczawiowa',
            'slug' => 'sekretna-zupa-szczawiowa',
        ]);
        $zapowiedz = Post::factory()->create(['author_id' => $autorWpisu->id, 'recipe_id' => $przepis->id, 'body' => null]);
        $wlasny = Post::factory()->create([
            'author_id' => $autorWpisu->id,
            'recipe_id' => $przepis->id,
            'body' => 'Ugotowałam po swojemu, z koprem '.Str::uuid(),
        ]);
        $zeszyt->posts()->attach([$zapowiedz->id, $wlasny->id]);

        // Kontrola dodatnia: przy dostępnym przepisie oba wpisy są liczone,
        // podane w szynie i pokazane w środku — razem z tytułem i slugiem
        // przepisu, więc asercje „brak tytułu" niżej mają co wykryć.
        [$liczba, $odnosniki] = $this->karta($zapisujacy, $zeszyt);
        $this->assertSame(2, $liczba);
        $this->assertContains($zapowiedz->url(), $odnosniki);
        $this->assertContains($wlasny->url(), $odnosniki);
        $wnetrze = $this->zeszytOczami($zapisujacy, $zeszyt);
        $this->assertEqualsCanonicalizing([$zapowiedz->id, $wlasny->id], $wnetrze->viewData('posts')->pluck('id')->all());
        $this->assertSame(0, $wnetrze->viewData('niewidoczne'));
        $this->assertStringContainsString($przepis->title, $wnetrze->getContent());
        $this->assertStringContainsString($przepis->slug, $wnetrze->getContent());

        match ($jak) {
            'private', 'followers' => $przepis->update(['visibility' => $jak]),
            'hidden' => Recipe::query()->whereKey($przepis->id)->update(['status' => Recipe::STATUS_HIDDEN]),
            'soft-delete' => $przepis->delete(),
            'banned' => $autorPrzepisu->forceFill(['status' => User::STATUS_BANNED])->save(),
        };

        // Karta i szyna: zostaje wpis z własną treścią, znika czysta zapowiedź.
        [$liczba, $odnosniki] = $this->karta($zapisujacy, $zeszyt);
        $this->assertSame(1, $liczba, 'karta zeszytu');
        $this->assertContains($wlasny->url(), $odnosniki, 'Ostatnio zapisane');
        $this->assertNotContains($zapowiedz->url(), $odnosniki, 'Ostatnio zapisane');

        // Wnętrze: ten sam podział, jeden niedostępny zapis (zapowiedź),
        // a karta wpisu bez tytułu, sluga i odnośnika przepisu.
        $wnetrze = $this->zeszytOczami($zapisujacy, $zeszyt);
        $this->assertSame([$wlasny->id], $wnetrze->viewData('posts')->pluck('id')->all(), 'wnętrze zeszytu');
        $this->assertSame(1, $wnetrze->viewData('niewidoczne'));
        $html = $wnetrze->getContent();
        $this->assertStringContainsString($wlasny->body, $html);
        $this->assertStringNotContainsString($przepis->title, $html);
        $this->assertStringNotContainsString($przepis->slug, $html);

        // Obie pozycje zostają w zeszycie — wrócą po przywróceniu przepisu.
        $this->assertSame(2, $zeszyt->posts()->count());
    }

    /** @return array{0: User, 1: User, 2: Collection} */
    private function scena(string $widocznosc): array
    {
        $wlasciciel = $this->user();
        $autor = $this->user();
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->id, 'name' => 'Zeszyt kontrolny', 'visibility' => $widocznosc]);

        return [$wlasciciel, $autor, $zeszyt];
    }

    private function przepis(Collection $zeszyt, User $autor, string $widocznosc = 'public'): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => $widocznosc, 'title' => 'Przepis kontrolny '.Str::uuid()]);
        $zeszyt->recipes()->attach($przepis->id);

        return $przepis;
    }

    private function wpis(Collection $zeszyt, User $autor, string $widocznosc = 'public'): Post
    {
        $wpis = Post::factory()->create(['author_id' => $autor->id, 'visibility' => $widocznosc, 'body' => 'Wpis kontrolny '.Str::uuid()]);
        $zeszyt->posts()->attach($wpis->id);

        return $wpis;
    }

    private function zeszytOczami(User $widz, Collection $zeszyt): TestResponse
    {
        return $this->actingAs($widz)->get(route('collections.show', $zeszyt))->assertOk();
    }

    /**
     * Gość nie dochodzi nawet do Policy: trasa zeszytu stoi w grupie `auth`,
     * więc publiczny zeszyt znaczy „widoczny dla zalogowanych". Pilnujemy
     * tego tutaj, bo zdjęcie tej bramki odsłoniłoby gościom wszystko, co
     * ten plik sprawdza dla obcego zalogowanego.
     */
    private function zeszytGosciowi(Collection $zeszyt): void
    {
        $this->app['auth']->forgetGuards();
        $this->get(route('collections.show', $zeszyt))->assertRedirect(route('login'));
    }

    /** @return array{0: int, 1: list<string>} liczba wpisów na karcie i odnośniki szyny */
    private function karta(User $wlasciciel, Collection $zeszyt): array
    {
        $odpowiedz = $this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk();
        $karta = $odpowiedz->viewData('collections')->firstWhere('id', $zeszyt->id);

        return [
            (int) $karta->posts_count,
            $odpowiedz->viewData('ostatnioZapisane')->pluck('href')->all(),
        ];
    }

    private function tekst(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', $html));
    }
}
