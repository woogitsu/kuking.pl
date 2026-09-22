<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Karta zeszytu na liście „Twój zeszyt" liczyła co innego niż wnętrze tego
 * samego zeszytu (issue #774): prywatna treść wliczała się w OBIE liczby,
 * a treść usunięta miękko wypadała tylko z karty (SoftDeletes dokłada
 * globalny zakres, którego `show()` świadomie omija przez `withTrashed()`,
 * a `index()` nie omijał wcale).
 *
 * PO NAPRAWIE karta liczy WIDOCZNE zapisy — dokładnie tyle, ile będzie
 * widać po wejściu — a różnicę wobec wszystkich zachowanych zapisów
 * (prywatne, zablokowani autorzy, konta zamknięte, treść usunięta miękko)
 * nazywa osobnym zdaniem, tym samym wzorcem co `ZeszytNiedostepneZapisyTest`
 * wewnątrz zeszytu.
 */
final class LicznikKartyZeszytuSpojnyTest extends TestCase
{
    use RefreshDatabase;

    public function test_prywatna_tresc_i_usunieta_miekko_licza_sie_tak_samo_na_karcie(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');

        $prywatny = $this->recipe($autor, 'private');
        $usuniety = $this->recipe($autor, 'public');
        $widoczny = $this->recipe($autor, 'public');

        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Testowy', 'visibility' => 'private']);
        $zeszyt->recipes()->attach([$prywatny->getKey(), $usuniety->getKey(), $widoczny->getKey()]);
        $usuniety->delete();

        $html = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk()->getContent());

        // Widoczny jest TYLKO jeden przepis — karta ma to powiedzieć wprost,
        // nie „3 przepisy" jak przed naprawą.
        $this->assertStringContainsString('1 przepis', $html);
        $this->assertStringNotContainsString('3 przepis', $html);

        // Prywatna treść i treść usunięta miękko liczą się RAZEM, tym samym
        // zdaniem co wewnątrz zeszytu (ZeszytNiedostepneZapisyTest).
        $this->assertStringContainsString('2 zapisy nie są dla Ciebie dostępne', $html);

        // Zeszyt wewnątrz zgadza się z kartą: tyle samo widocznych, tyle
        // samo niedostępnych.
        $wnetrze = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.show', $zeszyt))->assertOk()->getContent());
        $this->assertStringContainsString($widoczny->title, $wnetrze);
        $this->assertStringNotContainsString($prywatny->title, $wnetrze);
        $this->assertStringContainsString('2 zapisy nie są dla Ciebie dostępne', $wnetrze);
    }

    /** Ujednolica białe znaki, żeby wcięcia widoku nie psuły porównania tekstu. */
    private function tekst(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', $html));
    }

    public function test_pusty_niedostepny_zeszyt_nie_pokazuje_zerowej_niedostepnosci(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $widoczny = $this->recipe($autor, 'public');

        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Bez niedostepnych', 'visibility' => 'private']);
        $zeszyt->recipes()->attach($widoczny->getKey());

        $html = $this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('dla Ciebie dostępn', $html);
    }

    public function test_liczenie_nie_dodaje_zapytania_na_kazda_karte(): void
    {
        $malo = $this->user('malo');
        $this->wyposaz($malo, 2);

        $duzo = $this->user('duzo');
        $this->wyposaz($duzo, 20);

        $maloZapytan = $this->policzZapytania(fn () => $this->actingAs($malo)->get(route('collections.index'))->assertOk());
        $duzoZapytan = $this->policzZapytania(fn () => $this->actingAs($duzo)->get(route('collections.index'))->assertOk());

        $this->assertSame($maloZapytan, $duzoZapytan, 'Liczba zapytań rośnie z liczbą zeszytów — wachlarz zapytań na kartę.');
    }

    private function wyposaz(User $wlasciciel, int $ileZeszytow): void
    {
        for ($i = 0; $i < $ileZeszytow; $i++) {
            $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => "Zeszyt {$i}", 'visibility' => 'private']);
            $autor = $this->user();
            $zeszyt->recipes()->attach($this->recipe($autor, 'private')->getKey());
            $zeszyt->posts()->attach(Post::factory()->create(['author_id' => $autor->getKey()])->getKey());
        }
    }

    private function recipe(User $author, string $visibility): Recipe
    {
        return Recipe::factory()->create(['author_id' => $author->getKey(), 'visibility' => $visibility]);
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }
}
