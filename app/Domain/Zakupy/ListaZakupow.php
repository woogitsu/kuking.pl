<?php

declare(strict_types=1);

namespace App\Domain\Zakupy;

use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Recipes\GrupySkladnikow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListUndo;
use App\Models\User;
use App\Policies\RecipePolicy;
use App\Policies\ShoppingListPolicy;
use App\Support\Czas;
use App\Support\Odmiana;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * KRÓTKIE COFNIĘCIE USUNIĘCIA (#2630, rozszerzenie D-333): „Usuń” przy
 * pozycji i „Wyczyść odhaczone” zapamiętują migawkę usuniętych pozycji
 * (`shopping_list_undos`, JEDNA ostatnia operacja na osobę) na
 * `kuking.zakupy.cofniecie_minut` minut. „Cofnij usunięcie” dopisuje te
 * pozycje z powrotem (z tekstem, pochodzeniem, przepisem, odhaczeniem i datą
 * dopisania), NIE ruszając pozycji dodanych w międzyczasie. Potwierdzenia
 * obecnych akcji zostają. To nie jest kosz ani historia zakupów.
 *
 * NAZWANE LISTY (#2528, V2, D-333 — paczka E): oprócz listy domyślnej
 * („Na co dzień”, pozycje z `list_id = NULL`, czyli wszystko, co było przed tą
 * funkcją) osoba może założyć nazwane listy na okazje („Święta”). Reguły:
 *  - najwyżej `kuking.zakupy.list_max` list razem z domyślną;
 *  - lista docelowa jest zawsze JAWNA: formularz niesie wybraną listę, a brak
 *    wyboru znaczy domyślna — żadnego cichego wyboru według zachowania;
 *  - odhaczanie, usuwanie, „Wyczyść odhaczone” i ostrzeżenie o ponownym
 *    dodaniu przepisu dotyczą jednej listy; ten sam przepis na innej liście
 *    nie jest duplikatem;
 *  - limit pozycji (`pozycji_max`) liczy się dla CAŁEGO konta, pod blokadą
 *    wiersza osoby, jak dotąd;
 *  - lista należy do osoby: identyfikator cudzej listy niczego nie otwiera
 *    (`ShoppingListPolicy`), a lista usunięta w innej karcie daje komunikat
 *    po polsku, nie błąd;
 *  - usunięcie listy z pozycjami wymaga potwierdzenia z liczbą pozycji,
 *    którą człowiek widział; gdy liczba się zmieniła, nic nie jest kasowane.
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

    public const COFNIECIE_PRZYWROCONO = 'przywrocono';

    /** Nic do cofnięcia: nie było usunięcia, wygasło albo już cofnięte (drugie kliknięcie). */
    public const COFNIECIE_BRAK = 'brak';

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
    public function pozycje(User $user, ?ShoppingList $lista = null): array
    {
        $pozycje = self::naLiscie(ShoppingListItem::query()->where('user_id', $user->getKey()), $lista)
            ->orderBy('position')
            ->orderBy('created_at')
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
    public function dodajReczna(User $user, string $tekst, ?ShoppingList $lista = null): ShoppingListItem
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

        return DB::transaction(function () use ($user, $tekst, $lista): ShoppingListItem {
            $this->zablokujListe($user);
            $lista = $this->swiezaLista($user, $lista);
            $this->upewnijSieZeSaMiejsca($user, 1);

            return $this->zapisz($user, $tekst, ShoppingListItem::SOURCE_MANUAL, null, $this->nastepnaPozycja($user), $lista);
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
    public function dodajSkladniki(User $user, Recipe $przepis, bool $potwierdzone = false, ?ShoppingList $lista = null): array
    {
        if (! $this->przepisy->view($user, $przepis)) {
            throw new AuthorizationException;
        }

        $linie = $this->linieSkladnikow($przepis);

        if ($linie === []) {
            return ['wynik' => self::WYNIK_BRAK_SKLADNIKOW, 'dodano' => 0, 'wczesniej' => null];
        }

        return DB::transaction(function () use ($user, $przepis, $linie, $potwierdzone, $lista): array {
            $this->zablokujListe($user);
            $lista = $this->swiezaLista($user, $lista);

            // Duplikat to ten sam przepis na TEJ SAMEJ liście; na innej liście
            // składniki wolno dopisać bez pytania (#2528).
            $wczesniej = self::naLiscie(ShoppingListItem::query()->where('user_id', $user->getKey()), $lista)
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
                $this->zapisz($user, $linia, ShoppingListItem::SOURCE_RECIPE, $przepis, $pozycja++, $lista);
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

    /**
     * „Wyczyść odhaczone” — tylko pozycje tej osoby z WYBRANEJ listy (#2528),
     * tylko odhaczone. Przed
     * skasowaniem zapamiętuje je do krótkiego cofnięcia (zastępuje poprzednią
     * migawkę). Gdy nie ma odhaczonych, niczego nie zmienia — także migawki.
     */
    public function wyczyscOdhaczone(User $user, ?ShoppingList $lista = null): int
    {
        return DB::transaction(function () use ($user, $lista): int {
            $this->zablokujListe($user);
            $lista = $this->swiezaLista($user, $lista);

            $odhaczone = self::naLiscie(ShoppingListItem::query()->where('user_id', $user->getKey()), $lista)
                ->whereNotNull('checked_at')
                ->orderBy('position')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($odhaczone->isEmpty()) {
                return 0;
            }

            $this->zapamietajUsuniecie($user, ShoppingListUndo::SCOPE_CHECKED, $odhaczone);

            return ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->whereIn('id', $odhaczone->modelKeys())
                ->delete();
        });
    }

    /**
     * „Usuń” przy jednej pozycji: zapamiętuje ją do krótkiego cofnięcia
     * (zastępuje poprzednią migawkę) i kasuje. Gdy pozycji już nie ma
     * (powtórzone żądanie), nic nie zmienia — także migawki.
     */
    public function usunPozycje(User $user, ShoppingListItem $pozycja): void
    {
        DB::transaction(function () use ($user, $pozycja): void {
            $this->zablokujListe($user);

            $swieza = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->whereKey($pozycja->getKey())
                ->first();

            if ($swieza === null) {
                return;
            }

            $this->zapamietajUsuniecie($user, ShoppingListUndo::SCOPE_SINGLE, collect([$swieza]));
            $swieza->delete();
        });
    }

    /**
     * Czekająca na cofnięcie ostatnia operacja tej osoby albo null. Wygasłą
     * migawkę kasuje przy okazji (retencja nie czeka na zadanie cykliczne).
     */
    public function oczekujaceCofniecie(User $user): ?ShoppingListUndo
    {
        ShoppingListUndo::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '<=', now())
            ->delete();

        return ShoppingListUndo::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * „Cofnij usunięcie”: dopisuje pozycje ostatniej operacji z powrotem.
     *
     *  - tylko migawka tej osoby (wiersz wybierany po `user_id`, bez
     *    identyfikatora z żądania);
     *  - NIE zastępuje listy: pozycje dodane w międzyczasie zostają, a
     *    późniejsze korekty (np. odhaczenie innych pozycji) nie są ruszane;
     *  - limit konta liczony pod blokadą wiersza osoby, jak przy dopisywaniu;
     *    gdy brakuje miejsca, wyjątek cofa transakcję i migawka ZOSTAJE;
     *  - pozycje wracają z własnymi identyfikatorami i `insertOrIgnore`, a
     *    migawka jest kasowana w tej samej transakcji, więc ponowne kliknięcie
     *    nie powiela niczego (drugie dostaje `brak`);
     *  - przepis, który zniknął, nie zostawia klucza (sam tekst, `source`
     *    zostaje `recipe`); tytuł, link ani zdjęcie przepisu nie są
     *    odtwarzane — ekran pyta o widoczność przepisu jak zawsze.
     *
     * `lista_id` w wyniku to lista pierwszej przywróconej pozycji (do której ekran wraca);
     * `null` = lista domyślna.
     *
     * @return array{wynik: string, przywrocono: int, zakres: ?string, lista_id: ?string}
     */
    public function cofnijUsuniecie(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $this->zablokujListe($user);

            $migawka = ShoppingListUndo::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($migawka === null) {
                return ['wynik' => self::COFNIECIE_BRAK, 'przywrocono' => 0, 'zakres' => null, 'lista_id' => null];
            }

            if ($migawka->wygasla()) {
                $migawka->delete();

                return ['wynik' => self::COFNIECIE_BRAK, 'przywrocono' => 0, 'zakres' => null, 'lista_id' => null];
            }

            $this->upewnijSieZeSaMiejscaNaPrzywrocenie($user, $migawka);

            /** @var list<array<string, mixed>> $pozycje */
            $pozycje = $migawka->items;
            $idPrzepisow = array_values(array_filter(array_column($pozycje, 'recipe_id')));
            $istniejace = $idPrzepisow === []
                ? []
                : DB::table('recipes')->whereIn('id', $idPrzepisow)->pluck('id')->all();

            // Lista, z której pozycja zniknęła, mogła być w międzyczasie usunięta —
            // wtedy pozycja wraca na listę domyślną (nie ginie).
            $idList = array_values(array_filter(array_column($pozycje, 'list_id')));
            $istniejaceListy = $idList === []
                ? []
                : ShoppingList::query()->where('user_id', $user->getKey())->whereIn('id', $idList)->pluck('id')->all();

            usort($pozycje, fn (array $a, array $b): int => [(int) $a['position'], (string) $a['created_at']]
                <=> [(int) $b['position'], (string) $b['created_at']]);

            $przywrocono = 0;
            $listaId = null;
            foreach ($pozycje as $i => $p) {
                if ($i === 0) {
                    $listaId = in_array($p['list_id'] ?? null, $istniejaceListy, true) ? $p['list_id'] : null;
                }
                $przywrocono += DB::table('shopping_list_items')->insertOrIgnore([
                    'id' => $p['id'],
                    'user_id' => $user->getKey(),
                    'text' => $p['text'],
                    'source' => $p['source'],
                    'recipe_id' => in_array($p['recipe_id'], $istniejace, true) ? $p['recipe_id'] : null,
                    'list_id' => in_array($p['list_id'] ?? null, $istniejaceListy, true) ? $p['list_id'] : null,
                    'position' => (int) $p['position'],
                    'checked_at' => $p['checked_at'],
                    'created_at' => $p['created_at'],
                    'updated_at' => now(),
                ]);
            }

            $zakres = $migawka->scope;
            $migawka->delete();

            return ['wynik' => self::COFNIECIE_PRZYWROCONO, 'przywrocono' => $przywrocono, 'zakres' => $zakres, 'lista_id' => $listaId];
        });
    }

    /** Retencja: kasuje wszystkie wygasłe migawki (zadanie `kuking:sprzataj-cofniecia-zakupow`). */
    public function sprzatnijWygasleCofniecia(): int
    {
        return ShoppingListUndo::query()->where('expires_at', '<=', now())->delete();
    }

    public static function minutCofniecia(): int
    {
        return max(1, (int) config('kuking.zakupy.cofniecie_minut'));
    }

    /**
     * Jedna ostatnia operacja na osobę: poprzednia migawka znika (żadnej
     * historii). Wołane pod blokadą wiersza osoby.
     *
     * @param  Collection<int, ShoppingListItem>  $pozycje
     */
    private function zapamietajUsuniecie(User $user, string $zakres, Collection $pozycje): void
    {
        $migawka = $pozycje->map(fn (ShoppingListItem $p): array => [
            'id' => $p->getKey(),
            'text' => $p->text,
            'source' => $p->source,
            'recipe_id' => $p->recipe_id,
            'list_id' => $p->list_id,
            'position' => $p->position,
            'checked_at' => $p->checked_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ])->values()->all();

        DB::table('shopping_list_undos')->where('user_id', $user->getKey())->delete();
        DB::table('shopping_list_undos')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'scope' => $zakres,
            'items' => json_encode($migawka, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'items_count' => count($migawka),
            'expires_at' => now()->addMinutes(self::minutCofniecia()),
            'created_at' => now(),
        ]);
    }

    private function upewnijSieZeSaMiejscaNaPrzywrocenie(User $user, ShoppingListUndo $migawka): void
    {
        $jest = ShoppingListItem::query()->where('user_id', $user->getKey())->count();

        if ($jest + $migawka->items_count <= self::maksPozycji()) {
            return;
        }

        $zmiesci = max(0, self::maksPozycji() - $migawka->items_count);

        throw ValidationException::withMessages([
            'cofniecie' => 'Nie ma miejsca, żeby cofnąć usunięcie: na liście jest '.$jest.' z '.self::maksPozycji()
                .' pozycji, a do przywrócenia czeka '.$migawka->items_count.'. Usunięte pozycje nie zginęły i można je cofnąć do '
                .Czas::lokalnie($migawka->expires_at)->format('H:i')
                .', ale na liście musiałoby być najwyżej '.$zmiesci.'. Uwaga: każde kolejne usunięcie zastąpi tę możliwość cofnięcia.',
        ]);
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

    // ------------------------------------------------------------------
    // Nazwane listy (#2528)
    // ------------------------------------------------------------------

    public static function maksList(): int
    {
        return max(1, (int) config('kuking.zakupy.list_max'));
    }

    public static function maksZnakowNazwy(): int
    {
        return (int) config('kuking.zakupy.nazwa_listy_znakow_max');
    }

    /**
     * Nazwane listy osoby w kolejności zakładania (domyślnej tu nie ma —
     * to pozycje bez `list_id`).
     *
     * @return Collection<int, ShoppingList>
     */
    public function listy(User $user): Collection
    {
        return ShoppingList::query()
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Zakładki list z liczbą pozycji: najpierw domyślna, potem nazwane.
     * Jedno zapytanie o liczby niezależnie od liczby list.
     *
     * @return list<array{lista: ?ShoppingList, nazwa: string, ile: int, do_kupienia: int}>
     */
    public function podsumowanie(User $user): array
    {
        $liczby = ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->selectRaw('list_id, count(*) as ile, count(*) filter (where checked_at is null) as do_kupienia')
            ->groupBy('list_id')
            ->get()
            ->keyBy(fn (ShoppingListItem $w): string => (string) $w->getAttribute('list_id'));

        $wiersz = function (?ShoppingList $lista, string $nazwa) use ($liczby): array {
            $liczba = $liczby->get($lista === null ? '' : (string) $lista->getKey());

            return [
                'lista' => $lista,
                'nazwa' => $nazwa,
                'ile' => (int) ($liczba?->getAttribute('ile') ?? 0),
                'do_kupienia' => (int) ($liczba?->getAttribute('do_kupienia') ?? 0),
            ];
        };

        $wynik = [$wiersz(null, ShoppingList::NAZWA_DOMYSLNEJ)];
        foreach ($this->listy($user) as $lista) {
            $wynik[] = $wiersz($lista, $lista->name);
        }

        return $wynik;
    }

    /**
     * Lista wskazana w żądaniu: pusty identyfikator = domyślna (`null`).
     * Cudza lista — odmowa (UUID nie jest autoryzacją); lista, której nie ma
     * (usunięta w innej karcie) — komunikat po polsku przy polu `$pole`, bez
     * gubienia tego, co człowiek wpisał.
     */
    public function znajdzListe(User $user, ?string $id, string $pole = 'lista'): ?ShoppingList
    {
        if ($id === null || $id === '') {
            return null;
        }

        $lista = Str::isUuid($id) ? ShoppingList::query()->find($id) : null;

        if ($lista === null) {
            throw ValidationException::withMessages([
                $pole => 'Tej listy zakupów już nie ma. Wybierz listę jeszcze raz.',
            ]);
        }

        if (! (new ShoppingListPolicy)->view($user, $lista)) {
            throw new AuthorizationException;
        }

        return $lista;
    }

    /** Zakłada nazwaną listę; pod blokadą konta, więc dwa kliknięcia nie przekroczą limitu. */
    public function utworzListe(User $user, string $nazwa): ShoppingList
    {
        $nazwa = $this->sprawdzNazwe($nazwa, 'nazwa');

        return DB::transaction(function () use ($user, $nazwa): ShoppingList {
            $this->zablokujListe($user);

            $mam = ShoppingList::query()->where('user_id', $user->getKey())->count();
            if ($mam + 1 >= self::maksList()) {
                throw ValidationException::withMessages([
                    'nazwa' => 'Możesz mieć najwyżej '.self::maksList().' '.Odmiana::rzeczownik(self::maksList(), 'listę', 'listy', 'list')
                        .' zakupów, razem z „'.ShoppingList::NAZWA_DOMYSLNEJ.'”. Usuń listę, której już nie potrzebujesz, i spróbuj jeszcze raz.',
                ]);
            }

            $this->upewnijSieZeNazwaWolna($user, $nazwa, null, 'nazwa');

            $lista = new ShoppingList(['name' => $nazwa]);
            $lista->user_id = $user->getKey();
            $lista->save();

            return $lista;
        });
    }

    /** Zmienia samą nazwę; pozycje, ich kolejność i odhaczenia zostają. */
    public function zmienNazweListy(User $user, ShoppingList $lista, string $nazwa): ShoppingList
    {
        $nazwa = $this->sprawdzNazwe($nazwa, 'nowa_nazwa');

        return DB::transaction(function () use ($user, $lista, $nazwa): ShoppingList {
            $this->zablokujListe($user);
            $swieza = $this->swiezaLista($user, $lista);
            $this->upewnijSieZeNazwaWolna($user, $nazwa, $swieza?->getKey(), 'nowa_nazwa');

            $swieza?->update(['name' => $nazwa]);

            return $swieza ?? $lista;
        });
    }

    /**
     * Usuwa nazwaną listę RAZEM z jej pozycjami — wyłącznie po potwierdzeniu,
     * które niesie liczbę pozycji widzianą na ekranie. Gdy w międzyczasie
     * (np. w drugiej karcie) liczba się zmieniła, NIC nie jest kasowane:
     * człowiek potwierdzał co innego, niż jest teraz na liście.
     *
     * @return int ile pozycji usunięto razem z listą; -1 gdy listy już nie było (powtórzone żądanie)
     */
    public function usunListe(User $user, ShoppingList $lista, bool $potwierdzone, ?int $widzianaLiczba): int
    {
        return DB::transaction(function () use ($user, $lista, $potwierdzone, $widzianaLiczba): int {
            $this->zablokujListe($user);

            $swieza = ShoppingList::query()->where('user_id', $user->getKey())->whereKey($lista->getKey())->first();
            if ($swieza === null) {
                return -1;
            }

            $ile = ShoppingListItem::query()->where('user_id', $user->getKey())->where('list_id', $swieza->getKey())->count();

            if ($ile > 0 && ! $potwierdzone) {
                throw ValidationException::withMessages([
                    'potwierdzam' => 'Lista „'.$swieza->name.'” ma pozycje ('.$ile.'). Otwórz „Usuń listę”, przeczytaj, co zniknie, i potwierdź.',
                ]);
            }

            if ($ile > 0 && $widzianaLiczba !== $ile) {
                throw ValidationException::withMessages([
                    'potwierdzam' => 'Na liście „'.$swieza->name.'” zmieniły się pozycje: jest ich teraz '.$ile
                        .'. Nic nie usunęliśmy. Sprawdź listę i potwierdź usunięcie jeszcze raz.',
                ]);
            }

            $swieza->delete(); // klucz obcy kasuje pozycje tej listy

            return $ile;
        });
    }

    /** Lista odczytana jeszcze raz pod blokadą konta (mogła zniknąć w drugiej karcie). */
    private function swiezaLista(User $user, ?ShoppingList $lista): ?ShoppingList
    {
        if ($lista === null) {
            return null;
        }

        $swieza = ShoppingList::query()->where('user_id', $user->getKey())->whereKey($lista->getKey())->first();

        if ($swieza === null) {
            throw ValidationException::withMessages([
                'lista' => 'Tej listy zakupów już nie ma. Nic nie zapisaliśmy — wybierz listę jeszcze raz.',
            ]);
        }

        return $swieza;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $zapytanie
     * @return Builder<TModel>
     */
    private static function naLiscie(Builder $zapytanie, ?ShoppingList $lista): Builder
    {
        return $lista === null
            ? $zapytanie->whereNull('list_id')
            : $zapytanie->where('list_id', $lista->getKey());
    }

    private function sprawdzNazwe(string $nazwa, string $pole): string
    {
        $nazwa = self::oczysc($nazwa);

        if ($nazwa === null) {
            throw ValidationException::withMessages([
                $pole => 'Wpisz nazwę listy, np. „Święta” albo „Przyjęcie u Kasi”.',
            ]);
        }

        if (mb_strlen($nazwa) > self::maksZnakowNazwy()) {
            throw ValidationException::withMessages([
                $pole => 'Skróć nazwę listy do '.self::maksZnakowNazwy().' znaków i spróbuj jeszcze raz.',
            ]);
        }

        return $nazwa;
    }

    private function upewnijSieZeNazwaWolna(User $user, string $nazwa, ?string $pomijana, string $pole): void
    {
        if (mb_strtolower($nazwa) === mb_strtolower(ShoppingList::NAZWA_DOMYSLNEJ)) {
            throw ValidationException::withMessages([
                $pole => '„'.ShoppingList::NAZWA_DOMYSLNEJ.'” to Twoja podstawowa lista, która już jest. Wybierz inną nazwę.',
            ]);
        }

        $zajeta = ShoppingList::query()
            ->where('user_id', $user->getKey())
            ->when($pomijana !== null, fn ($q) => $q->where('id', '!=', $pomijana))
            ->get(['name'])
            ->contains(fn (ShoppingList $l): bool => mb_strtolower($l->name) === mb_strtolower($nazwa));

        if ($zajeta) {
            throw ValidationException::withMessages([
                $pole => 'Masz już listę o nazwie „'.$nazwa.'”. Wybierz inną nazwę.',
            ]);
        }
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

    private function zapisz(User $user, string $tekst, string $zrodlo, ?Recipe $przepis, int $pozycja, ?ShoppingList $lista = null): ShoppingListItem
    {
        $wpis = new ShoppingListItem(['text' => $tekst]);
        $wpis->user_id = $user->getKey();
        $wpis->source = $zrodlo;
        $wpis->recipe_id = $przepis?->getKey();
        $wpis->position = $pozycja;
        $wpis->list_id = $lista?->getKey();
        $wpis->save();

        return $wpis;
    }

    /**
     * Pozycje niezałatwione i odhaczone jako dwie osobne listy — ekran nie
     * opiera znaczenia na samym przekreśleniu.
     *
     * @param  list<array{pozycja: ShoppingListItem, stan: string, przepis: ?Recipe}>  $pozycje
     * @return array{do_kupienia: list<array{pozycja: ShoppingListItem, stan: string, przepis: ?Recipe}>, odhaczone: list<array{pozycja: ShoppingListItem, stan: string, przepis: ?Recipe}>}
     */
    public static function podziel(array $pozycje): array
    {
        $doKupienia = [];
        $odhaczone = [];

        foreach ($pozycje as $p) {
            if ($p['pozycja']->jestOdhaczona()) {
                $odhaczone[] = $p;
            } else {
                $doKupienia[] = $p;
            }
        }

        return [
            'do_kupienia' => $doKupienia,
            'odhaczone' => $odhaczone,
        ];
    }
}
