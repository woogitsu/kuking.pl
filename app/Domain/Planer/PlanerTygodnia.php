<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use App\Support\FrazaWyszukiwania;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Odczyt planera tygodnia (#27, D-310) i reguły wspólne dla ekranu, akcji
 * i paczki danych.
 *
 * TYDZIEŃ ZACZYNA SIĘ W PONIEDZIAŁEK W STREFIE CZŁOWIEKA (`Czas`), nie w UTC
 * — inaczej w niedzielę po 22:00 „ten tydzień” byłby już następnym.
 *
 * PRZEPIS POKAZUJEMY TYLKO WTEDY, GDY WŁAŚCICIEL PLANU WCIĄŻ GO WIDZI.
 * Plan nie jest furtką do treści: przepis, który autor zawęził, usunął albo
 * który odciął blokadą, zostaje w planie jako „Przepis jest już
 * niedostępny.” — bez tytułu i bez linku. Ta sama reguła co na liście
 * zeszytu (`WidocznaZawartoscZeszytu::przepisy()`); własny przepis omija
 * filtr autora jak w eksporcie zeszytu (karencja usuwania konta).
 */
final class PlanerTygodnia
{
    public const STAN_PRZEPIS = 'przepis';

    public const STAN_WLASNY = 'wlasny';

    public const STAN_NIEDOSTEPNY = 'niedostepny';

    public const STAN_USUNIETY = 'usuniety';

    /** Numer dnia ISO (1 = poniedziałek) → nazwa po „na”. */
    private const DNI_W_BIERNIKU = [
        1 => 'poniedziałek',
        2 => 'wtorek',
        3 => 'środę',
        4 => 'czwartek',
        5 => 'piątek',
        6 => 'sobotę',
        7 => 'niedzielę',
    ];

    public static function wpisowNaDzien(): int
    {
        return (int) config('kuking.planer.wpisow_na_dzien');
    }

    /**
     * Poniedziałek tygodnia z parametru `?tydzien=RRRR-MM-DD` (dowolny dzień
     * tego tygodnia). Zły albo pusty parametr = bieżący tydzień, bez błędu:
     * to jest nawigacja, nie formularz.
     *
     * `mixed`, nie `?string` (#2239): ukryte pole `tydzien` formularza
     * „Skopiuj poprzedni tydzień” przychodzi z treści żądania, a tam może być
     * tablicą. Tablica jest tym samym, co zły parametr — bieżący tydzień.
     */
    public static function poniedzialek(mixed $tydzien): CarbonImmutable
    {
        $dzis = CarbonImmutable::parse(Czas::dzisiajData());

        if (is_string($tydzien) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tydzien) === 1) {
            try {
                $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $tydzien);
            } catch (\Throwable) {
                $dzien = false;
            }

            if ($dzien instanceof CarbonImmutable && $dzien->format('Y-m-d') === $tydzien) {
                return $dzien->startOfWeek(CarbonInterface::MONDAY);
            }
        }

        return $dzis->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * „poniedziałek, 28 września”.
     *
     * Przez `Czas`, nie przez gołe `translatedFormat()` — tak jak każda data
     * pokazywana człowiekowi (#746). Sam dzień planu jest datą kalendarzową
     * (kolumna `date`), więc przeliczenie strefy go nie przesuwa; pomocnik
     * jest tu po to, żeby nie było w serwisie drugiej drogi formatowania dat.
     */
    public static function nazwaDnia(CarbonInterface $dzien): string
    {
        return Czas::data($dzien, 'l, j F');
    }

    /**
     * Nazwa dnia W BIERNIKU, po przyimku „na”: „na środę, 30 września” (#2246).
     *
     * `nazwaDnia()` zwraca mianownik i zostaje taka, bo stoi w nagłówkach
     * dni i przy przyciskach wyboru dnia („Jutro — środa, 30 września”).
     * Komunikaty planera wstawiały ją jednak po „na”, co dawało „na środa”.
     * Formy są wpisane jawnie, nie brane z `translatedFormat()` — Carbon zna
     * tylko mianownik nazw dni, a biernik różni się w trzech dniach z siedmiu.
     * Miesiąc (dopełniacz: „30 września”) dalej idzie przez `Czas`.
     */
    public static function naDzien(CarbonInterface $dzien): string
    {
        return self::DNI_W_BIERNIKU[$dzien->dayOfWeekIso].', '.Czas::data($dzien, 'j F');
    }

    /** „28 września – 4 października” albo „5–11 października” w jednym miesiącu. */
    public static function zakresTygodnia(CarbonImmutable $poniedzialek): string
    {
        $niedziela = $poniedzialek->addDays(6);

        return $poniedzialek->month === $niedziela->month
            ? Czas::data($poniedzialek, 'j').'–'.Czas::data($niedziela, 'j F')
            : Czas::data($poniedzialek, 'j F').' – '.Czas::data($niedziela, 'j F');
    }

    /**
     * Przepisy, które ten człowiek dziś widzi.
     *
     * @return Builder<Recipe>
     */
    public function widocznePrzepisy(User $user): Builder
    {
        return Recipe::query()
            ->widoczneDla($user)
            ->where(fn (Builder $q) => $q
                ->where('recipes.author_id', $user->getKey())
                ->orWhereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()));
    }

    /**
     * Pozycje z podanego zakresu dni, każda ze stanem i — gdy wolno — przepisem.
     * Dwa zapytania niezależnie od liczby pozycji.
     *
     * @return list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>
     */
    public function pozycje(User $user, CarbonImmutable $od, CarbonImmutable $do): array
    {
        $wpisy = $user->mealPlanEntries()
            ->whereBetween('day', [$od->toDateString(), $do->toDateString()])
            ->orderBy('day')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $idPrzepisow = $wpisy->pluck('recipe_id')->filter()->unique()->values();
        $widoczne = $idPrzepisow->isEmpty()
            ? collect()
            : $this->widocznePrzepisy($user)->whereIn('recipes.id', $idPrzepisow)->get()->keyBy('id');

        return $wpisy->map(function (MealPlanEntry $wpis) use ($widoczne): array {
            if ($wpis->recipe_id !== null) {
                $przepis = $widoczne->get($wpis->recipe_id);

                return [
                    'wpis' => $wpis,
                    'stan' => $przepis !== null ? self::STAN_PRZEPIS : self::STAN_NIEDOSTEPNY,
                    'przepis' => $przepis,
                ];
            }

            return [
                'wpis' => $wpis,
                'stan' => $wpis->label !== null ? self::STAN_WLASNY : self::STAN_USUNIETY,
                'przepis' => null,
            ];
        })->values()->all();
    }

    /**
     * Siedem dni tygodnia, każdy ze swoimi pozycjami.
     *
     * @return array<string, array{dzien: CarbonImmutable, pozycje: list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>}>
     */
    public function tydzien(User $user, CarbonImmutable $poniedzialek): array
    {
        $dni = [];
        for ($i = 0; $i < 7; $i++) {
            $dzien = $poniedzialek->addDays($i);
            $dni[$dzien->toDateString()] = ['dzien' => $dzien, 'pozycje' => []];
        }

        foreach ($this->pozycje($user, $poniedzialek, $poniedzialek->addDays(6)) as $pozycja) {
            $dni[$pozycja['wpis']->day->toDateString()]['pozycje'][] = $pozycja;
        }

        return $dni;
    }

    /**
     * „Szukaj w moich planach” (#2581): własne pozycje, których tekst albo
     * tytuł WIDOCZNEGO przepisu pasuje do frazy. Najnowsze dni najpierw.
     *
     * Właściciela pilnuje `$user->mealPlanEntries()` (user_id) — cudze plany
     * nie dają wyników. Tytuł liczy się tylko dla przepisów z
     * `widocznePrzepisy()`, tej samej reguły co odczyt tygodnia; przepis
     * niedostępny nie dopasowuje się i jego tytuł nie wychodzi z bazy.
     * Porównanie po kolumnie `recipes.title_search` i `kuking_normalize(label)`
     * (małe litery, bez polskich znaków), metaznaki LIKE cytowane.
     * Pobieramy `limit + 1` wierszy, żeby wiedzieć, że jest więcej.
     *
     * @return array{wyniki: list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>, wiecej: bool}
     */
    public function szukajWPlanach(User $user, string $fraza, int $limit = 50): array
    {
        $wzorzec = '%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($fraza)).'%';

        $trafione = $this->widocznePrzepisy($user)
            ->whereRaw('recipes.title_search LIKE ?', [$wzorzec])
            ->select('recipes.id');

        $wpisy = $user->mealPlanEntries()
            ->where(fn (Builder $q) => $q
                ->whereRaw('public.kuking_normalize(meal_plan_entries.label) LIKE ?', [$wzorzec])
                ->orWhereIn('meal_plan_entries.recipe_id', $trafione))
            ->orderByDesc('day')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $wiecej = $wpisy->count() > $limit;
        $wpisy = $wpisy->take($limit);

        $idPrzepisow = $wpisy->pluck('recipe_id')->filter()->unique()->values();
        $przepisy = $idPrzepisow->isEmpty()
            ? collect()
            : $this->widocznePrzepisy($user)->whereIn('recipes.id', $idPrzepisow)->get()->keyBy('id');

        return [
            'wyniki' => $wpisy->map(fn (MealPlanEntry $wpis): array => [
                'wpis' => $wpis,
                'stan' => $wpis->recipe_id !== null ? self::STAN_PRZEPIS : self::STAN_WLASNY,
                'przepis' => $wpis->recipe_id !== null ? $przepisy->get($wpis->recipe_id) : null,
            ])->values()->all(),
            'wiecej' => $wiecej,
        ];
    }
}
