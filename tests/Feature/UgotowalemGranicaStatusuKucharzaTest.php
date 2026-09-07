<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Galeria „Komu wyszło" a STATUS KONTA kucharza (audyt „Ugotowałem").
 *
 * CO BYŁO ZEPSUTE
 * `CookedEvent::scopeWidoczneDla()` liczyła wyłącznie blokadę. Wykonanie
 * osoby ZBANOWANEJ stało więc w galerii pod cudzym, publicznym przepisem —
 * ze zdjęciem, notatką, nazwą i awatarem — a link do jej profilu dawał 403.
 * `CookedEventPolicy::celebrate()` tę granicę miała od początku; galeria,
 * czyli powierzchnia, na której serwis SAM podsuwa cudze wykonanie
 * nieznajomemu, nie miała jej wcale.
 *
 * ZMIERZONE PRZED POPRAWKĄ: `$przepis->cookedEvents()->widoczneDla($widz)
 * ->count()` zwracało 2 przy jednym wykonaniu, które wolno pokazać, i strona
 * przepisu renderowała notatkę zbanowanego konta.
 *
 * CO ZOSTAJE OTWARTE I DLACZEGO NIE ZMIENIŁEM TEGO PRZY OKAZJI
 * Sam adres `cooked.show` takiego wykonania dalej odpowiada 200. To NIE jest
 * przeoczenie: przypina to `Visibility\KomusWyszloWidocznoscTest::
 * test_wykonanie_zbanowanego_kucharza_nie_dostaje_celebracji` wraz
 * z uzasadnieniem („treść zostaje, znika tylko wyróżnienie"). Rozjazd między
 * listą i adresem jest tu więc świadomy i w OSTRZEJSZĄ stronę — lista
 * pokazuje mniej. Czy ma taki zostać, jest pytaniem produktowym; zgłoszone
 * właścicielowi, nie rozstrzygnięte w tym pliku.
 */
class UgotowalemGranicaStatusuKucharzaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `suspended` ZOSTAJE — zawieszenie jest karą za pisanie i nie kasuje
     * tego, co ktoś już ugotował. Ten wiersz pilnuje, żeby poprawka nie
     * sięgnęła po węższy, promocyjny próg (`status = active`).
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function statusy(): array
    {
        return [
            'aktywny' => [User::STATUS_ACTIVE, true],
            'zawieszony' => [User::STATUS_SUSPENDED, true],
            'zbanowany' => [User::STATUS_BANNED, false],
            'kasuje konto' => [User::STATUS_PENDING_DELETE, false],
        ];
    }

    /** @return array{0: Recipe, 1: User} */
    private function przepisZDwomaWykonaniami(string $statusDrugiegoKucharza): array
    {
        $autorPrzepisu = $this->user('autorkaprzepisu');
        $bezSankcji = $this->user('bezsankcji');
        $zeStatusem = $this->user('zestatusem');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'visibility' => 'public',
            'title' => 'Rosol na galerie',
            'slug' => 'rosol-na-galerie',
        ]);

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $bezSankcji->getKey(),
            'note' => 'Notatka kontrolna bez sankcji.',
        ]);

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $zeStatusem->getKey(),
            'note' => 'Notatka osoby ze statusem.',
        ]);

        match ($statusDrugiegoKucharza) {
            User::STATUS_ACTIVE => null,
            User::STATUS_SUSPENDED => $zeStatusem->suspend(now()->addWeek()),
            User::STATUS_BANNED => $zeStatusem->ban(),
            User::STATUS_PENDING_DELETE => $zeStatusem->markForDeletion(),
            default => throw new \LogicException('Nieznany status w teście.'),
        };

        return [$przepis, $zeStatusem->refresh()];
    }

    #[DataProvider('statusy')]
    public function test_galeria_pokazuje_wykonanie_dokladnie_wtedy_co_profil_kucharza(
        string $status,
        bool $powinnoByc,
    ): void {
        [$przepis] = $this->przepisZDwomaWykonaniami($status);

        $odpowiedz = $this->actingAs($this->user('widz'))
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk();

        // KONTROLA: wykonanie osoby bez sankcji zostaje w tej samej galerii.
        $odpowiedz->assertSee('Notatka kontrolna bez sankcji');

        if ($powinnoByc) {
            $odpowiedz->assertSee('Notatka osoby ze statusem');
        } else {
            $odpowiedz->assertDontSee('Notatka osoby ze statusem');
        }
    }

    #[DataProvider('statusy')]
    public function test_zapytanie_galerii_zwraca_dokladna_liczbe(
        string $status,
        bool $powinnoByc,
    ): void {
        [$przepis] = $this->przepisZDwomaWykonaniami($status);

        // KONTROLNA LICZBA, nie „mniej niż" — tak samo jak w liczniku
        // komentarzy i w liczniku obserwujących.
        $this->assertSame(
            $powinnoByc ? 2 : 1,
            $przepis->cookedEvents()->widoczneDla($this->user('widz'))->count(),
            'Galeria „Komu wyszło" zwraca inną liczbę wykonań, niż wolno pokazać.',
        );
    }

    #[DataProvider('statusy')]
    public function test_gosc_widzi_dokladnie_to_samo_co_zalogowany(
        string $status,
        bool $powinnoByc,
    ): void {
        [$przepis] = $this->przepisZDwomaWykonaniami($status);

        Auth::logout();

        // Zakres kończył się wcześniej na `return` dla gościa, więc gość nie
        // przechodził przez żaden filtr. Status konta kucharza nie jest
        // relacją dwóch osób — obowiązuje też niezalogowanego.
        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $odpowiedz->assertSee('Notatka kontrolna bez sankcji');

        if ($powinnoByc) {
            $odpowiedz->assertSee('Notatka osoby ze statusem');
        } else {
            $odpowiedz->assertDontSee('Notatka osoby ze statusem');
        }
    }
}
