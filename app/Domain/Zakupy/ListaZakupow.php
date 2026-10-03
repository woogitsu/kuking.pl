<?php

declare(strict_types=1);

namespace App\Domain\Zakupy;

use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\Porcje\PrzeliczonySkladnik;
use App\Domain\Recipes\Porcje\WyborPorcji;
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

    /** Podgląd nie zgadza się już z przepisem — przeliczone porcje (#2489) albo wybór składników (#2462): nic nie dopisano. */
    public const WYNIK_ZMIENIONY = 'zmieniony';

    public const COFNIECIE_PRZYWROCONO = 'przywrocono';

    /** Nic do cofnięcia: nie było usunięcia, wygasło albo już cofnięte (drugie kliknięcie). */
    public const COFNIECIE_BRAK = 'brak';

    public const POPRAWKA_ZASTOSOWANA = 'poprawiono';

    /** Tekst jest już taki, jak wpisano (po ściśnięciu odstępów): nic się nie zmienia. */
    public const POPRAWKA_BEZ_ZMIAN = 'bez_zmian';

    /** Ktoś zmienił tekst w innym oknie, odkąd ten formularz go widział. */
    public const POPRAWKA_KONFLIKT = 'konflikt';

    /** Pozycji już nie ma (usunięta w innym oknie) — nie powstaje ponownie. */
    public const POPRAWKA_BRAK = 'brak';

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

            return $this->zapisz($user, $tekst, ShoppingListItem::SOURCE_MANUAL, null, $this->nastepnaPozycja($user), lista: $lista);
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
     * PRZELICZONE PORCJE (V2, #2489): z `$porcje` i `$odcisk` z podglądu
     * (`podgladPorcji()`) pozycje dostają ilości przeliczone na tę liczbę
     * porcji tym samym mechanizmem co strona przepisu. Serwer liczy je od nowa
     * z aktualnych składników; gdy wynik różni się od podglądu (odcisk),
     * nic się nie dopisuje (`WYNIK_ZMIENIONY`). Linie, których nie wolno
     * przeliczyć („do smaku”, bez ilości, suma, brak liczby), zostają
     * oryginalne i NIE niosą oznaczenia przeliczenia.
     *
     * WYBÓR SKŁADNIKÓW (#2462, V2): z `$wybraneId` dopisujemy dokładnie wskazane
     * linie TEGO przepisu (kolejność przepisu, nie żądania). Identyfikatory nie
     * są treścią ani autoryzacją — tekst czyta serwer, po blokadzie, z linii
     * przepisu, który osoba nadal widzi. `$odcisk` to skrót listy linii z chwili
     * podglądu: jeśli przepis zmienił się od podglądu, nic nie jest kopiowane
     * (`zmieniony`), a człowiek dostaje świeży podgląd. Identyfikator spoza
     * przepisu odrzuca całość. Ostrzeżenie o ponownym dodaniu, limit i zapis
     * „wszystko albo nic” działają jak przy dodaniu wszystkich.
     *
     * Oba tryby razem nie są obsługiwane (dwa różne podglądy, dwa odciski):
     * takie żądanie kończy się błędem przy polu, bez zapisu. `$odcisk` należy
     * do trybu, który jest aktywny.
     *
     * @param  list<string>|null  $wybraneId
     * @return array{wynik: string, dodano: int, wczesniej: ?CarbonInterface, przeliczono: int, bez_przeliczenia: int}
     */
    public function dodajSkladniki(User $user, Recipe $przepis, bool $potwierdzone = false, ?float $porcje = null, ?string $odcisk = null, ?array $wybraneId = null, ?ShoppingList $lista = null): array
    {
        if (! $this->przepisy->view($user, $przepis)) {
            throw new AuthorizationException;
        }

        $wybor = null;
        if ($porcje !== null) {
            $wybor = WyborPorcji::dla($przepis, $porcje);

            if (! $wybor->przeliczone() || $wybor->odrzucone) {
                throw ValidationException::withMessages([
                    'porcje' => 'Nie rozpoznajemy tej liczby porcji. Wybierz ją jeszcze raz na stronie przepisu.',
                ]);
            }
        }

        if ($porcje !== null && $wybraneId !== null) {
            throw ValidationException::withMessages([
                'skladniki' => 'Wybierz albo liczbę porcji, albo pojedyncze składniki — oba naraz nie działają. Wróć do przepisu i spróbuj jeszcze raz.',
            ]);
        }

        $linie = $wybor === null ? $this->linieDoZapisu($this->linieSkladnikow($przepis)) : $this->liniePrzeliczone($przepis, $wybor);

        if ($linie === []) {
            return ['wynik' => self::WYNIK_BRAK_SKLADNIKOW, 'dodano' => 0, 'wczesniej' => null, 'przeliczono' => 0, 'bez_przeliczenia' => 0];
        }

        return DB::transaction(function () use ($user, $przepis, $linie, $potwierdzone, $wybor, $odcisk, $wybraneId, $lista): array {
            $this->zablokujListe($user);
            $lista = $this->swiezaLista($user, $lista);

            // Linie liczone PO blokadzie i od nowa: podgląd musi zgadzać się z zapisem.
            if ($wybor !== null) {
                $swieze = $this->liniePrzeliczone($przepis->fresh() ?? $przepis, $wybor);

                if ($odcisk === null || ! hash_equals(self::odcisk($swieze, (float) $wybor->wybrane), $odcisk)) {
                    return ['wynik' => self::WYNIK_ZMIENIONY, 'dodano' => 0, 'wczesniej' => null, 'przeliczono' => 0, 'bez_przeliczenia' => 0];
                }

                // Zapisujemy dokładnie to, co zatwierdził podgląd.
                $linie = $swieze;
            }

            // Wybór składników (#2462): tylko wskazane linie, z odciskiem podglądu.
            if ($wybraneId !== null) {
                $wybrane = $this->wybraneLinie($przepis, $wybraneId, (string) $odcisk);

                if ($wybrane === null) {
                    return ['wynik' => self::WYNIK_ZMIENIONY, 'dodano' => 0, 'wczesniej' => null, 'przeliczono' => 0, 'bez_przeliczenia' => 0];
                }

                $linie = $this->linieDoZapisu($wybrane);
            }

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
                    'przeliczono' => 0,
                    'bez_przeliczenia' => 0,
                ];
            }

            $this->upewnijSieZeSaMiejsca($user, count($linie));

            $pozycja = $this->nastepnaPozycja($user);
            $przeliczono = 0;
            foreach ($linie as $linia) {
                $skala = $wybor !== null && $linia['przeliczona'] ? (float) $wybor->wybrane : null;
                $this->zapisz($user, $linia['tekst'], ShoppingListItem::SOURCE_RECIPE, $przepis, $pozycja++, $skala, $lista);
                $przeliczono += $skala !== null ? 1 : 0;
            }

            return [
                'wynik' => self::WYNIK_DODANO,
                'dodano' => count($linie),
                'wczesniej' => null,
                'przeliczono' => $przeliczono,
                'bez_przeliczenia' => $wybor !== null ? count($linie) - $przeliczono : 0,
            ];
        });
    }

    /**
     * Podgląd „Dodaj składniki na N porcji” (#2489): każda linia autora obok
     * tego, co trafi na listę, oraz odcisk, który zatwierdzenie odsyła z powrotem.
     * Nic nie zapisuje.
     *
     * @return array{linie: list<array{tekst: string, oryginal: string, przeliczona: bool, uwaga: ?string}>, odcisk: string, przeliczono: int}
     */
    public function podgladPorcji(Recipe $przepis, WyborPorcji $wybor): array
    {
        $linie = $this->liniePrzeliczone($przepis, $wybor);

        return [
            'linie' => $linie,
            'odcisk' => self::odcisk($linie, (float) $wybor->wybrane),
            'przeliczono' => count(array_filter($linie, fn (array $l): bool => $l['przeliczona'])),
        ];
    }

    /**
     * Linie w kolejności strony przepisu: przeliczone tym samym mechanizmem co
     * strona (`WyborPorcji::przelicz`) albo oryginalne, gdy ich przeliczyć nie wolno.
     *
     * @return list<array{tekst: string, oryginal: string, przeliczona: bool, uwaga: ?string}>
     */
    private function liniePrzeliczone(Recipe $przepis, WyborPorcji $wybor): array
    {
        $linie = [];

        foreach (GrupySkladnikow::ulozyc($przepis->ingredients()->get()) as $grupa) {
            foreach ($grupa['skladniki'] as $skladnik) {
                /** @var RecipeIngredient $skladnik */
                $oryginal = self::oczysc((string) $skladnik->ingredient_text);
                if ($oryginal === null) {
                    continue;
                }

                $oryginal = mb_substr($oryginal, 0, self::maksZnakow());
                $wynik = $wybor->przelicz($skladnik);
                $tekst = $wynik->zmieniony ? self::oczysc($wynik->tekst()) : null;

                if ($tekst !== null && mb_strlen($tekst) <= self::maksZnakow()) {
                    $linie[] = ['tekst' => $tekst, 'oryginal' => $oryginal, 'przeliczona' => true, 'uwaga' => null];

                    continue;
                }

                $linie[] = [
                    'tekst' => $oryginal,
                    'oryginal' => $oryginal,
                    'przeliczona' => false,
                    'uwaga' => $wynik->nieprzeliczony
                        ? PrzeliczonySkladnik::UWAGA_SUMA
                        : ($tekst !== null ? 'Po przeliczeniu linia byłaby za długa, więc zostaje oryginalna. Sprawdź ją samodzielnie.' : 'Tej linii nie przeliczamy (bez ilości, „do smaku” albo brak liczby). Sprawdź ją samodzielnie.'),
                ];
            }
        }

        return $linie;
    }

    /**
     * @param  list<string>  $linie
     * @return list<array{tekst: string, oryginal: string, przeliczona: bool, uwaga: ?string}>
     */
    private function linieDoZapisu(array $linie): array
    {
        return array_map(fn (string $l): array => ['tekst' => $l, 'oryginal' => $l, 'przeliczona' => false, 'uwaga' => null], $linie);
    }

    /** @param  list<array{tekst: string, oryginal: string, przeliczona: bool, uwaga: ?string}>  $linie */
    private static function odcisk(array $linie, float $porcje): string
    {
        return hash('sha256', json_encode([
            round($porcje, 2),
            array_map(fn (array $l): array => [$l['tekst'], $l['przeliczona']], $linie),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
                    // Przeliczenie (#2489) wraca z pozycją; migawki sprzed niego go nie mają.
                    'scaled_servings' => $p['scaled_servings'] ?? null,
                    'list_id' => in_array($p['list_id'] ?? null, $istniejaceListy, true) ? $p['list_id'] : null,
                    'position' => (int) $p['position'],
                    'checked_at' => $p['checked_at'],
                    'edited_at' => $p['edited_at'] ?? null,
                    'created_at' => $p['created_at'],
                    'updated_at' => now(),
                ]);
            }

            $zakres = $migawka->scope;
            $migawka->delete();

            return ['wynik' => self::COFNIECIE_PRZYWROCONO, 'przywrocono' => $przywrocono, 'zakres' => $zakres, 'lista_id' => $listaId];
        });
    }

    /** Znacznik tekstu widzianego w formularzu (skrót, nie sam tekst). */
    public static function znacznikTekstu(ShoppingListItem $pozycja): string
    {
        return hash('sha256', $pozycja->text);
    }

    /**
     * „Popraw” przy jednej pozycji (#2443, V2): zmienia WYŁĄCZNIE tekst
     * istniejącej pozycji tej osoby i zapisuje chwilę korekty (`edited_at`).
     *
     *  - ta sama pozycja: UUID, kolejność, odhaczenie, właściciel, `source`,
     *    `recipe_id` i `created_at` zostają; żaden składnik przepisu nie jest
     *    ruszany. Zapis idzie zapytaniem o DWIE kolumny (plus `updated_at`),
     *    więc równoległe odhaczenie nie ginie;
     *  - pod blokadą wiersza osoby (jak dopisywanie i usuwanie) i z pozycją
     *    czytaną po blokadzie: usunięta w innej karcie pozycja nie powstaje
     *    ponownie (`brak`);
     *  - konflikt kart: formularz niesie znacznik tekstu, który człowiek
     *    widział; gdy tekst zmienił się w międzyczasie, nic się nie zapisuje;
     *  - ten sam limit i ściskanie odstępów co przy dopisaniu, bez cichego
     *    obcinania: za długi albo pusty tekst to błąd przy polu;
     *  - bez porównywania z przepisem: znacznik `edited_at` mówi, że TEKST
     *    jest poprawką właściciela listy, niezależnie od tego, co autor
     *    przepisu zmieni później.
     *
     * @return self::POPRAWKA_ZASTOSOWANA|self::POPRAWKA_BEZ_ZMIAN|self::POPRAWKA_KONFLIKT|self::POPRAWKA_BRAK
     *
     * @throws ValidationException pusty albo za długi tekst
     */
    public function popraw(User $user, string $idPozycji, string $nowyTekst, string $widzianyZnacznik): string
    {
        $tekst = self::oczysc($nowyTekst);

        if ($tekst === null) {
            throw ValidationException::withMessages([
                'text' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.',
            ]);
        }

        if (mb_strlen($tekst) > self::maksZnakow()) {
            throw ValidationException::withMessages([
                'text' => 'Skróć wpis do '.self::maksZnakow().' znaków i zapisz jeszcze raz.',
            ]);
        }

        return DB::transaction(function () use ($user, $idPozycji, $tekst, $widzianyZnacznik): string {
            $this->zablokujListe($user);

            // Cudza pozycja jest dla pytającego tym samym, co nieistniejąca.
            $pozycja = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->whereKey($idPozycji)
                ->lockForUpdate()
                ->first();

            if ($pozycja === null) {
                return self::POPRAWKA_BRAK;
            }

            if ($pozycja->text === $tekst) {
                return self::POPRAWKA_BEZ_ZMIAN;
            }

            if (! hash_equals(self::znacznikTekstu($pozycja), $widzianyZnacznik)) {
                return self::POPRAWKA_KONFLIKT;
            }

            ShoppingListItem::query()
                ->where('user_id', $user->getKey())
                ->whereKey($pozycja->getKey())
                ->update(['text' => $tekst, 'edited_at' => now(), 'updated_at' => now()]);

            return self::POPRAWKA_ZASTOSOWANA;
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
            'scaled_servings' => $p->scaled_servings,
            'list_id' => $p->list_id,
            'position' => $p->position,
            'checked_at' => $p->checked_at?->toIso8601String(),
            'edited_at' => $p->edited_at?->toIso8601String(),
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
        return array_column($this->liniePrzepisu($przepis), 'tekst');
    }

    /**
     * Te same linie z identyfikatorami, płasko, w kolejności ze strony przepisu.
     *
     * @return list<array{id: string, tekst: string}>
     */
    private function liniePrzepisu(Recipe $przepis): array
    {
        $linie = [];

        foreach ($this->grupyPrzepisu($przepis) as $grupa) {
            foreach ($grupa['skladniki'] as $linia) {
                $linie[] = $linia;
            }
        }

        return $linie;
    }

    /**
     * Grupy składników z identyfikatorami linii (do ekranu wyboru, #2462).
     * Linia pusta po oczyszczeniu odpada; grupa bez linii znika.
     *
     * @return list<array{nazwa: ?string, skladniki: list<array{id: string, tekst: string}>}>
     */
    private function grupyPrzepisu(Recipe $przepis): array
    {
        $grupy = [];

        foreach (GrupySkladnikow::ulozyc($przepis->ingredients()->get()) as $grupa) {
            $linie = [];
            foreach ($grupa['skladniki'] as $skladnik) {
                /** @var RecipeIngredient $skladnik */
                $tekst = self::oczysc((string) $skladnik->ingredient_text);
                if ($tekst !== null) {
                    $linie[] = ['id' => (string) $skladnik->getKey(), 'tekst' => mb_substr($tekst, 0, self::maksZnakow())];
                }
            }

            if ($linie !== []) {
                $grupy[] = ['nazwa' => $grupa['nazwa'], 'skladniki' => $linie];
            }
        }

        return $grupy;
    }

    /**
     * Skrót listy linii (id + tekst, w kolejności ze strony) — odcisk podglądu wyboru.
     *
     * @param  list<array{id: string, tekst: string}>  $linie
     */
    private static function odciskLinii(array $linie): string
    {
        return hash('sha256', implode("\n", array_map(fn (array $l): string => $l['id'].'|'.$l['tekst'], $linie)));
    }

    /**
     * Ekran wyboru składników (#2462): grupy z identyfikatorami linii i odcisk
     * aktualnej listy. Tylko odczyt — niczego nie dopisuje.
     *
     * @return array{grupy: list<array{nazwa: ?string, skladniki: list<array{id: string, tekst: string}>}>, odcisk: string, ile: int}
     */
    public function doWyboru(Recipe $przepis): array
    {
        $grupy = $this->grupyPrzepisu($przepis);
        $linie = $this->liniePrzepisu($przepis);

        return ['grupy' => $grupy, 'odcisk' => self::odciskLinii($linie), 'ile' => count($linie)];
    }

    /** Kiedy składniki TEGO przepisu trafiły na listę po raz pierwszy (albo null) — do ostrzeżenia. */
    public function pierwszeDodanie(User $user, Recipe $przepis, ?ShoppingList $lista = null): ?CarbonInterface
    {
        // Ta sama lista co w `dodajSkladniki()` — duplikat liczy się na jednej liście (#2528).
        $wczesniej = self::naLiscie(ShoppingListItem::query()->where('user_id', $user->getKey()), $lista)
            ->where('recipe_id', $przepis->getKey())
            ->min('created_at');

        return $wczesniej === null ? null : Carbon::parse($wczesniej);
    }

    /**
     * Teksty wybranych linii w kolejności przepisu — albo `null`, gdy przepis
     * zmienił się od podglądu (odcisk się nie zgadza). Identyfikator spoza
     * tego przepisu przy zgodnym odcisku to podmiana: odrzucamy całość.
     * Wołane po blokadzie listy, z linii czytanych na nowo.
     *
     * @param  list<string>  $wybraneId
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    private function wybraneLinie(Recipe $przepis, array $wybraneId, string $odcisk): ?array
    {
        $linie = $this->liniePrzepisu($przepis);

        if (! hash_equals(self::odciskLinii($linie), $odcisk)) {
            return null;
        }

        $znane = array_column($linie, 'id');
        $wybraneId = array_values(array_unique($wybraneId));

        if ($wybraneId === [] || array_diff($wybraneId, $znane) !== []) {
            throw ValidationException::withMessages([
                'skladniki' => $wybraneId === []
                    ? 'Zaznacz co najmniej jeden składnik i naciśnij „Dodaj wybrane”. Nic nie zostało dodane.'
                    : 'Nie rozpoznajemy części zaznaczonych składników. Odśwież stronę, zaznacz je jeszcze raz — nic nie zostało dodane.',
            ]);
        }

        $teksty = [];
        foreach ($linie as $linia) {
            if (in_array($linia['id'], $wybraneId, true)) {
                $teksty[] = $linia['tekst'];
            }
        }

        return $teksty;
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

    private function zapisz(User $user, string $tekst, string $zrodlo, ?Recipe $przepis, int $pozycja, ?float $przeliczoneNa = null, ?ShoppingList $lista = null): ShoppingListItem
    {
        $wpis = new ShoppingListItem(['text' => $tekst]);
        $wpis->user_id = $user->getKey();
        $wpis->source = $zrodlo;
        // Poza `$fillable`: przeliczenie na porcje ustawia wyłącznie ta akcja (#2489).
        $wpis->scaled_servings = $przeliczoneNa;
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
