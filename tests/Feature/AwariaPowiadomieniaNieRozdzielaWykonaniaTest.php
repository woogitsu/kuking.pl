<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * „Ugotowałem" i powiadomienie autora to JEDNA operacja, nie dwie po sobie.
 *
 * AGENTS.md §1 mówi: „«Ugotowałem» jest ważniejsze niż lajk i ZAWSZE
 * powiadamia autora przepisu". Docblock `RecordCookedEvent` powtarza to
 * jeszcze mocniej: „powiadomienie autora jest OBOWIĄZKOWĄ częścią tej
 * operacji, nie dodatkiem". Kod tego nie dotrzymywał.
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ (audyt zewnętrzny G04, potwierdzony tutaj)
 * Transakcja kończyła się na zapisie wykonania, a powiadomienie szło PO niej.
 * Jednorazowa awaria zapisu powiadomienia dawała więc 1 wykonanie i 0
 * wiadomości. Gorsze było to, co działo się potem: ponowienie tego samego
 * formularza odbijało się o `cooked_events_one_per_klucz_wyslania`, kod
 * znajdował istniejące wykonanie i wychodził PRZED powiadomieniem. Autor
 * przepisu nie dowiadywał się NIGDY, a kucharz nie miał jak tego naprawić —
 * kolejne kliknięcia zachowywały się identycznie.
 *
 * Awarię wstrzykujemy zdarzeniem modelu `creating`, czyli PRZED zapytaniem
 * SQL. Dzięki temu wynik nie jest artefaktem zatrutej transakcji PostgreSQL
 * (raz zerwana transakcja odrzuca każde następne zapytanie i wtedy „brak
 * powiadomienia" nic by nie dowodził).
 */
class AwariaPowiadomieniaNieRozdzielaWykonaniaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Awaria uzbrojona na PIERWSZY zapis powiadomienia i tylko na niego.
     *
     * Zwraca domknięcie rozbrajające — wywołanie go zdejmuje nasłuch, żeby
     * kolejne testy w tym samym procesie nie dostały cudzej awarii.
     */
    private function zepsujPierwszyZapisPowiadomienia(): callable
    {
        $uzbrojona = true;

        Notification::creating(function () use (&$uzbrojona): void {
            if ($uzbrojona) {
                $uzbrojona = false;

                throw new RuntimeException('Symulowana awaria zapisu powiadomienia.');
            }
        });

        return function () use (&$uzbrojona): void {
            $uzbrojona = false;
        };
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function iloscPowiadomien(User $autor): int
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->count();
    }

    /**
     * Kontrola: bez awarii jedno wykonanie daje jedno powiadomienie.
     *
     * Bez tego test niżej nie dowodzi niczego — „zero powiadomień" mogłoby
     * znaczyć, że powiadomienia nie powstają w ogóle.
     */
    public function test_kontrola_bez_awarii_jedno_wykonanie_daje_jedno_powiadomienie(): void
    {
        $autor = $this->user('autorkontrola');
        $kucharz = $this->user('kucharkontrola');
        $przepis = $this->przepis($autor);

        app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $przepis,
            note: 'Wyszło pięknie.',
            kluczWyslania: (string) Str::uuid7(),
        );

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(1, $this->iloscPowiadomien($autor));
    }

    public function test_awaria_powiadomienia_cofa_takze_zapis_wykonania(): void
    {
        $autor = $this->user('autorawarii');
        $kucharz = $this->user('kucharzawarii');
        $przepis = $this->przepis($autor);

        $rozbroj = $this->zepsujPierwszyZapisPowiadomienia();

        try {
            app(RecordCookedEvent::class)->handle(
                cook: $kucharz,
                recipe: $przepis,
                note: 'Wyszło pięknie.',
                kluczWyslania: (string) Str::uuid7(),
            );

            $this->fail('Awaria zapisu powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException) {
            // Tego się spodziewamy — chodzi o to, CO ZOSTAŁO w bazie.
        } finally {
            $rozbroj();
        }

        // Sedno: nie zostaje wykonanie bez powiadomienia. Człowiek zobaczy
        // błąd i spróbuje jeszcze raz — a wtedy ma zacząć od czystego stanu,
        // nie od wykonania, którego autor nigdy nie zobaczy.
        $this->assertSame(
            0,
            CookedEvent::query()->count(),
            'Wykonanie zostało w bazie mimo nieudanego powiadomienia — autor nigdy się nie dowie.',
        );

        $this->assertSame(0, $this->iloscPowiadomien($autor));

        // Wpis audytowy też nie ma prawa zostać po cofniętej operacji.
        $this->assertSame(
            0,
            DB::table('audit_log')->where('action', 'cooked_event.created')->count(),
            'Audyt zapisał wykonanie, którego w bazie nie ma.',
        );
    }

    public function test_ponowienie_po_awarii_dowozi_wykonanie_i_powiadomienie(): void
    {
        $autor = $this->user('autorponowien');
        $kucharz = $this->user('kucharzponowien');
        $przepis = $this->przepis($autor);

        // TEN SAM klucz w obu próbach — bo to jest to samo wysłanie tego
        // samego formularza. Właśnie na tym kluczu psuło się odzyskiwanie.
        $klucz = (string) Str::uuid7();

        $rozbroj = $this->zepsujPierwszyZapisPowiadomienia();

        try {
            app(RecordCookedEvent::class)->handle(
                cook: $kucharz,
                recipe: $przepis,
                note: 'Wyszło pięknie.',
                kluczWyslania: $klucz,
            );
        } catch (RuntimeException) {
            // Pierwsza próba ma paść. Awaria jest już rozbrojona.
        } finally {
            $rozbroj();
        }

        app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $przepis,
            note: 'Wyszło pięknie.',
            kluczWyslania: $klucz,
        );

        $this->assertSame(
            1,
            CookedEvent::query()->count(),
            'Ponowienie zapisało wykonanie dwa razy albo ani razu.',
        );

        $this->assertSame(
            1,
            $this->iloscPowiadomien($autor),
            'Po ponowieniu autor przepisu nadal nie ma powiadomienia (usterka G04).',
        );
    }

    /**
     * Idempotencja NIE zostaje po drodze zgubiona.
     *
     * Naprawa G04 przesuwa powiadomienie do transakcji. Gdyby przy okazji
     * wypadła obsługa `cooked_events_one_per_klucz_wyslania`, dwa kliknięcia
     * „Wyślij" znowu dawałyby dwa powiadomienia — czyli naprawilibyśmy jedną
     * usterkę, przywracając drugą (ADR idempotencji formularzy, §1.2).
     */
    public function test_idempotencja_jednego_wyslania_dziala_tak_samo_jak_przedtem(): void
    {
        $autor = $this->user('autoridem');
        $kucharz = $this->user('kucharzidem');
        $przepis = $this->przepis($autor);

        $klucz = (string) Str::uuid7();

        $pierwsze = app(RecordCookedEvent::class)->handle(
            cook: $kucharz, recipe: $przepis, note: 'Raz.', kluczWyslania: $klucz,
        );

        $drugie = app(RecordCookedEvent::class)->handle(
            cook: $kucharz, recipe: $przepis, note: 'Raz.', kluczWyslania: $klucz,
        );

        $this->assertTrue($pierwsze->is($drugie), 'Drugie wysłanie zapisało osobne wykonanie.');
        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(1, $this->iloscPowiadomien($autor));
    }

    /**
     * D-005 zostaje nienaruszone: świadome drugie gotowanie przechodzi.
     */
    public function test_drugie_gotowanie_z_nowego_formularza_nadal_sie_zapisuje(): void
    {
        $autor = $this->user('autordwa');
        $kucharz = $this->user('kucharzdwa');
        $przepis = $this->przepis($autor);

        app(RecordCookedEvent::class)->handle(
            cook: $kucharz, recipe: $przepis, note: 'Pierwszy raz.', kluczWyslania: (string) Str::uuid7(),
        );

        app(RecordCookedEvent::class)->handle(
            cook: $kucharz, recipe: $przepis, note: 'Drugi raz.', kluczWyslania: (string) Str::uuid7(),
        );

        $this->assertSame(2, CookedEvent::query()->count());
        $this->assertSame(2, $this->iloscPowiadomien($autor));
    }
}
