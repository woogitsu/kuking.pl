<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Ugotowałem” przeżywa rejestrację gościa (#2058).
 *
 * Gość kończy tryb gotowania, klika „Załóż konto”, rejestruje się
 * i przechodzi pierwsze kroki — na końcu ma zobaczyć ISTNIEJĄCY formularz
 * „Ugotowałem” tego przepisu. Nic nie jest zapisywane za niego: wykonanie
 * i powiadomienie autora powstają dopiero po wysłaniu formularza.
 */
class ZamiarUgotowaniaPoRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_gosc_z_trybu_gotowania_po_rejestracji_i_pominieciu_onboardingu_trafia_na_formularz_ugotowalem(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));

        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        $this->assertNicNieZapisanoZaCzlowieka($autor);

        $this->get(route('cooked.create', $przepis->slug))->assertOk()->assertSee($przepis->title);
        $this->assertAuthenticatedAs($this->nowy());
        $this->assertNicNieZapisanoZaCzlowieka($autor);
    }

    public function test_gosc_z_trybu_gotowania_po_przejsciu_calego_onboardingu_trafia_na_formularz_ugotowalem(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        $this->post(route('onboarding.interests'), [])->assertRedirect(route('onboarding.people'));
        $this->get(route('onboarding.people'))->assertOk();
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));

        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        $this->get(route('cooked.create', $przepis->slug))->assertOk();
        $this->assertNicNieZapisanoZaCzlowieka($autor);
    }

    public function test_zamiar_jest_jednorazowy(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    /** @return iterable<string, array{0: \Closure(Recipe, User): void}> */
    public static function przepisyNiedostepne(): iterable
    {
        yield 'prywatny' => [fn (Recipe $p) => $p->forceFill(['visibility' => 'private'])->save()];
        yield 'dla obserwujących' => [fn (Recipe $p) => $p->forceFill(['visibility' => 'followers'])->save()];
        yield 'ukryty' => [fn (Recipe $p) => $p->forceFill(['status' => Recipe::STATUS_HIDDEN])->save()];
        yield 'szkic' => [fn (Recipe $p) => $p->forceFill(['status' => Recipe::STATUS_DRAFT, 'published_at' => null])->save()];
        yield 'autor zbanowany' => [fn (Recipe $p, User $a) => $a->forceFill(['status' => User::STATUS_BANNED])->save()];
        yield 'autor usuwa konto' => [fn (Recipe $p, User $a) => $a->forceFill(['status' => User::STATUS_PENDING_DELETE])->save()];
        yield 'usunięty' => [fn (Recipe $p) => $p->delete()];
    }

    #[DataProvider('przepisyNiedostepne')]
    public function test_niedostepny_przepis_nie_zapisuje_zamiaru(\Closure $zepsuj): void
    {
        [$autor, $przepis] = $this->przepis();
        $zepsuj($przepis, $autor);

        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    #[DataProvider('przepisyNiedostepne')]
    public function test_przepis_niedostepny_w_chwili_powrotu_nie_otwiera_formularza(\Closure $zepsuj): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        $zepsuj($przepis->fresh() ?? $przepis, $autor->fresh());
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_blokada_powstala_po_rejestracji_zamyka_powrot_bo_policy_cook_liczy_sie_w_chwili_powrotu(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        Block::query()->create(['blocker_id' => $autor->getKey(), 'blocked_id' => $this->nowy()->getKey(), 'created_at' => now()]);
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_wygasa(): void
    {
        [, $przepis] = $this->przepis();

        $this->rejestracjaZTrybuGotowania($przepis);
        $this->post(route('onboarding.skip'));
        $this->travel(3)->hours();

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function podrobioneAdresy(): iterable
    {
        yield 'pełny adres' => [['cook_recipe' => 'https://evil.example/ugotowalem']];
        yield 'adres bez schematu' => [['cook_recipe' => '//evil.example']];
        yield 'ścieżka' => [['cook_recipe' => '/przepisy/cokolwiek/ugotowalem']];
        yield 'slug zamiast identyfikatora' => [['cook_recipe' => 'szarlotka']];
        yield 'tablica' => [['cook_recipe[]' => 'x']];
    }

    /** @param  array<string, string>  $zapytanie */
    #[DataProvider('podrobioneAdresy')]
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
            'cook_recipe' => $przepis->getKey(),
            'return' => 'https://evil.example',
            'redirect' => '//evil.example',
        ]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $cel = $this->get(route('onboarding.done'))->headers->get('Location');
        $this->assertSame(route('cooked.create', $przepis->slug), $cel);
    }

    public function test_zamiar_goscia_nie_przechodzi_na_konto_ktore_sie_nie_rejestrowalo(): void
    {
        [, $przepis] = $this->przepis();
        $inna = $this->user('inna');

        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->actingAs($inna)->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_nowszy_zamiar_obserwowania_wypiera_zamiar_ugotowania_i_odwrotnie(): void
    {
        [$autor, $przepis] = $this->przepis();

        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->get(route('register', ['follow_user' => $autor->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect(route('profile.show', 'autorka'));

        auth()->logout();
        $this->get(route('register', ['follow_user' => $autor->getKey()]))->assertOk();
        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj('druga');
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        // Zużyty zamiar ugotowania nie odsłania starszego zamiaru obserwowania.
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    /**
     * Gość: tryb gotowania → link z HTML-a ostatniego kroku → rejestracja.
     * Odnośnik bierzemy z ekranu, nie budujemy w teście — inaczej test
     * przechodziłby także wtedy, gdy przycisk prowadzi do gołego `/rejestracja`.
     */
    private function rejestracjaZTrybuGotowania(Recipe $przepis): void
    {
        $html = (string) $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $linki = (new DOMXPath($dom))->query('//section[contains(@class, "cook-finish")]//a');
        $this->assertSame(1, $linki->length, 'Na ostatnim kroku powinien być jeden odnośnik dla gościa.');
        $href = (string) $linki->item(0)?->getAttribute('href');
        $this->assertSame('Załóż konto', trim((string) $linki->item(0)?->textContent));

        $this->get($href)->assertOk();
        $this->zarejestruj();
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

    private function assertNicNieZapisanoZaCzlowieka(User $autor): void
    {
        $this->assertSame(0, CookedEvent::query()->count(), 'Wykonanie powstaje dopiero po wysłaniu formularza.');
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count(), 'Autor nie dostaje nic przed wysłaniem formularza.');
    }
}
