<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tryb ścisły Eloquent poza produkcją (issue #976).
 *
 * `AppServiceProvider` woła `Model::shouldBeStrict(environment('local', 'testing'))`, więc
 * w `local` i `testing` trzy ciche przeoczenia przerywają test w miejscu
 * błędu: leniwe ładowanie relacji (N+1), odczyt kolumny spoza częściowego
 * `select()` i pole spoza `$fillable` odrzucone przy masowym przypisaniu.
 * Produkcja zostaje tolerancyjna.
 *
 * Każdy test niżej ma też kontrolę dodatnią: ta sama operacja wykonana
 * poprawnie (z `with()`, z pobraną kolumną, z polem z `$fillable`) przechodzi —
 * inaczej zielony wynik dałoby się osiągnąć psując sam model.
 *
 * Kontrola ujemna: `scripts/kontrole-negatywne-alfa08.py` usuwa rejestrację
 * z `AppServiceProvider` i ten test ma oblać.
 */
class TrybScislyEloquentTest extends TestCase
{
    use RefreshDatabase;

    public function test_w_testach_wszystkie_trzy_ochrony_sa_wlaczone(): void
    {
        $this->assertFalse($this->app->isProduction(), 'Kontrola: ten test biegnie poza produkcją.');
        $this->assertTrue(Model::preventsLazyLoading(), 'Leniwe ładowanie relacji nie jest zablokowane poza produkcją.');
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes(), 'Pola spoza $fillable są odrzucane po cichu poza produkcją.');
        $this->assertTrue(Model::preventsAccessingMissingAttributes(), 'Odczyt niepobranej kolumny nie rzuca wyjątku poza produkcją.');
    }

    public function test_leniwe_ladowanie_relacji_rzuca_wyjatek(): void
    {
        $autor = $this->user('leniwa');
        Recipe::factory()->count(2)->create(['author_id' => $autor->getKey()]);

        // Laravel blokuje leniwe ładowanie tylko w modelach wczytanych RAZEM
        // (więcej niż jeden) — tam powstaje N+1. Stąd dwa przepisy.
        $przepisy = Recipe::query()->get();

        $this->expectException(LazyLoadingViolationException::class);
        $przepisy->first()->author;
    }

    public function test_relacja_zaladowana_z_gory_nie_rzuca(): void
    {
        $autor = $this->user('zgory');
        Recipe::factory()->count(2)->create(['author_id' => $autor->getKey()]);

        $przepisy = Recipe::query()->with('author')->get();

        $this->assertSame($autor->getKey(), $przepisy->first()->author->getKey());
    }

    public function test_odczyt_kolumny_spoza_czesciowego_selecta_rzuca_wyjatek(): void
    {
        $this->user('czesciowy');

        $konto = User::query()->select('id')->firstOrFail();

        $this->expectException(MissingAttributeException::class);
        $konto->locale;
    }

    public function test_odczyt_pobranej_kolumny_nie_rzuca(): void
    {
        $this->user('pelny');

        $konto = User::query()->select(['id', 'locale'])->firstOrFail();

        $this->assertSame('pl', $konto->locale);
    }

    public function test_pole_spoza_fillable_rzuca_wyjatek_zamiast_cichego_odrzucenia(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Post(['kind' => Post::KIND_QUESTION, 'body' => 'Podrzucony rodzaj.']);
    }

    public function test_pole_z_fillable_przechodzi(): void
    {
        $wpis = new Post(['body' => 'Zwykła treść.']);

        $this->assertSame('Zwykła treść.', $wpis->body);
    }

    public function test_w_produkcji_ochrony_sa_wylaczone(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertTrue($this->app->isProduction(), 'Kontrola: środowisko naprawdę przełączone na produkcję.');

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(Model::preventsLazyLoading(), 'Produkcja blokuje leniwe ładowanie — przeoczenie stałoby się błędem 500.');
        $this->assertFalse(Model::preventsSilentlyDiscardingAttributes(), 'Produkcja rzuca na polu spoza $fillable.');
        $this->assertFalse(Model::preventsAccessingMissingAttributes(), 'Produkcja rzuca na niepobranej kolumnie.');

        // Skutek, nie tylko flaga: pole sterujące odpada po cichu, jak dotąd.
        $wpis = new Post(['kind' => Post::KIND_QUESTION, 'body' => 'Podrzucony rodzaj.']);
        $this->assertSame(Post::KIND_DISH, $wpis->kind, 'Pole sterujące przeszło masowym przypisaniem w produkcji.');
        $this->assertSame('Podrzucony rodzaj.', $wpis->body);
    }

    public function test_na_stagingu_ochrony_sa_wylaczone(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        $this->assertTrue($this->app->environment('staging'), 'Kontrola: środowisko naprawdę przełączone na staging.');

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(Model::preventsLazyLoading(), 'Staging blokuje leniwe ładowanie — joby spoza testów padałyby bez wyłącznika.');
        $this->assertFalse(Model::preventsSilentlyDiscardingAttributes(), 'Staging rzuca na polu spoza $fillable.');
        $this->assertFalse(Model::preventsAccessingMissingAttributes(), 'Staging rzuca na niepobranej kolumnie.');
    }
}
