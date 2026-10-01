<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Facades\DB;

/**
 * Typowy rzeczywisty czas przygotowania z wykonań „Ugotowałem” (#2067).
 *
 * Czas autora i czas gotujących to DWA osobne źródła i nigdy jedna liczba:
 * autor deklaruje, ile jego zdaniem to trwa, a gotujący mówią, ile im
 * to zajęło. Strona przepisu pokazuje je w dwóch zdaniach.
 *
 * REGUŁY (decyzja właściciela z 1 października 2026, wiersz #2067 w D-333):
 *  – miara to mediana, zaokrąglona do 5 minut (nigdy poniżej 5);
 *  – wynik pokazujemy dopiero od 5 RÓŻNYCH osób; każda osoba liczy się raz
 *    (jej mediana z jej własnych wykonań), więc nikt sam statystyki nie
 *    zbuduje, a ktoś, kto wpisał ten sam przepis dziesięć razy, nie waży
 *    więcej niż inni;
 *  – tylko `actual_minutes` > 0 i najwyżej doba (1440 min); wyższe to
 *    prawdopodobnie pomyłki albo odleżakowanie, więc odpadają z agregatu;
 *  – bez zakresu percentyli, bez średniej, bez „za mało danych” poniżej
 *    progu (żeby brak wyniku nie wyglądał na usterkę);
 *  – tylko wykonania widoczne dla widza (`CookedEvent::scopeWidoczneDla`:
 *    blokady w obie strony i status konta kucharza). Ta sama granica co
 *    galeria „Komu wyszło” i jej licznik — agregat nie może zdradzić
 *    istnienia osoby, którą widz zablokował.
 *
 * KOSZT: jedno zapytanie agregujące w bazie — mediana na osobę
 * w podzapytaniu, potem mediana median i liczba osób — niezależnie od liczby
 * wykonań. Bez migracji (kolumna i CHECK już są).
 */
final class TypowyCzasPrzepisu
{
    /** Od ilu różnych osób wolno pokazać wynik. */
    public const PROG_OSOB = 5;

    /** Najdłuższy czas (doba), który wchodzi do agregatu. */
    public const MAKS_MINUT = 1440;

    /** Krok zaokrąglenia w minutach. */
    public const KROK_MINUT = 5;

    public function __construct(
        public readonly int $minuty,
        public readonly int $osoby,
    ) {}

    /**
     * Wynik dla widza albo `null`, gdy osób jest mniej niż próg.
     *
     * Gość to `null`: blokad nie ma, więc wynik jest ten sam dla każdego
     * gościa — i może leżeć w cache HTML brzegu (#610).
     *
     * @phpstan-impure Czyta bazę: ten sam przepis po nowym wykonaniu daje inny wynik.
     */
    public static function dla(Recipe $recipe, ?User $widz): ?self
    {
        $naOsobe = $recipe->cookedEvents()
            ->widoczneDla($widz)
            ->reorder() // relacja ma domyślną kolejność, a agregat jej nie zniesie
            ->toBase()
            ->where('cooked_events.actual_minutes', '>', 0)
            ->where('cooked_events.actual_minutes', '<=', self::MAKS_MINUT)
            ->select('cooked_events.user_id')
            ->selectRaw('percentile_cont(0.5) within group (order by cooked_events.actual_minutes) as mediana')
            ->groupBy('cooked_events.user_id');

        $wiersz = DB::query()
            ->fromSub($naOsobe, 'na_osobe')
            ->selectRaw('count(*) as osoby')
            ->selectRaw('percentile_cont(0.5) within group (order by na_osobe.mediana) as mediana')
            ->first();

        $osoby = (int) ($wiersz->osoby ?? 0);

        if ($osoby < self::PROG_OSOB || $wiersz->mediana === null) {
            return null;
        }

        return new self(self::zaokraglij((float) $wiersz->mediana), $osoby);
    }

    /** Do pełnych 5 minut, nie mniej niż 5 (mediana 2 min to „około 5”, nie „około 0”). */
    public static function zaokraglij(float $minuty): int
    {
        return max(self::KROK_MINUT, (int) (round($minuty / self::KROK_MINUT) * self::KROK_MINUT));
    }

    /**
     * Zdanie bez rodzaju gramatycznego wobec czytelnika. „Osób” albo „osoby”
     * wg polskiej odmiany (próg gwarantuje liczbę od 5 w górę: 5 osób,
     * 22 osoby, 25 osób, 112 osób).
     */
    public function zdanie(): string
    {
        $koncowka = $this->osoby % 10;
        $ostatnieDwie = $this->osoby % 100;
        $slowo = ($koncowka >= 2 && $koncowka <= 4 && ($ostatnieDwie < 12 || $ostatnieDwie > 14)) ? 'osoby' : 'osób';

        return 'Gotujący zwykle potrzebują około '.Czas::czasPrzepisu($this->minuty).' (na podstawie '.$this->osoby.' '.$slowo.').';
    }
}
