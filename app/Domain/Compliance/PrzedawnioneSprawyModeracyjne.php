<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retencja SPRAWY MODERACYJNEJ — `appeals` + `moderation_actions` + `reports`
 * (issue #19, docs/decyzje/ADR_RETENCJE.md §4, §5.3-5.5).
 *
 * Wspólny okres (`config('kuking.moderation.case_retention_months')`,
 * decyzja właściciela — 36 miesięcy, art. 442¹ k.c.), liczony od ZAMKNIĘCIA
 * każdej z trzech tabel osobno: `appeals.decided_at`, `moderation_actions.created_at`
 * (decyzja jest niemutowalna), `reports.resolved_at`. Sprawy wciąż otwarte
 * NIGDY nie są kandydatem, niezależnie od wieku — to nie jest wyjątek liczony
 * jak w `audit_log`, tylko konsekwencja tego, że "zamknięcie" nie istnieje.
 *
 * DLACZEGO JEDNA KOMENDA DLA TRZECH TABEL, KOLEJNOŚĆ APPEALS → MODERATION_ACTIONS → REPORTS
 *
 * `appeals.moderation_action_id` ma `cascadeOnDelete` (migracja
 * `2026_09_06_100100_create_appeals_table`) — skasowanie `moderation_actions`
 * zabiera jego `appeals` razem z nim, bez pytania. Gdyby ta komenda kasowała
 * `moderation_actions` wyłącznie na podstawie JEGO WŁASNEGO wieku, odwołanie
 * zniknęłoby, nawet gdyby jego WŁASNY okres retencji jeszcze nie minął —
 * dokładnie błąd, przed którym ostrzega ADR §4.
 *
 * Rozwiązanie: usuwamy `appeals` NAJPIERW (ich własny wiek), więc gdy
 * przychodzi kolej na `moderation_actions`, tabela `appeals` już "wie", czy
 * dana decyzja ma jeszcze żywe odwołanie — bez dodatkowego, specjalnego
 * zapytania. "Żywe" odwołanie = stan `open` (blokuje BEZWARUNKOWO,
 * niezależnie od wieku decyzji) ALBO rozpatrzone, ale jeszcze przed swoim
 * WŁASNYM progiem retencji. Równa liczba miesięcy dla obu tabel (ta sama
 * wartość configu) usuwa pułapkę, w której `moderation_actions` realnie
 * żyłoby tak długo, jak jego najdłużej ważne odwołanie.
 *
 * `reports` idzie na końcu: `moderation_actions.report_id` ma `nullOnDelete()`,
 * więc kolejność względem `moderation_actions` nie ma znaczenia dla
 * bezpieczeństwa (ten kierunek kaskady jest bezpieczny sam z siebie) — ale
 * ADR każe trzymać wszystkie trzy kroki w JEDNEJ komendzie, żeby dziennik
 * działania opisywał całą "sprawę" spójnie.
 *
 * WZORZEC C — TRANSAKCJA PER WIERSZ, NIE ŚLEPY MASOWY `DELETE`
 * W odróżnieniu od `product_signals`/`audit_log`/`notifications`, te trzy
 * tabele zależą od siebie przez klucze obce z różnym zachowaniem przy
 * kasowaniu, więc każdy wiersz jest osobną transakcją i osobną próbą —
 * błąd jednego nie blokuje reszty listy, a zostaje log z identyfikatorem.
 *
 * BŁĄD PRZY KASOWANIU ODWOŁANIA MUSI ZABLOKOWAĆ KASOWANIE JEGO DECYZJI
 * (ADR §5.4) — nie może "zgadywać", że się udało. Jeśli transakcja kasująca
 * `appeals` zawiedzie, wiersz zostaje w bazie NIETKNIĘTY (rollback), więc gdy
 * krok drugi pyta "czy ta decyzja ma jeszcze jakiekolwiek `appeals`",
 * odpowiedź brzmi "tak" — i decyzja zostaje pominięta w tym przebiegu razem
 * z nieudanie skasowanym odwołaniem. Kolejny przebieg podejmie oba wiersze
 * razem.
 *
 * "ŻYWE ODWOŁANIE" LICZYMY WPROST NA TABELI `appeals`, NIE PRZEZ RELACJĘ
 * Od migracji `2026_09_07_800000_appeals_open_to_reporters` (issue #23,
 * DSA art. 20 ust. 1) jedna decyzja może mieć DWA odwołania naraz — od
 * autora treści i od zgłaszającego (`appeals.appellant`), każde z osobnym
 * `UNIQUE (moderation_action_id, appellant)`. `ModerationAction` nie ma już
 * pojedynczej relacji `appeal()` — ma `authorAppeal()` i `reporterAppeal()`
 * osobno. Ta klasa świadomie NIE woła żadnej z nich: blokada kasowania
 * decyzji ma dotyczyć KAŻDEGO żywego odwołania, niezależnie od roli, więc
 * pytanie idzie wprost do `appeals` przez `moderation_action_id` —
 * odporne na to, że jutro dojdzie trzecia rola albo relacje zmienią nazwę
 * jeszcze raz.
 *
 * PARTIE PO KLUCZU, NIE `get()` NA CAŁYM BACKLOGU (issue #998)
 * Każdy z trzech etapów czyta kandydatów przez `chunkById` po
 * {@see self::ROZMIAR_PARTII} wierszy — pamięć jednego przebiegu jest
 * ograniczona rozmiarem partii, nie liczbą rekordów z całej historii (pierwszy
 * próg retencji, dłuższy przestój harmonogramu). `chunkById`, nie `chunk`:
 * kolejna partia startuje od `id > ostatnie_id`, więc kasowanie w trakcie
 * iteracji niczego nie przesuwa (bez pominięć i duplikatów), a wiersz, którego
 * nie udało się skasować, nie wraca w tej samej pętli. Kolejność CAŁYCH
 * etapów (appeals → moderation_actions → reports) i transakcja per wiersz
 * zostają bez zmian; warunek "żywego odwołania" jest liczony w zapytaniu
 * każdej partii, więc chroni decyzję także na granicy partii.
 */
final class PrzedawnioneSprawyModeracyjne
{
    /** Maksymalna liczba modeli hydratowanych naraz w jednym etapie. */
    public const ROZMIAR_PARTII = 500;

    public function __construct(
        private readonly int $rozmiarPartii = self::ROZMIAR_PARTII,
    ) {}

    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): RaportRetencjiSpraw
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — ta sama pułapka co
        // w `PrzedawnionePowiadomienia` (A6-04): przepełnienie daty przesuwa
        // próg w stronę nowszych wierszy i kasuje je przed czasem.
        // Pełne uzasadnienie i pomiar są tam, przy oryginalnym znalezisku.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        [$usunieteOdwolania, $bledyOdwolan] = $this->posprzatajOdwolania($prog, $naSucho);

        [$usunieteDecyzje, $bledyDecyzji, $pominieteZywymOdwolaniem] = $this->posprzatajDecyzje($prog, $naSucho);

        [$usunieteZgloszenia, $bledyZgloszen] = $this->posprzatajZgloszenia($prog, $naSucho);

        $raport = new RaportRetencjiSpraw(
            usunieteOdwolania: $usunieteOdwolania,
            bledyOdwolan: $bledyOdwolan,
            usunieteDecyzje: $usunieteDecyzje,
            bledyDecyzji: $bledyDecyzji,
            pominieteDecyzjeZywymOdwolaniem: $pominieteZywymOdwolaniem,
            usunieteZgloszenia: $usunieteZgloszenia,
            bledyZgloszen: $bledyZgloszen,
        );

        // Podsumowanie w logu aplikacji, nie tylko na wyjściu komendy —
        // żeby nocny przebieg zostawiał ślad także poza logiem harmonogramu.
        // W trybie normalnym kandydaci = usunięte + błędy.
        Log::info('Retencja spraw moderacyjnych: podsumowanie przebiegu', [
            'na_sucho' => $naSucho,
            'rozmiar_partii' => $this->rozmiarPartii,
            'kandydaci_odwolan' => $usunieteOdwolania + $bledyOdwolan,
            'usuniete_odwolania' => $naSucho ? 0 : $usunieteOdwolania,
            'bledy_odwolan' => $bledyOdwolan,
            'kandydaci_decyzji' => $usunieteDecyzje + $bledyDecyzji,
            'usuniete_decyzje' => $naSucho ? 0 : $usunieteDecyzje,
            'bledy_decyzji' => $bledyDecyzji,
            'pominiete_decyzje_zywym_odwolaniem' => $pominieteZywymOdwolaniem,
            'kandydaci_zgloszen' => $usunieteZgloszenia + $bledyZgloszen,
            'usuniete_zgloszenia' => $naSucho ? 0 : $usunieteZgloszenia,
            'bledy_zgloszen' => $bledyZgloszen,
        ]);

        return $raport;
    }

    /**
     * Kasuje kandydatów partiami po kluczu, każdy wiersz w osobnej transakcji.
     * Błąd jednego wiersza trafia do logu i nie zatrzymuje reszty.
     *
     * @param  Builder<covariant Model>  $kandydaci
     * @param  callable(Model): array<string, mixed>  $kontekstBledu
     * @return array{0: int, 1: int} [usunięto, błędy]
     */
    private function skasujPartiami(Builder $kandydaci, string $komunikatBledu, callable $kontekstBledu): array
    {
        $usuniete = 0;
        $bledy = 0;

        $kandydaci->chunkById($this->rozmiarPartii, function ($partia) use (&$usuniete, &$bledy, $komunikatBledu, $kontekstBledu): void {
            foreach ($partia as $wiersz) {
                try {
                    DB::transaction(static function () use ($wiersz): void {
                        $wiersz->delete();
                    });
                    $usuniete++;
                } catch (Throwable $e) {
                    $bledy++;
                    Log::error($komunikatBledu, [...$kontekstBledu($wiersz), 'error' => $e->getMessage()]);
                }
            }
        });

        return [$usuniete, $bledy];
    }

    /** @return array{0: int, 1: int} [usunięto, błędy] */
    private function posprzatajOdwolania(CarbonInterface $prog, bool $naSucho): array
    {
        $kandydaci = Appeal::query()
            ->whereIn('status', [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED])
            ->where('decided_at', '<', $prog);

        if ($naSucho) {
            return [$kandydaci->count(), 0];
        }

        return $this->skasujPartiami(
            $kandydaci,
            'Nie udało się skasować przedawnionego odwołania',
            static fn (Model $odwolanie): array => [
                'appeal_id' => $odwolanie->getKey(),
                'moderation_action_id' => $odwolanie->getAttribute('moderation_action_id'),
            ],
        );
    }

    /**
     * Podzapytanie `EXISTS (SELECT 1 FROM appeals WHERE moderation_action_id = ...)`,
     * opcjonalnie zawężone do odwołań "żywych" (`open`, albo rozpatrzone, ale
     * jeszcze przed swoim własnym progiem retencji). Wprost na tabeli, nie
     * przez relację Eloquent — patrz komentarz klasy.
     *
     * $tylkoZywe = true: używane w trybie NA SUCHO, gdzie appeals, które BY
     * zniknęły w kroku 1, wciąż fizycznie tu są — liczymy więc wg WARUNKU
     * "żywe odwołanie", zakładając powodzenie kroku 1 (tak samo jak dry-run
     * w reszcie repozytorium, `SprzatajSygnaly`/`sprzataj-eksporty`, zawsze
     * zakłada, że operacja by się udała).
     *
     * $tylkoZywe = false: używane w trybie NORMALNYM, PO wykonaniu kroku 1 —
     * wtedy samo ISTNIENIE jakiegokolwiek wiersza `appeals` wystarcza, bo
     * krok 1 już usunął te, którym minął czas; to, co zostało, jest z
     * definicji żywe ALBO nieudanie skasowane (co i tak ma blokować —
     * ADR §5.4: błąd przy `appeals` musi zablokować kasowanie decyzji, a nie
     * zgadywać, że się udało).
     */
    private function odwolaniePodzapytanie(CarbonInterface $prog, bool $tylkoZywe): \Closure
    {
        return static function (QueryBuilder $query) use ($prog, $tylkoZywe): void {
            $query->select(DB::raw(1))
                ->from('appeals')
                ->whereColumn('appeals.moderation_action_id', 'moderation_actions.id');

            if ($tylkoZywe) {
                $query->where(function (QueryBuilder $q) use ($prog): void {
                    $q->where('appeals.status', Appeal::STATUS_OPEN)
                        ->orWhere('appeals.decided_at', '>=', $prog);
                });
            }
        };
    }

    /** @return array{0: int, 1: int, 2: int} [usunięto, błędy, pominięte przez żywe odwołanie] */
    private function posprzatajDecyzje(CarbonInterface $prog, bool $naSucho): array
    {
        if ($naSucho) {
            $pominiete = ModerationAction::query()
                ->where('created_at', '<', $prog)
                ->whereExists($this->odwolaniePodzapytanie($prog, tylkoZywe: true))
                ->count();

            $kandydaci = ModerationAction::query()
                ->where('created_at', '<', $prog)
                ->whereNotExists($this->odwolaniePodzapytanie($prog, tylkoZywe: true))
                ->count();

            return [$kandydaci, 0, $pominiete];
        }

        // Tryb normalny: krok 1 już wykonany powyżej.
        $pominiete = ModerationAction::query()
            ->where('created_at', '<', $prog)
            ->whereExists($this->odwolaniePodzapytanie($prog, tylkoZywe: false))
            ->count();

        // Warunek NOT EXISTS jest w zapytaniu KAŻDEJ partii — żywe albo
        // nieudanie skasowane odwołanie chroni decyzję także na granicy partii.
        $kandydaci = ModerationAction::query()
            ->where('created_at', '<', $prog)
            ->whereNotExists($this->odwolaniePodzapytanie($prog, tylkoZywe: false));

        [$usuniete, $bledy] = $this->skasujPartiami(
            $kandydaci,
            'Nie udało się skasować przedawnionej decyzji moderacyjnej',
            static fn (Model $decyzja): array => ['moderation_action_id' => $decyzja->getKey()],
        );

        return [$usuniete, $bledy, $pominiete];
    }

    /** @return array{0: int, 1: int} [usunięto, błędy] */
    private function posprzatajZgloszenia(CarbonInterface $prog, bool $naSucho): array
    {
        $kandydaci = Report::query()
            ->whereIn('status', [Report::STATUS_RESOLVED, Report::STATUS_REJECTED])
            ->where('resolved_at', '<', $prog);

        if ($naSucho) {
            return [$kandydaci->count(), 0];
        }

        return $this->skasujPartiami(
            $kandydaci,
            'Nie udało się skasować przedawnionego zgłoszenia',
            static fn (Model $zgloszenie): array => ['report_id' => $zgloszenie->getKey()],
        );
    }
}
