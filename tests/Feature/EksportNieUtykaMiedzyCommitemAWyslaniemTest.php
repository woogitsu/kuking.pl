<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Eksport danych nie utyka pomiędzy commitem rekordu a wysłaniem zadania
 * (audyt A02, P1).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZMIERZONE PRZED POPRAWKĄ
 * ══════════════════════════════════════════════════════════════════════
 *
 * `DataSettingsController::requestExport()` zatwierdzał rekord `queued`,
 * a zadanie wysyłał DOPIERO POTEM:
 *
 *     $export = DB::transaction(fn () => DataExport::create([…]));  // COMMIT
 *     GenerateUserExport::dispatch((string) $export->getKey());     // linia 161
 *
 * Pomiędzy tymi dwiema linijkami jest okno. Cokolwiek się w nim stanie —
 * awaria zapisu do kolejki, `kill`, wyczerpana pamięć, restart przy
 * wdrożeniu — zostawia rekord `queued`, którego NIKT nie wykona. A wtedy
 * człowiek jest zamknięty: `maAktywnyEksport()` blokuje każde następne
 * zgłoszenie, bo w bazie stoi eksport `queued`. Nie ma przycisku „spróbuj
 * jeszcze raz", bo nie ma awarii, którą ekran mógłby pokazać — jest tylko
 * spokojne zdanie „Przygotowanie paczki już trwa", powtarzane bezterminowo.
 * To jest odmowa wykonania prawa z RODO art. 15 i 20, wyglądająca jak
 * cierpliwość.
 *
 * Zmierzone tymi testami przeciwko kodowi sprzed poprawki (kolejka
 * `database`, zapis do tabeli `jobs` przerwany przez `DB::beforeExecuting()`):
 *
 *     rekordów `data_exports` po awarii: 1     zadań w kolejce: 0
 *     odpowiedź na następne zgłoszenie: „Przygotowanie paczki […] już trwa."
 *
 * ── DLACZEGO `afterCommit()` TEGO NIE ZAMYKA ──
 *
 * Audyt pisze to wprost i ma rację: `afterCommit()` przenosi wysyłkę na
 * moment PO zatwierdzeniu, czyli zostawia dokładnie to samo okno, tylko
 * ustawia je w innym miejscu. Rekord jest już trwały, zadania jeszcze nie
 * ma. Sprawdzone w kodzie frameworka, nie przyjęte na słowo.
 *
 * Zamyka to natomiast coś, czego w tym projekcie nie trzeba dobudowywać:
 * kolejka jest kolejką BAZODANOWĄ, na tym samym połączeniu co aplikacja
 * (`config/queue.php`: `'default' => env('QUEUE_CONNECTION', 'database')`,
 * `'connection' => env('DB_QUEUE_CONNECTION')` — puste, czyli domyślne).
 * Wiersz w `jobs` i wiersz w `data_exports` mogą więc wejść do JEDNEJ
 * transakcji i zatwierdzić się razem. To jest transactional outbox
 * z ustaleń audytu, tylko bez nowej tabeli, bez Redisa i bez nowego
 * mechanizmu dostarczania — czyli dokładnie w granicach `AGENTS.md` §3.
 *
 * ── DRUGA POŁOWA: CO Z REKORDAMI, KTÓRE JUŻ TAM STOJĄ ──
 *
 * Outbox zamyka okno na przyszłość, ale nie odblokowuje konta, w którym
 * rekord `queued` utknął wcześniej — ani żadnej innej drogi zgubienia
 * zadania (wyczyszczona tabela `jobs`, worker, który nigdy nie wstał, zła
 * nazwa kolejki). Dlatego rekord porzucony w kolejce daje się PONOWIĆ:
 * następne zgłoszenie przejmuje TEN SAM wiersz, zamiast odmawiać.
 */
class EksportNieUtykaMiedzyCommitemAWyslaniemTest extends TestCase
{
    use RefreshDatabase;

    private const JUZ_TRWA = 'Przygotowanie paczki z Twoimi danymi już trwa. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.';

    /**
     * Kolejka bazodanowa — tak jak na produkcji (`.env`: `QUEUE_CONNECTION=database`).
     *
     * Zestaw testów chodzi domyślnie na `sync` (`phpunit.xml`), a przy `sync`
     * nie ma ani tabeli `jobs`, ani zapisu, który mógłby paść — czyli nie ma
     * czego mierzyć. To jest ta sama pułapka co „skan, który nie znalazł
     * żadnego pliku" (`docs/PULAPKI_TESTOW.md` §2), tylko w konfiguracji.
     */
    private function kolejkaBazodanowa(): void
    {
        config(['queue.default' => 'database']);
    }

