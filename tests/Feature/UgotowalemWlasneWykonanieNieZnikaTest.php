<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Własne „Ugotowałem" nie znika, gdy CUDZY przepis zmienia stan
 * (audyt „Ugotowałem", punkt o soft delete i ukryciu przez moderację).
 *
 * TA SAMA KLASA BŁĘDU, TYLKO W DRUGĄ STRONĘ: nie lista pokazuje za dużo,
 * a polityka odmawia tego, co lista obiecuje.
 *
 * `CookedEventPolicy::view()` miała furtkę dla właściciela wykonania TYLKO
 * wtedy, gdy przepis był SKASOWANY miękko (`$event->recipe === null`, audyt
 * A23). Przepis UKRYTY przez moderację leci innym torem — prosto do
 * `RecipePolicy::view()`, która przy nieopublikowanym przepisie wpuszcza
 * wyłącznie jego AUTORA. Kucharz autorem przepisu nie jest, więc tracił
 * dostęp do własnego zdjęcia, notatki i czasu.
 *
 * ZMIERZONE PRZED POPRAWKĄ:
 *   - wykonanie pod przepisem SKASOWANYM: właściciel dostaje 200,
 *   - wykonanie pod przepisem UKRYTYM: właściciel dostaje 403,
 *   - a jego własny profil, zakładka „Ugotowane", NADAL to wykonanie
 *     wymieniał (`ProfileController` przepuszcza właściciela bez filtra).
 *     Lista i polityka odpowiadały na to samo pytanie inaczej — dokładnie
 *     ten rozjazd, którego ten audyt szuka.
 *
 * Rozstrzygnięcie: rację ma LISTA. Notatka, czas i zdjęcie należą do
 * kucharza; decyzja moderacyjna wobec CUDZEGO przepisu nie ma prawa mu ich
 * odebrać — choćby dlatego, że musi móc je skasować („poprawne dane nigdy
 * nie znikają", AGENTS.md).
 */
class UgotowalemWlasneWykonanieNieZnikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_kucharz_wchodzi_na_wlasne_wykonanie_pod_przepisem_ukrytym_przez_moderacje(): void
    {
        $autorPrzepisu = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'visibility' => 'public',
            'slug' => 'rosol-ukryty',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Moja wlasna notatka z gotowania.',
        ]);

        // KONTROLA: przed decyzją moderacyjną wykonanie jest dostępne.
        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))->assertOk();

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Moja wlasna notatka z gotowania');

        // KONTROLA W DRUGĄ STRONĘ: to nie może stać się otwartą furtką.
        // Obca osoba po ukryciu przepisu wykonania NIE widzi.
        $this->actingAs($this->user('obca'))
            ->get(route('cooked.show', $wykonanie))
            ->assertForbidden();

        // Gość również nie.
        $this->get(route('cooked.show', $wykonanie))->assertForbidden();
    }

    public function test_kucharz_wchodzi_na_wlasne_wykonanie_pod_przepisem_skasowanym(): void
    {
        $autorPrzepisu = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'visibility' => 'public',
            'slug' => 'rosol-skasowany',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Notatka pod skasowanym przepisem.',
        ]);

        $przepis->delete();

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Notatka pod skasowanym przepisem');

        $this->actingAs($this->user('obca'))
            ->get(route('cooked.show', $wykonanie))
            ->assertForbidden();
    }

    /**
     * Lista i polityka MUSZĄ odpowiadać tak samo — to jest reguła, nie
     * pojedynczy przypadek. Zakładka „Ugotowane" na własnym profilu
     * wymienia wykonanie; kliknięcie w nie nie może dać 403.
     */
    public function test_zakladka_ugotowane_i_adres_wykonania_odpowiadaja_tak_samo(): void
    {
        $autorPrzepisu = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'visibility' => 'public',
            'slug' => 'rosol-do-zakladki',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Notatka widoczna w zakladce.',
        ]);

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $zakladka = $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk();

        $naLiscie = str_contains($zakladka->getContent(), (string) $wykonanie->getKey());

        $status = $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))->getStatusCode();

        $this->assertSame(
            $naLiscie,
            $status === 200,
            'Zakładka „Ugotowane" i adres wykonania odpowiadają na to samo pytanie '
            .'inaczej: na liście jest '.($naLiscie ? 'TAK' : 'NIE')
            .", a pod adresem HTTP {$status}.",
        );

        // Kontrola, że test nie przechodzi na dwóch fałszach naraz
        // („nie ma na liście i daje 403" też spełniłoby asercję wyżej).
        $this->assertTrue($naLiscie, 'Wykonania nie ma na własnej liście — test sprawdza co innego.');
    }

    /**
     * Własne wykonanie otwiera się kucharzowi mimo blokady z autorem przepisu,
     * ale bez tytułu i adresu przepisu (D-259).
     *
     * Karta wykonania renderuje tytuł i adres przepisu, czyli treść AUTORA
     * PRZEPISU. Blokada ma pierwszeństwo (`AGENTS.md` §4) wobec TEJ treści,
     * więc przy blokadzie z autorem nie może się ona pokazać. Zdjęcie
     * i notatka są treścią kucharza i zostają dla niego dostępne.
     *
     * ZMIANA ROZSTRZYGNIĘCIA (issue #1394): wcześniej ten test żądał 403 dla
     * kucharza. Skutek — własna zakładka „Ugotowane" obiecywała przycisk,
     * który kończył się odmową, a kucharz tracił dostęp do własnego zdjęcia.
     * Teraz kucharz wchodzi (200), ale tytułu ani adresu przepisu nie widzi.
     * Pełna macierz: `WykonaniePoBlokadzieAutoraPrzepisuTest`.
     */
    public function test_wlasne_wykonanie_po_blokadzie_z_autorem_przepisu_otwiera_sie_bez_przepisu(): void
    {
        $autorPrzepisu = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'visibility' => 'public',
            'slug' => 'rosol-z-blokada',
            'title' => 'Rosół zablokowanej autorki',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Notatka kucharza przy blokadzie.',
        ]);

        // KONTROLA: przed blokadą kucharz widzi tytuł przepisu.
        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Rosół zablokowanej autorki');

        app(BlockUser::class)->handle($kucharz, $autorPrzepisu);

        $this->actingAs($kucharz->refresh())
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Notatka kucharza przy blokadzie.')
            ->assertDontSee('Rosół zablokowanej autorki')
            ->assertDontSee('rosol-z-blokada');

        // I w drugą stronę — to autor przepisu blokuje kucharza.
        $drugiKucharz = $this->user('drugikucharz');
        $drugieWykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $drugiKucharz->getKey(),
        ]);

        $this->actingAs($drugiKucharz)->get(route('cooked.show', $drugieWykonanie))
            ->assertOk()
            ->assertSee('Rosół zablokowanej autorki');

        app(BlockUser::class)->handle($autorPrzepisu, $drugiKucharz);

        $this->actingAs($drugiKucharz->refresh())
            ->get(route('cooked.show', $drugieWykonanie))
            ->assertOk()
            ->assertDontSee('Rosół zablokowanej autorki')
            ->assertDontSee('rosol-z-blokada');
    }
}
