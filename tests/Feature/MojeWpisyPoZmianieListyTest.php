<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MojeWpisyPoZmianieListyTest extends TestCase
{
    use RefreshDatabase;

    private function wpisy(User $autor, int $ile): Post
    {
        $ostatni = null;
        for ($i = 0; $i < $ile; $i++) {
            $ostatni = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'body' => 'Własny wpis '.$i,
                'published_at' => now()->subMinutes($i),
            ]);
        }

        if ($ostatni === null) {
            throw new \InvalidArgumentException('Fixture wymaga co najmniej jednego wpisu.');
        }

        return $ostatni;
    }

    public function test_stara_druga_strona_po_usunieciu_wraca_do_istniejacych_wpisow(): void
    {
        $autor = $this->user('autorka');
        $ostatni = $this->wpisy($autor, 21);
        $this->actingAs($autor)->get(route('collections.own-posts', ['page' => 2]))
            ->assertOk()->assertSee('Własny wpis 20');

        $ostatni->delete();
        $stara = $this->get(route('collections.own-posts', ['page' => 2]));
        $this->assertSame(302, $stara->getStatusCode(), 'MOJE_WPISY_2473_STARA_STRONA_WRACA');
        $stara->assertRedirect(route('collections.own-posts'));
        $docelowa = $this->get(route('collections.own-posts'))->assertOk();
        $docelowa->assertSee('Własny wpis 0')->assertDontSee('Nie masz jeszcze żadnego wpisu');
        $this->assertCount(20, $docelowa->viewData('wpisy')->items());
    }

    public function test_daleka_strona_wraca_do_ostatniej_a_nie_zawsze_pierwszej(): void
    {
        $autor = $this->user('autorka');
        $this->wpisy($autor, 41);
        $adres = route('collections.own-posts', ['page' => 3]);
        $this->actingAs($autor)->get(route('collections.own-posts', ['page' => 999]))->assertRedirect($adres);
        $odpowiedz = $this->get($adres)->assertOk()->assertSee('Własny wpis 40');
        $this->assertCount(1, $odpowiedz->viewData('wpisy')->items());
    }

    public function test_wylaczenie_pytan_koryguje_zakres_po_filtrze(): void
    {
        config(['kuking.questions.enabled' => true]);
        $autor = $this->user('autorka');
        $this->wpisy($autor, 20);
        Post::factory()->question()->create(['author_id' => $autor->getKey(), 'title' => 'Wyłączone pytanie']);
        $this->actingAs($autor)->get(route('collections.own-posts', ['page' => 2]))->assertOk();

        config(['kuking.questions.enabled' => false]);
        $this->get(route('collections.own-posts', ['page' => 2]))->assertRedirect(route('collections.own-posts'));
        $odpowiedz = $this->get(route('collections.own-posts'))->assertOk()->assertDontSee('Wyłączone pytanie');
        $this->assertCount(20, $odpowiedz->viewData('wpisy')->items());
    }

    public function test_pusta_lista_ma_prawdziwy_pusty_stan_i_kontener_bez_petli(): void
    {
        $this->actingAs($this->user('nowa'))->get(route('collections.own-posts', ['page' => 999]))
            ->assertRedirect(route('collections.own-posts'));
        $odpowiedz = $this->get(route('collections.own-posts'))->assertOk()
            ->assertSee('Nie masz jeszcze żadnego wpisu');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.(string) $odpowiedz->getContent());
        $xpath = new \DOMXPath($dom);
        $listy = $xpath->query('//*[@id="lista-moich-wpisow"]');
        $karty = $xpath->query('//*[@id="lista-moich-wpisow"]/*[@data-klucz]');
        $this->assertInstanceOf(\DOMNodeList::class, $listy);
        $this->assertInstanceOf(\DOMNodeList::class, $karty);
        $this->assertSame(1, $listy->length,
            'MOJE_WPISY_2473_PUSTA_PORCJA_MA_LISTE');
        $this->assertSame(0, $karty->length);
        $odpowiedz->assertDontSee('Następna strona wpisów');
    }

    public function test_pobranie_pokaz_wiecej_po_usunieciu_nie_zmienia_zakresu_autora(): void
    {
        $autor = $this->user('autorka');
        $ostatni = $this->wpisy($autor, 21);
        Post::factory()->private()->create(['body' => 'Cudza prywatna treść']);
        $ostatni->delete();
        $stara = $this->actingAs($autor)->get(route('collections.own-posts', ['page' => 2]), ['Accept' => 'text/html']);
        $stara->assertRedirect(route('collections.own-posts'));
        $this->get(route('collections.own-posts'), ['Accept' => 'text/html'])->assertOk()
            ->assertSee('id="lista-moich-wpisow"', false)
            ->assertDontSee('Cudza prywatna treść')->assertDontSee('Następna strona wpisów');
    }
}
