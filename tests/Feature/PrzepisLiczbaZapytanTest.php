<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Strona przepisu nie powtarza tych samych zapytań (audyt wydajności, P3 W8):
 *
 * 1. dwa `count` na `cooked_events` z tym samym zakresem („zrobią ponownie"
 *    i „oceniło") to jedno zapytanie z `count(*) FILTER (WHERE …)`;
 * 2. `RecipePolicy::view()` woła `hasBlockRelationWith()` kilka razy o tę samą
 *    parę osób — wynik jest pamiętany przez czas jednego żądania
 *    (`BlokadyWZadaniu`) i nie przeżywa ani żądania, ani zmiany blokady.
 */
final class PrzepisLiczbaZapytanTest extends TestCase
{
    use RefreshDatabase;

    public function test_liczniki_opinii_to_jedno_zapytanie_i_te_same_liczby(): void
    {
        [$przepis, $widz] = $this->scena();

        $zapytania = $this->zapytaniaStrony($widz, $przepis);

        $this->assertCount(
            1,
            $this->pasujace($zapytania, '/^select count\(\*\) filter \(where cooked_events\.would_make_again is true\)/i'),
            'Oba liczniki opinii mają iść jednym zapytaniem z FILTER.',
        );
        // Zostaje jeden `count(*)` po `cooked_events` — suma paginatora galerii.
        $this->assertCount(
            1,
            $this->pasujace($zapytania, '/^select count\(\*\) as "aggregate" from "cooked_events"/i'),
            'Na stronie przepisu zostaje jedno zwykłe COUNT po wykonaniach (suma paginatora).',
        );

        $odpowiedz = $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk();

        // Widzialne: 2 × „tak", 1 × „nie", 1 × bez odpowiedzi. Niewidzialne (nie liczą się):
        // wykonanie osoby zablokowanej przez widza i osoby z kontem zbanowanym.
        $this->assertSame(2, $odpowiedz->viewData('zrobiaPonownie'));
        $this->assertSame(3, $odpowiedz->viewData('oceniloWykonanie'));
        $this->assertSame(4, $odpowiedz->viewData('cookedCount'));
    }

    public function test_blokada_miedzy_widzem_a_autorem_jest_pytana_raz_na_zadanie(): void
    {
        [$przepis, $widz] = $this->scena();

        $zapytania = $this->zapytaniaStrony($widz, $przepis);

        $this->assertCount(
            1,
            $this->pasujace($zapytania, '/^select exists\(select \* from "blocks" where \("blocker_id" = \? and "blocked_id" = \?\) or/i'),
            'Polityka przepisu ma zapytać o blokadę tej pary raz na żądanie.',
        );
    }

    public function test_pamiec_blokady_nie_przezywa_zadania(): void
    {
        [$przepis, $widz, $autor] = $this->scena();

        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk();

        // Ten sam obiekt `User` w następnym żądaniu: blokada ma zadziałać od razu.
        app(BlockUser::class)->handle($autor, $widz);
        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertForbidden();

        app(UnblockUser::class)->handle($autor, $widz);
        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk();
    }

    public function test_zmiana_blokady_w_tym_samym_zadaniu_czysci_pamiec(): void
    {
        [$przepis, $widz, $autor] = $this->scena();

        Route::middleware('web')->get('/__test/blokada-w-zadaniu', function () use ($przepis, $widz, $autor): array {
            $wynik = [Gate::forUser($widz)->allows('view', $przepis)];
            $wynik[] = Gate::forUser($widz)->allows('view', $przepis); // z pamięci

            app(BlockUser::class)->handle($autor, $widz);
            $wynik[] = Gate::forUser($widz)->allows('view', $przepis);

            app(UnblockUser::class)->handle($autor, $widz);
            $wynik[] = Gate::forUser($widz)->allows('view', $przepis);

            return $wynik;
        });

        $this->getJson('/__test/blokada-w-zadaniu')->assertOk()->assertExactJson([true, true, false, true]);
    }

    /**
     * Akcje sprawdzają uprawnienie PONOWNIE pod zamkiem (#1022,
     * `ZapisDoZeszytuPoUtracieDostepuTest`): tam pamięć żądania nie może
     * przesłonić blokady, którą inny proces założył po pierwszym sprawdzeniu.
     */
    public function test_pod_transakcja_otwarta_w_zadaniu_pamiec_nie_obowiazuje(): void
    {
        [$przepis, $widz, $autor] = $this->scena();

        Route::middleware('web')->get('/__test/blokada-pod-zamkiem', function () use ($przepis, $widz, $autor): array {
            $wynik = [Gate::forUser($widz)->allows('view', $przepis)];

            // Inny proces zakłada blokadę (surowy zapis, bez zdarzeń aplikacji).
            DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $widz->getKey(), 'created_at' => now()]);

            $wynik[] = DB::transaction(fn (): bool => Gate::forUser($widz)->allows('view', $przepis));

            return $wynik;
        });

        $this->getJson('/__test/blokada-pod-zamkiem')->assertOk()->assertExactJson([true, false]);
    }

    /** @return array{0: Recipe, 1: User, 2: User} */
    private function scena(): array
    {
        $autor = $this->user('autor_zapytan');
        $widz = $this->user('widz_zapytan');
        $zablokowany = $this->user('zablokowany_kucharz');
        $zbanowany = $this->user('zbanowany_kucharz', ['status' => User::STATUS_BANNED]);
        DB::table('blocks')->insert(['blocker_id' => $widz->getKey(), 'blocked_id' => $zablokowany->getKey(), 'created_at' => now()]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);
        $wykonanie = fn (?User $kto, ?bool $znowu) => CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => ($kto ?? $this->user('kucharz_'.uniqid()))->getKey(),
            'would_make_again' => $znowu,
        ]);
        $wykonanie(null, true);
        $wykonanie(null, true);
        $wykonanie(null, false);
        $wykonanie(null, null);
        $wykonanie($zablokowany, true);
        $wykonanie($zbanowany, true);

        return [$przepis, $widz, $autor];
    }

    /** @return list<string> */
    private function zapytaniaStrony(User $widz, Recipe $przepis): array
    {
        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            $zapytania[] = $zapytanie->sql;
        });

        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk();

        return $zapytania;
    }

    /**
     * @param  list<string>  $zapytania
     * @return list<string>
     */
    private function pasujace(array $zapytania, string $wzorzec): array
    {
        return array_values(array_filter($zapytania, fn (string $sql): bool => (bool) preg_match($wzorzec, $sql)));
    }
}
