<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pytanie ma jeden adres: `/pytania/{id}` (#968).
 *
 * Wcześniej to samo pytanie renderowało się także pod `/wpisy/{id}`,
 * a mapa strony ogłaszała wyłącznie ten drugi wariant — sprzeczny z linkami,
 * z `Post::url()` i ze schematem QAPage. Pytanie z samym tytułem (`body`
 * null) w ogóle z mapy wypadało.
 */
class JedenAdresPytaniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.questions.enabled' => true]);
    }

    public function test_publiczne_pytanie_pod_adresem_wpisu_przekierowuje_na_stale(): void
    {
        $pytanie = Post::factory()->question()->create(['body' => 'Czym zastąpić drożdże?']);

        $this->get('/wpisy/'.$pytanie->getKey())
            ->assertStatus(301)
            ->assertRedirect('/pytania/'.$pytanie->getKey());

        $html = $this->get('/pytania/'.$pytanie->getKey())->assertOk()->getContent();
        $this->assertStringContainsString(
            '<link rel="canonical" href="'.route('questions.show', $pytanie).'">',
            $html,
        );
    }

    public function test_przekierowanie_zachowuje_parametry_i_komunikat(): void
    {
        $pytanie = Post::factory()->question()->create();

        $this->get('/wpisy/'.$pytanie->getKey().'?komentarze=2')
            ->assertRedirect('/pytania/'.$pytanie->getKey().'?komentarze=2');

        // Akcje odsyłające na `posts.show` z komunikatem nie mogą go zgubić
        // na drugim skoku.
        $this->withSession(['status' => 'Odpowiedź zapisana.', '_flash' => ['old' => ['status'], 'new' => []]])
            ->get('/wpisy/'.$pytanie->getKey())
            ->assertRedirect();
        $this->get('/pytania/'.$pytanie->getKey())->assertOk()->assertSee('Odpowiedź zapisana.');
    }

    public function test_zwykly_wpis_zostaje_pod_swoim_adresem_i_nie_udaje_pytania(): void
    {
        $wpis = Post::factory()->create(['body' => 'Pierogi z kaszą']);

        $this->get('/wpisy/'.$wpis->getKey())->assertOk()->assertSee('Pierogi z kaszą');
        $this->get('/pytania/'.$wpis->getKey())->assertNotFound();
    }

    public function test_przekierowanie_nie_zdradza_pytan_niedostepnych(): void
    {
        $prywatne = Post::factory()->question()->private()->create();
        $szkic = Post::factory()->question()->draft()->create();

        $this->get('/wpisy/'.$prywatne->getKey())->assertForbidden();
        $this->get('/wpisy/'.$szkic->getKey())->assertForbidden();

        config(['kuking.questions.enabled' => false]);
        $publiczne = Post::factory()->question()->create();
        $this->get('/wpisy/'.$publiczne->getKey())->assertForbidden();
    }

    public function test_mapa_oglasza_pytania_tylko_pod_ich_wlasnym_adresem(): void
    {
        $pytanie = Post::factory()->question()->create(['body' => 'Rozwinięcie pytania']);
        $samTytul = Post::factory()->question()->create(['body' => null]);
        $wpis = Post::factory()->create(['body' => 'Zwykły wpis z treścią']);

        $adresy = $this->adresyZMapy();

        $this->assertContains(route('questions.show', $pytanie), $adresy);
        $this->assertContains(route('questions.show', $samTytul), $adresy);
        $this->assertNotContains(route('posts.show', $pytanie), $adresy);
        $this->assertNotContains(route('posts.show', $samTytul), $adresy);
        $this->assertContains(route('posts.show', $wpis), $adresy);
    }

    public function test_mapa_nie_rozszerza_granicy_zwyklych_wpisow_bez_tresci(): void
    {
        $pusty = Post::factory()->create(['body' => null]);

        $this->assertNotContains(route('posts.show', $pusty), $this->adresyZMapy());
    }

    public function test_mapa_nie_ujawnia_pytan_niedostepnych(): void
    {
        $prywatne = Post::factory()->question()->private()->create(['body' => null]);
        $obserwujacy = Post::factory()->question()->followersOnly()->create(['body' => null]);
        $szkic = Post::factory()->question()->draft()->create(['body' => null]);
        $ukryte = Post::factory()->question()->create(['body' => null, 'status' => Post::STATUS_HIDDEN]);
        $zbanowany = User::factory()->create();
        $zbanowany->forceFill(['status' => User::STATUS_BANNED])->save();
        $autoraNiedostepnego = Post::factory()->question()->create(['body' => null, 'author_id' => $zbanowany->getKey()]);

        $adresy = implode("\n", $this->adresyZMapy());

        foreach ([$prywatne, $obserwujacy, $szkic, $ukryte, $autoraNiedostepnego] as $pytanie) {
            $this->assertStringNotContainsString($pytanie->getKey(), $adresy);
        }
    }

    public function test_mapa_pomija_pytania_przy_wylaczonym_dziale(): void
    {
        config(['kuking.questions.enabled' => false]);
        $pytanie = Post::factory()->question()->create();

        $this->assertStringNotContainsString($pytanie->getKey(), implode("\n", $this->adresyZMapy()));
    }

    /** @return list<string> */
    private function adresyZMapy(): array
    {
        cache()->forget('sitemap.urls');
        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertNotFalse($xml, 'Mapa strony nie jest poprawnym XML-em.');

        $adresy = [];
        foreach ($xml->url as $url) {
            $adresy[] = (string) $url->loc;
        }

        return $adresy;
    }
}
