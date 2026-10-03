<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #2889: pełny HTTP, z rzeczywistym suspend() i niezmienionymi middleware oraz Policy. */
class ZawieszoneKontoOpuszczaWspolneGotowanieTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function dostepneKonta(): array
    {
        return [
            'aktywne' => ['active'],
            'zawieszone czasowo' => ['timed'],
            'zawieszone bezterminowo' => ['indefinite'],
        ];
    }

    #[DataProvider('dostepneKonta')]
    public function test_pomocnik_wychodzi_i_zostawia_wspolne_dane(string $stan): void
    {
        [$gospodarz, $pomocnik, , $sesja] = $this->sesjaZPelnaHistoria();
        $this->sesjaZPelnaHistoria();
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $pomocnik = $this->ustawStan($pomocnik, $stan);
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->assertTrue(Gate::forUser($pomocnik)->allows('leave', $sesja));
        $przed = $this->pelneDane();

        $odpowiedz = $this->actingAs($pomocnik)->from(route('wspolne-gotowanie.show', $sesja))
            ->delete(route('wspolne-gotowanie.leave', $sesja));

        $this->assertSame(route('home'), $odpowiedz->headers->get('Location'), 'WSPOLNE_2889_HTTP_WYJSCIE');
        $odpowiedz->assertStatus(302)->assertSessionHasNoErrors();
        $po = $this->pelneDane();
        $oczekiwane = $przed;
        $oczekiwane['cooking_session_participants'] = array_values(array_filter(
            $przed['cooking_session_participants'],
            static fn (array $wiersz): bool => $wiersz['session_id'] !== $sesja->getKey() || $wiersz['user_id'] !== $pomocnik->getKey(),
        ));
        foreach ($oczekiwane['cooking_sessions'] as &$wiersz) {
            if ($wiersz['id'] === $sesja->getKey()) {
                $wiersz['revision']++;
                $wiersz['updated_at'] = $sesja->fresh()->getRawOriginal('updated_at');
            }
        }
        unset($wiersz);
        $this->assertSame($oczekiwane, $po, 'Wyjście usuwa tylko własny udział i zwiększa rewizję o jeden; pełne kroki, podpisy, link i druga sesja zostają.');
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
    }

    #[DataProvider('dostepneKonta')]
    public function test_gospodarz_konczy_tylko_wlasna_sesje(string $stan): void
    {
        [$gospodarz, $pomocnik, $recipe, $sesja] = $this->sesjaZPelnaHistoria();
        $this->sesjaZPelnaHistoria();
        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $gospodarz = $this->ustawStan($gospodarz, $stan);
        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->assertTrue(Gate::forUser($gospodarz)->allows('end', $sesja));
        $przed = $this->pelneDane();

        $odpowiedz = $this->actingAs($gospodarz)->from(route('wspolne-gotowanie.show', $sesja))
            ->delete(route('wspolne-gotowanie.destroy', $sesja));

        $this->assertSame(route('cooking.show', $recipe->slug), $odpowiedz->headers->get('Location'), 'WSPOLNE_2889_HTTP_KONIEC');
        $odpowiedz->assertStatus(302)->assertSessionHasNoErrors();
        $oczekiwane = $przed;
        foreach (['cooking_sessions', 'cooking_session_participants', 'cooking_session_steps', 'cooking_session_invitations'] as $tabela) {
            $klucz = $tabela === 'cooking_sessions' ? 'id' : 'session_id';
            $oczekiwane[$tabela] = array_values(array_filter(
                $przed[$tabela],
                static fn (array $wiersz): bool => $wiersz[$klucz] !== $sesja->getKey(),
            ));
        }
        $this->assertSame($oczekiwane, $this->pelneDane(), 'Zakończenie kasuje tylko tę sesję z jej pełnymi danymi potomnymi; przepisy i druga sesja są nietknięte.');
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
    }

    /** @return array<string, array{string, string, string}> */
    public static function cudzyZakres(): array
    {
        $przypadki = [];
        foreach (['active', 'timed', 'indefinite'] as $stan) {
            foreach ([['host', 'leave'], ['helper', 'destroy'], ['foreign', 'leave'], ['foreign', 'destroy']] as [$rola, $akcja]) {
                $przypadki[$stan.' '.$rola.' '.$akcja] = [$stan, $rola, $akcja];
            }
        }

        return $przypadki;
    }

    #[DataProvider('cudzyZakres')]
    public function test_wyjatek_nie_daje_cudzej_roli_ani_dostepu_do_obcej_sesji(string $stan, string $rola, string $akcja): void
    {
        [$gospodarz, $pomocnik, $recipe, $sesja] = $this->sesjaZPelnaHistoria();
        [$obca] = $this->sesjaZPelnaHistoria();
        $osoba = $this->ustawStan(match ($rola) {
            'host' => $gospodarz,
            'helper' => $pomocnik,
            default => $obca,
        }, $stan);
        $przed = $this->pelneDane();

        $odpowiedz = $this->actingAs($osoba)->delete(route('wspolne-gotowanie.'.$akcja, $sesja))->assertNotFound();

        $odpowiedz->assertDontSee($recipe->title)->assertDontSee($gospodarz->displayName());
        $this->assertSame($przed, $this->pelneDane(), 'Odmowa Policy pozostawia całe dane oraz rewizje obu sesji.');
    }

    /** @return array<string, array{string, string}> */
    public static function zamknieteKonta(): array
    {
        $przypadki = [];
        foreach ([User::STATUS_BANNED, User::STATUS_PENDING_DELETE, User::STATUS_ERASED] as $stan) {
            foreach (['host', 'helper'] as $rola) {
                $przypadki[$stan.' '.$rola] = [$stan, $rola];
            }
        }

        return $przypadki;
    }

    #[DataProvider('zamknieteKonta')]
    public function test_zamkniete_konto_nie_dostaje_wyjatku(string $stan, string $rola): void
    {
        [$gospodarz, $pomocnik, , $sesja] = $this->sesjaZPelnaHistoria();
        $osoba = $rola === 'host' ? $gospodarz : $pomocnik;
        // Fixture zachowuje istniejącą sesję nawet po wymazaniu danych konta:
        // globalna bramka nie może zakładać, że egzekutor zdążył posprzątać.
        $osoba->forceFill([
            'status' => $stan,
            'data_erased_at' => $stan === User::STATUS_ERASED ? now() : null,
        ])->save();
        $przed = $this->pelneDane();

        $this->actingAs($osoba->fresh())->delete(route('wspolne-gotowanie.'.($rola === 'host' ? 'destroy' : 'leave'), $sesja))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($przed, $this->pelneDane(), 'Zamknięte konto nie usuwa sesji ani udziału przez wyjątek zawieszenia.');
    }

    /** @return array<string, array{string, string}> */
    public static function zawieszeniUczestnicy(): array
    {
        return [
            'gospodarz czasowo' => ['host', 'timed'],
            'gospodarz bezterminowo' => ['host', 'indefinite'],
            'pomocnik czasowo' => ['helper', 'timed'],
            'pomocnik bezterminowo' => ['helper', 'indefinite'],
        ];
    }

    #[DataProvider('zawieszeniUczestnicy')]
    public function test_zawieszenie_nadal_odcina_postep_reset_i_zaproszenia(string $rola, string $stan): void
    {
        [$gospodarz, $pomocnik, , $sesja, $kroki] = $this->sesjaZPelnaHistoria();
        $osoba = $this->ustawStan($rola === 'host' ? $gospodarz : $pomocnik, $stan);
        $przed = $this->pelneDane();
        foreach ([
            ['POST', 'krok', ['krok_id' => $kroki[2]->getKey(), 'zrobiono' => '1']],
            ['POST', 'krok', ['krok_id' => $kroki[0]->getKey(), 'zrobiono' => '0']],
            ['POST', 'od-poczatku', []],
            ['POST', 'link.store', []],
            ['DELETE', 'link.destroy', []],
        ] as [$metoda, $akcja, $dane]) {
            $this->flushSession();
            $this->actingAs($osoba)->from(route('wspolne-gotowanie.show', $sesja))
                ->call($metoda, route('wspolne-gotowanie.'.$akcja, $sesja), $dane)
                ->assertRedirect(route('wspolne-gotowanie.show', $sesja))
                ->assertSessionHasErrors(['konto' => EnsureAccountIsActive::komunikatZawieszenia($osoba)]);
            $this->assertSame($przed, $this->pelneDane(), 'Zawieszenie nadal odcina '.$akcja.' bez zmiany kroków, rewizji, udziałów i linku.');
        }
    }

    private function ustawStan(User $osoba, string $stan): User
    {
        if ($stan !== 'active') {
            $osoba->suspend($stan === 'timed' ? now()->addDay() : null);
        }
        $swieza = $osoba->fresh();
        $this->assertSame($stan === 'active' ? User::STATUS_ACTIVE : User::STATUS_SUSPENDED, $swieza->status);
        $this->assertSame($stan === 'timed', $swieza->status_expires_at !== null);

        return $swieza;
    }

    /** @return array{User, User, Recipe, CookingSession, list<RecipeStep>} */
    private function sesjaZPelnaHistoria(): array
    {
        $gospodarz = $this->user();
        $pomocnik = $this->user();
        $drugiPomocnik = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $gospodarz->getKey()]);
        $kroki = [];
        foreach ([0, 1, 2] as $position) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => 'Przygotuj składnik numer '.($position + 1).'.',
            ]);
        }
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $token);
        app(ZaproszenieDoGotowania::class)->dolacz($drugiPomocnik, $token);
        app(PostepWspolnegoGotowania::class)->ustaw($gospodarz, $sesja, (string) $kroki[0]->getKey(), true);
        app(PostepWspolnegoGotowania::class)->ustaw($pomocnik, $sesja, (string) $kroki[1]->getKey(), true);

        return [$gospodarz, $pomocnik, $recipe, $sesja->fresh(), $kroki];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function pelneDane(): array
    {
        $dane = [];
        foreach ([
            'cooking_sessions' => ['id'],
            'cooking_session_participants' => ['session_id', 'user_id'],
            'cooking_session_steps' => ['session_id', 'step_id'],
            'cooking_session_invitations' => ['id'],
            'recipes' => ['id'],
            'recipe_steps' => ['id'],
        ] as $tabela => $kolejnosc) {
            $zapytanie = DB::table($tabela);
            foreach ($kolejnosc as $kolumna) {
                $zapytanie->orderBy($kolumna);
            }
            $dane[$tabela] = $zapytanie->get()->map(static fn (object $wiersz): array => get_object_vars($wiersz))->all();
        }

        return $dane;
    }
}
