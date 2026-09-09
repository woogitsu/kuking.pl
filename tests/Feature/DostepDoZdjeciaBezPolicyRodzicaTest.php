<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Models\Ingredient;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK NIE MIERZYŁ, CHOĆ TAK SIĘ NAZYWA (audyt zewnętrzny, G11)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Dwa testy niżej wołają WYŁĄCZNIE `Gate::allows()`. Opisują zachowanie
 * frameworka i opisują je poprawnie — ale ani razu nie dotykają
 * `DostepDoZdjecia`, o którym mówi cały nagłówek.
 *
 * ZMIERZONE: po podmianie `DostepDoZdjecia::moze()` na `return true`
 * (czyli po otwarciu KAŻDEGO zdjęcia KAŻDEMU) ten plik przechodził
 * w całości — 2 testy, 5 asercji, zielono. Ta sama mutacja wywalała
 * 21 z 28 testów w `ZdjeciaChronioneNieWyciekajaTest`.
 *
 * Test, który przeżywa mutację będącą dokładnie tą awarią, przed którą
 * ostrzega jego własny nagłówek, jest gorszy niż brak testu: liczy się
 * w pokryciu i uspokaja przy przeglądzie.
 *
 * Dlatego niżej doszły dwa przypadki wołające PRAWDZIWĄ usługę — jeden
 * na zdjęciu bez rodzica (to jest właśnie stan „nowy rodzic bez Policy":
 * żaden rodzic nie przepuszcza), drugi na prawdziwej odmowie i prawdziwej
 * zgodzie `RecipePolicy`. Każdy z nich ma kontrolę w drugą stronę, bo
 * „usługa zawsze odmawia" zabiłoby mutację `return true` i przepuściło
 * `return false`, czyli zdjęcia zniknięte wszystkim.
 *
 * `ZdjeciaChronioneNieWyciekajaTest` zostaje nietknięty — audyt mówi
 * wprost, żeby go nie osłabiać, i słusznie: tamten stoi na kanonicznej
 * macierzy widoczności, ten pilnuje jednego konkretnego mechanizmu.
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

    // =================================================================
    //  Prawdziwa usługa, nie sam Gate (audyt G11)
    // =================================================================

    /**
     * Zdjęcie, którego ŻADEN rodzic nie przepuszcza, jest niewidoczne dla
     * obcego i dla gościa — sprawdzone przez `DostepDoZdjecia::moze()`.
     *
     * Zdjęcie bez rodzica to dokładnie ten stan, do którego prowadzi awaria
     * opisana w nagłówku: nowy typ rodzica bez Policy nie przepuszcza,
     * więc pętla po rodzicach kończy się tak samo jak pusta. Różnica jest
     * tylko w tym, ile razy Gate powiedział „nie".
     *
     * Ten sam przypadek jest zresztą normalnym stanem produkcyjnym: zdjęcie
     * wgrane do kreatora i jeszcze nieprzypięte do przepisu.
     */
    public function test_usluga_odmawia_obcemu_zdjecia_bez_rodzica_a_wlascicielowi_nie(): void
    {
        $autorka = $this->user('autorka');
        $obcy = $this->user('obcy');

        $zdjecie = Media::factory()->create([
            'owner_id' => $autorka->getKey(),
            'status' => Media::STATUS_READY,
        ]);

        $dostep = app(DostepDoZdjecia::class);

        $this->assertFalse(
            $dostep->moze($obcy, $zdjecie),
            'Zdjęcie bez ani jednego przepuszczającego rodzica otworzyło się obcemu. '
            .'To jest ta sama gałąź, w którą wpada nowy typ rodzica bez Policy.',
        );

        $this->assertFalse(
            $dostep->moze(null, $zdjecie),
            'To samo dla gościa — brak konta nie może być szerszym dostępem niż konto.',
        );

        // KONTROLA W DRUGĄ STRONĘ. Bez niej `moze()` przerobione na
        // `return false` przeszłoby oba testy wyżej — a to znaczy zdjęcia
        // zniknięte WSZYSTKIM, łącznie z autorem w kreatorze.
        $this->assertTrue(
            $dostep->moze($autorka, $zdjecie),
            'Właścicielka nie zobaczyła własnego, jeszcze nieprzypiętego zdjęcia. '
            .'Podgląd w kreatorze byłby pustą ramką.',
        );
    }

    /**
     * Prawdziwa odmowa i prawdziwa zgoda, przez prawdziwą Policy.
     *
     * Jedno zdjęcie, jeden rodzic, jedna zmiana: przepis prywatny → obcy nie
     * widzi, ten sam przepis publiczny → widzi. To jest cała umowa tej klasy
     * („nie powtarza ani jednego warunku widoczności, woła Policy") ściśnięta
     * do dwóch asercji.
     *
     * Kanoniczna macierz wszystkich kombinacji stoi w
     * `ZdjeciaChronioneNieWyciekajaTest` i ma tam zostać — tu chodzi o to,
     * żeby TEN plik nie przeżył mutacji, o której mówi jego własny nagłówek.
     */
    public function test_usluga_idzie_za_policy_rodzica_w_obie_strony(): void
    {
        $autorka = $this->user('autorka');
        $obcy = $this->user('obcy');

        $zdjecie = Media::factory()->create([
            'owner_id' => $autorka->getKey(),
            'status' => Media::STATUS_READY,
        ]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Żurek na zakwasie',
            'slug' => 'zurek-na-zakwasie-'.Str::lower(Str::random(6)),
        ]);

        $dostep = app(DostepDoZdjecia::class);

        $this->assertFalse(
            $dostep->moze($obcy, $zdjecie),
            'Zdjęcie główne PRYWATNEGO przepisu otworzyło się obcemu.',
        );

        $przepis->forceFill(['visibility' => 'public'])->save();

        $this->assertTrue(
            $dostep->moze($obcy, $zdjecie->fresh()),
            'Zdjęcie główne PUBLICZNEGO przepisu nie otworzyło się obcemu — '
            .'czyli usługa nie idzie za Policy, tylko odmawia wszystkiego.',
        );
    }
}
