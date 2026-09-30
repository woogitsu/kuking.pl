<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Logging\BezpiecznyBlad;
use App\Models\RecipeVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retencja `recipe_versions` (#2024, decyzja D-333, potwierdzone przez właściciela 30.09).
 *
 * REGUŁA. Wersję przepisu kasujemy, gdy spełnia OBA warunki:
 *  1. jest starsza niż `config('kuking.przepisy.version_retention_months')`
 *     miesięcy (24), liczone po DACIE W POLSCE (`config('kuking.strefa')`),
 *  2. NIE należy do `config('kuking.przepisy.version_keep_latest')` (3)
 *     najnowszych wersji swojego przepisu.
 * Nie kasujemy też wersji przepisu, na który wskazuje zgłoszenie albo decyzja
 * moderacyjna (`reports`, `moderation_actions`) — wersja z chwili zgłoszenia
 * bywa dowodem; po retencji sprawy (36 mies.) przepis wraca do tej kolejki.
 *
 * PO CO. Historia wersji jest publiczna (#2024) i zachowuje treść, którą
 * autor później usunął z przepisu (adres, imię, notatkę). Bez limitu taka
 * treść leżałaby publicznie w nieskończoność, a polityka prywatności obiecuje
 * krótsze trzymanie danych niż „do końca konta”. Dwa lata wystarczą, żeby
 * ktoś, kto gotował ze starej wersji, mógł ją jeszcze obejrzeć.
 *
 * DLACZEGO OSTATNIE K, A NIE „PIERWSZA WERSJA ZAWSZE”. Wcześniejszy projekt
 * (gałąź feat/retencja-wersji-przepisu z 20.09) chronił pierwszą wersję
 * bezterminowo. To właśnie ona najczęściej zawiera to, co autor potem
 * wycofał, a jest publiczna — reguła „na zawsze” przeczyłaby celowi
 * retencji. Przepis poprawiany rzadko nie traci historii i tak: jego
 * najnowsze wersje zostają bez względu na wiek, więc historia z ≥ 2 wersji
 * dalej działa, a porównanie ma z czym porównywać.
 * Wersje zostają trzy, nie dwie: ostatnia jest aktualnym stanem,
 * przedostatnia daje „co się zmieniło”, trzecia to zapas.
 *
 * LUKI W NUMERACJI SĄ W PORZĄDKU. `version_number` rośnie o `max + 1`
 * (`SnapshotRecipeVersion`), a ekrany historii liczą sąsiadów z faktycznej
 * listy numerów (`HistoriaWersji::numery()`), nie z arytmetyki.
 *
 * WERSJE USUNIĘTEGO PRZEPISU nie potrzebują tej reguły: idą razem z nim
 * (`PrzedawnioneUsunieteTresci`, 30 dni; nagrobek też je kasuje).
 *
 * PARTIE. Kandydaci idą po kluczu (`id > ostatni`) partiami po
 * `ROZMIAR_PARTII`, każda w jednej transakcji; jeden przebieg rusza najwyżej
 * `BUDZET_PRZEBIEGU` wierszy, reszta czeka na następną noc. Warunek
 * kandydata wraca w samym `DELETE`, więc wersja, która w międzyczasie
 * przestała być kandydatem (np. przepis dostał zgłoszenie), zostaje. Błąd
 * partii jest logowany i liczony; komenda kończy się wtedy kodem ≠ 0.
 *
 * `--na-sucho` i przebieg prawdziwy liczą ten sam predykat.
 */
final class PrzedawnioneWersjePrzepisow
{
    public const ROZMIAR_PARTII = 500;

    public const BUDZET_PRZEBIEGU = 20000;

    public function __construct(
        private readonly int $rozmiarPartii = self::ROZMIAR_PARTII,
        private readonly int $budzetPrzebiegu = self::BUDZET_PRZEBIEGU,
    ) {}

    /**
     * Próg wieku: początek dnia w Polsce sprzed N miesięcy. Wersja zapisana
     * tego dnia (czasu polskiego) i później zostaje; wcześniejsza jest
     * kandydatem. `subMonthsNoOverflow`, nie `subMonths` — 31 marca minus
     * miesiąc nie może przeskoczyć na 3 marca (A6-04).
     */
    public static function prog(int $miesiace): CarbonInterface
    {
        return now(config('kuking.strefa'))
            ->subMonthsNoOverflow(max(1, $miesiace))
            ->startOfDay()
            ->utc();
    }

    /**
     * @return array{skasowano: int, zostaje: int, bledy: int, miesiace: int, ostatnie: int}
     */
    public function posprzataj(int $miesiace, int $zostawOstatnich, bool $naSucho = false): array
    {
        $miesiace = max(1, $miesiace);
        $zostawOstatnich = max(2, $zostawOstatnich);
        $prog = self::prog($miesiace);

        $kandydaci = fn (): Builder => $this->kandydaci($prog, $zostawOstatnich);

        if ($naSucho) {
            return ['skasowano' => $kandydaci()->count(), 'zostaje' => 0, 'bledy' => 0, 'miesiace' => $miesiace, 'ostatnie' => $zostawOstatnich];
        }

        $skasowano = 0;
        $bledy = 0;
        $ruszone = 0;
        $ostatniId = null;

        while ($ruszone < $this->budzetPrzebiegu) {
            /** @var list<string> $partia */
            $partia = $kandydaci()
                ->when($ostatniId !== null, static fn (Builder $q) => $q->where('recipe_versions.id', '>', $ostatniId))
                ->orderBy('recipe_versions.id')
                ->limit(min($this->rozmiarPartii, $this->budzetPrzebiegu - $ruszone))
                ->pluck('recipe_versions.id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($partia === []) {
                break;
            }

            $ruszone += count($partia);
            $ostatniId = end($partia);

            try {
                $skasowano += DB::transaction(static fn (): int => $kandydaci()->whereIn('recipe_versions.id', $partia)->delete());
            } catch (Throwable $e) {
                $bledy += count($partia);
                Log::error('Retencja wersji przepisów: nie udało się skasować partii', [
                    'wierszy' => count($partia),
                    'error' => BezpiecznyBlad::kontekst($e),
                ]);
            }
        }

        $zostaje = $kandydaci()->count();

        if ($zostaje > 0) {
            Log::warning('Retencja wersji przepisów: część kandydatów czeka na następny przebieg', [
                'zostaje' => $zostaje,
                'budzet_przebiegu' => $this->budzetPrzebiegu,
            ]);
        }

        return ['skasowano' => $skasowano, 'zostaje' => $zostaje, 'bledy' => $bledy, 'miesiace' => $miesiace, 'ostatnie' => $zostawOstatnich];
    }

    /**
     * @return Builder<RecipeVersion>
     */
    private function kandydaci(CarbonInterface $prog, int $zostawOstatnich): Builder
    {
        return RecipeVersion::query()
            ->where('recipe_versions.created_at', '<', $prog)
            // Ile wersji tego przepisu jest NOWSZYCH; wersja wśród K
            // najnowszych ma ich mniej niż K.
            ->whereRaw(
                '(select count(*) from recipe_versions as nowsze
                  where nowsze.recipe_id = recipe_versions.recipe_id
                    and nowsze.version_number > recipe_versions.version_number) >= ?',
                [$zostawOstatnich],
            )
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))->from('reports')
                    ->where('reports.target_type', 'recipe')
                    ->whereColumn('reports.target_id', 'recipe_versions.recipe_id');
            })
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))->from('moderation_actions')
                    ->where('moderation_actions.target_type', 'recipe')
                    ->whereColumn('moderation_actions.target_id', 'recipe_versions.recipe_id');
            });
    }
}
