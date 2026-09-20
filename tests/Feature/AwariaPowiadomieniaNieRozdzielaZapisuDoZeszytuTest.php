<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * ISSUE #772 — ZAPIS DO ZESZYTU I POWIADOMIENIE AUTORA SĄ JEDNĄ OPERACJĄ,
 * NIE DWIEMA PO SOBIE.
 *
 * CO SIĘ DZIAŁO PRZED ZMIANĄ
 * `SaveRecipeToCollection::handle()` najpierw dopinał przepis do zeszytu
 * (`attach()`), a dopiero potem wołał `NotifyUser::handle()`. Awaria przy
 * zapisie powiadomienia zostawiała przepis JUŻ przypięty, a odbiorca żądania
 * dostawał błąd. Ponowienie tego samego kliknięcia trafiało w idempotentną
 * gałąź „już zapisane” na samej górze metody i wracało PRZED próbą
 * powiadomienia — więc brakujące powiadomienie było już nie do odzyskania
 * żadnym ponowieniem, nawet stukrotnym.
 *
 * DLACZEGO TO NIE JEST TA SAMA NAPRAWA CO #797
 * `NotifyReporterReceipt` (issue #797, zgłoszenia DSA) musi przeżyć awarię
 * powiadomienia, bo sprawa ma własny obowiązek prawny niezależny od pingu.
 * Zapis do zeszytu nie ma takiego powodu — to prywatna półka, nie sprawa
 * urzędowa. Naprawa jest więc PROSTSZA: zapis i powiadomienie w jednej
 * transakcji, cofane RAZEM. Ponowienie tego samego kliknięcia po awarii robi
 * obie rzeczy od nowa i tym razem się udaje.
 *
 * AWARIA JEST WSTRZYKNIĘTA NAPRAWDĘ, na modelu `Notification` (zdarzenie
 * `creating`, dokładnie ta sama technika co w
 * `AwariaPowiadomieniaNieRozdzielaKomentarzaTest` i
 * `AwariaPowiadomieniaNieRozdzielaWykonaniaTest` dla tej samej klasy błędu)
 * — NIE przez `DB::listen`. `DB::listen` w Laravelu odpala się PO wykonaniu
 * zapytania (`QueryExecuted`), więc wyjątek rzucony w jego callbacku nie
 * zapobiega samemu zapisowi — cofa go dopiero prawdziwy `ROLLBACK`
 * jawnej transakcji, obejmującej TĘ instrukcję. Zdarzenie `creating` na
 * modelu odpala się PRZED wstawieniem wiersza, więc naprawdę zapobiega
 * powstaniu powiadomienia — to jest wierniejszy obraz realnej awarii
 * zapisu (np. rzuconego wyjątku w kodzie, a nie odrzuconego zapytania SQL).
 */
class AwariaPowiadomieniaNieRozdzielaZapisuDoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Psuje PIERWSZE tworzenie powiadomienia — i tylko jedno, żeby ponowienie
     * w tym samym teście mogło przejść normalnie.
     */
    private function zepsujZapisPowiadomienia(): callable
    {
        $uzbrojona = true;

        Notification::creating(function () use (&$uzbrojona): void {
            if (! $uzbrojona) {
                return;
            }

            $uzbrojona = false;

            throw new RuntimeException('Wstrzyknięta awaria: zapis powiadomienia.');
        });

        return function () use (&$uzbrojona): void {
            $uzbrojona = false;
        };
    }

    private function przepisAutorki(): array
    {
        $autor = $this->user('autorkazeszytu');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        return [$autor, $przepis];
    }

    private function zapisow(): int
    {
        return DB::table('collection_items')->count();
    }

    private function powiadomien(User $autor): int
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->count();
    }

    public function test_kontrola_bez_awarii_zapis_i_powiadomienie_powstaja(): void
    {
        [$autor, $przepis] = $this->przepisAutorki();
        $zapisujaca = $this->user('kontrolazeszytu');

        app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis);

        $this->assertSame(1, $this->zapisow());
        $this->assertSame(1, $this->powiadomien($autor));
    }

    public function test_awaria_powiadomienia_cofa_takze_zapis_do_zeszytu(): void
    {
        [$autor, $przepis] = $this->przepisAutorki();
        $zapisujaca = $this->user('awariazeszytu');

        $rozbroj = $this->zepsujZapisPowiadomienia();

        try {
            app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis);

            $this->fail('Awaria zapisu powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException) {
            // O to chodzi — liczy się to, co zostało w bazie.
        } finally {
            $rozbroj();
        }

        $this->assertSame(
            0,
            $this->zapisow(),
            'Przepis został przypięty do zeszytu, choć autor nigdy się o tym nie dowie — '
            .'poprawne dane miały zniknąć razem, nie zostać w połowie.',
        );

        $this->assertSame(0, $this->powiadomien($autor));
    }

    /**
     * PONOWIENIE PO AWARII MUSI DOKOŃCZYĆ OBIE RZECZY — to jest sedno
     * zgłoszenia: „ponowienie tego nie naprawia”. Po tej poprawce naprawia,
     * bo pierwsze wywołanie nic trwałego nie zostawiło.
     */
    public function test_ponowienie_po_awarii_daje_dokladnie_jeden_zapis_i_jedno_powiadomienie(): void
    {
        [$autor, $przepis] = $this->przepisAutorki();
        $zapisujaca = $this->user('ponowieniezeszytu');

        $rozbroj = $this->zepsujZapisPowiadomienia();

        try {
            app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis);
            $this->fail('Awaria nie wyszła na zewnątrz — reszta testu nic by nie zmierzyła.');
        } catch (RuntimeException) {
            // Jak wyżej.
        } finally {
            $rozbroj();
        }

        // PONOWIENIE tego samego kliknięcia, bez awarii.
        app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis);

        $this->assertSame(1, $this->zapisow(), 'Ponowienie zrobiło zero albo dwa zapisy zamiast jednego.');
        $this->assertSame(1, $this->powiadomien($autor), 'Zaległe powiadomienie nie zostało dokończone.');
    }

    /**
     * KONTROLA DODATNIA DLA WŁASNEGO PRZEPISU: `NotifyUser` sam odmawia
     * powiadomienia, gdy autor zapisuje własny przepis — to legalne `null`,
     * nie awaria, i transakcja nie ma prawa cofnąć za to samego zapisu.
     */
    public function test_zapis_wlasnego_przepisu_bez_powiadomienia_nie_jest_blokowany(): void
    {
        $autor = $this->user('wlasnyzeszyt');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        app(SaveRecipeToCollection::class)->handle($autor, $przepis);

        $this->assertSame(1, $this->zapisow());
        $this->assertSame(0, $this->powiadomien($autor));
    }

    /**
     * KONTROLA UNIKALNOŚCI POD TRANSAKCJĄ: dwa równoległe kliknięcia nadal
     * mają dać dokładnie jeden zapis i jedno powiadomienie — nowa
     * zagnieżdżona transakcja wokół `attach()` (SAVEPOINT) nie ma prawa
     * zepsuć istniejącej ochrony przed naruszeniem klucza głównego.
     */
    public function test_rownolegle_klikniecia_nadal_daja_jeden_zapis_i_jedno_powiadomienie(): void
    {
        [$autor, $przepis] = $this->przepisAutorki();
        $zapisujaca = $this->user('rownoleglezeszytu');
        $zeszyt = $zapisujaca->defaultCollection();

        // Symulacja wyścigu: wiersz wstawia się „pod spodem”, MIĘDZY
        // sprawdzeniem istnienia a próbą `attach()` w akcji.
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'created_at' => now(),
        ]);

        app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis, $zeszyt);

        $this->assertSame(1, $this->zapisow());
        $this->assertSame(0, $this->powiadomien($autor), 'Naruszenie klucza głównego nie ma dawać drugiego powiadomienia.');
    }
}
