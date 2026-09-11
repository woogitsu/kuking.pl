<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\HeroKolaz;
use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran `/admin/kolaz-powitalny` — wybór zdjęć do kolażu w hero.
 *
 * Ten plik pilnuje trzech rzeczy, które łatwo zepsuć niezależnie od siebie:
 *
 *  1. KTO w ogóle wchodzi (Policy, nie sam adres — UUID nie jest
 *     autoryzacją, AGENTS.md §7),
 *  2. CO widać na liście do wyboru (tylko publiczne, opublikowane,
 *     od kont aktywnych),
 *  3. CO da się zapisać (ta sama granica, egzekwowana drugi raz po stronie
 *     zapisu — bo formularz przychodzi od klienta i nie musi pochodzić
 *     z naszej listy).
 *
 * Granica WYŚWIETLENIA (co wypada z gotowego wyboru, gdy wpis zmieni stan)
 * ma własny plik: `KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest`.
 */
class PanelKolazuPowitalnegoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Post, 1: Media} */
    private function wpisZeZdjeciem(User $autor, array $atrybuty = []): array
    {
        $wpis = Post::factory()->create(array_merge([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(random_int(1, 500)),
        ], $atrybuty));

        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$wpis->refresh(), $zdjecie];
    }

    // ---------------------------------------------------------------------
    // 1. Kto wchodzi
    // ---------------------------------------------------------------------

    public function test_gosc_nie_wchodzi_na_ekran_kolazu(): void
    {
        $this->get('/admin/kolaz-powitalny')->assertRedirect(route('login'));
    }

    /**
     * 404, NIE 403 — i to jest celowe. `EnsureUserIsModerator` odpowiada
     * „nie ma takiej strony", bo 403 potwierdziłoby komuś z zewnątrz, że
     * panel pod tym adresem istnieje. Ten sam wzorzec co przy zdjęciach
     * (`MediaController`: „odmowa to 404, nie 403").
     */
    public function test_zwykle_konto_nie_wchodzi_na_ekran_kolazu(): void
    {
        $this->actingAs($this->user('zwykly'))
            ->get('/admin/kolaz-powitalny')
            ->assertNotFound();
    }

    public function test_zwykle_konto_nie_zapisze_kolazu_nawet_wprost_zadaniem_put(): void
    {
        $autor = $this->user('autor');
        [, $zdjecie] = $this->wpisZeZdjeciem($autor);

        $this->actingAs($this->user('napastnik'))
            ->put('/admin/kolaz-powitalny', ['zdjecia' => [$zdjecie->getKey()]])
            ->assertNotFound();

        $this->assertSame(0, HeroPick::query()->count());
    }

    public function test_gospodarz_wchodzi_i_widzi_swoj_ekran(): void
    {
        $autor = $this->user('autor');
        $this->wpisZeZdjeciem($autor);

        $this->actingAs($this->moderator())
            ->get(route('admin.hero-kolaz'))
            ->assertOk()
            ->assertSee('Kolaż na powitanie')
            ->assertSee('Zdjęcia do wyboru');
    }

    // ---------------------------------------------------------------------
    // 2. Co widać na liście
    // ---------------------------------------------------------------------

    public function test_lista_do_wyboru_pomija_zdjecia_ktorych_nie_wolno_pokazac(): void
    {
        $autor = $this->user('autor');

        [, $publiczne] = $this->wpisZeZdjeciem($autor);
        [, $prywatne] = $this->wpisZeZdjeciem($autor, ['visibility' => Post::VISIBILITY_PRIVATE]);
        [, $dlaObserwujacych] = $this->wpisZeZdjeciem($autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        [, $schowane] = $this->wpisZeZdjeciem($autor, ['status' => Post::STATUS_HIDDEN]);

        $zawieszony = $this->user('zawieszony');
        [, $odZawieszonego] = $this->wpisZeZdjeciem($zawieszony);
        $zawieszony->suspend();

        $html = (string) $this->actingAs($this->moderator())
            ->get(route('admin.hero-kolaz'))->assertOk()->getContent();

        // KONTROLA DODATNIA. Bez niej ten test przechodziłby także wtedy,
        // gdyby ekran nie pokazywał NICZEGO — a wtedy nie sprawdzałby niczego
        // (pułapka 2 z `docs/PULAPKI_TESTOW.md`).
        $this->assertStringContainsString((string) $publiczne->getKey(), $html);

        foreach ([$prywatne, $dlaObserwujacych, $schowane, $odZawieszonego] as $zdjecie) {
            $this->assertStringNotContainsString(
                (string) $zdjecie->getKey(),
                $html,
                'Na liście wyboru pojawiło się zdjęcie, którego nie wolno pokazać nieznajomemu.',
            );
        }
    }

    public function test_ekran_mowi_gospodarzowi_czego_dotyczy_ten_wybor(): void
    {
        $html = (string) $this->actingAs($this->moderator())
            ->get(route('admin.hero-kolaz'))->assertOk()->getContent();

        // Zdanie o użyciu promocyjnym stoi na ekranie, dopóki licencja UGC
        // nie jest rozstrzygnięta — patrz komentarz w widoku i raport z PR-a.
        $this->assertStringContainsString('jako zachętę do rejestracji', $html);
        $this->assertStringContainsString('nie na użycie promocyjne', $html);
    }

    // ---------------------------------------------------------------------
    // 3. Co da się zapisać
    // ---------------------------------------------------------------------

    public function test_gospodarz_zapisuje_i_czysci_wybor(): void
    {
        $autor = $this->user('autor');
        [$wpis, $zdjecie] = $this->wpisZeZdjeciem($autor);

        $gospodarz = $this->moderator();

        $this->actingAs($gospodarz)
            ->put(route('admin.hero-kolaz'), ['zdjecia' => [$zdjecie->getKey()]])
            ->assertRedirect();

        $this->assertSame(1, HeroPick::query()->count());

        $pozycja = HeroPick::query()->first();
        $this->assertSame((string) $zdjecie->getKey(), (string) $pozycja->media_id);
        $this->assertSame((string) $wpis->getKey(), (string) $pozycja->post_id);
        $this->assertSame((string) $gospodarz->getKey(), (string) $pozycja->curator_id);

        $this->actingAs($gospodarz)->delete(route('admin.hero-kolaz'))->assertRedirect();
        $this->assertSame(0, HeroPick::query()->count());
    }

    /**
     * NAJWAŻNIEJSZY TEST TEGO PLIKU. Formularz przychodzi od klienta i nie
     * musi pochodzić z naszej listy — ktoś, kto ma dostęp do panelu, może
     * wpisać w żądanie dowolny UUID. Bramka zapisu ma to odrzucić bez
     * względu na to, co pokazywał ekran.
     */
    public function test_zapis_odrzuca_zdjecie_z_wpisu_niepublicznego_mimo_ze_przyszlo_w_formularzu(): void
    {
        $autor = $this->user('autor');
        [, $publiczne] = $this->wpisZeZdjeciem($autor);
        [, $prywatne] = $this->wpisZeZdjeciem($autor, ['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->actingAs($this->moderator())
            ->put(route('admin.hero-kolaz'), [
                'zdjecia' => [$publiczne->getKey(), $prywatne->getKey()],
            ])->assertRedirect();

        $zapisane = HeroPick::query()->pluck('media_id')->map(fn ($id) => (string) $id)->all();

        $this->assertSame([(string) $publiczne->getKey()], $zapisane);
    }

    public function test_ponowny_zapis_zastepuje_wybor_a_nie_doklada(): void
    {
        $autor = $this->user('autor');
        [, $pierwsze] = $this->wpisZeZdjeciem($autor);
        [, $drugie] = $this->wpisZeZdjeciem($autor);

        $gospodarz = $this->moderator();

        $this->actingAs($gospodarz)->put(route('admin.hero-kolaz'), ['zdjecia' => [$pierwsze->getKey()]]);
        $this->actingAs($gospodarz)->put(route('admin.hero-kolaz'), ['zdjecia' => [$drugie->getKey()]]);

        $this->assertSame(1, HeroPick::query()->count());
        $this->assertSame((string) $drugie->getKey(), (string) HeroPick::query()->first()->media_id);
    }

    public function test_kolaz_przyjmuje_najwyzej_tyle_zdjec_ile_ma_miejsc(): void
    {
        $autor = $this->user('autor');

        $identyfikatory = collect(range(1, HeroKolaz::SLOTOW + 1))
            ->map(fn () => (string) $this->wpisZeZdjeciem($autor)[1]->getKey())
            ->all();

        $this->actingAs($this->moderator())
            ->put(route('admin.hero-kolaz'), ['zdjecia' => $identyfikatory])
            ->assertSessionHasErrors('zdjecia');

        $this->assertSame(0, HeroPick::query()->count());
    }

    public function test_kolejnosc_z_formularza_staje_sie_kolejnoscia_w_kolazu(): void
    {
        $autor = $this->user('autor');
        [, $a] = $this->wpisZeZdjeciem($autor);
        [, $b] = $this->wpisZeZdjeciem($autor);

        $this->actingAs($this->moderator())
            ->put(route('admin.hero-kolaz'), ['zdjecia' => [$b->getKey(), $a->getKey()]]);

        $kolejnosc = HeroPick::query()->orderBy('position')->pluck('media_id')
            ->map(fn ($id) => (string) $id)->all();

        $this->assertSame([(string) $b->getKey(), (string) $a->getKey()], $kolejnosc);
    }
}
