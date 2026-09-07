<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Audyt W7-02, punkt 1: co robi `Gate::allows('view', ...)`, na którym stoi
 * `App\Domain\Media\DostepDoZdjecia`, gdy rodzic NIE MA Policy — albo ma
 * Policy, ale bez metody `view`?
 *
 * PO CO TEN TEST, SKORO `DostepDoZdjecia::rodzice()` ZNA TYLKO PIĘĆ TYPÓW
 * `DostepDoZdjecia::ODWOLANIA` jest pilnowane przez
 * `ZdjeciaChronioneNieWyciekajaTest::test_kazde_odwolanie_do_zdjecia_ma_tu_swojego_rodzica`
 * (zgodność z `KasujZdjecie::ODWOLANIA`) i przez
 * `ZdjeciaPrzezylyBladWalidacjiTest::test_lista_odwolan_do_media_jest_pelna`
 * (zgodność ze schematem bazy). Żaden z tych testów nie mówi jednak, CO
 * SIĘ STANIE w dniu, w którym ktoś dopisze nową kolumnę wskazującą na
 * `media` i NOWY typ rodzica w `DostepDoZdjecia::rodzice()` — a zapomni
 * dodać mu Policy, albo doda Policy bez metody `view` (literówka,
 * `show()` zamiast `view()`, kopia z innej klasy).
 *
 * `DostepDoZdjecia` NIE MA własnego `try/catch` ani sprawdzenia
 * `Gate::has()` — woła `Gate::forUser($widz)->allows('view', $rodzic)`
 * wprost i ufa temu, co Laravel zwróci. To jest CELOWE (komentarz w klasie:
 * "nie powtarza ani jednego warunku widoczności"), ale oznacza, że
 * bezpieczeństwo tej klasy w takim dniu zależy CAŁKOWICIE od tego, jak
 * `Illuminate\Auth\Access\Gate` zachowuje się bez pasującej Policy — a to
 * nigdzie w tym repozytorium nie było przypięte testem.
 *
 * ZMIERZONE (czytając `Gate::resolveAuthCallback()` i `resolvePolicyCallback()`
 * w vendor/laravel/framework): brak Policy ALBO brak metody `view` na
 * istniejącej Policy prowadzi do tego samego — `raw()` zwraca `null`,
 * `inspect()` zamienia `null` na `Response::deny()`, `allows()` zwraca
 * `false`. Ani jednego wyjątku. To jest strona BEZPIECZNA — nowy,
 * niepodłączony rodzic robi zdjęcie NIEWIDOCZNYM dla każdego (poza
 * właścicielem i moderatorem, którzy w `DostepDoZdjecia::moze()` mają
 * odrębny warunek PRZED zapytaniem o rodziców) — ale to WCIĄŻ jest awaria,
 * tylko po bezpiecznej stronie, i lepiej, żeby wyszła stąd niż ze
 * zgłoszenia „moje zdjęcie nagle zniknęło".
 */
class DostepDoZdjeciaBezPolicyRodzicaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `App\Models\Ingredient` reprezentuje tu KAŻDY przyszły typ rodzica,
     * któremu ktoś zapomni dopisać Policy — nie jest sam w sobie rodzicem
     * zdjęcia i nigdy nie powinien nim zostać. Wybrany dlatego, że żyje
     * w `app/Models`, więc podlega tej samej automatycznej regule zgadywania
     * nazwy Policy (`App\Models\X` → `App\Policies\XPolicy`) co Post, Recipe
     * i reszta prawdziwych rodziców — to nie jest test na sztucznej klasie,
     * tylko na tym samym mechanizmie, którego one używają.
     */
    public function test_model_bez_zadnej_policy_dostaje_cicha_odmowe_a_nie_wyjatek(): void
    {
        $skladnik = new Ingredient(['canonical_name' => 'sól', 'normalized_name' => 'sol']);

        // KONTROLA: ten test coś dowodzi tylko wtedy, gdy Ingredient
        // NAPRAWDĘ nie ma Policy. Gdyby ktoś kiedyś ją dodał, ten test ma
        // zacząć krzyczeć „sprawdź inny model", a nie cicho przestać
        // sprawdzać to, po co powstał.
        $this->assertNull(
            Gate::getPolicyFor($skladnik),
            'Kontrola nieaktualna: App\\Models\\Ingredient dostało Policy. '.
            'Ten test miał sprawdzać zachowanie Gate dla modelu BEZ Policy — '.
            'wybierz inny model albo klasę pomocniczą bez Policy.',
        );

        $ktos = User::factory()->create();

        $this->assertFalse(
            Gate::forUser($ktos)->allows('view', $skladnik),
            'Gate::allows() na modelu bez zarejestrowanej Policy MUSI zwrócić '.
            'false, nie rzucić wyjątkiem — na tym cichym fail-closed opiera '.
            'się cała bezpieczna strona DostepDoZdjecia::moze().',
        );

        $this->assertFalse(
            Gate::forUser(null)->allows('view', $skladnik),
            'To samo dla gościa — brak zalogowanego użytkownika nie może '.
            'zmienić odpowiedzi z „brak Policy" na coś innego niż odmowa.',
        );
    }

    /**
     * Drugi, PRAWDZIWY (nie zmyślony) przypadek z tego repozytorium:
     * `App\Policies\UserPolicy` ISTNIEJE, ale nie ma metody `view()` —
     * ma `viewProfile()`, `follow()`, `moderate()`. Gdyby ktoś kiedyś
     * dodał do `DostepDoZdjecia::ODWOLANIA` kolumnę wskazującą wprost na
     * `users` (a nie na `profiles`, jak dziś robi awatar) i doleciał typem
     * `User` zamiast `Profile` do `rodzice()`, Gate znalazłby politykę,
     * ale nie znalazłby w niej pasującej metody — i to MUSI skończyć się
     * tak samo jak brak Policy w ogóle, a nie wyjątkiem `BadMethodCallException`.
     */
    public function test_policy_bez_pasujacej_metody_dostaje_cicha_odmowe_a_nie_wyjatek(): void
    {
        $ktos = $this->user('kimkolwiek');
        $cel = $this->user('celem');

        $this->assertFalse(
            method_exists(UserPolicy::class, 'view'),
            'Kontrola nieaktualna: UserPolicy dostało metodę view(). '.
            'Ten test miał sprawdzać Policy istniejącą, ale bez pasującej '.
            'metody — wybierz inną kombinację.',
        );

        $this->assertFalse(
            Gate::forUser($ktos)->allows('view', $cel),
            'Policy istnieje (UserPolicy), ale bez metody view() — Gate::allows() '.
            'musi i tak zwrócić false, nie wywalić się BadMethodCallException.',
        );
    }
}