    private function zepsujZapisDoKolejki(): void
    {
        DB::beforeExecuting(function (string $zapytanie): void {
            if (str_contains($zapytanie, 'insert into "jobs"')) {
                throw new RuntimeException('kolejka nie przyjmuje zadań (awaria wymuszona testem)');
            }
        });
    }

    /**
     * KONTROLA DODATNIA CAŁEGO PLIKU (`docs/PULAPKI_TESTOW.md` §4).
     *
     * Testy niżej sprawdzają, czego po awarii NIE MA. Przeszłyby także wtedy,
     * gdyby zgłoszenie eksportu nie działało nigdy.
     */
    public function test_zwykle_zgloszenie_tworzy_rekord_i_wysyla_zadanie(): void
    {
        $this->kolejkaBazodanowa();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame(DataExport::STATUS_QUEUED, DataExport::query()->firstOrFail()->status);
        $this->assertSame(1, DB::table('jobs')->count(), 'Zadanie nie weszło do kolejki.');
    }

    public function test_awaria_kolejki_nie_zostawia_eksportu_ktorego_nikt_nie_wykona(): void
    {
        $this->kolejkaBazodanowa();
        $this->withoutExceptionHandling();

        $basia = $this->user('basia');

        $this->zepsujZapisDoKolejki();

        $zlapany = null;

        try {
            $this->actingAs($basia)->post(route('settings.data.export'));
        } catch (RuntimeException $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Awaria zapisu do kolejki nie przerwała zgłoszenia — test nie zmierzył tego, co miał.');
        $this->assertStringContainsString('kolejka nie przyjmuje zadań', $zlapany->getMessage());

        // TO JEST CAŁE ZNALEZISKO A02. Przed poprawką stało tu „1 / 0":
        // rekord trwały, zadania nie ma, konto zablokowane bezterminowo.
        $this->assertSame(
            0,
            DataExport::query()->count(),
            'Rekord eksportu został zatwierdzony, mimo że zadanie nie weszło do kolejki — nikt go nie wykona.',
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Po awarii kolejki człowiek może zgłosić eksport jeszcze raz I DOSTAĆ GO
     * — a nie usłyszeć „już przygotowujemy".
     *
     * Sabotaż psuje WYŁĄCZNIE PIERWSZY zapis do `jobs`, żeby drugie
     * zgłoszenie szło już zupełnie zwykłą drogą. Bez tego licznika test
     * mierzyłby to samo, co test wyżej, tylko innymi słowami.
     */
    public function test_po_awarii_kolejki_da_sie_zglosic_eksport_jeszcze_raz_i_zadanie_wchodzi(): void
    {
        $this->kolejkaBazodanowa();
        $this->withoutExceptionHandling();

        $basia = $this->user('basia');

        $padlo = false;

        DB::beforeExecuting(function (string $zapytanie) use (&$padlo): void {
            if (! $padlo && str_contains($zapytanie, 'insert into "jobs"')) {
                $padlo = true;

                throw new RuntimeException('kolejka nie przyjmuje zadań (awaria wymuszona testem)');
            }
        });

        try {
            $this->actingAs($basia)->post(route('settings.data.export'));
        } catch (RuntimeException) {
            // Oczekiwane — pierwsze zgłoszenie pada na kolejce.
        }

        $this->assertTrue($padlo, 'Sabotaż nie trafił w zapis do kolejki — test nie zmierzył niczego.');
        $this->assertSame(0, DataExport::query()->count());

        // DRUGIE zgłoszenie, już bez awarii. Przed poprawką odbiłoby się
        // o „Przygotowanie paczki już trwa" i nie powstałoby ani zadanie,
        // ani nowy rekord.
        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    /**
     * Rekord porzucony w kolejce daje się ponowić — bez tego konto, w którym
     * eksport utknął PRZED tą poprawką, zostałoby zablokowane na zawsze.
     */
    public function test_eksport_porzucony_w_kolejce_zostaje_ponowiony_przy_kolejnym_zgloszeniu(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $porzucony = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        // Rekord bez ruchu dłużej niż okno podjęcia — czyli dokładnie stan,
        // który zostawiała awaria w oknie z nagłówka tego pliku.
        DataExport::query()->whereKey($porzucony->getKey())->update([
            'created_at' => now()->subMinutes(DataExport::MINUT_NA_PODJECIE + 5),
            'updated_at' => now()->subMinutes(DataExport::MINUT_NA_PODJECIE + 5),
        ]);

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, 'ponowiliśmy'));

        // TEN SAM wiersz, nie drugi — indeks częściowy
        // `data_exports_one_active_per_user` (D-078) nie dopuszcza dwóch
        // aktywnych, a i bez niego dwa wiersze znaczyłyby dwie paczki tych
        // samych zdjęć i dwa listy z dobowego wiadra.
        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame($porzucony->getKey(), DataExport::query()->firstOrFail()->getKey());

        Queue::assertPushed(GenerateUserExport::class, 1);

        // Znacznik ruchu przesunięty, więc następny dwuklik NIE ponowi zadania
        // jeszcze raz. Bez tego „ponów" byłby przyciskiem do mnożenia zadań.
        $this->assertTrue(DataExport::query()->firstOrFail()->updated_at->isAfter(now()->subMinute()));
    }

    /**
     * KONTROLA DODATNIA DO POPRZEDNIEGO TESTU, i to ta ważniejsza połowa
     * (`docs/PULAPKI_TESTOW.md` §4): eksport, który dopiero wszedł do
     * kolejki, NIE jest ponawiany. Inaczej „ponów" znaczyłoby „ponów
     * zawsze", czyli dwuklik pakowałby zdjęcia dwa razy.
     */
    public function test_swiezo_zakolejkowany_eksport_nie_jest_ponawiany(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHas('status', self::JUZ_TRWA);

        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        Queue::assertNothingPushed();
    }

    /**
     * PUŁAPKA NA PRZYSZŁOŚĆ, nie obsługa przypadku: kolejka, która NIE
     * zapisuje zadań do tej samej bazy co rekord, odmawia głośno.
     *
     * Przy Redisie albo SQS-ie zadanie stałoby się widoczne dla workera
     * PRZED commitem rekordu — czyli ta sama usterka co A02, tylko
     * odwrócona: worker sięgałby po `data_exports`, którego jeszcze nie ma.
     * `AGENTS.md` §3 zabrania tu obu tych kolejek, więc to nie jest
     * przypadek do obsłużenia, a granica do postawienia. Ten sam wzorzec co
     * `ZdjeciaDoPrzypiecia::zablokuj()`: niech pada przy pierwszym
     * uruchomieniu testów, a nie po cichu na produkcji.
     */
    public function test_kolejka_poza_baza_aplikacji_odmawia_zamiast_gubic_rekord(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
        ]);

        $this->withoutExceptionHandling();

        $basia = $this->user('basia');

        $zlapany = null;

        try {
            $this->actingAs($basia)->post(route('settings.data.export'));
        } catch (LogicException $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Kolejka spoza bazy aplikacji przeszła bez słowa — pułapka nie działa.');
        $this->assertStringContainsString('nie zapisuje zadań do tej samej bazy', $zlapany->getMessage());

        // I ani jednego rekordu — odmowa cofa transakcję, więc konto nie
        // zostaje z eksportem, którego nikt nie wykona.
        $this->assertSame(0, DataExport::query()->count());
    }

    /**
     * Eksport, który worker już PODJĄŁ (`processing`), nie jest ponawiany
     * niezależnie od tego, jak długo trwa.
     *
     * Granica jest tu świadoma i wąska: ponowienie zadania, które właśnie
     * pakuje setki megabajtów, dałoby dwa workery na tym samym rekordzie
     * i dwa listy do tej samej osoby. Rekordy zawieszone w `processing`
     * domyka `GenerateUserExport::failed()` (łapie także przekroczenie
     * 15-minutowego limitu czasu) i to jest właściwe dla nich miejsce.
     */
    public function test_eksport_w_robocie_nie_jest_ponawiany_nawet_stary(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $wRobocie = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_PROCESSING,
        ]);

        DataExport::query()->whereKey($wRobocie->getKey())->update([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));

        $odpowiedz->assertSessionHas('status', self::JUZ_TRWA);

        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        Queue::assertNothingPushed();
    }
}
