<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gość czyta komentarze, klika „zaloguj się” albo „załóż konto” — po
 * wejściu wraca do tej samej rozmowy (`#komentarze`), nie na Start (#2027).
 *
 * Odnośniki bierzemy z HTML-a wątku, nie budujemy ich w teście: inaczej
 * test przechodziłby także przy gołym `/login` bez kontekstu. Komentarza nie
 * wysyłamy za człowieka — po powrocie nie ma żadnego nowego wiersza.
 */
class PowrotDoRozmowyPoWejsciuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_zaloguj_sie_w_watku_przepisu_wraca_do_komentarzy_tego_przepisu(): void
    {
        $przepis = $this->przepis();
        $konto = $this->user('basia');

        $this->get($this->linkZWatku($przepis->url(), 'zaloguj się'))->assertOk();
        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('recipes.show', $przepis->slug).'#komentarze');

        $this->assertAuthenticatedAs($konto);
        $this->assertSame(0, Comment::query()->count(), 'Komentarz wysyła człowiek, nie logowanie.');
    }

    public function test_zaloguj_sie_w_watku_wpisu_wraca_do_komentarzy_wpisu(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->getKey()]);
        $this->user('basia');

        $this->get($this->linkZWatku($wpis->url(), 'zaloguj się'))->assertOk();
        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect($wpis->url().'#komentarze');
    }

    public function test_zaloguj_sie_w_watku_ugotowanego_wraca_do_komentarzy_wykonania(): void
    {
        $przepis = $this->przepis();
        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(), 'user_id' => $this->user('kucharz')->getKey(),
        ]);
        $this->user('basia');

        $this->get($this->linkZWatku($wykonanie->url(), 'zaloguj się'))->assertOk();
        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect($wykonanie->url().'#komentarze');
    }

    public function test_zaloz_konto_w_watku_prowadzi_przez_pierwsze_kroki_i_wraca_do_komentarzy(): void
    {
        $przepis = $this->przepis();

        $this->get($this->linkZWatku($przepis->url(), 'załóż konto'))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.interests'), [])->assertRedirect(route('onboarding.people'));
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));

        $this->get(route('onboarding.done'))->assertRedirect(route('recipes.show', $przepis->slug).'#komentarze');
        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertSee('Wyślij komentarz');
        $this->assertSame(0, Comment::query()->count(), 'Komentarz wysyła człowiek, nie onboarding.');

        // Jednorazowo: drugi raz „Gotowe” to zwykły ekran.
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zaloz_konto_i_pominiecie_pierwszych_krokow_tez_wraca_do_komentarzy(): void
    {
        $przepis = $this->przepis();

        $this->get($this->linkZWatku($przepis->url(), 'załóż konto'))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));

        $this->get(route('onboarding.done'))->assertRedirect(route('recipes.show', $przepis->slug).'#komentarze');
    }

    /** @return iterable<string, array{0: string}> */
    public static function podrobioneCele(): iterable
    {
        yield 'zewnętrzny adres' => ['https://evil.example/przepisy/x'];
        yield 'adres bez schematu' => ['//evil.example'];
        yield 'ukośnik wsteczny' => ['/\\evil.example'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'zakodowane ukośniki' => ['%2F%2Fevil.example'];
        yield 'rodzaj z zewnętrznym adresem' => ['recipe://evil.example'];
        yield 'nieznany rodzaj' => ['user:00000000-0000-4000-8000-000000000000'];
        yield 'bardzo długi' => ['recipe:'.str_repeat('a', 5000)];
    }

    #[DataProvider('podrobioneCele')]
    public function test_podrobiony_cel_przy_logowaniu_konczy_na_domyslnym_celu(string $wartosc): void
    {
        $this->user('basia');

        $this->get(route('login').'?comment_on='.rawurlencode($wartosc))->assertOk();
        $this->assertNull(session('url.intended'));
        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('home'));
    }

    #[DataProvider('podrobioneCele')]
    public function test_podrobiony_cel_przy_rejestracji_konczy_na_domyslnym_celu(string $wartosc): void
    {
        $this->get(route('register').'?comment_on='.rawurlencode($wartosc))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_doklejony_adres_obok_poprawnego_parametru_nie_trafia_do_przekierowania(): void
    {
        $przepis = $this->przepis();
        $this->user('basia');

        $this->get(route('login', [
            'comment_on' => 'recipe:'.$przepis->getKey(),
            'redirect' => 'https://evil.example', 'return' => '//evil.example',
        ]))->assertOk();

        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('recipes.show', $przepis->slug).'#komentarze');
    }

    public function test_przepis_niepubliczny_nie_staje_sie_celem(): void
    {
        $przepis = $this->przepis();
        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->user('basia');

        $this->get(route('login', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->post(route('login'), ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('home'));
    }

    public function test_blokada_powstala_po_rejestracji_zamyka_powrot(): void
    {
        $przepis = $this->przepis();

        $this->get(route('register', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        Block::query()->create(['blocker_id' => $przepis->author_id, 'blocked_id' => $this->nowy()->getKey(), 'created_at' => now()]);
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_goscia_nie_przechodzi_na_konto_ktore_sie_nie_rejestrowalo(): void
    {
        $przepis = $this->przepis();

        $this->get(route('register', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->actingAs($this->user('inna'))->get(route('onboarding.done'))
            ->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_nowszy_zamiar_ugotowania_wypiera_powrot_do_rozmowy(): void
    {
        $przepis = $this->przepis();

        $this->get(route('register', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_nowszy_powrot_do_rozmowy_wypiera_zamiar_ugotowania_i_obserwowania(): void
    {
        $przepis = $this->przepis();

        $this->get(route('register', ['cook_recipe' => $przepis->getKey()]))->assertOk();
        $this->get(route('register', ['follow_user' => $przepis->author_id]))->assertOk();
        $this->get(route('register', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertRedirect(route('recipes.show', $przepis->slug).'#komentarze');
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_wygasa(): void
    {
        $przepis = $this->przepis();

        $this->get(route('register', ['comment_on' => 'recipe:'.$przepis->getKey()]))->assertOk();
        $this->zarejestruj();
        $this->travel(3)->hours();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    /** Odnośnik z bloku „Żeby dodać komentarz…” w wątku, oglądanym jako gość. */
    private function linkZWatku(string $adres, string $tekst): string
    {
        $html = (string) $this->get($adres)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $linki = (new DOMXPath($dom))->query('//section[@aria-labelledby="komentarze"]//p[contains(@class, "notice")]//a[normalize-space()="'.$tekst.'"]');
        $this->assertSame(1, $linki->length, 'W wątku powinien być jeden odnośnik „'.$tekst.'”.');
        $link = $linki->item(0);
        if (! $link instanceof DOMElement) {
            throw new \UnexpectedValueException('Odnośnik nie jest elementem HTML.');
        }

        return $link->getAttribute('href');
    }

    private function zarejestruj(): void
    {
        $this->post(route('register'), [
            'display_name' => 'Nowa Osoba', 'username' => 'nowaosoba',
            'email' => 'nowa@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));
    }

    private function nowy(): User
    {
        return User::query()->where('email', 'nowa@example.test')->firstOrFail();
    }

    private function przepis(): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(), 'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public', 'published_at' => now(),
        ]);
    }
}
