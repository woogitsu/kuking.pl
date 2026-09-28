<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Zapisz do zeszytu” przeżywa rejestrację i logowanie gościa (#2028).
 *
 * Gość klika „Zapisz do zeszytu” przy przepisie, zakłada konto (albo się
 * loguje) i wraca na TEN SAM przepis z rozwiniętym wyborem zeszytu. Nic nie
 * jest zapisywane za niego — przepis trafia do zeszytu dopiero po jego
 * własnym kliknięciu.
 */
class ZamiarZapisuPoRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_gosc_widzi_na_przepisie_przyciski_zapisu_z_identyfikatorem_przepisu(): void
    {
        [, $przepis] = $this->przepis();

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertNotNull($this->linkZTekstem($html, 'Zapisz do zeszytu'));
        $this->assertNotNull($this->linkZTekstem($html, 'Masz konto? Zaloguj się i zapisz'));
    }

    public function test_gosc_po_rejestracji_i_pominieciu_onboardingu_wraca_na_przepis_z_rozwinietym_wyborem_zeszytu(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));

        $cel = $this->get(route('onboarding.done'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(route('recipes.show', $przepis->slug), (string) $cel);
        $this->assertStringContainsString('wybierz_zeszyt=1', (string) $cel);
        $this->assertNicNieZapisano();

        $strona = $this->get((string) $cel)->assertOk()->assertSee('Jeszcze niczego nie zapisaliśmy');
        $this->assertTrue($this->wyborZeszytuJestRozwiniety((string) $strona->getContent(), 'wybor-zeszytu-przepis-'.$przepis->getKey()));
        $this->assertNicNieZapisano();
    }

    public function test_po_przejsciu_calego_onboardingu_wybor_zeszytu_tez_sie_otwiera_i_zapis_dopiero_po_kliknieciu(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        $this->post(route('onboarding.interests'), [])->assertRedirect(route('onboarding.people'));
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));

        $cel = (string) $this->get(route('onboarding.done'))->assertRedirect()->headers->get('Location');
        $this->get($cel)->assertOk()->assertSee('Jeszcze niczego nie zapisaliśmy');
        $this->assertNicNieZapisano();

        // Dopiero świadomy wybór zeszytu zapisuje przepis.
        $zeszyt = Collection::create(['owner_id' => $this->nowy()->getKey(), 'name' => 'Moje', 'visibility' => 'private']);
        $this->post(route('collections.save', $przepis->slug), ['collection_id' => $zeszyt->getKey()])->assertRedirect();
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());
    }

    public function test_zamiar_jest_jednorazowy(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect();

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zwykle_wejscie_na_przepis_nie_rozwija_wyboru_zeszytu(): void
    {
        [, $przepis] = $this->przepis();
        $user = $this->user('czytelnik');

        $this->actingAs($user)->get(route('recipes.show', $przepis->slug))
            ->assertOk()->assertDontSee('Jeszcze niczego nie zapisaliśmy');
    }

    /** @return iterable<string, array{0: \Closure(Recipe, User): void}> */
    public static function przepisyNiedostepne(): iterable
    {
        yield 'prywatny' => [static function (Recipe $p, User $a): void {
            $p->forceFill(['visibility' => 'private'])->save();
        }];
        yield 'dla obserwujących' => [static function (Recipe $p, User $a): void {
            $p->forceFill(['visibility' => 'followers'])->save();
        }];
        yield 'ukryty' => [static function (Recipe $p, User $a): void {
            $p->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        }];
        yield 'szkic' => [static function (Recipe $p, User $a): void {
            $p->forceFill(['status' => Recipe::STATUS_DRAFT, 'published_at' => null])->save();
        }];
        yield 'autor zbanowany' => [static function (Recipe $p, User $a): void {
            $a->forceFill(['status' => User::STATUS_BANNED])->save();
        }];
        yield 'usunięty' => [static function (Recipe $p, User $a): void {
            $p->delete();
        }];
    }

    #[DataProvider('przepisyNiedostepne')]
    public function test_niedostepny_przepis_nie_zapisuje_zamiaru(\Closure $zepsuj): void
    {
        [$autor, $przepis] = $this->przepis();
        $zepsuj($przepis, $autor);

        $this->get(route('register', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    #[DataProvider('przepisyNiedostepne')]
    public function test_przepis_ktory_stal_sie_niedostepny_w_chwili_powrotu_nie_otwiera_wyboru(\Closure $zepsuj): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        $zepsuj($przepis->fresh() ?? $przepis, $autor->fresh());
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_blokada_powstala_po_rejestracji_zamyka_powrot(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        Block::query()->create(['blocker_id' => $autor->getKey(), 'blocked_id' => $this->nowy()->getKey(), 'created_at' => now()]);
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_wygasa(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZPrzepisu($przepis);
        $this->post(route('onboarding.skip'));
        $this->travel(3)->hours();

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function podrobioneParametry(): iterable
    {
        yield 'pełny adres' => [['save_recipe' => 'https://evil.example/zapisz']];
        yield 'adres bez schematu' => [['save_recipe' => '//evil.example']];
        yield 'ścieżka' => [['save_recipe' => '/przepisy/cokolwiek']];
        yield 'slug zamiast identyfikatora' => [['save_recipe' => 'szarlotka']];
        yield 'tablica' => [['save_recipe[]' => 'x']];
    }

    /** @param  array<string, string>  $zapytanie */
    #[DataProvider('podrobioneParametry')]
    public function test_podrobiony_parametr_jest_ignorowany(array $zapytanie): void
    {
        $this->get(route('register').'?'.http_build_query($zapytanie))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_doklejony_adres_powrotu_nie_trafia_do_przekierowania(): void
    {
        [, $przepis] = $this->przepis();

        $this->get(route('register', [
            'save_recipe' => $przepis->getKey(),
            'return' => 'https://evil.example',
            'redirect' => '//evil.example',
        ]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $cel = (string) $this->get(route('onboarding.done'))->headers->get('Location');
        $this->assertStringStartsWith(route('recipes.show', $przepis->slug).'?', $cel);
        $this->assertStringNotContainsString('evil.example', $cel);
    }

    public function test_zamiar_goscia_nie_przechodzi_na_konto_ktore_sie_nie_rejestrowalo(): void
    {
        [, $przepis] = $this->przepis();

        $this->get(route('register', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->actingAs($this->user('inna'))->get(route('onboarding.done'))
            ->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_nowszy_zamiar_wypiera_zapis_i_odwrotnie(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->get(route('register', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->get(route('register', ['follow_user' => $autor->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect(route('profile.show', 'autorka'));

        auth()->logout();
        $this->get(route('register', ['follow_user' => $autor->getKey()]))->assertOk();
        $this->get(route('register', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj('druga');
        $this->post(route('onboarding.skip'));
        $cel = (string) $this->get(route('onboarding.done'))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('wybierz_zeszyt=1', $cel);
        // Zużyty zamiar zapisu nie odsłania starszego zamiaru obserwowania.
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_ugotowania_z_nowszego_linku_wypiera_zapis(): void
    {
        [, $przepis] = $this->przepis();

        $this->get(route('register', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_istniejace_konto_po_zalogowaniu_wraca_na_przepis_z_rozwinietym_wyborem_bez_zapisu(): void
    {
        [, $przepis] = $this->przepis();
        $user = $this->user('stala');

        $this->get(route('login', ['save_recipe' => $przepis->getKey()]))->assertOk();
        $this->post(route('login'), ['login' => $user->email, 'password' => 'haslo-testowe-123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('recipes.show', ['recipe' => $przepis->slug, 'wybierz_zeszyt' => 1]).'#wybor-zeszytu-przepis-'.$przepis->getKey());

        $this->assertAuthenticatedAs($user);
        $this->assertNicNieZapisano();
        $odpowiedz = $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'wybierz_zeszyt' => 1]));
        $odpowiedz->assertOk()->assertSee('Jeszcze niczego nie zapisaliśmy');
    }

    public function test_logowanie_z_podrobionym_parametrem_nie_ustawia_celu(): void
    {
        $this->get(route('login', ['save_recipe' => 'https://evil.example']))->assertOk();

        $this->assertNull(session('url.intended'));
    }

    public function test_logowanie_ustawia_cel_z_przepisu_z_bazy(): void
    {
        [, $przepis] = $this->przepis();

        $this->get(route('login', ['save_recipe' => $przepis->getKey(), 'return' => 'https://evil.example']))->assertOk();

        $cel = (string) session('url.intended');
        $this->assertStringStartsWith(route('recipes.show', $przepis->slug).'?', $cel);
        $this->assertStringNotContainsString('evil.example', $cel);
    }

    public function test_logowanie_z_linku_do_prywatnego_przepisu_nie_ustawia_celu(): void
    {
        [, $przepis] = $this->przepis();
        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->get(route('login', ['save_recipe' => $przepis->getKey()]))->assertOk();

        $this->assertNull(session('url.intended'));
    }

    /**
     * Gość: strona przepisu → link z HTML-a → rejestracja. Odnośnik bierzemy
     * z ekranu, nie budujemy w teście — inaczej test przechodziłby także
     * wtedy, gdy przycisk prowadzi do gołego `/rejestracja`.
     */
    private function rejestracjaZPrzepisu(Recipe $przepis): void
    {
        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $href = $this->linkZTekstem($html, 'Zapisz do zeszytu');
        $this->assertNotNull($href, 'Gość powinien mieć przycisk „Zapisz do zeszytu”.');

        $this->get($href)->assertOk();
        $this->zarejestruj();
    }

    private function wyborZeszytuJestRozwiniety(string $html, string $id): bool
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $el = $dom->getElementById($id);

        return $el instanceof DOMElement && $el->tagName === 'details' && $el->hasAttribute('open');
    }

    private function linkZTekstem(string $html, string $tekst): ?string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        foreach ((new DOMXPath($dom))->query('//a[@href]') as $a) {
            if ($a instanceof DOMElement && trim($a->textContent) === $tekst) {
                return $a->getAttribute('href');
            }
        }

        return null;
    }

    private function zarejestruj(string $prefiks = 'nowa'): TestResponse
    {
        return $this->post(route('register'), [
            'display_name' => 'Nowa Osoba', 'username' => $prefiks.'osoba',
            'email' => $prefiks.'@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));
    }

    private function nowy(): User
    {
        return User::query()->where('email', 'nowa@example.test')->firstOrFail();
    }

    /** @return array{0: User, 1: Recipe} */
    private function przepis(): array
    {
        $autor = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Upiecz.']);

        return [$autor, $przepis];
    }

    private function assertNicNieZapisano(): void
    {
        $this->assertSame(0, DB::table('collection_items')->count(), 'Przepis trafia do zeszytu dopiero po kliknięciu człowieka.');
    }
}
