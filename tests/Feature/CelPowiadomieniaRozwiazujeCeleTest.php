<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\CelPowiadomienia;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #1687, etap 6: rozwiązywanie celów powiadomienia (`pierwszyWpis()`,
 * `wersjaDoPokazania()`, `slugZapisanegoPrzepisu()`) przeszło z modelu
 * `Notification` do `CelPowiadomienia`. Test kontraktowy: przycisk „Zobacz"
 * (`adresDocelowy()`) i treść karty (predykaty modelu) opierają się na tym
 * samym rozstrzygnięciu resolvera — nie mogą się rozjechać.
 */
class CelPowiadomieniaRozwiazujeCeleTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_nie_rozwiazuje_juz_celow_sam(): void
    {
        foreach (['pierwszyWpis', 'wersjaDoPokazania', 'slugZapisanegoPrzepisu'] as $metoda) {
            $this->assertFalse(method_exists(Notification::class, $metoda), "Notification::{$metoda}() wróciła do modelu.");
            $this->assertTrue(method_exists(CelPowiadomienia::class, $metoda), "CelPowiadomienia::{$metoda}() zniknęła.");
        }
    }

    public function test_zapisany_przepis_adres_i_karta_zgadzaja_sie_przed_i_po_usunieciu(): void
    {
        $autor = $this->user('cel_autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $dane = ['recipe_id' => $przepis->getKey()];
        $resolver = app(CelPowiadomienia::class);

        $swieze = fn (): Notification => $this->powiadomienie($autor, Notification::TYPE_SAVED, $dane);

        $this->assertSame($przepis->slug, $resolver->slugZapisanegoPrzepisu($swieze()));
        $this->assertSame(route('recipes.show', $przepis->slug), $swieze()->adresDocelowy());
        $this->assertFalse($swieze()->przepisUsuniety());

        $przepis->delete();

        $this->assertNull($resolver->slugZapisanegoPrzepisu($swieze()));
        $this->assertNull($swieze()->adresDocelowy());
        $this->assertTrue($swieze()->przepisUsuniety());

        // Nie-UUID traktowany jak przepis, którego nie ma (bez błędu SQL).
        $zly = $this->powiadomienie($autor, Notification::TYPE_SAVED, ['recipe_id' => 'nie-uuid']);
        $this->assertNull($resolver->slugZapisanegoPrzepisu($zly));
        $this->assertTrue($zly->przepisUsuniety());
    }

    public function test_wynik_zbiorczy_z_listy_ma_pierwszenstwo_i_nie_pyta_bazy(): void
    {
        $autor = $this->user('cel_cache');
        $n = $this->powiadomienie($autor, Notification::TYPE_SAVED, ['recipe_id' => (string) Str::uuid()]);
        $n->zapamietajSlugPrzepisu('podany-z-listy');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $slug = app(CelPowiadomienia::class)->slugZapisanegoPrzepisu($n);
        $adres = $n->adresDocelowy();
        DB::disableQueryLog();

        $this->assertSame('podany-z-listy', $slug);
        $this->assertSame(route('recipes.show', 'podany-z-listy'), $adres);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_wersja_do_pokazania_zgadza_sie_z_adresem_i_policy(): void
    {
        $autor = $this->user('cel_fork_autor');
        $forker = $this->user('cel_forker');
        $wersja = Recipe::factory()->create(['author_id' => $forker->getKey()]);
        $resolver = app(CelPowiadomienia::class);
        $swieze = fn (): Notification => $this->powiadomienie($autor, Notification::TYPE_FORKED, ['fork_id' => $wersja->getKey()]);

        $this->assertSame($wersja->getKey(), $resolver->wersjaDoPokazania($swieze())?->getKey());
        $this->assertSame($wersja->url(), $swieze()->adresDocelowy());
        $this->assertTrue($swieze()->wersjaDostepna());

        // Inny typ powiadomienia nigdy nie ma wersji do pokazania (wersja istnieje).
        $inne = $this->powiadomienie($autor, Notification::TYPE_SAVED, ['fork_id' => $wersja->getKey()]);
        $this->assertNull($resolver->wersjaDoPokazania($inne));

        $wersja->delete();

        $this->assertNull($resolver->wersjaDoPokazania($swieze()));
        $this->assertNull($swieze()->adresDocelowy());
        $this->assertFalse($swieze()->wersjaDostepna());
    }

    public function test_pierwszy_wpis_zgadza_sie_z_adresem_i_predykatem_niedostepnosci(): void
    {
        $gospodarz = $this->user('cel_gospodarz');
        $nowa = $this->user('cel_nowa');
        $wpis = Post::factory()->create(['author_id' => $nowa->getKey()]);
        $resolver = app(CelPowiadomienia::class);
        $swieze = fn (): Notification => $this->powiadomienie($gospodarz, Notification::TYPE_FIRST_POST, ['post_id' => $wpis->getKey()]);

        $this->assertSame($wpis->getKey(), $resolver->pierwszyWpis($swieze())?->getKey());
        $this->assertSame(route('posts.show', $wpis), $swieze()->adresDocelowy());
        $this->assertFalse($swieze()->pierwszyWpisNiedostepny());

        $wpis->delete();

        $this->assertNull($resolver->pierwszyWpis($swieze()));
        $this->assertTrue($swieze()->pierwszyWpisNiedostepny());
        $this->assertSame(route('admin.unanswered'), $swieze()->adresDocelowy());

        // Inny typ albo brak post_id — nigdy wpis.
        $this->assertNull($resolver->pierwszyWpis($this->powiadomienie($gospodarz, Notification::TYPE_SAVED, ['post_id' => $wpis->getKey()])));
        $this->assertNull($resolver->pierwszyWpis($this->powiadomienie($gospodarz, Notification::TYPE_FIRST_POST, [])));
    }

    private function powiadomienie(User $odbiorca, string $typ, array $dane): Notification
    {
        $n = Notification::query()->create([
            'user_id' => $odbiorca->getKey(),
            'type' => $typ,
            'data' => $dane,
        ]);

        return Notification::query()->with('user')->findOrFail($n->getKey());
    }
}
