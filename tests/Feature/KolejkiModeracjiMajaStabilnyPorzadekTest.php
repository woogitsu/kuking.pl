<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dwie kolejki moderacyjne dzielą się na strony ZAWSZE TAK SAMO (audyt G10).
 *
 * CO BYŁO NIE TAK
 * `ModerationController::reports()` sortowało samym `latest()`
 * (`ORDER BY created_at DESC`), a `AppealController::index()` samym
 * `orderBy('created_at')`. Przy REMISIE na `created_at` PostgreSQL nie
 * obiecuje żadnej kolejności: oddaje wiersze w takim porządku, w jakim
 * dotarły do sortowania, czyli w porządku FIZYCZNYM w stercie.
 *
 * DLACZEGO TO NIE JEST KOSMETYKA
 * Obie kolejki są stronicowane po 25. Niestabilny porządek nie znaczy „inna
 * kolejność na ekranie", tylko INNY PODZIAŁ NA STRONY między jednym
 * kliknięciem a drugim: to samo zgłoszenie widziane dwa razy na dwóch
 * stronach, a inne — pominięte. Moderator nie ma jak tego zauważyć, bo nie
 * zna liczby, której szuka. Przy odwołaniach boli podwójnie: mają termin
 * odpowiedzi (DSA art. 20), a pominięte nie zgłosi się samo.
 *
 * CZEGO NIE TWIERDZĘ
 * Że moderator już gubi sprawy. Duplikatu między stronami nie udało się
 * odtworzyć — ani audytowi, ani tutaj. To jest usterka GWARANCJI, nie
 * zaobserwowana awaria, i tak trzeba ją czytać.
 *
 * ZMIERZONE, na 60 zgłoszeniach z identycznym `created_at`, bez łatki:
 *
 *   • to samo zapytanie różniące się WYŁĄCZNIE obecnością `LIMIT 25` oddaje
 *     dwa RÓŻNE zestawy pierwszych 25 wierszy. Bez limitu wychodzi porządek
 *     sterty, z limitem — porządek sortowania top-N. Jedna zmiana planu, dwie
 *     odpowiedzi;
 *   • po przestawieniu sterty (`UPDATE` na 30 wierszach) kolejka zwraca
 *     porządek inny niż `id DESC` i inny niż kolejność wstawiania.
 *
 * Po łatce oba pomiary dają dokładnie jeden porządek. `id` jest UUID-em v7,
 * więc rozstrzyga remis w tę samą stronę co czas i nie zmienia kolejności
 * ANI JEDNEJ pary wierszy o różnym `created_at`.
 *
 * DLACZEGO TESTY NIŻEJ PRZESTAWIAJĄ STERTĘ
 * Bo bez tego nie mierzyłyby niczego: przy świeżo wstawionych wierszach
 * `paginate()` i tak trafiał w `id DESC`, więc asercja przechodziłaby również
 * na kodzie sprzed łatki. `UPDATE` rozjeżdża porządek fizyczny z porządkiem
 * identyfikatorów, bo Postgres zapisuje wtedy nową wersję krotki, zwykle na
 * końcu tabeli.
 *
 * To nie jest sztuczka wymyślona na potrzeby testu. Rozpatrzenie zgłoszenia
 * i rozstrzygnięcie odwołania to właśnie UPDATE — czyli codzienna praca
 * moderatora przestawia stertę tej samej tabeli, po której chodzi jego
 * kolejka.
 */
class KolejkiModeracjiMajaStabilnyPorzadekTest extends TestCase
{
    use RefreshDatabase;

    private const ILE = 60;

