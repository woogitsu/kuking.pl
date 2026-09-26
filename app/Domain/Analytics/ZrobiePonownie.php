<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * „Zrobię ponownie" po realnym gotowaniu — sygnał JAKOŚCI przepisu, nie
 * zasięgu (`docs/research/ANALITYKA.md` §1, issue #1509).
 *
 * TRZY STANY, NIE DWA
 * `cooked_events.would_make_again` jest opcjonalnym pytaniem: `true` —
 * „zrobię ponownie", `false` — „raczej nie powtórzę", `NULL` — człowiek nie
 * odpowiedział albo wycofał odpowiedź (#767). `NULL` NIE jest „nie". Dlatego
 * odsetek „tak" liczy się z `tak / (tak + nie)`, a obok zawsze stoi odsetek
 * odpowiedzi `(tak + nie) / wszystkie wykonania` — bez niego „100% tak" przy
 * jednej odpowiedzi na sto wykonań wyglądałoby jak mocny wynik.
 *
 * JEDNOSTKA: KAŻDE REALNE WYKONANIE
 * Raport pyta „jak często gotowanie kończy się chęcią powtórki", więc liczy
 * każdy wiersz `cooked_events` osobno — także drugie i trzecie gotowanie
 * tego samego przepisu przez tę samą osobę (D-005: każde wykonanie jest
 * osobnym wydarzeniem). Kto zmienił zdanie, ma w raporcie oba zdania, tak
 * jak oba gotowania naprawdę się odbyły.
 *
 * WŁASNE OSOBNO OD CUDZYCH
 * Autor gotujący własny przepis to dziennik, nie społeczny dowód. Stąd dwa
 * niezależne wyniki: `cudze` (`recipes.author_id <> cooked_events.user_id`)
 * i `wlasne`. Złączenie idzie po tabeli `recipes` bez warunku `deleted_at`:
 * przepis ukryty moderacyjnie zostawia historyczne, zagregowane wykonanie
 * (ta sama reguła co w `ZasiegUgotowalem`).
 *
 * KTO SIĘ NIE LICZY
 * Gotujący wykluczeni przez `CookEligibility` (gospodarz, konta testowe,
 * zalążkowe i zamknięte) — to wskaźnik porównywany w czasie jak WAC,
 * a gwarantowane odpowiedzi jednego konta by go przesuwały.
 *
 * OKNO CZASU
 * Ostatnie `DNI` dni wstecz od chwili liczenia, po `cooked_at`
 * (`timestamptz`). Okno jest przedziałem chwil, nie dni kalendarzowych,
 * więc strefa czasowa nie przesuwa jego granicy.
 *
 * MAŁA PRÓBA
 * Poniżej `MINIMUM_ODPOWIEDZI` odpowiedzi procent jest `null`, a raport
 * pisze „za mało danych" — pięć odpowiedzi to anegdota, nie wskaźnik.
 *
 * Wynik jest wyłącznie zbiorczy: bez nazw kont, tytułów, notatek, rankingu
 * przepisów ani autorów.
 */
final class ZrobiePonownie
{
    public const DNI = 30;

    public const MINIMUM_ODPOWIEDZI = 20;

    public function __construct(private readonly CookEligibility $eligibility) {}

    /**
     * @return array{
     *     cudze: array{tak: int, nie: int, brak: int, wszystkie: int, odsetek_odpowiedzi: float|null, odsetek_tak: float|null},
     *     wlasne: array{tak: int, nie: int, brak: int, wszystkie: int, odsetek_odpowiedzi: float|null, odsetek_tak: float|null},
     * }
     */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $wykluczeni = $this->eligibility->excludedUserIds();

        $wiersze = DB::table('cooked_events')
            ->join('recipes', 'recipes.id', '=', 'cooked_events.recipe_id')
            ->where('cooked_events.cooked_at', '>', $teraz->subDays(self::DNI))
            ->where('cooked_events.cooked_at', '<=', $teraz)
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('cooked_events.user_id', $wykluczeni))
            ->selectRaw('(recipes.author_id = cooked_events.user_id) AS wlasny')
            ->selectRaw('count(*) FILTER (WHERE cooked_events.would_make_again IS TRUE) AS tak')
            ->selectRaw('count(*) FILTER (WHERE cooked_events.would_make_again IS FALSE) AS nie')
            ->selectRaw('count(*) FILTER (WHERE cooked_events.would_make_again IS NULL) AS brak')
            ->groupByRaw('1')
            ->get()
            ->keyBy(fn (object $w): string => $w->wlasny ? 'wlasne' : 'cudze');

        return [
            'cudze' => $this->wynik($wiersze['cudze'] ?? null),
            'wlasne' => $this->wynik($wiersze['wlasne'] ?? null),
        ];
    }

    /** @return array{tak: int, nie: int, brak: int, wszystkie: int, odsetek_odpowiedzi: float|null, odsetek_tak: float|null} */
    private function wynik(?object $wiersz): array
    {
        $tak = (int) ($wiersz->tak ?? 0);
        $nie = (int) ($wiersz->nie ?? 0);
        $brak = (int) ($wiersz->brak ?? 0);
        $wszystkie = $tak + $nie + $brak;
        $odpowiedzi = $tak + $nie;

        return [
            'tak' => $tak,
            'nie' => $nie,
            'brak' => $brak,
            'wszystkie' => $wszystkie,
            'odsetek_odpowiedzi' => $wszystkie === 0 ? null : round($odpowiedzi / $wszystkie * 100, 1),
            'odsetek_tak' => $odpowiedzi < self::MINIMUM_ODPOWIEDZI ? null : round($tak / $odpowiedzi * 100, 1),
        ];
    }
}
