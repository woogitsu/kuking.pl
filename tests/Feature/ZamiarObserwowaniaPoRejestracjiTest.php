<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZamiarObserwowaniaPoRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_rejestracja_i_pominiecie_onboardingu_wraca_do_osoby_bez_automatycznego_obserwowania(): void
    {
        $osoba = $this->user('basia');
        $link = route('register', ['follow_user' => $osoba->getKey()]);
        $this->get($link)->assertOk();

        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $nowy = User::query()->where('email', 'nowa@example.test')->firstOrFail();
        $this->assertFalse($nowy->following()->where('users.id', $osoba->getKey())->exists());

        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertRedirect(route('profile.show', 'basia'));
        $this->assertFalse($nowy->following()->where('users.id', $osoba->getKey())->exists());
        $this->assertAuthenticatedAs($nowy);
        $this->assertFollowForm((string) $this->get(route('profile.show', 'basia'))->assertOk()->getContent(), 'basia');
    }

    public function test_rejestracja_z_przepisu_wraca_do_tego_przepisu_a_obserwowanie_nadal_wymaga_post(): void
    {
        $osoba = $this->user('autorka');
        $przepis = Recipe::factory()->create([
            'author_id' => $osoba->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        $this->get(route('register', [
            'follow_user' => $osoba->getKey(), 'follow_recipe' => $przepis->slug,
        ]))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertRedirect(route('recipes.show', $przepis->slug));

        $nowy = User::query()->where('email', 'nowa@example.test')->firstOrFail();
        $this->assertFalse($nowy->following()->where('users.id', $osoba->getKey())->exists());
        $this->assertAuthenticatedAs($nowy);
        $this->assertFollowForm((string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent(), 'autorka');
    }

    public function test_zewnetrzny_lub_podmieniony_cel_nie_staje_sie_przekierowaniem(): void
    {
        $osoba = $this->user('basia');
        $this->get(route('register', [
            'follow_user' => $osoba->getKey(),
            'return' => 'https://evil.example/steal',
        ]))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertRedirect(route('profile.show', 'basia'));

        // Podrobiony slug nie może zamienić wskazanej osoby na autora innego przepisu.
        $inny = $this->user('inny');
        $przepis = Recipe::factory()->create([
            'author_id' => $inny->getKey(), 'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public', 'published_at' => now(),
        ]);
        auth()->logout();
        $this->get(route('login', [
            'follow_user' => $osoba->getKey(), 'follow_recipe' => $przepis->slug,
            'return' => '//evil.example',
        ]))->assertOk();
        $this->assertSame(route('profile.show', 'basia'), session('url.intended'));
    }

    public function test_niepoprawny_identyfikator_nie_zapisuje_zamiaru(): void
    {
        $this->get(route('register', ['follow_user' => 'https://evil.example']))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertOk();
    }

    public function test_wszystkie_karty_goscia_prowadza_do_wlasciwej_osoby_i_logowania(): void
    {
        auth()->logout();
        $osoba = $this->user('basia');
        $przepis = Recipe::factory()->create([
            'author_id' => $osoba->getKey(), 'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public', 'published_at' => now(),
        ]);

        $profil = $this->xpath((string) $this->get(route('profile.show', 'basia'))->assertOk()->getContent());
        $this->assertLink($profil, 'Załóż konto, żeby obserwować', route('register', ['follow_user' => $osoba->getKey()]));
        $this->assertLink($profil, 'Zaloguj się do swojego konta', route('login', ['follow_user' => $osoba->getKey()]));

        $strona = $this->xpath((string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent());
        $this->assertLink($strona, 'Załóż konto, żeby obserwować autora', route('register', ['follow_user' => $osoba->getKey(), 'follow_recipe' => $przepis->slug]));
        $this->assertLink($strona, 'Zaloguj się do swojego konta', route('login', ['follow_user' => $osoba->getKey(), 'follow_recipe' => $przepis->slug]));

        // Ta cząstka jest osadzana przez tablicę dnia; kontrakt linków
        // sprawdzamy w źródle, bo na stronie powitalnej karty gościa celowo
        // nie pokazują indywidualnych akcji.
        $source = (string) file_get_contents(resource_path('views/components/kuking-board/people.blade.php'));
        $this->assertStringContainsString("route('register', ['follow_user' => \$person->getKey()])", $source);
        $this->assertStringContainsString("route('login', ['follow_user' => \$person->getKey()])", $source);
    }

    /** @return array<string, string> */
    private function dane(): array
    {
        return [
            'display_name' => 'Nowa Osoba', 'username' => 'nowaosoba',
            'email' => 'nowa@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }

    private function assertLink(DOMXPath $xpath, string $text, string $href): void
    {
        $links = $xpath->query('//a[normalize-space()="'.$text.'"]');
        $this->assertSame(1, $links->length, 'Oczekiwany link powinien wystąpić raz: '.$text);
        $this->assertSame($href, $links->item(0)?->getAttribute('href'));
    }

    private function assertFollowForm(string $html, string $username): void
    {
        $xpath = $this->xpath($html);
        $buttons = $xpath->query('//button[normalize-space()="Obserwuj"]');
        $this->assertSame(1, $buttons->length, 'Na stronie nie ma jawnej akcji obserwowania.');
        $form = $buttons->item(0)?->parentNode;
        $this->assertSame('form', $form?->nodeName);
        $this->assertSame(route('social.follow', $username), $form->getAttribute('action'));
    }
}
