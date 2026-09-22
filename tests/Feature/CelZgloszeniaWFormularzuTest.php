<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Formularz zgłoszenia pokazuje cel zgłoszenia (rodzaj i nazwę oraz wycinek
 * treści w cudzysłowie), a nie anonimowy nagłówek „Zgłoś tę treść" (issue #794).
 *
 * Wymagania rozstrzygnięte decyzją właściciela z 20.09.2026:
 * 1. Rodzaj i nazwa celu, np. „komentarz Basi pod przepisem «Rosół babci»".
 * 2. Krótki, obcięty fragment treści w cudzysłowie:
 *    - fragment do 200 znaków, cięty po granicy słowa, nigdy w połowie wyrazu,
 *    - obcięcie sygnalizowane wielokropkiem WEWNĄTRZ cudzysłowu: „...…”,
 *    - treść pusta albo usunięta → sama nazwa, BEZ cudzysłowu.
 * 3. BEZ miniatury i bez awatara.
 * 4. BRAMKA #757: treść ukryta przez `body_removed_at` NIE MA PRAWA się tu
 *    pojawić, nawet jeśli fizycznie istnieje w bazie.
 */
class CelZgloszeniaWFormularzuTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_pokazuje_rodzaj_i_nazwe_celu_oraz_cytat_komentarza(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'visibility' => 'public',
        ]);

        $komentarz = Comment::factory()->create([
            'post_id' => null,
            'recipe_id' => $przepis->getKey(),
            'author_id' => $basia->getKey(),
            'body' => 'Pyszny ten rosół, dodałam lubczyk.',
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk();

        $html = (string) $response->getContent();

        // 1. Nazwa i rodzaj celu
        $this->assertStringContainsString('komentarz Basi pod przepisem «Rosół babci»', $html);

        // 2. Cytat treści w polskich cudzysłowach
        $this->assertStringContainsString('„Pyszny ten rosół, dodałam lubczyk.”', $html);

        // 3. Nagłówek nie jest już pustym „Zgłoś tę treść" bez kontekstu
        $this->assertStringNotContainsString('<h1>Zgłoś tę treść</h1>', $html);

        // 4. Bez awatarów i miniatur celu zgłoszenia
        $this->assertSekcjaCeluNieMaMiniaturyAniAwatara($html);
    }

    private function assertSekcjaCeluNieMaMiniaturyAniAwatara(string $html): void
    {
        $start = strpos($html, '<main');
        $koniec = strpos($html, '<form');
        $sekcjaCelu = substr($html, (int) $start, (int) $koniec - (int) $start);

        $this->assertStringNotContainsString('<img', $sekcjaCelu, 'Sekcja celu nie może zawierać żadnych obrazów ani miniatur.');
        $this->assertStringNotContainsString('avatar', $sekcjaCelu, 'Sekcja celu nie może zawierać awatara.');
    }

    /**
     * Bramka z #757: treść ukryta przez `body_removed_at` NIE MA PRAWA pojawić
     * się w formularzu zgłoszenia. Wiersz w bazie fizycznie istnieje i może
     * mieć stary tekst, ale znacznik odcina treść.
     */
    public function test_komentarz_z_body_removed_at_nie_pokazuje_oryginalnej_tresci_w_formularzu_zgloszenia(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'visibility' => 'public',
        ]);

        $tajnaTresc = 'Bardzo drastyczna treść z usuniętego komentarza.';

        $komentarz = Comment::factory()->create([
            'post_id' => null,
            'recipe_id' => $przepis->getKey(),
            'author_id' => $basia->getKey(),
            'body' => $tajnaTresc,
            'body_removed_at' => now(),
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk();

        $html = (string) $response->getContent();

        // Nazwa celu zostaje widoczna
        $this->assertStringContainsString('komentarz Basi pod przepisem «Rosół babci»', $html);

        // Usunięta treść NIE MA PRAWA się pojawić
        $this->assertStringNotContainsString($tajnaTresc, $html);

        // Treść usunięta → sama nazwa, BEZ cudzysłowu (pusty cudzysłów myli)
        $this->assertStringNotContainsString('„', $html);
        $this->assertStringNotContainsString('”', $html);
    }

    public function test_obciecie_dlugiego_fragmentu_do_200_znakow_po_granicy_slowa_z_wielokropkiem_w_cudzyslowie(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'visibility' => 'public',
        ]);

        // Budujemy tekst, który ma ponad 200 znaków i gdzie granica 200 znaków
        // wypada w środku wyrazu „niepowtarzalny”.
        // Długość: ~250 znaków.
        $poczatek = 'Gotowałam ten rosół dokładnie według wskazówek mojej babci i muszę przyznać że wyszedł absolutnie wyśmienity w smaku oraz cudownie klarowny a zapach unosił się w całym domu sprawiając że każdy domownik miał apetyt';
        $calosc = $poczatek.' niepowtarzalny aromat i smak.';

        $this->assertGreaterThan(200, mb_strlen($calosc));

        $komentarz = Comment::factory()->create([
            'post_id' => null,
            'recipe_id' => $przepis->getKey(),
            'author_id' => $basia->getKey(),
            'body' => $calosc,
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk();

        $html = (string) $response->getContent();

        // Obcięcie sygnalizowane wielokropkiem WEWNĄTRZ cudzysłowu: „...…”
        $this->assertMatchesRegularExpression('/„[^”]+…”/u', $html);

        // Wyciągamy zawartość cudzysłowu
        preg_match('/„([^”]+)…”/u', $html, $matches);
        $this->assertNotEmpty($matches, 'Nie znaleziono fragmentu w cudzysłowie zakończonego wielokropkiem.');

        $wycietyTekst = $matches[1];

        // Fragment nie przekracza 200 znaków
        $this->assertLessThanOrEqual(200, mb_strlen($wycietyTekst));

        // Nigdy nie cięty w połowie wyrazu — każdy wyraz jest pełnym słowem z oryginału
        $wyrazyOryginalne = preg_split('/\s+/u', $calosc, -1, PREG_SPLIT_NO_EMPTY);
        $wyrazyWyciete = preg_split('/\s+/u', $wycietyTekst, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($wyrazyWyciete as $wyraz) {
            $this->assertContains(
                $wyraz,
                $wyrazyOryginalne,
                "Wyraz «{$wyraz}» został ucięty w połowie słowa!",
            );
        }
    }

    public function test_pusta_tresc_pokazuje_sama_nazwe_bez_cudzyslowu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'summary' => null,
            'visibility' => 'public',
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertOk();

        $html = (string) $response->getContent();

        // Sama nazwa jest widoczna
        $this->assertStringContainsString('przepis «Rosół babci»', $html);

        // Bez pustego cudzysłowu
        $this->assertStringNotContainsString('„', $html);
        $this->assertStringNotContainsString('”', $html);
    }

    public function test_formularz_pokazuje_cel_dla_przepisu(): void
    {
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'summary' => 'Tradycyjny niedzielny obiad.',
            'visibility' => 'public',
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('przepis «Rosół babci»', $html);
        $this->assertStringContainsString('„Tradycyjny niedzielny obiad.”', $html);
        $this->assertSekcjaCeluNieMaMiniaturyAniAwatara($html);
    }

    public function test_formularz_pokazuje_cel_dla_ugotowalem(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $przepis = Recipe::factory()->create([
            'title' => 'Rosół babci',
            'visibility' => 'public',
        ]);

        $ugotowanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $basia->getKey(),
            'note' => 'Wyszło super, polecam każdemu!',
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'cooked_event', 'id' => $ugotowanie->getKey()]))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('wykonanie przepisu «Rosół babci»', $html);
        $this->assertStringContainsString('„Wyszło super, polecam każdemu!”', $html);
        $this->assertSekcjaCeluNieMaMiniaturyAniAwatara($html);
    }

    public function test_formularz_pokazuje_cel_dla_profilu_osoby(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $basia->profile()->update(['bio' => 'Gotuję od 40 lat dla całej rodziny.']);

        $widz = $this->user('widz');

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'user', 'id' => 'basia']))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('profil osoby Basia', $html);
        $this->assertStringContainsString('„Gotuję od 40 lat dla całej rodziny.”', $html);
        $this->assertSekcjaCeluNieMaMiniaturyAniAwatara($html);
    }

    public function test_formularz_pokazuje_cel_dla_wpisu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $wpis = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Niedzielny obiad gotowy, zapraszam do stołu.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString('wpis Basi', $html);
        $this->assertStringContainsString('„Niedzielny obiad gotowy, zapraszam do stołu.”', $html);
        $this->assertSekcjaCeluNieMaMiniaturyAniAwatara($html);
    }
}