    public function test_kolejka_zgloszen_dzieli_sie_na_strony_po_id_gdy_czas_remisuje(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajaca');

        $chwila = now()->subHour();
        $wstawione = [];

        for ($i = 0; $i < self::ILE; $i++) {
            $zgloszenie = Report::create([
                'reporter_id' => $zglaszajaca->getKey(),
                'target_type' => 'post',
                // Losowy cel, bo `reports_one_open_per_pair` nie pozwala
                // złożyć dwóch otwartych zgłoszeń na tę samą parę.
                'target_id' => (string) Str::uuid(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);

            $zgloszenie->forceFill(['created_at' => $chwila, 'updated_at' => $chwila])->save();

            $wstawione[] = (string) $zgloszenie->getKey();
        }

        $this->przestawSterte('reports');

        $zebrane = $this->przejdzStrony(route('admin.reports'), $moderator, 'reports');

        $oczekiwany = $wstawione;
        rsort($oczekiwany);

        $this->assertPorzadek($oczekiwany, $zebrane, 'zgłoszeń', 'najnowsze na górze');
    }

    public function test_kolejka_odwolan_dzieli_sie_na_strony_po_id_gdy_czas_remisuje(): void
    {
        $admin = $this->admin();
        $autor = $this->user('autor');

        $chwila = now()->subHour();
        $wstawione = [];

        for ($i = 0; $i < self::ILE; $i++) {
            $odwolanie = Appeal::create([
                'moderation_action_id' => $this->decyzja($admin)->getKey(),
                'user_id' => $autor->getKey(),
                'appellant' => Appeal::APPELLANT_AUTHOR,
                'body' => 'Nie zgadzam się z tą decyzją.',
                'status' => Appeal::STATUS_OPEN,
            ]);

            $odwolanie->forceFill(['created_at' => $chwila, 'updated_at' => $chwila])->save();

            $wstawione[] = (string) $odwolanie->getKey();
        }

        $this->przestawSterte('appeals');

        $zebrane = $this->przejdzStrony(route('admin.appeals'), $admin, 'appeals');

        // Odwrotny kierunek niż przy zgłoszeniach i to jest zamierzone:
        // odwołania mają termin liczony od złożenia, więc najstarsze idą
        // na górę. Rozstrzygnięcie remisu leci w tę samą stronę.
        $oczekiwany = $wstawione;
        sort($oczekiwany);

        $this->assertPorzadek($oczekiwany, $zebrane, 'odwołań', 'najstarsze na górze');
    }

    /**
     * Kontrola metody pomiaru: bez rozstrzygnięcia remisu porządek NAPRAWDĘ
     * zależy od planu zapytania.
     *
     * Bez tego testu dwie asercje wyżej byłyby tylko opinią: „dopisaliśmy
     * `id`, więc jest lepiej". Tu widać wprost, że przed łatką to samo
     * zapytanie — różniące się WYŁĄCZNIE obecnością `LIMIT` — oddaje dwa
     * różne zestawy pierwszych 25 wierszy. `paginate()` dokłada do zapytania
     * dokładnie `LIMIT` i `OFFSET`, więc to nie jest przypadek akademicki:
     * tak właśnie różnią się między sobą strony kolejki.
     *
     * Druga połowa testu pokazuje, że po dopisaniu `id` różnica znika.
     */
    public function test_kontrola_bez_rozstrzygniecia_remisu_porzadek_zalezy_od_planu(): void
    {
        $zglaszajaca = $this->user('zglaszajaca');
        $chwila = now()->subHour();

        for ($i = 0; $i < self::ILE; $i++) {
            $zgloszenie = Report::create([
                'reporter_id' => $zglaszajaca->getKey(),
                'target_type' => 'post',
                'target_id' => (string) Str::uuid(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);

            $zgloszenie->forceFill(['created_at' => $chwila, 'updated_at' => $chwila])->save();
        }

        // TAK BYŁO: sam `created_at`.
        $pelneBez = array_map('strval', Report::query()->orderByDesc('created_at')->pluck('id')->all());
        $zLimitemBez = array_map('strval', Report::query()->orderByDesc('created_at')->limit(25)->pluck('id')->all());

        $this->assertNotSame(
            array_slice($pelneBez, 0, 25),
            $zLimitemBez,
            'To samo zapytanie z `LIMIT` i bez `LIMIT` oddało ten sam porządek — '
            .'czyli ten test przestał pokazywać, o co chodzi w tej poprawce. '
            .'Jeśli PostgreSQL zaczął zachowywać się inaczej, popraw nagłówek '
            .'tego pliku razem z tą asercją; NIE usuwaj rozstrzygnięcia remisu '
            .'z kontrolerów, bo obietnicy dalej nie ma.',
        );

        // TAK JEST: `created_at` plus `id`.
        $pelneZ = array_map(
            'strval',
            Report::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all(),
        );
        $zLimitemZ = array_map(
            'strval',
            Report::query()->orderByDesc('created_at')->orderByDesc('id')->limit(25)->pluck('id')->all(),
        );

        $this->assertSame(
            array_slice($pelneZ, 0, 25),
            $zLimitemZ,
            'Po dopisaniu `id` obecność `LIMIT` nadal zmienia wynik. Rozstrzygnięcie '
            .'remisu nie działa albo nie dotarło do zapytania.',
        );
    }

    /**
     * Rozjeżdża porządek FIZYCZNY z porządkiem identyfikatorów.
     *
     * UPDATE w PostgreSQL zapisuje nową wersję krotki, zwykle na końcu
     * tabeli, więc skan sekwencyjny czyta te wiersze jako ostatnie. Kolumna
     * `updated_at` jest tu bez znaczenia — liczy się sam fakt zapisu.
     */
    private function przestawSterte(string $tabela): void
    {
        DB::statement(
            "UPDATE {$tabela} SET updated_at = updated_at WHERE id IN ("
            ."SELECT id FROM {$tabela} ORDER BY id LIMIT 30)",
        );
    }

    /**
     * @return list<string>
     */
    private function przejdzStrony(string $adres, User $kto, string $klucz): array
    {
        $zebrane = [];

        foreach ([1, 2, 3] as $strona) {
            $odpowiedz = $this->actingAs($kto)->get($adres.'?page='.$strona);
            $odpowiedz->assertOk();

            foreach ($odpowiedz->viewData($klucz) as $wiersz) {
                $zebrane[] = (string) $wiersz->getKey();
            }
        }

        return $zebrane;
    }

    /**
     * @param  list<string>  $oczekiwany
     * @param  list<string>  $zebrane
     */
    private function assertPorzadek(array $oczekiwany, array $zebrane, string $co, string $kierunek): void
    {
        // Najpierw to, co boli człowieka: nic się nie zdublowało i nic nie
        // zniknęło między stronami. Ta asercja jest osobno, bo jej komunikat
        // mówi o skutku, a nie o kolejności.
        $this->assertSame(
            count($zebrane),
            count(array_unique($zebrane)),
            "Stronicowanie kolejki {$co} pokazało tę samą pozycję dwa razy. "
            .'Skoro jedna wraca dwa razy, to inna została pominięta — '
            .'a moderator nie ma jak tego zauważyć.',
        );

        $this->assertEqualsCanonicalizing(
            $oczekiwany,
            $zebrane,
            "Trzy strony kolejki {$co} nie pokryły wszystkich pozycji.",
        );

        $this->assertSame(
            $oczekiwany,
            $zebrane,
            "Kolejka {$co} przy remisie na `created_at` nie rozstrzyga po `id` "
            ."({$kierunek}). Porządek zależy wtedy od fizycznego układu wierszy "
            .'w tabeli, który przestawia każdy UPDATE — czyli każda rozpatrzona '
            .'sprawa.',
        );
    }

    private function decyzja(User $moderator): ModerationAction
    {
        return ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
        ]);
    }
}
