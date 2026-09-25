<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Archiwum profilu nie może wydać przepisu, którego oglądający nie zobaczy.
 *
 * NAJWIĘKSZY ZASIĘG Z CAŁEJ RODZINY (#368): `/@{username}` stoi POZA `auth`,
 * więc widzem jest tu gość bez konta — i wyszukiwarka. Pozostałe miejsca
 * wymagały przynajmniej zalogowania albo obserwowania tagu.
 *
 * WPIS ZAPOWIADAJĄCY PRZEPIS JEST TRWALE `public`
 * (`WpisWskazujacyPrzepis::dopisz()`), a `ProfileController::tylkoWidoczneWpisy()`
 * pyta wyłącznie o `posts.visibility`. Zapowiedź przechodziła więc przez
 * filtr archiwum w każdym stanie przepisu, a karta wpisu rysuje tytuł
 * i zdjęcie główne z relacji `$post->recipe`.
 *
 * WYCIEKA TEŻ SLUG, NIE TYLKO TYTUŁ. Karta linkuje do `recipes.show`, a slug
 * jest tytułem zapisanym inaczej — „Bigos z kapusty kiszonej" wychodzi jako
 * `bigos-z-kapusty-kiszonej`. Asercja czytająca sam tytuł przepuściłaby
 * poprawkę, która usuwa napis, a zostawia odnośnik.
 *
 * DLACZEGO BRAMKA LĄDUJE W `tylkoWidoczneWpisy()`, A NIE W SAMYM `postsFor()`
 * Bo `tylkoWidoczneWpisy()` jest wspólnym filtrem SZEŚCIU zapytań tego ekranu:
 * archiwum, lista lat, oba liczniki, szyna tematów i szyna zdjęć. Każde
 * z nich ma w tym pliku komentarz mówiący, że MUSI odpowiadać na to samo
 * pytanie co archiwum — bo licznik, który nie zgadza się z listą, i rok,
 * który prowadzi do pustej strony, są oracle'ami istnienia treści. Bramka
 * postawiona w samym `postsFor()` zrobiłaby dokładnie ten rozjazd, przed
 * którym te komentarze ostrzegają: tytuł zniknąłby z listy, a licznik nad
 * nią dalej by go liczył.
 *
 * DLACZEGO NIE `tylkoZWidocznychPrzepisow()`, KTÓRA LEŻY 120 LINII NIŻEJ
 * Bo ona robi `whereHas('recipe', …)` BEZ gałęzi na `recipe_id IS NULL`.
 * Na wykonaniach jest to poprawne — każde wykonanie ma przepis. Na `posts`
 * większość wierszy przepisu NIE MA, więc ten warunek skasowałby z profilu
 * całe zwykłe archiwum. Pilnuje tego
 * `test_zwykly_wpis_bez_przepisu_zostaje_w_archiwum_goscia` — to jedyny
 * przypadek, który odróżnia poprawną naprawę od tamtego skrótu. Zakres
 * `Post::scopeZWidocznymPrzepisem($widz)` tę gałąź ma i jest tym samym,
 * którym bramkują się wszystkie strumienie.
 */
class ProfilNiePokazujeUkrytegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Bigos z kapusty kiszonej';

    private const SLUG = 'bigos-z-kapusty-kiszonej';

    /**
     * Basia ma przepis o zadanej widoczności i JEDEN zwykły wpis obok.
     *
     * @return array{0: User, 1: Recipe, 2: Post}
     */
    private function profil(string $widocznosc = 'followers'): array
    {
        $basia = $this->user('basia');

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => self::TYTUL, 'visibility' => $widocznosc, 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        $zwykly = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykly obiad, bez zadnego przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail()->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją zwykły filtr widoczności '
            .'wpisu i te testy przechodziłyby z niewłaściwego powodu.',
        );

        return [$basia, $przepis->fresh(), $zwykly];
    }

    private function stronaGoscia(User $owner): string
    {
        // `actingAs()` z poprzedniej asercji przecieka na kolejne żądanie
        // w tym samym teście — bez tego „gość" bywa zalogowanym autorem
        // i pomiar mówi o czymś innym, niż się wydaje.
        Auth::logout();

        return $this->get('/@'.$owner->profile->username)->assertOk()->getContent();
    }

    /**
     * KONTROLA DODATNIA PRZED NADGORLIWOŚCIĄ — i jedyny test, który odróżnia
     * poprawną naprawę od `whereHas('recipe', …)` bez gałęzi na NULL.
     */
    public function test_zwykly_wpis_bez_przepisu_zostaje_w_archiwum_goscia(): void
    {
        [$basia, , $zwykly] = $this->profil();

        $this->assertNull(
            $zwykly->recipe_id,
            'Wpis kontrolny wskazuje przepis — wtedy nie sprawdza gałęzi `recipe_id IS NULL`.',
        );

        $this->assertStringContainsString(
            'Zwykly obiad, bez zadnego przepisu.',
            $this->stronaGoscia($basia),
            'Bramka przepisu skasowała z profilu zwykły wpis, który z żadnym przepisem nie ma nic '
            .'wspólnego. Tak psuje profil `whereHas(\'recipe\')` bez gałęzi na `recipe_id IS NULL`.',
        );
    }

    /**
     * KONTROLA DODATNIA: karta zapowiedzi NAPRAWDĘ wypisuje tytuł i slug.
     *
     * Bez niej asercje „tytułu nie ma" niżej przechodziłyby także wtedy,
     * gdyby profil w ogóle nie rysował kart przepisów — i cisza nic by nie
     * znaczyła.
     */
    public function test_publiczny_przepis_na_profilu_dalej_widac(): void
    {
        [$basia] = $this->profil('public');

        $html = $this->stronaGoscia($basia);

        $this->assertStringContainsString(
            self::TYTUL,
            $html,
            'Profil nie wypisuje tytułu nawet przepisu w pełni publicznego — asercje o wycieku '
            .'nie mówiłyby wtedy o bramce, tylko o pustym ekranie.',
        );

        $this->assertStringContainsString(
            self::SLUG,
            $html,
            'Profil nie linkuje nawet do przepisu w pełni publicznego — asercja o slugu nie ma '
            .'wtedy czego pilnować.',
        );
    }

    public function test_tytul_i_slug_przepisu_tylko_dla_obserwujacych_nie_stoja_na_profilu_goscia(): void
    {
        [$basia] = $this->profil('followers');

        $html = $this->stronaGoscia($basia);

        $this->assertStringNotContainsString(
            self::TYTUL,
            $html,
            'Tytuł przepisu „tylko dla obserwujących" stoi na profilu otwartym przez gościa bez konta.',
        );

        $this->assertStringNotContainsString(
            self::SLUG,
            $html,
            'Slug przepisu „tylko dla obserwujących" stoi w odnośniku na profilu gościa — a slug '
            .'niesie tytuł. Napis zniknął, odnośnik został.',
        );
    }

    public function test_przepis_zdjety_przez_moderacje_nie_stoi_na_profilu_goscia(): void
    {
        [$basia, $przepis] = $this->profil('public');

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $html = $this->stronaGoscia($basia);

        $this->assertStringNotContainsString(
            self::TYTUL,
            $html,
            'Tytuł przepisu zdjętego przez moderację stoi na profilu gościa.',
        );

        $this->assertStringNotContainsString(
            self::SLUG,
            $html,
            'Slug przepisu zdjętego przez moderację stoi w odnośniku na profilu gościa.',
        );
    }

    /**
     * Licznik nad listą jest ORACLE'EM ISTNIENIA i musi się zgadzać z listą.
     *
     * Mierzymy to przez `noindex`, bo ta jedna flaga czyta OBA liczniki
     * (`$stats['posts'] === 0 && $stats['recipes'] === 0`) i jest w HTML-u
     * jednoznaczna. Profil, którego całą treścią jest zapowiedź schowanego
     * przepisu, ma dla gościa wyglądać na pusty — łącznie z tym, co o nim
     * powie wyszukiwarce.
     */
    public function test_profil_z_samym_ukrytym_przepisem_jest_dla_goscia_pusty(): void
    {
        [$basia, , $zwykly] = $this->profil('private');

        // Zostaje WYŁĄCZNIE zapowiedź schowanego przepisu.
        $zwykly->forceDelete();

        $this->assertSame(
            1,
            Post::query()->where('author_id', $basia->getKey())->count(),
            'Po usunięciu zwykłego wpisu nie została sama zapowiedź — nie ma czego mierzyć.',
        );

        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            $this->stronaGoscia($basia),
            'Profil, którego jedyną treścią jest zapowiedź prywatnego przepisu, liczy ją do '
            .'liczników i przez to wygląda dla gościa i dla wyszukiwarki na profil z treścią. '
            .'Licznik, który nie zgadza się z listą, zdradza, że coś tam jednak jest.',
        );
    }

    public function test_autor_swoj_ukryty_przepis_na_profilu_dalej_widzi(): void
    {
        [$basia] = $this->profil('private');

        $this->assertStringContainsString(
            self::TYTUL,
            $this->actingAs($basia)->get('/@'.$basia->profile->username)->assertOk()->getContent(),
            'Poprawka odcięła autorowi jego własny przepis na jego własnym profilu. '
            .'„Poprawne dane nigdy nie znikają" — własne archiwum widać zawsze.',
        );
    }
}
