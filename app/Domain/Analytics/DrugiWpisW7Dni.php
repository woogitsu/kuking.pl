<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * „Drugi wpis w 7 dni” — czy pierwszy wpis zamienia się w nawyk (issue #29).
 *
 * `docs/product/COLD_START.md` §4.5 każe gospodarzowi w dniach 4–5 sprawdzić,
 * czy nowa osoba ma drugi wpis. Do tej pory żadna komenda nie mówiła, jak
 * często tak się dzieje, a to jest najwcześniejszy sygnał, czy ludzie wracają
 * bez ręcznego popychania (warunek STOP bramki A).
 *
 * KOHORTA
 * Autorzy, których PIERWSZY opublikowany wpis (`posts`, oba rodzaje) ma
 * `published_at` w oknie (teraz − `KOHORTA_DNI`, teraz − `OKNO_DNI`]. Górna
 * granica jest przesunięta o `OKNO_DNI`, bo osoba z pierwszym wpisem
 * przedwczoraj nie MIAŁA jeszcze siedmiu dni — liczyłaby się jako „nie”
 * tylko dlatego, że nie zdążyła (ta sama zasada, co w `PowrotPoDniach`).
 *
 * „DRUGI W 7 DNI”: drugi wpis w kolejności (`published_at`, potem `id`)
 * nie później niż 7×24 h po pierwszym. Czas liczony z `extract(epoch …)`,
 * niezależnie od strefy sesji bazy — uzasadnienie w `PowrotPoDniach`.
 *
 * KTÓRE WPISY: te same, co w WAC (`Post::published()` bez usuniętych) —
 * jedna reguła dla wszystkich mierników aktywności (`CookActivity`). Wpis
 * usunięty potem przez autora albo ukryty przez moderację wypada z liczenia;
 * wtedy „pierwszym” staje się najstarszy nadal opublikowany. To zaniża
 * miernik, nigdy go nie zawyża.
 *
 * WYKLUCZENIA: `CookEligibility` (gospodarz, konta testowe, zalążkowe
 * i zamknięte) — gospodarz z definicji publikuje codziennie.
 *
 * TYLKO LICZBY. Zapytanie zwraca dwa liczniki; żaden identyfikator, tytuł
 * ani treść nie opuszcza bazy.
 *
 * MAŁA PRÓBA: poniżej `MINIMUM_OSOB` procent jest `null` — raport pisze
 * „za mało danych” zamiast udawać rozstrzygające 0% albo 100%.
 */
final class DrugiWpisW7Dni
{
    public const OKNO_DNI = 7;

    public const KOHORTA_DNI = 90;

    public const MINIMUM_OSOB = 10;

    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    /** @return array{kohorta: int, z_drugim: int, procent: float|null} */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $wykluczeni = $this->eligibility->excludedUserIds();

        $ponumerowane = Post::query()
            ->published()
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('author_id', $wykluczeni))
            ->toBase()
            ->select('author_id', 'published_at')
            ->selectRaw('row_number() OVER (PARTITION BY author_id ORDER BY published_at, id) AS nr');

        $autorzy = DB::query()
            ->fromSub($ponumerowane, 'p')
            ->where('nr', '<=', 2)
            ->groupBy('author_id')
            ->select('author_id')
            ->selectRaw('min(published_at) FILTER (WHERE nr = 1) AS pierwszy')
            ->selectRaw('min(published_at) FILTER (WHERE nr = 2) AS drugi');

        $wiersz = DB::query()
            ->fromSub($autorzy, 'a')
            ->where('pierwszy', '>', $teraz->subDays(self::KOHORTA_DNI))
            ->where('pierwszy', '<=', $teraz->subDays(self::OKNO_DNI))
            ->selectRaw('count(*) AS kohorta')
            ->selectRaw(
                'count(*) FILTER (WHERE drugi IS NOT NULL AND extract(epoch from drugi) - extract(epoch from pierwszy) <= ?) AS z_drugim',
                [self::OKNO_DNI * 86400],
            )
            ->first();

        $kohorta = (int) ($wiersz->kohorta ?? 0);
        $zDrugim = (int) ($wiersz->z_drugim ?? 0);

        return [
            'kohorta' => $kohorta,
            'z_drugim' => $zDrugim,
            'procent' => $kohorta < self::MINIMUM_OSOB ? null : round($zDrugim / $kohorta * 100, 1),
        ];
    }
}
