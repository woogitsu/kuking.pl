<?php

declare(strict_types=1);

namespace App\Domain\Zakupy;

use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Recipes\GrupySkladnikow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Policies\RecipePolicy;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prywatna lista zakupów (#27, etap 2; decyzja właściciela z 29.09.2026, D-333).
 *
 * ZAKRES (wąski i celowy):
 *  - ręczne dopisanie pozycji, odhaczanie, usuwanie, „Wyczyść odhaczone”;
 *  - „Dodaj składniki” z przepisu KOPIUJE ORYGINALNE LINIE składników
 *    (`recipe_ingredients.ingredient_text`), po jednej pozycji na linię,
 *    w kolejności, w jakiej człowiek widzi je na stronie przepisu. BEZ
 *    sumowania i łączenia: składnik jest wolnym tekstem („2 jajka”,
 *    „szczypta soli”), a „1 jajko + 2 jajka” wymagałoby parsera i
 *    potwierdzania wyniku (D-310);
 *  - pozycja niesie pochodzenie: skopiowana z przepisu albo ręczna;
 *  - ponowne dodanie składników TEGO SAMEGO przepisu najpierw ostrzega
 *    i dopisuje dopiero po jawnym potwierdzeniu.
 *
 * POZA ZAKRESEM: lista wspólna, offline, grupowanie po działach sklepu.
 *
 * LISTA NIE JEST FURTKĄ DO TREŚCI. Pozycja jest tekstem skopiowanym wtedy,
 * gdy osoba przepis widziała — zostaje jej też wtedy, gdy przepis znika.
 * Tytuł i link do przepisu ekran pokazuje tylko wtedy, gdy właściciel listy
 * wciąż ten przepis widzi (ta sama reguła co planer: `PlanerTygodnia`);
 * inaczej pozycja jest samym tekstem z dopiskiem „Przepis jest już
 * niedostępny.” albo „Przepis został usunięty.”.
 */
final class ListaZakupow
{
    public const STAN_RECZNA = 'reczna';

    public const STAN_PRZEPIS = 'przepis';

    public const STAN_NIEDOSTEPNY = 'niedostepny';

    public const STAN_USUNIETY = 'usuniety';

    public const WYNIK_DODANO = 'dodano';

    public const WYNIK_JUZ_JEST = 'juz_jest';

    public const WYNIK_BRAK_SKLADNIKOW = 'brak_skladnikow';

    public function __construct(
        private readonly RecipePolicy $przepisy = new RecipePolicy,
        private readonly PlanerTygodnia $planer = new PlanerTygodnia,
    ) {}

    public static function maksPozycji(): int
    {
        return (int) config('kuking.zakupy.pozycji_max');
    }

    public static function maksZnakow(): int
    {
        return (int) config('kuking.zakupy.znakow_max');
    }

    /**
     * Pozycje osoby w kolejności dopisywania, każda z opisem pochodzenia.
     * Dwa zapytania niezależnie od liczby pozycji.
     *
     * @return list<array{pozycja: ShoppingListItem, stan: string, przepis: ?Recipe}>
     */
    public function pozycje(User $user): array
    {
        $pozycje = ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $idPrzepisow = $pozycje->pluck('recipe_id')->filter()->unique()->values();
        $widoczne = $idPrzepisow->isEmpty()
            ? collect()
            : $this->planer->widocznePrzepisy($user)->whereIn('recipes.id', $idPrzepisow)->get()->keyBy('id');

        return $pozycje->map(function (ShoppingListItem $pozycja) use ($widoczne): array {
            if ($pozycja->source !== ShoppingListItem::SOURCE_RECIPE) {
                return ['pozycja' => $pozycja, 'stan' => self::STAN_RECZNA, 'przepis' => null];
            }

            if ($pozycja->recipe_id === null) {
                return ['pozycja' => $pozycja, 'stan' => self::STAN_USUNIETY, 'przepis' => null];
            }

            $przepis = $widoczne->get($pozycja->recipe_id);

            return [
                'pozycja' => $pozycja,
                'stan' => $przepis !== null ? self::STAN_PRZEPIS : self::STAN_NIEDOSTEPNY,
                'przepis' => $przepis,
            ];
        })->values()->all();
    }

    /** Ręcznie dopisana pozycja („mleko”, „papier toaletowy”). */
    public function dodajReczna(User $user, string $tekst): ShoppingListItem
    {
        $tekst = self::oczysc($tekst);

        if ($tekst === null) {
            throw ValidationException::withMessages([
                'text' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.',
            ]);
        }

        if (mb_strlen($tekst) > self::maksZnakow()) {
            throw ValidationException::withMessages([
                'text' => 'Skróć wpis do '.self::maksZnakow().' znaków i dopisz jeszcze raz.',
            ]);
        }

        return DB::transaction(function () use ($user, $tekst): ShoppingListItem {
            $this->zablokujListe($user);
            $this->upewnijSieZeSaMiejsca($user, 1);

            return $this->zapisz($user, $tekst, ShoppingListItem::SOURCE_MANUAL, null, $this->nastepnaPozycja($user));
        });
    }

    /**
     * „Dodaj składniki” z przepisu.
     *
     * Przepis musi być widoczny dla osoby (`RecipePolicy::view()`) —
     * identyfikator z żądania nie jest autoryzacją (AGENTS.md §7).
     *
     * Gdy składniki TEGO przepisu już stoją na liście, a osoba nie
     * potwierdziła, nic nie jest dopisywane: wynik `juz_jest` niesie datę
     * wcześniejszego dodania, żeby ekran mógł zapytać.
     *
     * @return array{wynik: string, dodano: int, wczesniej: ?CarbonInterface}
     */
    public function dodajSkladniki(User $user, Recipe $przepis, bool $potwierdzone = false): array
    {
        if (! $this->przepisy->view($user, $przepis)) {
            throw new AuthorizationException;
        }

        $linie = $this->linieSkladnikow($przepis);

        if ($linie === []) {
            return ['wynik' => self::WYNIK_BRAK_SKLADNIKOW, 'dodano' => 0, 'wczesniej' => null];
        }

        return DB::transaction(function () use ($user, $przepis, $linie, $potwierdzone): array {
            $this->zablokujListe($user);

            $wczesniej = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->where('recipe_id', $przepis->getKey())
                ->min('created_at');

            if ($wczesniej !== null && ! $potwierdzone) {
                return [
                    'wynik' => self::WYNIK_JUZ_JEST,
                    'dodano' => 0,
                    'wczesniej' => Carbon::parse($wczesniej),
                ];
            }

            $this->upewnijSieZeSaMiejsca($user, count($linie));

            $pozycja = $this->nastepnaPozycja($user);
            foreach ($linie as $linia) {
                $this->zapisz($user, $linia, ShoppingListItem::SOURCE_RECIPE, $przepis, $pozycja++);
            }

            return ['wynik' => self::WYNIK_DODANO, 'dodano' => count($linie), 'wczesniej' => null];
        });
    }

    /** Odhaczenie albo cofnięcie odhaczenia — ustawienie stanu, nie przełącznik (idempotentne). */
    public function ustawOdhaczenie(ShoppingListItem $pozycja, bool $odhaczona): void
    {
        if ($odhaczona === $pozycja->jestOdhaczona()) {
            return;
        }

        $pozycja->checked_at = $odhaczona ? now() : null;
        $pozycja->save();
    }

    /** „Wyczyść odhaczone” — tylko pozycje tej osoby, tylko odhaczone. */
    public function wyczyscOdhaczone(User $user): int
    {
        return ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('checked_at')
            ->delete();
    }

    /**
     * Linie składników w kolejności widzianej na stronie przepisu (grupy
     * „Ciasto”, „Farsz” pod sobą), dosłownie, bez pustych.
     *
     * @return list<string>
     */
    private function linieSkladnikow(Recipe $przepis): array
    {
        $linie = [];

        foreach (GrupySkladnikow::ulozyc($przepis->ingredients()->get()) as $grupa) {
            foreach ($grupa['skladniki'] as $skladnik) {
                /** @var RecipeIngredient $skladnik */
                $tekst = self::oczysc((string) $skladnik->ingredient_text);
                if ($tekst !== null) {
                    $linie[] = mb_substr($tekst, 0, self::maksZnakow());
                }
            }
        }

        return $linie;
    }

    private static function oczysc(string $tekst): ?string
    {
        $tekst = trim(preg_replace('/\s+/u', ' ', $tekst) ?? '');

        return $tekst === '' ? null : $tekst;
    }

    /**
     * Blokada własnego wiersza konta szereguje dwa równoległe dopisania tej
     * samej osoby — bez niej obie liczyłyby „299 pozycji” i obie by weszły
     * (ten sam wzorzec co `DodajDoPlanu`).
     */
    private function zablokujListe(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();
    }

    private function upewnijSieZeSaMiejsca(User $user, int $ile): void
    {
        $jest = ShoppingListItem::query()->where('user_id', $user->getKey())->count();

        if ($jest + $ile > self::maksPozycji()) {
            $wolne = max(0, self::maksPozycji() - $jest);

            throw ValidationException::withMessages([
                'text' => 'Lista zakupów mieści najwyżej '.self::maksPozycji().' pozycji, a Twoja ma ich już '.$jest
                    .($wolne > 0 ? ' (zmieści się jeszcze '.$wolne.')' : '')
                    .'. Usuń kupione pozycje przyciskiem „Wyczyść odhaczone” albo skreśl niepotrzebne i spróbuj jeszcze raz.',
            ]);
        }
    }

    private function nastepnaPozycja(User $user): int
    {
        $max = ShoppingListItem::query()->where('user_id', $user->getKey())->max('position');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function zapisz(User $user, string $tekst, string $zrodlo, ?Recipe $przepis, int $pozycja): ShoppingListItem
    {
        $wpis = new ShoppingListItem(['text' => $tekst]);
        $wpis->user_id = $user->getKey();
        $wpis->source = $zrodlo;
        $wpis->recipe_id = $przepis?->getKey();
        $wpis->position = $pozycja;
        $wpis->save();

        return $wpis;
    }

    /**
     * Pozycje niezałatwione i odhaczone jako dwie osobne listy — ekran nie
     * opiera znaczenia na samym przekreśleniu.
     *
     * @param  list<array{pozycja: ShoppingListItem, stan: string, przepis: ?Recipe}>  $pozycje
     * @return array{do_kupienia: Collection<int, array<string, mixed>>, odhaczone: Collection<int, array<string, mixed>>}
     */
    public static function podziel(array $pozycje): array
    {
        $kolekcja = collect($pozycje);

        return [
            'do_kupienia' => $kolekcja->reject(fn (array $p) => $p['pozycja']->jestOdhaczona())->values(),
            'odhaczone' => $kolekcja->filter(fn (array $p) => $p['pozycja']->jestOdhaczona())->values(),
        ];
    }
}
