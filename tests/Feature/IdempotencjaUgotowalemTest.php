<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jedno wysłanie formularza „Ugotowałem" to jedno wykonanie i JEDNO
 * powiadomienie (ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`, §1.2).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Dwa razy POST /przepisy/{slug}/ugotowalem dawało dwa wiersze
 * w `cooked_events` i DWA powiadomienia `cooked` u autora przepisu. Wykonanie
 * da się usunąć, powiadomienia nie da się cofnąć — a to jest, wprost
 * z `AGENTS.md` §1, „najcenniejsze powiadomienie w całym serwisie".
 *
 * D-005 ZOSTAJE NIENARUSZONE, I TO JEST POŁOWA TEGO PLIKU.
 * Indeks jest na `(user_id, klucz_wyslania)`, NIE na `(user_id, recipe_id)`.
 * Ta sama osoba nadal może zapisać dowolnie wiele wykonań tego samego
 * przepisu — byle każde przyszło z własnego formularza. Zakazane jest
 * wyłącznie dwukrotne policzenie JEDNEGO wysłania.
 */
class IdempotencjaUgotowalemTest extends TestCase
{
    use RefreshDatabase;

    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    private function kluczZFormularzaUgotowalem(User $kucharz, Recipe $przepis): ?string
    {
        return $this->kluczZFormularza(
            $this->actingAs($kucharz)->get(route('cooked.create', $przepis->slug))->getContent(),
        );
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

    public function test_dwa_klikniecia_wyslij_daja_jedno_wykonanie_i_jedno_powiadomienie(): void
    {
        $autor = $this->user('autorprzepisu');
        $kucharz = $this->user('kucharka');
        $przepis = $this->przepis($autor);

        $klucz = $this->kluczZFormularzaUgotowalem($kucharz, $przepis);

        $tresc = ['note' => 'Wyszło pięknie.', 'klucz_wyslania' => $klucz];

        $pierwsze = $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), $tresc);
        $drugie = $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), $tresc);

        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count(), 'Podwójne kliknięcie zapisało dwa wykonania.');
        $this->assertSame(1, $this->iloscPowiadomien($autor), 'Autor dostał dwa powiadomienia za jedno gotowanie.');
        $this->assertSame(
            $pierwsze->headers->get('Location'),
            $drugie->headers->get('Location'),
            'Drugie kliknięcie odesłało człowieka pod inny adres niż pierwsze.',
        );
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cooked_event.created')->count());

        // Komunikat mówi o powiadomieniu, więc musi być prawdziwy — liczba
        // powiadomień jest sprawdzona wyżej, w tym samym teście (§7.2 i §7.3
        // ADR-u).
        $drugie->assertSessionHas(
            'status',
            'To wykonanie już zapisaliśmy. Autor przepisu dostał jedno powiadomienie, nie dwa. '
            .'Gotujesz ten przepis drugi raz? Otwórz „Ugotowałem” jeszcze raz — każde wykonanie zapisujemy osobno.',
        );
    }

    public function test_drugie_prawdziwe_gotowanie_tego_samego_przepisu_zapisuje_sie(): void
    {
        // OBRONA D-005 („obowiązuje, nienaruszalne"): ta sama osoba może
        // ugotować ten sam przepis za tydzień i to jest osobne, wartościowe
        // wydarzenie. Bez tego testu pierwsza osoba, która zobaczy UNIQUE
        // w `cooked_events`, słusznie się przestraszy.
        $autor = $this->user('autorrosolu');
        $kucharz = $this->user('gotujacacotydzien');
        $przepis = $this->przepis($autor);

        $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Pierwsze gotowanie.',
            'klucz_wyslania' => $this->kluczZFormularzaUgotowalem($kucharz, $przepis),
        ])->assertSessionHasNoErrors();

        $this->travel(7)->days();

        $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Drugie gotowanie, tydzień później.',
            'klucz_wyslania' => $this->kluczZFormularzaUgotowalem($kucharz, $przepis),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CookedEvent::query()->count(), 'Drugie prawdziwe gotowanie nie zapisało się — to jest naruszenie D-005.');
        $this->assertSame(2, $this->iloscPowiadomien($autor), 'Autor nie dowiedział się o drugim wykonaniu.');
    }

    public function test_dwa_puste_wykonania_z_dwoch_formularzy_zapisuja_sie_osobno(): void
    {
        // Puste wykonanie („wystarczy sam fakt ugotowania") to najtrudniejszy
        // przypadek: odcisk treści degenerowałby się tu do
        // `(user_id, recipe_id)`, czyli do tego, czego D-005 zakazuje
        // (ADR §3.3). Klucz wysłania nie liczy treści, więc oba przechodzą.
        $autor = $this->user('autorpustych');
        $kucharz = $this->user('kucharzpustych');
        $przepis = $this->przepis($autor);

        foreach ([1, 2] as $ktore) {
            $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), [
                'klucz_wyslania' => $this->kluczZFormularzaUgotowalem($kucharz, $przepis),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, CookedEvent::query()->count());
        $this->assertSame(2, $this->iloscPowiadomien($autor));
    }

    public function test_klucz_z_cudzego_formularza_nie_blokuje_wlasnego_wykonania(): void
    {
        // UUID w żądaniu nie jest autoryzacją (AGENTS.md §7).
        $autor = $this->user('autorwspolny');
        $pierwsza = $this->user('pierwszakucharka');
        $druga = $this->user('drugakucharka');
        $przepis = $this->przepis($autor);

        $klucz = $this->kluczZFormularzaUgotowalem($pierwsza, $przepis);

        $this->actingAs($pierwsza)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Moje wykonanie.',
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $wykonaniePierwszej = CookedEvent::query()->firstOrFail();

        $obce = $this->actingAs($druga)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Wykonanie kogoś innego.',
            'klucz_wyslania' => $klucz,
        ]);

        $obce->assertSessionHasNoErrors();

        $this->assertSame(2, CookedEvent::query()->count(), 'Cudzy klucz zablokował własne wykonanie.');
        $this->assertNotSame(
            route('cooked.show', $wykonaniePierwszej),
            $obce->headers->get('Location'),
            'Cudzy klucz odesłał kogoś obcego na wykonanie innej osoby.',
        );
        $this->assertSame($pierwsza->getKey(), $wykonaniePierwszej->fresh()->user_id);
    }

    public function test_wyslanie_bez_klucza_dalej_zapisuje_wykonanie(): void
    {
        // Zawodzenie otwarte (ADR §4.3).
        $autor = $this->user('autorbezklucza');
        $kucharz = $this->user('kucharzbezklucza');
        $przepis = $this->przepis($autor);

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $przepis->slug), ['note' => 'Bez ukrytego pola.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count());
    }

    public function test_klucz_przezywa_blad_walidacji_formularza_ugotowalem(): void
    {
        // Ten sam wymóg, co przy wpisie (ADR §8.4): klucza nie zużywa
        // wysłanie, które wykonania nie zapisało. Inaczej po jednym błędzie
        // walidacji poprawione „Ugotowałem" nie miałoby jak przejść.
        $autor = $this->user('autorwalidacja');
        $kucharz = $this->user('kucharzwalidacja');
        $przepis = $this->przepis($autor);

        $klucz = $this->kluczZFormularzaUgotowalem($kucharz, $przepis);

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $przepis->slug), [
                'note' => str_repeat('a', 2001),
                'klucz_wyslania' => $klucz,
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(0, CookedEvent::query()->count());

        $poBledzie = $this->actingAs($kucharz)->get(route('cooked.create', $przepis->slug));

        $this->assertSame($klucz, $this->kluczZFormularza((string) $poBledzie->getContent()));

        $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Krótsza uwaga.',
            'klucz_wyslania' => $klucz,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count(), 'Poprawione wykonanie nie zapisało się.');
        $this->assertSame(1, $this->iloscPowiadomien($autor));
    }

    public function test_baza_odbija_drugie_wykonanie_z_tym_samym_kluczem_z_pominieciem_kontrolera(): void
    {
        $autor = $this->user('autorbaza');
        $kucharz = $this->user('kucharzbaza');
        $przepis = $this->przepis($autor);
        $klucz = (string) Str::uuid7();

        $wiersz = [
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Wstawione wprost do bazy.',
            'klucz_wyslania' => $klucz,
            'cooked_at' => now(),
            'created_at' => now(),
        ];

        DB::table('cooked_events')->insert(['id' => (string) Str::uuid7()] + $wiersz);

        $this->expectException(QueryException::class);

        DB::table('cooked_events')->insert(['id' => (string) Str::uuid7()] + $wiersz);
    }

    public function test_baza_przyjmuje_wiele_wykonan_tej_samej_pary_bez_klucza(): void
    {
        // To jest ten sam warunek, co zakaz z `AGENTS.md` §6, sprawdzony
        // w bazie: `UNIQUE (user_id, recipe_id)` nie istnieje i nie może
        // powstać przy okazji.
        $autor = $this->user('autorwielu');
        $kucharz = $this->user('kucharzwielu');
        $przepis = $this->przepis($autor);

        foreach ([1, 2, 3] as $ktore) {
            DB::table('cooked_events')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $kucharz->getKey(),
                'recipe_id' => $przepis->getKey(),
                'klucz_wyslania' => null,
                'cooked_at' => now(),
                'created_at' => now(),
            ]);
        }

        $this->assertSame(3, CookedEvent::query()->count());
    }
}
