<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Moderation\KolejkiPanelu;
use App\Logging\BezpiecznyBlad;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
 * WZORZEC C — PARTIA W TRANSAKCJI, A PRZY BŁĘDZIE WIERSZ PO WIERSZU
 * W odróżnieniu od `product_signals`/`audit_log`/`notifications`, te trzy
 * tabele zależą od siebie przez klucze obce z różnym zachowaniem przy
 * kasowaniu, więc nie ma tu ślepego `DELETE ... WHERE` na całą tabelę.
 * Kandydaci idą partiami po `ROZMIAR_PARTII` identyfikatorów; partia jest
 * jednym `DELETE ... WHERE id IN (...)` w transakcji. Gdy partia padnie,
 * jest wycofana w całości i powtórzona wiersz po wierszu — błąd jednego nie
 * blokuje reszty listy, a zostaje log z identyfikatorem (issue #998).
 *
 * BUDŻET PRZEBIEGU (issue #998). Jeden przebieg rusza najwyżej
 * `BUDZET_PRZEBIEGU` wierszy z każdej tabeli. Wcześniej cały backlog szedł
 * do pamięci jednym `get()` i był kasowany rekord po rekordzie — po
 * dłuższej przerwie harmonogramu albo przy pierwszym uruchomieniu na starej
 * bazie to był przebieg bez końca. Reszta czeka na następną noc, a raport
 * mówi, ile jej zostało.
 *
 * ODŚWIEŻENIE LICZNIKÓW KOLEJEK RAZ, NIE PER WIERSZ. `AppServiceProvider`
 * przelicza `KolejkiPanelu` po każdym `deleted` na `Appeal` i `Report`.
 * Kasowanie zbiorcze tych zdarzeń nie wywołuje, więc ta klasa odświeża
 * liczniki sama — jeden raz na koniec przebiegu, jeśli cokolwiek zniknęło.
 * Skutek jest ten sam (liczniki po sprzątaniu są świeże), a koszt nie rośnie
 * z liczbą skasowanych wierszy. Innych obserwatorów te trzy modele nie mają.
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
 */
final class PrzedawnioneSprawyModeracyjne
{
    /** Ile identyfikatorów idzie w jednym `DELETE ... WHERE id IN (...)`. */
    public const ROZMIAR_PARTII = 500;

    /** Ile wierszy z KAŻDEJ z trzech tabel rusza jeden przebieg. */
    public const BUDZET_PRZEBIEGU = 20000;

    public function __construct(
        private readonly int $rozmiarPartii = self::ROZMIAR_PARTII,
        private readonly int $budzetPrzebiegu = self::BUDZET_PRZEBIEGU,
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

        if ($usunieteOdwolania + $usunieteDecyzje + $usunieteZgloszenia > 0) {
            app(KolejkiPanelu::class)->odswiez();
        }

        // Ile kandydatów zostało na następny przebieg — bo nie zmieścili się
        // w budżecie albo ich skasowanie padło. Na sucho nic nie znika, więc
        // „zostało” nie ma sensu i zostaje zerem.
        $pozostalo = $naSucho ? 0 : $this->odwolaniaDoSkasowania($prog)->count()
            + $this->decyzjeDoSkasowania($prog)->count()
            + $this->zgloszeniaDoSkasowania($prog)->count();

        // Harmonogram woła komendę przez `Artisan::call()`, więc jej wyjście
        // nigdzie nie trafia — backlog, który nie mieści się w budżecie, musi
        // być widoczny w dzienniku serwera, inaczej nikt się o nim nie dowie.
        if ($pozostalo > 0) {
            Log::warning('Retencja spraw moderacyjnych: część kandydatów czeka na następny przebieg', [
                'pozostalo' => $pozostalo,
                'budzet_przebiegu_na_tabele' => $this->budzetPrzebiegu,
            ]);
        }

        return new RaportRetencjiSpraw(
            usunieteOdwolania: $usunieteOdwolania,
            bledyOdwolan: $bledyOdwolan,
            usunieteDecyzje: $usunieteDecyzje,
            bledyDecyzji: $bledyDecyzji,
            pominieteDecyzjeZywymOdwolaniem: $pominieteZywymOdwolaniem,
            usunieteZgloszenia: $usunieteZgloszenia,
            bledyZgloszen: $bledyZgloszen,
            pozostaloNaKolejnyPrzebieg: $pozostalo,
        );
    }

    /** @return array{0: int, 1: int} [usunięto, błędy] */
    private function posprzatajOdwolania(CarbonInterface $prog, bool $naSucho): array
    {
        if ($naSucho) {
            return [$this->odwolaniaDoSkasowania($prog)->count(), 0];
        }

        return $this->skasujPartiami(
            fn (): Builder => $this->odwolaniaDoSkasowania($prog),
            'Nie udało się skasować przedawnionego odwołania',
            'appeal_id',
        );
    }

    private function odwolaniaDoSkasowania(CarbonInterface $prog): Builder
    {
        return Appeal::query()
            ->whereIn('status', [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED])
            ->where('decided_at', '<', $prog);
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

        [$usuniete, $bledy] = $this->skasujPartiami(
            fn (): Builder => $this->decyzjeDoSkasowania($prog),
            'Nie udało się skasować przedawnionej decyzji moderacyjnej',
            'moderation_action_id',
        );

        return [$usuniete, $bledy, $pominiete];
    }

    /**
     * Decyzje bez JAKIEGOKOLWIEK wiersza `appeals` (tryb normalny, po kroku 1).
     * Warunek wraca także w samym `DELETE` każdej partii, więc odwołanie
     * złożone w trakcie przebiegu chroni swoją decyzję przed kaskadą.
     */
    private function decyzjeDoSkasowania(CarbonInterface $prog): Builder
    {
        return ModerationAction::query()
            ->where('created_at', '<', $prog)
            ->whereNotExists($this->odwolaniePodzapytanie($prog, tylkoZywe: false));
    }

    /** @return array{0: int, 1: int} [usunięto, błędy] */
    private function posprzatajZgloszenia(CarbonInterface $prog, bool $naSucho): array
    {
        if ($naSucho) {
            return [$this->zgloszeniaDoSkasowania($prog)->count(), 0];
        }

        return $this->skasujPartiami(
            fn (): Builder => $this->zgloszeniaDoSkasowania($prog),
            'Nie udało się skasować przedawnionego zgłoszenia',
            'report_id',
        );
    }

    private function zgloszeniaDoSkasowania(CarbonInterface $prog): Builder
    {
        return Report::query()
            ->whereIn('status', [Report::STATUS_RESOLVED, Report::STATUS_REJECTED])
            ->where('resolved_at', '<', $prog);
    }

    /**
     * Kasuje kandydatów partiami, najwyżej `budzetPrzebiegu` wierszy.
     *
     * Identyfikatory idą stronami po kluczu (`id > ostatni`), nie przez
     * `OFFSET` — skasowane wiersze znikają z wyniku, więc przesunięcie
     * przeskakiwałoby żywych kandydatów. Wiersz, którego nie dało się
     * skasować, zostaje w tabeli, ale kursor jest już za nim: ten przebieg do
     * niego nie wraca, następny spróbuje znowu.
     *
     * Każdy `DELETE` powtarza warunek kandydata (`$kandydaci()`), a nie
     * tylko listę identyfikatorów — między odczytem a kasowaniem sprawa mogła
     * zostać otwarta na nowo albo dostać odwołanie.
     *
     * @param  \Closure(): Builder  $kandydaci
     * @return array{0: int, 1: int} [usunięto, błędy]
     */
    private function skasujPartiami(\Closure $kandydaci, string $komunikatBledu, string $kluczLogu): array
    {
        $usuniete = 0;
        $bledy = 0;
        $ruszone = 0;
        $ostatni = null;

        while ($ruszone < $this->budzetPrzebiegu) {
            /** @var list<string> $partia */
            $partia = $kandydaci()
                ->when($ostatni !== null, static fn (Builder $q) => $q->where($q->qualifyColumn('id'), '>', $ostatni))
                ->orderBy($kandydaci()->qualifyColumn('id'))
                ->limit(min($this->rozmiarPartii, $this->budzetPrzebiegu - $ruszone))
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($partia === []) {
                break;
            }

            $ruszone += count($partia);
            $ostatni = end($partia);

            try {
                $usuniete += DB::transaction(static fn (): int => $kandydaci()->whereKey($partia)->delete());

                continue;
            } catch (Throwable) {
                // Partia wycofana w całości — niżej wiersz po wierszu, żeby
                // jeden zły wiersz nie zatrzymał pięciuset dobrych.
            }

            foreach ($partia as $id) {
                try {
                    $usuniete += DB::transaction(static fn (): int => $kandydaci()->whereKey($id)->delete());
                } catch (Throwable $e) {
                    $bledy++;
                    Log::error($komunikatBledu, [
                        $kluczLogu => $id,
                        'error' => BezpiecznyBlad::kontekst($e),
                    ]);
                }
            }
        }

        return [$usuniete, $bledy];
    }
}
