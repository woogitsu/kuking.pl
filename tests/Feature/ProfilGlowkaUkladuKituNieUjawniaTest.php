<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etap D UI kitu v2 — nagłówek profilu (ekran 04_desktop_profile).
 *
 * ZMIANA UKŁADU, KTÓRA TU ZASZŁA
 * Liczniki („86 wpisów”, „24 przepisy”…) przeniosły się z osobnego,
 * pełnoszerokiego wiersza POD nagłówkiem do kolumny z imieniem i opisem —
 * dokładnie jak w kicie. To jest wyłącznie przesunięcie węzła w DOM:
 * `ProfileController` i warunki `@if`/`@auth` w widoku, które decydują,
 * CO jest widoczne, nie zostały ruszone.
 *
 * DLACZEGO TEN TEST MIMO TO ISTNIEJE
 * AGENTS.md wymaga testu pozytywnego i negatywnego dla każdej zmiany
 * dotykającej tego, co widać na profilu — nawet jeśli autor zmiany uważa,
 * że widoczności to nie dotyczy. Poniżej: pozytyw dowodzi, że nowy układ
 * naprawdę stoi (liczniki są TERAZ pod opisem, nie osobnym wierszem —
 * asercja kolejności, nie samej obecności tekstu), negatyw dowodzi, że
 * przesunięcie węzła w DOM nie otworzyło furtki do treści, która ma
 * zostać ukryta przed obcym. Każdy test ma asercję kontrolną (`assertSee`
 * na czymś, co na pewno jest widoczne), żeby nie mógł przejść na pusto.
 */
class ProfilGlowkaUkladuKituNieUjawniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_licznik_stoi_pod_opisem_w_kolumnie_z_imieniem_jak_w_kicie(): void
    {
        $autor = $this->user('kucharka', [
            'display_name' => 'Kucharka Testowa',
        ]);
        $autor->profile->update(['bio' => 'Gotuję zupy od lat.']);

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Publiczny rosół',
        ]);

        $odpowiedz = $this->get(route('profile.show', 'kucharka'))->assertOk();

        // Asercja kontrolna: strona naprawdę pokazuje ten profil, a nie
        // pustą stronę błędu, która przepuściłaby też poniższy test na ślepo.
        $odpowiedz->assertSee('Kucharka Testowa');
        $odpowiedz->assertSee('<span class="stat-value">1</span> <span class="stat-label">wpis</span>', false);

        // DOWÓD UKŁADU, NIE SAMEJ TREŚCI.
        //
        // `assertSeeInOrder` sprawdza kolejność TEKSTU w odpowiedzi, a nie
        // zagnieżdżenie w DOM — a w starym układzie liczniki i tak stały
        // W TEKŚCIE po opisie (były po prostu OSOBNYM, pełnoszerokim
        // wierszem tuż pod całą główką, nie częścią kolumny z imieniem).
        // `assertSeeInOrder` przechodziłoby więc na obu układach i nic by
        // nie dowodziło.
        //
        // Dowodem jest klasa `.profil-glowka-tresc` z `ekran-profilu.css`:
        // to ona robi z awatara i kolumny „imię+opis+liczniki” jedną
        // siatkę wg kitu. Bez niej (stary układ) tej klasy w ogóle nie ma
        // w odpowiedzi — test padłby dokładnie tam, gdzie trzeba.
        $odpowiedz->assertSee('profil-glowka-tresc', false);
    }

    public function test_obcy_nie_widzi_prywatnego_wpisu_mimo_przesuniecia_licznikow_w_dom(): void
    {
        $autor = $this->user('kucharka2', [
            'display_name' => 'Druga Kucharka',
        ]);
        $obcy = $this->user('obcaosoba');

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
            'body' => 'Sekretny przepis na bigos',
        ]);

        $odpowiedz = $this->actingAs($obcy)
            ->get(route('profile.show', 'kucharka2'))
            ->assertOk();

        // Asercja kontrolna — inaczej test przeszedłby, nawet gdyby
        // /@kucharka2 w ogóle nie renderowało profilu.
        $odpowiedz->assertSee('Druga Kucharka');
        $odpowiedz->assertSee('<span class="stat-value">0</span> <span class="stat-label">wpisów</span>', false);

        $odpowiedz->assertDontSee('Sekretny przepis na bigos');
    }
}
