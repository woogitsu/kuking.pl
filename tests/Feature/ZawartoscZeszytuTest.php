<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zawartość zeszytu a widoczność przepisów (audyt A04).
 *
 * Policy pilnuje dostępu do SAMEGO zeszytu. Nie mówi nic o tym, co jest
 * w środku — a w środku są przepisy wielu różnych autorów, każdy z własną
 * widocznością i własnymi blokadami.
 *
 * To jest czwarta droga wycieku, obok trzech opisanych w #41 (widok, lista,
 * wyszukiwarka). Różni się tym, że treść wycieka przez CUDZY pojemnik:
 * o tym, co widać, decyduje właściciel zeszytu, a nie autor przepisu.
 *
 * Przypadki, w których to boli:
 *
 *   * autor zapisuje własny PRYWATNY przepis do publicznego zeszytu —
 *     i publikuje go w ten sposób, nie mając takiego zamiaru;
 *   * ktoś zapisuje przepis „tylko dla obserwujących" (bo obserwuje), a potem
 *     jego publiczny zeszyt pokazuje go obcym;
 *   * przepis osoby, którą oglądający zablokował, wraca do niego przez cudzy
 *     zeszyt.
 */
class ZawartoscZeszytuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(string $widocznosc, $autor): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Przepis '.$widocznosc,
            'slug' => Str::slug('przepis-'.$widocznosc).'-'.Str::lower(Str::random(6)),
        ]);
    }

    public function test_publiczny_zeszyt_nie_ujawnia_prywatnego_przepisu_wlasciciela(): void
    {
        $wlasciciel = $this->user('wlascicielka');
        $obcy = $this->user('obca');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Na święta',
            'visibility' => 'public',
        ]);

        $prywatny = $this->przepis('private', $wlasciciel);
        $publiczny = $this->przepis('public', $wlasciciel);

        $zeszyt->recipes()->attach([$prywatny->getKey(), $publiczny->getKey()]);

        $odpowiedz = $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertOk();

        $odpowiedz->assertSee($publiczny->title);
        $odpowiedz->assertDontSee(
            $prywatny->title,
        );
    }

    public function test_publiczny_zeszyt_nie_ujawnia_przepisu_tylko_dla_obserwujacych(): void
    {
        $autor = $this->user('autorka');
        $wlasciciel = $this->user('wlascicielka');
        $obcy = $this->user('obca');

        // Właściciel zeszytu obserwuje autora, więc MOŻE zapisać ten przepis.
        app(FollowUser::class)->handle($wlasciciel, $autor);

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Od znajomych',
            'visibility' => 'public',
        ]);

        $dlaObserwujacych = $this->przepis('followers', $autor);
        $zeszyt->recipes()->attach($dlaObserwujacych->getKey());

        // Obcy NIE obserwuje autora — nie ma prawa zobaczyć tego tytułu.
        $this->actingAs($obcy)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertDontSee($dlaObserwujacych->title);
    }

    public function test_zeszyt_nie_przemyca_przepisu_osoby_zablokowanej(): void
    {
        $natret = $this->user('natret');
        $wlasciciel = $this->user('wlascicielka');
        $czytelnik = $this->user('czytelniczka');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zbiór',
            'visibility' => 'public',
        ]);

        $przepis = $this->przepis('public', $natret);
        $zeszyt->recipes()->attach($przepis->getKey());

        app(BlockUser::class)->handle($czytelnik, $natret);

        // Blokada działa w obie strony i nie ma wyjątku „chyba że przez cudzy
        // zeszyt" (AGENTS.md §4).
        $this->actingAs($czytelnik)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertDontSee($przepis->title);
    }

    public function test_wlasciciel_widzi_w_swoim_zeszycie_wszystko_co_zapisal(): void
    {
        $wlasciciel = $this->user('wlascicielka');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Mój zeszyt',
            'visibility' => 'private',
        ]);

        $prywatny = $this->przepis('private', $wlasciciel);
        $zeszyt->recipes()->attach($prywatny->getKey());

        // Filtrowanie nie może zabrać właścicielowi jego własnych treści —
        // „poprawne dane nigdy nie znikają" (AGENTS.md, UX 50+).
        $this->actingAs($wlasciciel)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee($prywatny->title);
    }

    public function test_autor_widzi_wlasny_szkic_w_swoim_zeszycie(): void
    {
        $wlasciciel = $this->user('wlascicielka');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Robocze',
            'visibility' => 'private',
        ]);

        // Pułapka, w którą sam wpadłem przy pisaniu filtra: warunek
        // „opublikowany" nałożony na WSZYSTKICH zabrałby autorowi jego własne
        // niedokończone przepisy. Naprawa prywatności nie może kasować ludziom
        // ich pracy z widoku.
        $szkic = Recipe::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => 'private',
            'status' => Recipe::STATUS_DRAFT,
            'published_at' => null,
            'title' => 'Niedokonczony rosol',
            'slug' => 'niedokonczony-rosol-'.Str::lower(Str::random(6)),
        ]);

        $zeszyt->recipes()->attach($szkic->getKey());

        $this->actingAs($wlasciciel)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee($szkic->title);
    }
}
