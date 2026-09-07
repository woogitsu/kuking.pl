<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Liczniki pod przepisem nie mogą zdradzać wykonań, których nie wolno pokazać.
 *
 * TA SAMA KLASA BŁĘDU CO ZAMKNIĘTE W7-05 („oracle istnienia")
 * Galeria „Komu wyszło" przechodziła przez `CookedEvent::scopeWidoczneDla()`
 * od audytu A4, ale trzy liczby nad nią — „Ugotowane N ×", „X z Y osób zrobi
 * to ponownie" oraz `userInteractionCount` w JSON-LD — były liczone gołym
 * `count()` na całej relacji. Reguła siedziała więc w jednej warstwie
 * (zapytanie o LISTĘ) i nie było jej w drugiej (zapytanie o LICZBĘ), dziesięć
 * linijek niżej w tym samym pliku.
 *
 * Zmierzone przed poprawką na stronie przepisu z dwoma wykonaniami, z których
 * jedno należało do osoby zablokowanej przez widza:
 *
 *     galeria:  1 karta (poprawnie)
 *     znaczek:  „Ugotowane 2 ×"
 *     JSON-LD:  "userInteractionCount":2
 *
 * Czyli widz, który kogoś zablokował, dowiadywał się z samej strony, że ta
 * osoba ugotowała ten przepis — dokładnie tak, jak licznik obserwujących na
 * profilu zdradzał istnienie konta ukrytego.
 *
 * ROZSTRZYGNIĘCIE: RACJĘ MA GALERIA
 * `CookedEvent::scopeWidoczneDla()` jest granicą tej treści i tak samo pyta
 * o nią `CookedEventPolicy::view`. Licznik jest opisem tej samej listy, więc
 * musi liczyć dokładnie to, co lista pokazuje. Skutek uboczny jest świadomy:
 * liczba jest per widz, tak jak per widz jest już galeria. Dla gościa
 * (`widoczneDla(null)`) nic się nie zmienia, więc dane dla wyszukiwarek
 * zostają takie jak były.
 *
 * ASERCJA KONTROLNA W KAŻDYM TEŚCIE: obok „nie widać ukrytego" stoi konkretna
 * LICZBA, nie samo „mniej niż". Bez tego test przechodziłby też wtedy, gdyby
 * znaczek zniknął z szablonu albo pokazywał zero.
 */
class PrzepisyLicznikiZgadzajaSieZGaleriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_znaczek_ugotowane_liczy_tylko_wykonania_widoczne_dla_widza(): void
    {
        $autor = $this->user('autorka', ['display_name' => 'Autorka Przepisu']);
        $widz = $this->user('widzaca', ['display_name' => 'Widzaca Osoba']);
        $bezSankcji = $this->user('zwykla', ['display_name' => 'Zwykla Kucharka']);
        $zablokowana = $this->user('zablokowana', ['display_name' => 'Zablokowana Kucharka']);

        $przepis = $this->przepis($autor, 'zurek-na-zakwasie');

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $bezSankcji->getKey(),
        ]);
        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $zablokowana->getKey(),
        ]);

        app(BlockUser::class)->handle($widz, $zablokowana);

        $odpowiedz = $this->actingAs($widz)->get('/przepisy/zurek-na-zakwasie');

        $odpowiedz->assertOk();

        // Kontrola: identyczne wykonanie osoby bez sankcji MUSI być widoczne.
        // Bez tej pary asercji test przechodziłby przy pustej galerii.
        $odpowiedz->assertSee('Zwykla Kucharka');
        $odpowiedz->assertDontSee('Zablokowana Kucharka');

        // Kontrolą przy liczniku jest konkretna LICZBA, nie „mniej niż".
        $odpowiedz->assertSee('Ugotowane 1 ×');
        $odpowiedz->assertDontSee('Ugotowane 2 ×');

        // Ta sama liczba idzie do structured data — nieprawda podana Google'owi
        // jest nieprawdą podaną człowiekowi, tylko okrężną drogą.
        $odpowiedz->assertSee('"userInteractionCount":1', false);
        $odpowiedz->assertDontSee('"userInteractionCount":2', false);
    }

    public function test_opinia_zrobia_ponownie_liczy_tylko_wykonania_widoczne_dla_widza(): void
    {
        $autor = $this->user('autorka2');
        $widz = $this->user('widzaca2');
        $zablokowana = $this->user('zablokowana2');

        $przepis = $this->przepis($autor, 'bigos-staropolski');

        // Trzy wykonania widoczne: dwa „zrobię ponownie", jedno „raczej nie".
        // Znaczek pokazuje się od trzech ocen (SOUL 4.2), więc to jest
        // najmniejszy układ, w którym da się go w ogóle zmierzyć.
        foreach ([true, true, false] as $opinia) {
            CookedEvent::factory()->create([
                'recipe_id' => $przepis->getKey(),
                'user_id' => $this->user()->getKey(),
                'would_make_again' => $opinia,
            ]);
        }

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $zablokowana->getKey(),
            'would_make_again' => true,
        ]);

        app(BlockUser::class)->handle($widz, $zablokowana);

        $odpowiedz = $this->actingAs($widz)->get('/przepisy/bigos-staropolski');

        $odpowiedz->assertOk();
        // Kontrola: znaczek NADAL jest na stronie i podaje liczby widocznych
        // wykonań. „2 z 3", nie „3 z 4".
        $odpowiedz->assertSee('2 z 3 osób zrobi to ponownie');
        $odpowiedz->assertDontSee('3 z 4 osób zrobi to ponownie');
    }

    /**
     * Kontrola całości: bez ani jednej blokady liczniki pokazują wszystko.
     *
     * Ten test pilnuje, żeby poprawka nie okazała się „naprawą" polegającą na
     * odcięciu licznika od danych.
     */
    public function test_bez_blokady_liczniki_pokazuja_wszystkie_wykonania(): void
    {
        $autor = $this->user('autorka3');
        $widz = $this->user('widzaca3');

        $przepis = $this->przepis($autor, 'pierogi-ruskie');

        foreach ([true, true, false] as $opinia) {
            CookedEvent::factory()->create([
                'recipe_id' => $przepis->getKey(),
                'user_id' => $this->user()->getKey(),
                'would_make_again' => $opinia,
            ]);
        }

        $odpowiedz = $this->actingAs($widz)->get('/przepisy/pierogi-ruskie');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Ugotowane 3 ×');
        $odpowiedz->assertSee('2 z 3 osób zrobi to ponownie');
        $odpowiedz->assertSee('"userInteractionCount":3', false);
    }

    /** Gość nie ma blokad, więc widzi pełną liczbę — tak jak przed poprawką. */
    public function test_gosc_widzi_pelna_liczbe_wykonan(): void
    {
        $autor = $this->user('autorka4');
        $przepis = $this->przepis($autor, 'kompot-z-rabarbaru');

        CookedEvent::factory()->count(2)->sequence(
            ['user_id' => $this->user()->getKey()],
            ['user_id' => $this->user()->getKey()],
        )->create(['recipe_id' => $przepis->getKey()]);

        $this->get('/przepisy/kompot-z-rabarbaru')
            ->assertOk()
            ->assertSee('Ugotowane 2 ×');
    }

    private function przepis(User $autor, string $slug): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => $slug,
        ]);
    }
}
