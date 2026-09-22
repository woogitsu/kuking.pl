<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\PriorytetSprawy;
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
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO SIĘ ZMIENIŁO PO DOŁOŻENIU PRIORYTETU (22 września 2026)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Kolejka zgłoszeń sortuje dziś `priorytet ASC, created_at DESC, id DESC`
 * (`App\Domain\Moderation\PriorytetSprawy`), więc zdanie „najnowsze na
 * górze" przestało obowiązywać W CAŁEJ KOLEJCE. Trzeba to powiedzieć wprost,
 * bo stara gwarancja była świadoma i udokumentowana, a nie przypadkowa.
 *
 * DLACZEGO UPADŁA. Obiecywała porządek, którego nie da się obronić przy
 * spamie: on przychodzi falami, więc „najnowsze na górze" znaczyło w praktyce
 * „im gorszy dzień, tym głębiej leży rzecz najcięższa". Zmierzone przed
 * zmianą, w `KolejkaModeracjiStawiaPilneNaGorzeTest`: zgłoszenie „Dotyczy
 * dziecka" sprzed dwóch dni pod trzydziestoma zgłoszeniami spamu z ostatniej
 * godziny, czyli na DRUGIEJ stronie kolejki.
 *
 * CO JĄ ZASTĘPUJE — DWIE OBIETNICE ZAMIAST JEDNEJ:
 *
 *  1. „Najnowsze na górze" obowiązuje WEWNĄTRZ jednego priorytetu i jest tam
 *     nietknięte. Mierzą to trzy testy niżej: wszystkie ich zgłoszenia mają
 *     `reason = 'spam'`, czyli jeden priorytet, więc mierzą DOKŁADNIE to, co
 *     mierzyły wcześniej — i przechodzą bez zmiany treści;
 *  2. stabilne stronicowanie zostaje bez żadnego osłabienia: remis dalej
 *     rozstrzyga `id`, tylko teraz w ramach wagi. Mierzy to test
 *     `test_kolejka_zgloszen_dzieli_sie_na_strony_stabilnie_takze_przy_mieszanych_priorytetach`,
 *     dopisany razem z priorytetem — bo to jest właśnie ten warunek, którego
 *     nowy pierwszy człon `ORDER BY` mógłby nie spełnić.
 *
 * Czego ta zmiana NIE zrobiła: nie odwróciła kierunku WEWNĄTRZ wagi.
 * Odrzucona gałąź `claude/priorytet-w-kolejce-moderacji` proponowała
 * `priorytet ASC, created_at ASC, id ASC` — najstarsze na górze, „bliżej
 * terminu". To jest osobna decyzja, z własną ceną (góra kolejki przestaje
 * się odświeżać), i bez dowodu, że jej potrzebujemy. Jedna zmiana naraz.
 *
 * Kolejki ODWOŁAŃ ta zmiana nie dotyczy w ogóle: `appeals` nie ma kategorii,
 * ma termin liczony od złożenia i dalej sortuje się najstarszymi na górze.
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
     * STABILNE STRONICOWANIE PRZEŻYWA DOŁOŻENIE PRIORYTETU.
     *
     * Nowy pierwszy człon `ORDER BY` jest dokładnie tym, co mogłoby zepsuć
     * obietnicę z góry tego pliku: gdyby priorytet liczył się inaczej przy
     * różnych planach zapytania albo gdyby remis wewnątrz wagi przestał być
     * rozstrzygany po `id`, pozycje znów zaczęłyby przeskakiwać między
     * stronami — tylko że tym razem po CICHU, bo kolejność na pierwszej
     * stronie wyglądałaby sensownie.
     *
     * Trzy kategorie, po jednej z każdej wagi, wymieszane w czasie:
     * `minor` (P0), `scam` (P1) i `spam` (P2). Oczekiwany porządek liczymy
     * w PHP z tej samej stałej, z której baza buduje swój `CASE`.
     */
    public function test_kolejka_zgloszen_dzieli_sie_na_strony_stabilnie_takze_przy_mieszanych_priorytetach(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacamix');

        $powody = ['minor', 'scam', 'spam'];
        $wstawione = [];

        for ($i = 0; $i < self::ILE; $i++) {
            $zgloszenie = Report::create([
                'reporter_id' => $zglaszajaca->getKey(),
                'target_type' => 'post',
                'target_id' => (string) Str::uuid(),
                'reason' => $powody[$i % 3],
                'status' => Report::STATUS_OPEN,
            ]);

            // Czas REMISUJE PARAMI wewnątrz każdej wagi — bez remisu ten
            // test nie sprawdziłby tego, po co powstał: rozstrzygnięcia
            // po `id` w ramach wagi.
            $chwila = now()->subMinutes(intdiv($i, 6));
            $zgloszenie->forceFill(['created_at' => $chwila, 'updated_at' => $chwila])->save();

            $wstawione[] = $zgloszenie;
        }

        $this->przestawSterte('reports');

        // Oczekiwany porządek liczymy tak, jak ma sortować baza:
        // `priorytet ASC, created_at DESC, id DESC`.
        usort($wstawione, static function (Report $a, Report $b): int {
            return [PriorytetSprawy::dla($a), -$a->created_at->getTimestamp(), 0] <=> [PriorytetSprawy::dla($b), -$b->created_at->getTimestamp(), 0]
                ?: strcmp((string) $b->getKey(), (string) $a->getKey());
        });

        $oczekiwany = array_map(static fn (Report $r): string => (string) $r->getKey(), $wstawione);

        $zebrane = $this->przejdzStrony(route('admin.reports'), $moderator, 'reports');

        $this->assertPorzadek($oczekiwany, $zebrane, 'zgłoszeń', 'najpilniejsze na górze, wewnątrz wagi najnowsze');
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
