<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

/**
 * Awaria w połowie wysyłki podsumowań nie może wysłać nikomu tego samego
 * listu dwa razy (audyt 10.09.2026 QUEUE-01 / MAIL-02 / RACE-04,
 * `docs/DECISIONS.md` D-077).
 *
 * CO BYŁO ZEPSUTE — KOLEJNOŚĆ, NIE BRAK SPRAWDZENIA
 * `Mail::queue()` szło PIERWSZE, a ślad w bazie
 * (`users.weekly_digest_sent_at`) stawiało jedno zapytanie PO CAŁEJ PĘTLI.
 * Awaria pomiędzy zostawiała listy w kolejce i zero śladu, więc następny
 * przebieg kwalifikował te same osoby jeszcze raz.
 *
 * DLACZEGO LICZYMY LISTY PER ODBIORCA, A NIE W SUMIE
 * Bo suma kłamie w obie strony. „Pięć listów na pięć osób" przechodzi także
 * wtedy, gdy jedna osoba dostała trzy, a dwie nie dostały nic — czyli
 * dokładnie w tej usterce, którą ten plik ma pilnować. Każdy test niżej
 * sprawdza więc liczbę listów DLA KAŻDEGO ADRESU osobno.
 *
 * JAK SYMULUJEMY AWARIĘ
 * Wyzwalaczem w PostgreSQL, który przy N-tym wierszu rezerwacji podnosi
 * wyjątek. Jest to najbliższe prawdzie, co da się zrobić w teście: wysyłka
 * przerywa się w połowie paczki, po zakolejkowaniu pierwszych wiadomości,
 * a wiersze rezerwacji zajęte wcześniej ZOSTAJĄ (`DB::transaction()` cofa
 * SAVEPOINT tylko tej jednej osoby). Podmiana `Mail` albo `ZapiszSygnal` nie
 * dałaby tego samego: `MailFake` nie ma jak rzucić wyjątku, a `ZapiszSygnal`
 * jest `final` i każdy wyjątek zjada u siebie (i słusznie — sygnał
 * analityczny nie ma prawa wywrócić wysyłki).
 */
class DigestNieWysylaDwaRazyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.dzienny_limit', 60);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 20);
        config()->set('kuking.digest.max_pozycji', 3);
    }

    // -----------------------------------------------------------------
    //  Pomocnicy
    // -----------------------------------------------------------------

    /**
     * Osoba z realnym powodem dostania listu: ktoś ugotował z jej przepisu.
     */
    private function odbiorcaZTrescia(string $nazwa, array $atrybuty = []): User
    {
        $autor = $this->user($nazwa, $atrybuty);
        $kucharz = $this->user($nazwa.'_kucharz');

        $przepis = Recipe::factory()->for($autor, 'author')->create();
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }

    /** @return list<User> */
    private function odbiorcy(int $ile, string $prefiks): array
    {
        $osoby = [];

        for ($i = 0; $i < $ile; $i++) {
            $osoby[] = $this->odbiorcaZTrescia($prefiks.'_'.$i);
        }

        return $osoby;
    }

    /** Ile listów podsumowania poszło POD TEN JEDEN ADRES. */
    private function ileListow(User $osoba): int
    {
        return Mail::queued(PodsumowanieTygodnia::class)
            ->filter(fn (PodsumowanieTygodnia $list): bool => $list->hasTo($osoba->email))
            ->count();
    }

    /**
     * Każda z tych osób ma dostać DOKŁADNIE tyle listów, ile mówi `$ile`.
     *
     * @param  list<User>  $osoby
     */
    private function assertKazdyDostal(int $ile, array $osoby): void
    {
        foreach ($osoby as $osoba) {
            $this->assertSame(
                $ile,
                $this->ileListow($osoba),
                "Adres {$osoba->email} dostał {$this->ileListow($osoba)} listów, a miał dostać {$ile}.",
            );
        }
    }

    /**
     * Wyzwalacz podnoszący wyjątek przy próbie zajęcia `$ktora` rezerwacji
     * w tym przebiegu — czyli awaria w połowie wysyłki.
     */
    private function przerwijPrzyRezerwacji(int $ktora): void
    {
        $granica = $ktora - 1;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION test_awaria_wysylki() RETURNS trigger AS $$
            BEGIN
                IF (SELECT count(*) FROM weekly_digest_sends) >= {$granica} THEN
                    RAISE EXCEPTION 'symulowana awaria w połowie wysyłki';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER test_awaria_wysylki
            BEFORE INSERT ON weekly_digest_sends
            FOR EACH ROW EXECUTE FUNCTION test_awaria_wysylki();
        SQL);
    }

    private function koniecAwarii(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS test_awaria_wysylki ON weekly_digest_sends');
    }

    /** Uruchamia wysyłkę i zwraca wyjątek, jeśli przebieg padł. */
    private function przebieg(): ?Throwable
    {
        try {
            Artisan::call('kuking:wyslij-podsumowania');
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // -----------------------------------------------------------------
    //  1. SEDNO: awaria w połowie, potem przebieg ponowny
    // -----------------------------------------------------------------

    public function test_awaria_w_polowie_przebiegu_nie_wysyla_nikomu_drugiego_listu(): void
    {
        Mail::fake();

        $osoby = $this->odbiorcy(5, 'awaria');

        // Pierwszy przebieg pada przy trzeciej osobie: dwa listy są już
        // w kolejce, dwie rezerwacje w bazie, reszty paczki nie ma.
        $this->przerwijPrzyRezerwacji(3);
        $padlo = $this->przebieg();
        $this->koniecAwarii();

        // Asercja kontrolna: gdyby wyzwalacz nie podniósł wyjątku, ten test
        // sprawdzałby zwykły, spokojny przebieg i przechodziłby zawsze.
        $this->assertNotNull($padlo, 'Przebieg nie padł — symulacja awarii nic nie zasymulowała.');
        $this->assertSame(2, Mail::queued(PodsumowanieTygodnia::class)->count());
        $this->assertSame(2, DB::table('weekly_digest_sends')->count());

        // Drugi przebieg — dokładnie to, co robi harmonogram nazajutrz albo
        // właściciel ręcznie po zobaczeniu, że coś padło.
        $this->assertNull($this->przebieg());

        // NIKT dwa razy i NIKT pominięty: pięć osób, pięć listów, po jednym.
        $this->assertKazdyDostal(1, $osoby);
        $this->assertSame(5, Mail::queued(PodsumowanieTygodnia::class)->count());
    }

    /**
     * Ten test jest sercem D-077: gwarancję daje OGRANICZENIE W BAZIE, nie
     * porównanie w PHP.
     *
     * Warunek odstępu (`weekly_digest_sent_at <= now() - 7 dni`) jest
     * odczytem, po którym następuje zapis — a między odczytem a zapisem jest
     * luka, w którą wchodzą dwa przebiegi równoległe (RACE-04). Test kasuje
     * ten znacznik CELOWO, żeby zostawić samą barierę i pokazać, że drugi
     * list nie wyjdzie także wtedy, gdy sprawdzenie w PHP przepuści.
     */
    public function test_bariera_trzyma_takze_wtedy_gdy_znacznik_odstepu_przepadl(): void
    {
        Mail::fake();

        $osoby = $this->odbiorcy(3, 'bez_znacznika');

        $this->assertNull($this->przebieg());
        $this->assertKazdyDostal(1, $osoby);

        // Znika cała pamięć po stronie `users` — stan po nieudanym zapisie,
        // po wycofanej migracji kolumny albo po ręcznej naprawie w bazie.
        User::query()->update(['weekly_digest_sent_at' => null]);

        $this->assertNull($this->przebieg());

        $this->assertKazdyDostal(1, $osoby);
        $this->assertSame(3, Mail::queued(PodsumowanieTygodnia::class)->count());
    }

    // -----------------------------------------------------------------
    //  2. KONTROLA DODATNIA: normalny przebieg nadal wysyła
    // -----------------------------------------------------------------

    /**
     * Bez tego testu punkt 1 przechodziłby także wtedy, gdyby digest
     * przestał wysyłać cokolwiek — „nikt nie dostał dwa razy" jest wtedy
     * prawdą i jest bezwartościowe.
     */
    public function test_zwykly_przebieg_wysyla_do_wszystkich_uprawnionych(): void
    {
        Mail::fake();

        $osoby = $this->odbiorcy(4, 'zwykly');

        $this->assertNull($this->przebieg());

        $this->assertKazdyDostal(1, $osoby);
        $this->assertSame(4, Mail::queued(PodsumowanieTygodnia::class)->count());
        $this->assertSame(4, DB::table('weekly_digest_sends')->count());
    }

    // -----------------------------------------------------------------
    //  3. Rezerwacja jest PER OKRES, a nie na zawsze
    // -----------------------------------------------------------------

    public function test_nastepny_tydzien_wysyla_znowu(): void
    {
        Mail::fake();

        $osoby = $this->odbiorcy(2, 'kolejny_tydzien');

        $this->assertNull($this->przebieg());
        $this->assertKazdyDostal(1, $osoby);

        // Ósmy dzień: minął odstęp i jest to już inny tydzień kalendarzowy
        // (dzień `x` i `x + 7` zawsze mają różne poniedziałki).
        $this->travel(8)->days();
        CookedEvent::query()->update(['cooked_at' => now()->subDay()]);

        $this->assertNull($this->przebieg());

        $this->assertKazdyDostal(2, $osoby);

        // Dwa różne klucze tygodnia, a nie dwa wiersze tego samego —
        // inaczej `UNIQUE` nie przepuściłby drugiego wcale.
        $this->assertSame(
            2,
            DB::table('weekly_digest_sends')->distinct()->count('week_start'),
            'Drugi tydzień zajął ten sam klucz co pierwszy — rezerwacja nie jest per okres.',
        );
    }

    // -----------------------------------------------------------------
    //  4. Zgoda nadal jest warunkiem pierwszym
    // -----------------------------------------------------------------

    public function test_osoba_bez_zgody_nadal_nie_dostaje_nic(): void
    {
        Mail::fake();

        $bezZgody = $this->odbiorcaZTrescia('bez_zgody', ['wants_weekly_digest' => false]);
        $zeZgoda = $this->odbiorcaZTrescia('ze_zgoda');

        $this->assertNull($this->przebieg());

        $this->assertSame(0, $this->ileListow($bezZgody));

        // Kontrola dodatnia w tym samym przebiegu: wysyłka na pewno chodziła.
        $this->assertSame(1, $this->ileListow($zeZgoda));

        $this->assertSame(
            0,
            DB::table('weekly_digest_sends')->where('user_id', $bezZgody->getKey())->count(),
            'Osoba bez zgody dostała rezerwację tygodnia, choć nie miała dostać listu.',
        );
    }

    // -----------------------------------------------------------------
    //  5. Sama bariera, bez udziału PHP
    // -----------------------------------------------------------------

    public function test_baza_odrzuca_druga_rezerwacje_tej_samej_pary(): void
    {
        $osoba = $this->user('para');
        $tydzien = Czas::poczatekTygodniaData();

        DB::table('weekly_digest_sends')->insert([
            'user_id' => $osoba->getKey(),
            'week_start' => $tydzien,
            'reserved_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('weekly_digest_sends')->insert([
            'user_id' => $osoba->getKey(),
            'week_start' => $tydzien,
            'reserved_at' => now(),
        ]);
    }

    /**
     * Klucz musi ZNACZYĆ tydzień. Data ze środka tygodnia dałaby tej samej
     * osobie dwa różne, oba wolne klucze w jednym tygodniu — czyli dwa listy
     * przy nietkniętym `UNIQUE`.
     */
    public function test_baza_nie_przyjmuje_klucza_ktory_nie_jest_poniedzialkiem(): void
    {
        $osoba = $this->user('nie_poniedzialek');

        $this->expectException(QueryException::class);

        DB::table('weekly_digest_sends')->insert([
            'user_id' => $osoba->getKey(),
            // Czwartek tego samego tygodnia.
            'week_start' => Carbon::parse(Czas::poczatekTygodniaData())->addDays(3)->toDateString(),
            'reserved_at' => now(),
        ]);
    }
}
