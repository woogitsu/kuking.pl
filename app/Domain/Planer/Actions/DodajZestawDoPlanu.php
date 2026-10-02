<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Planer\AktywneKontoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Planer\ZakresDatPlanu;
use App\Models\Collection;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\RecipePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wybrane przepisy z WŁASNEGO zeszytu na jeden wskazany dzień WŁASNEGO
 * planera (V2, #2483).
 *
 * Dwa kroki, ten sam rachunek: `podglad()` pokazuje, co się stanie, a
 * `zatwierdz()` liczy to samo od nowa pod blokadą konta i zapisuje tylko
 * wtedy, gdy wynik zgadza się z podglądem (odcisk). Gdy zestaw albo dostęp
 * zmieniły się w międzyczasie, nic się nie zapisuje, a człowiek dostaje
 * świeży podgląd do ponownego zatwierdzenia.
 *
 * GRANICE
 *  - zeszyt musi być własny (`owner_id`), a wybrane przepisy muszą nadal
 *    leżeć W TYM zeszycie i być widoczne dla właściciela
 *    (`WidocznaZawartoscZeszytu` + `RecipePolicy::view`) oraz opublikowane.
 *    Dostęp do zeszytu nigdy nie zastępuje polityki przepisu. Przepis, który
 *    to straci, jest po prostu pomijany i tylko LICZONY — bez tytułu;
 *  - dzień z `ZakresDatPlanu`, limit z `PlanerTygodnia::wpisowNaDzien()`
 *    (jeden limit, ten sam co przy zwykłym dodawaniu), liczony od całego
 *    istniejącego dnia; nie zapisujemy „pierwszych kilku” — za dużo nowych
 *    pozycji to błąd z prośbą o mniejszy wybór;
 *  - przepis już zaplanowany tego dnia jest pomijany i niczego nie
 *    resetuje; ponowne wysłanie nie mnoży wpisów;
 *  - zapis jest jedną transakcją: błąd limitu cofa wszystko;
 *  - nie kopiujemy notatek zeszytu, opisu ani ról, nie tworzymy zakupów,
 *    kolejki gotowania ani „Ugotowałem".
 */
final class DodajZestawDoPlanu
{
    /** Najwięcej przepisów w jednym wyborze (jedna strona zeszytu). */
    public const MAKS_WYBRANYCH = 50;

    public function __construct(
        private readonly WidocznaZawartoscZeszytu $zawartosc = new WidocznaZawartoscZeszytu,
        private readonly RecipePolicy $przepisy = new RecipePolicy,
    ) {}

    /**
     * @param  list<string>  $idPrzepisow  kolejność wyboru z formularza
     * @return array{
     *     nowe: list<Recipe>,
     *     juz_sa: list<Recipe>,
     *     niedostepne: int,
     *     dzien_ma: int,
     *     limit: int,
     *     odcisk: string
     * }
     */
    public function podglad(User $user, Collection $zeszyt, CarbonImmutable $dzien, array $idPrzepisow): array
    {
        return $this->rachunek($user, $zeszyt, $dzien, $idPrzepisow);
    }

    /**
     * @param  list<string>  $idPrzepisow
     * @return array{zapisano: bool, dodano: int, juz_bylo: int, niedostepne: int, podglad: array<string, mixed>}
     */
    public function zatwierdz(User $user, Collection $zeszyt, CarbonImmutable $dzien, array $idPrzepisow, string $odcisk): array
    {
        return DB::transaction(function () use ($user, $zeszyt, $dzien, $idPrzepisow, $odcisk): array {
            // Blokada konta szereguje równoległe zapisy tej samej osoby, więc
            // limit dnia liczy się raz, a nie dwa razy naraz.
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            $rachunek = $this->rachunek($swiezy, $zeszyt, $dzien, $idPrzepisow);

            if (! hash_equals($rachunek['odcisk'], $odcisk)) {
                return ['zapisano' => false, 'dodano' => 0, 'juz_bylo' => 0, 'niedostepne' => $rachunek['niedostepne'], 'podglad' => $rachunek];
            }

            foreach ($rachunek['nowe'] as $przepis) {
                $wpis = new MealPlanEntry([
                    'day' => $dzien->toDateString(),
                    'recipe_id' => $przepis->getKey(),
                ]);
                $wpis->user_id = $swiezy->getKey();
                $wpis->save();
            }

            return [
                'zapisano' => true,
                'dodano' => count($rachunek['nowe']),
                'juz_bylo' => count($rachunek['juz_sa']),
                'niedostepne' => $rachunek['niedostepne'],
                'podglad' => $rachunek,
            ];
        });
    }

    /**
     * @param  list<string>  $idPrzepisow
     * @return array{nowe: list<Recipe>, juz_sa: list<Recipe>, niedostepne: int, dzien_ma: int, limit: int, odcisk: string}
     */
    private function rachunek(User $user, Collection $zeszyt, CarbonImmutable $dzien, array $idPrzepisow): array
    {
        if ($zeszyt->owner_id !== $user->getKey()) {
            throw new AuthorizationException;
        }

        if (! ZakresDatPlanu::obejmuje($dzien)) {
            throw ValidationException::withMessages([
                'dzien' => 'Wybierz dzień w zakresie planera: '.ZakresDatPlanu::opis().'.',
            ]);
        }

        $idPrzepisow = array_slice(array_values(array_unique($idPrzepisow)), 0, self::MAKS_WYBRANYCH);

        if ($idPrzepisow === []) {
            throw ValidationException::withMessages([
                'przepisy' => 'Zaznacz przynajmniej jeden przepis, który chcesz zaplanować.',
            ]);
        }

        // Przepisy, które NADAL leżą w tym zeszycie, są opublikowane i widoczne
        // dla właściciela. Resztę liczymy, ale nie pokazujemy.
        $dostepne = $this->zawartosc->przepisy($zeszyt, $user)
            ->published()
            ->whereIn('recipes.id', $idPrzepisow)
            ->with('author')
            ->get()
            ->filter(fn (Recipe $przepis): bool => $this->przepisy->view($user, $przepis))
            ->keyBy(fn (Recipe $przepis): string => (string) $przepis->getKey());

        $wybrane = [];
        foreach ($idPrzepisow as $id) {
            if ($dostepne->has($id)) {
                $wybrane[] = $dostepne->get($id);
            }
        }
        $niedostepne = count($idPrzepisow) - count($wybrane);

        $juzWDniu = MealPlanEntry::query()
            ->where('user_id', $user->getKey())
            ->where('day', $dzien->toDateString())
            ->pluck('recipe_id')
            ->filter()
            ->flip();

        $nowe = [];
        $juzSa = [];
        foreach ($wybrane as $przepis) {
            if ($juzWDniu->has($przepis->getKey())) {
                $juzSa[] = $przepis;
            } else {
                $nowe[] = $przepis;
            }
        }

        $dzienMa = MealPlanEntry::query()
            ->where('user_id', $user->getKey())
            ->where('day', $dzien->toDateString())
            ->count();
        $limit = PlanerTygodnia::wpisowNaDzien();

        if ($dzienMa + count($nowe) > $limit) {
            throw ValidationException::withMessages([
                'przepisy' => 'Ten dzień ma już '.$dzienMa.' z '.$limit.' pozycji, a wybrano '.count($nowe)
                    .' nowych. Zostaw mniej przepisów albo wybierz inny dzień. Nic nie zostało dodane.',
            ]);
        }

        // Odcisk: dzień, nowe i już zaplanowane (w kolejności wyboru) oraz liczba
        // pominiętych. Zmiana któregokolwiek między podglądem a zatwierdzeniem
        // zatrzymuje zapis.
        $odcisk = hash('sha256', implode('|', [
            $dzien->toDateString(),
            'n:'.implode(',', array_map(fn (Recipe $p): string => (string) $p->getKey(), $nowe)),
            'j:'.implode(',', array_map(fn (Recipe $p): string => (string) $p->getKey(), $juzSa)),
            'x:'.$niedostepne,
        ]));

        return [
            'nowe' => $nowe,
            'juz_sa' => $juzSa,
            'niedostepne' => $niedostepne,
            'dzien_ma' => $dzienMa,
            'limit' => $limit,
            'odcisk' => $odcisk,
        ];
    }
}
