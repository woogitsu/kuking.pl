<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pętla „Zapisuję → Ugotowałem" — metryka `save → cooked w 30 dni`
 * z `docs/product/RETENTION_LOOPS.md` (cel ≥15%), issue #1015.
 *
 * Odpowiada na jedno pytanie: czy zapis przepisu w Zeszycie prowadzi z powrotem
 * do realnego gotowania, czy Zeszyt staje się cmentarzem zakładek. To bramka
 * PRZED jakimkolwiek przypomnieniem w tygodniowym podsumowaniu (D-057) —
 * ta klasa niczego nie wysyła i niczego nie zmienia w liście.
 *
 * JEDNOSTKA: PARA OSOBA–PRZEPIS, NIE WIERSZ `collection_items`
 * Ten sam przepis w trzech zeszytach tej samej osoby to jeden zamiar, nie trzy.
 * Liczy się PIERWSZY zapis: `min(collection_items.created_at)` po
 * `(collections.owner_id, recipe_id)` z całej historii. Para trafia do kohorty
 * według chwili pierwszego zapisu — późniejszy zapis tego samego przepisu do
 * innego zeszytu nie otwiera nowej pary w nowszej kohorcie.
 *
 * LICZNIK
 * Para jest konwersją, gdy ta sama osoba ma `cooked_events` dla tego przepisu
 * z `cooked_at` PÓŹNIEJSZYM niż pierwszy zapis i nie późniejszym niż 30 dni po
 * nim. Ugotowanie sprzed zapisu nie jest skutkiem zapisu. Kilka ugotowań
 * w oknie to wciąż jedna konwersja — pytamy „czy", nie „ile razy".
 * `cooked_at` ustawia serwer w chwili zgłoszenia (`RecordCookedEvent`), więc
 * człowiek nie może go cofnąć przed zapis.
 *
 * KOHORTA: PEŁNA I ZAMKNIĘTA
 * Pierwsze zapisy z przedziału (teraz − 60 dni, teraz − 30 dni]. Każda para
 * w tej kohorcie miała już pełne 30 dni na ugotowanie, więc zero w liczniku
 * znaczy „nie ugotowali", a nie „jeszcze nie zdążyli". Zapisy młodsze niż
 * 30 dni są liczone osobno (`w_oknie_obserwacji`) i NIGDY nie wchodzą do
 * mianownika — wpuszczenie ich zaniżałoby procent tym mocniej, im szybciej
 * rośnie serwis. Przedział jest odcinkiem chwil `timestamptz`, więc strefa
 * czasowa nie przesuwa jego granic.
 *
 * MAŁA PRÓBA
 * Poniżej `MINIMUM_PAR` par w kohorcie procent jest `null`, a raport pisze
 * „za mało danych" — ten sam próg co w `ZrobiePonownie`.
 *
 * KTO I CO SIĘ NIE LICZY
 * - Osoby wykluczone przez `CookEligibility` (gospodarz, konta testowe,
 *   zalążkowe, zamknięte) — to wskaźnik porównywany w czasie jak WAC.
 * - Zapis WŁASNEGO przepisu: autor nie potrzebuje Zeszytu, żeby wrócić do
 *   swojego przepisu, a jego gotowanie to dziennik, nie domknięta pętla.
 * - Wiersze z `post_id` (zapisane wpisy) — to nie przepis, nie ma czego
 *   „ugotować" w sensie `cooked_events`.
 * Przepis ukryty moderacyjnie zostaje (złączenie bez `deleted_at`, ta sama
 * reguła co w `ZrobiePonownie`).
 *
 * ZNANE OGRANICZENIE
 * Usunięcie przepisu z zeszytu kasuje wiersz `collection_items`, więc taki
 * zapis znika z pomiaru, a ponowny zapis po usunięciu liczy się jako nowy
 * pierwszy zapis. Nowej tabeli ani zdarzenia śledzącego dla tego nie ma —
 * issue #1015 wymaga pomiaru z istniejących danych (`docs/research/ANALITYKA.md`).
 *
 * Wynik jest wyłącznie zbiorczy: trzy liczby i procent. Bez identyfikatorów
 * osób, tytułów przepisów, nazw zeszytów, notatek i bez rankingu.
 */
final class ZapisDoUgotowania
{
    /** Okno na ugotowanie po zapisie i jednocześnie szerokość kohorty. */
    public const DNI = 30;

    public const MINIMUM_PAR = 20;

    public const CEL_PROCENT = 15.0;

    public function __construct(private readonly CookEligibility $eligibility) {}

    /**
     * @return array{
     *     kohorta_od: CarbonImmutable,
     *     kohorta_do: CarbonImmutable,
     *     w_kohorcie: int,
     *     ugotowane: int,
     *     procent: float|null,
     *     w_oknie_obserwacji: int,
     * }
     */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $kohortaDo = $teraz->subDays(self::DNI);
        $kohortaOd = $teraz->subDays(2 * self::DNI);
        $wykluczeni = $this->eligibility->excludedUserIds();

        $pierwszeZapisy = DB::table('collection_items')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->join('recipes', 'recipes.id', '=', 'collection_items.recipe_id')
            ->whereNotNull('collection_items.recipe_id')
            ->whereColumn('recipes.author_id', '<>', 'collections.owner_id')
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('collections.owner_id', $wykluczeni))
            ->groupBy('collections.owner_id', 'collection_items.recipe_id')
            ->select('collections.owner_id as user_id', 'collection_items.recipe_id')
            ->selectRaw('min(collection_items.created_at) as zapisano_at');

        $okno = "interval '".self::DNI." days'";
        $wKohorcie = 'z.zapisano_at > ? AND z.zapisano_at <= ?';

        $wiersz = DB::query()
            ->fromSub($pierwszeZapisy, 'z')
            ->selectRaw("count(*) FILTER (WHERE {$wKohorcie}) AS w_kohorcie", [$kohortaOd, $kohortaDo])
            ->selectRaw(
                "count(*) FILTER (WHERE {$wKohorcie} AND EXISTS ("
                .'SELECT 1 FROM cooked_events ce'
                .' WHERE ce.user_id = z.user_id AND ce.recipe_id = z.recipe_id'
                ." AND ce.cooked_at > z.zapisano_at AND ce.cooked_at <= z.zapisano_at + {$okno}"
                .')) AS ugotowane',
                [$kohortaOd, $kohortaDo],
            )
            ->selectRaw('count(*) FILTER (WHERE z.zapisano_at > ?) AS w_oknie_obserwacji', [$kohortaDo])
            ->first();

        $par = (int) ($wiersz->w_kohorcie ?? 0);
        $ugotowane = (int) ($wiersz->ugotowane ?? 0);

        return [
            'kohorta_od' => $kohortaOd,
            'kohorta_do' => $kohortaDo,
            'w_kohorcie' => $par,
            'ugotowane' => $ugotowane,
            'procent' => $par < self::MINIMUM_PAR ? null : round($ugotowane / $par * 100, 1),
            'w_oknie_obserwacji' => (int) ($wiersz->w_oknie_obserwacji ?? 0),
        ];
    }
}
