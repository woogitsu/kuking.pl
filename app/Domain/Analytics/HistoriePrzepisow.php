<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Media;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Czy przepisy zachowują historię i rodzinne pochodzenie (issue #1045).
 *
 * `docs/product/RETENTION_LOOPS.md` §5.3 ustala dwa mierniki jakości:
 * „% przepisów z »skąd ten przepis«” i „% przepisów z »po kim ten
 * przepis«”. Pola istnieją od MVP, ale żadna komenda ich nie liczyła —
 * produkt mógł dryfować w stronę zwykłej bazy receptur bez sygnału.
 *
 * MIANOWNIK
 * Przepisy `status = published`, bez `deleted_at`, z `published_at`
 * w ostatnich `DNI` dniach (przedział chwil `timestamptz`, jak
 * w `ZrobiePonownie`), których autor NIE jest wykluczony przez
 * `CookEligibility` (gospodarz, konta testowe, zalążkowe i zamknięte —
 * przepisy gospodarza i person zalążkowych mają wypełnione pola z definicji
 * i zawyżałyby wynik). Szkice, przepisy ukryte i usunięte się nie liczą.
 *
 * WIDOCZNOŚĆ: LICZĄ SIĘ WSZYSTKIE (public, followers, private)
 * Pytanie brzmi „czy autor zapisał historię przepisu”, a nie „czy ktoś
 * obcy ją zobaczy”. Prywatny przepis po babci to dokładnie ten przypadek,
 * dla którego pola powstały. Liczba przepisów publicznych stoi w wyniku
 * osobno, żeby było widać, jaka część mianownika jest w ogóle oglądana.
 * Raport nie czyta ani nie zwraca treści żadnego pola — tylko liczniki.
 *
 * CO JEST „ŚLADEM”
 * - `source_person`, `source_note` — tekst z choć jednym znakiem innym niż
 *   biały; pusty napis i same spacje nie są historią;
 * - `family_since_year` — niepusty;
 * - skan — `source_scan_media_id` wskazuje na istniejące zdjęcie w stanie
 *   `ready`; brak wiersza, `pending`, `processing`, `rejected` i `deleted`
 *   (kasowanie w toku, D-083) nie są zachowanym skanem.
 * `source_type = family` jest liczone OSOBNO i NIE jest śladem: samo
 * wybranie opcji „Rodzinny” nie zachowuje żadnej historii. Stąd dwa
 * zbiorcze mierniki: `ma_slad` (choć jeden z czterech konkretnych śladów)
 * i węższy `rodzinny_ze_sladem` (`family` ORAZ choć jeden ślad).
 *
 * MAŁA PRÓBA
 * Poniżej `MINIMUM_PRZEPISOW` procenty są `null` — raport pokazuje same
 * liczniki i pisze „za mało danych”, zamiast udawać rozstrzygające 0%.
 */
final class HistoriePrzepisow
{
    public const DNI = 90;

    public const MINIMUM_PRZEPISOW = 20;

    /** Kolejność kluczy = kolejność wierszy w raporcie. */
    public const MIERNIKI = [
        'od_kogo',
        'historia',
        'rok_rodzinny',
        'skan',
        'rodzinny',
        'ma_slad',
        'rodzinny_ze_sladem',
    ];

    public function __construct(private readonly CookEligibility $eligibility) {}

    /**
     * @return array{
     *     przepisy: int,
     *     publiczne: int,
     *     liczniki: array<string, int>,
     *     procenty: array<string, float|null>,
     * }
     */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $wykluczeni = $this->eligibility->excludedUserIds();

        $odKogo = "coalesce(recipes.source_person ~ '[^[:space:]]', false)";
        $historia = "coalesce(recipes.source_note ~ '[^[:space:]]', false)";
        $rok = '(recipes.family_since_year IS NOT NULL)';
        $skan = '(skan.id IS NOT NULL)';
        $slad = "({$odKogo} OR {$historia} OR {$rok} OR {$skan})";
        $rodzinny = '(recipes.source_type = ?)';

        $wiersz = DB::table('recipes')
            ->leftJoin('media as skan', function (JoinClause $join): void {
                $join->on('skan.id', '=', 'recipes.source_scan_media_id')
                    ->where('skan.status', '=', Media::STATUS_READY);
            })
            ->where('recipes.status', Recipe::STATUS_PUBLISHED)
            ->whereNull('recipes.deleted_at')
            ->where('recipes.published_at', '>', $teraz->subDays(self::DNI))
            ->where('recipes.published_at', '<=', $teraz)
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('recipes.author_id', $wykluczeni))
            ->selectRaw('count(*) AS przepisy')
            ->selectRaw("count(*) FILTER (WHERE recipes.visibility = 'public') AS publiczne")
            ->selectRaw("count(*) FILTER (WHERE {$odKogo}) AS od_kogo")
            ->selectRaw("count(*) FILTER (WHERE {$historia}) AS historia")
            ->selectRaw("count(*) FILTER (WHERE {$rok}) AS rok_rodzinny")
            ->selectRaw("count(*) FILTER (WHERE {$skan}) AS skan")
            ->selectRaw("count(*) FILTER (WHERE {$rodzinny}) AS rodzinny", [Recipe::SOURCE_FAMILY])
            ->selectRaw("count(*) FILTER (WHERE {$slad}) AS ma_slad")
            ->selectRaw("count(*) FILTER (WHERE {$rodzinny} AND {$slad}) AS rodzinny_ze_sladem", [Recipe::SOURCE_FAMILY])
            ->first();

        $przepisy = (int) ($wiersz->przepisy ?? 0);
        $liczniki = [];
        $procenty = [];

        foreach (self::MIERNIKI as $miernik) {
            $liczniki[$miernik] = (int) ($wiersz->{$miernik} ?? 0);
            $procenty[$miernik] = $przepisy < self::MINIMUM_PRZEPISOW
                ? null
                : round($liczniki[$miernik] / $przepisy * 100, 1);
        }

        return [
            'przepisy' => $przepisy,
            'publiczne' => (int) ($wiersz->publiczne ?? 0),
            'liczniki' => $liczniki,
            'procenty' => $procenty,
        ];
    }
}
