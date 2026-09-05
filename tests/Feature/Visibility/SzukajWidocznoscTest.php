<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Droga 3 wycieku: WYSZUKIWARKA (issue #41).
 *
 * Wyszukiwarka to osobne zapytanie z własnymi filtrami. Policy jej nie dotyczy,
 * filtry listy profilu jej nie dotyczą. Jeśli treść ma nie być widoczna, musi
 * to być powiedziane wyszukiwarce OSOBNO — i to jest ta droga, którą blokada
 * przecieka najczęściej, bo o wyszukiwarce myśli się na końcu.
 */
class SzukajWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private function przepis(string $widocznosc, string $tytul): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
        ]);
    }

    private function szukaj(?User $widz, string $fraza, string $sekcja = 'przepisy'): TestResponse
    {
        $adres = route('search').'?q='.urlencode($fraza).'&sekcja='.$sekcja;

        if ($widz === null) {
            Auth::logout();

            return $this->get($adres);
        }

        return $this->actingAs($widz)->get($adres);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->autor = $this->user('autorka');
    }

    public function test_wyszukiwarka_nie_pokazuje_przepisow_prywatnych(): void
    {
        $this->przepis('private', 'Sekretny bigos');

        $this->szukaj($this->user('obca'), 'bigos')->assertOk()->assertDontSee('Sekretny bigos');
        $this->szukaj(null, 'bigos')->assertOk()->assertDontSee('Sekretny bigos');
    }

    public function test_wyszukiwarka_nie_pokazuje_przepisow_dla_obserwujacych(): void
    {
        $this->przepis('followers', 'Bigos dla swoich');

        // Nawet obserwujący go tu nie zobaczy: wyszukiwarka pokazuje wyłącznie
        // treści publiczne. To jest świadome zawężenie, nie błąd — lepiej nie
        // pokazać za mało niż za dużo.
        $this->szukaj($this->user('obca'), 'bigos')->assertOk()->assertDontSee('Bigos dla swoich');
        $this->szukaj(null, 'bigos')->assertOk()->assertDontSee('Bigos dla swoich');
    }

    public function test_wyszukiwarka_pokazuje_przepisy_publiczne(): void
    {
        $this->przepis('public', 'Zwykly bigos');

        $this->szukaj($this->user('obca'), 'bigos')->assertOk()->assertSee('Zwykly bigos');
        $this->szukaj(null, 'bigos')->assertOk()->assertSee('Zwykly bigos');
    }

    // -----------------------------------------------------------------
    // Blokada — droga, którą przecieka najczęściej
    // -----------------------------------------------------------------

    public function test_wyszukiwarka_nie_pokazuje_przepisow_osoby_zablokowanej(): void
    {
        $czytelnik = $this->user('czytelniczka');
        app(BlockUser::class)->handle($czytelnik, $this->autor);

        $this->przepis('public', 'Bigos od zablokowanej');

        // Blokada ma znaczyć „nie chcę tej osoby widzieć", a nie „nie zobaczę
        // jej tylko wtedy, gdy nie użyję wyszukiwarki".
        $this->szukaj($czytelnik, 'bigos')->assertOk()->assertDontSee('Bigos od zablokowanej');
    }

    public function test_wyszukiwarka_nie_pokazuje_przepisow_gdy_to_autor_zablokowal(): void
    {
        $czytelnik = $this->user('czytelniczka');
        app(BlockUser::class)->handle($this->autor, $czytelnik);

        $this->przepis('public', 'Bigos autorki');

        // Blokada działa w obie strony (AGENTS.md §4).
        $this->szukaj($czytelnik, 'bigos')->assertOk()->assertDontSee('Bigos autorki');
    }

    public function test_wyszukiwarka_ludzi_nie_pokazuje_zablokowanych(): void
    {
        $czytelnik = $this->user('czytelniczka');
        app(BlockUser::class)->handle($czytelnik, $this->autor);

        $this->szukaj($czytelnik, 'autorka', 'ludzie')->assertOk()->assertDontSee('@autorka');
    }
}
