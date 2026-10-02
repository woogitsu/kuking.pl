<?php

declare(strict_types=1);

namespace App\Domain\Zakupy;

use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Recipes\GrupySkladnikow;
use App\Domain\Recipes\Porcje\PrzeliczonySkladnik;
use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListUndo;
use App\Models\User;
use App\Policies\RecipePolicy;
use App\Support\Czas;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
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

    /** Podgląd przeliczonych ilości nie zgadza się już z przepisem (#2489): nic nie dopisano. */
    public const WYNIK_ZMIENIONY = 'zmieniony';

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
    public function pozycje(User $user): array
    {
        $pozycje = ShoppingListItem::query()
            ->where('user_id', $user->getKey())
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
     * PRZELICZONE PORCJE (V2, #2489): z `$porcje` i `$odcisk` z podglądu
     * (`podgladPorcji()`) pozycje dostają ilości przeliczone na tę liczbę
     * porcji tym samym mechanizmem co strona przepisu. Serwer liczy je od nowa
     * z aktualnych składników; gdy wynik różni się od podglądu (odcisk),
     * nic się nie dopisuje (`WYNIK_ZMIENIONY`). Linie, których nie wolno
     * przeliczyć („do smaku”, bez ilości, suma, brak liczby), zostają
     * oryginalne i NIE niosą oznaczenia przeliczenia.
     *
     * @return array{wynik: string, dodano: int, wczesniej: ?CarbonInterface, przeliczono: int, bez_przeliczenia: int}
     */
    public function dodajSkladniki(User $user, Recipe $przepis, bool $potwierdzone = false, ?float $porcje = null, ?string $odcisk = null): array
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

        $linie = $wybor === null ? $this->linieDoZapisu($this->linieSkladnikow($przepis)) : $this->liniePrzeliczone($przepis, $wybor);

        if ($linie === []) {
            return ['wynik' => self::WYNIK_BRAK_SKLADNIKOW, 'dodano' => 0, 'wczesniej' => null, 'przeliczono' => 0, 'bez_przeliczenia' => 0];
        }

        return DB::transaction(function () use ($user, $przepis, $linie, $potwierdzone, $wybor, $odcisk): array {
            $this->zablokujListe($user);

            // Linie liczone PO blokadzie i od nowa: podgląd musi zgadzać się z zapisem.
            if ($wybor !== null) {
                $swieze = $this->liniePrzeliczone($przepis->fresh() ?? $przepis, $wybor);

                if ($odcisk === null || ! hash_equals(self::odcisk($swieze, (float) $wybor->wybrane), $odcisk)) {
                    return ['wynik' => self::WYNIK_ZMIENIONY, 'dodano' => 0, 'wczesniej' => null, 'przeliczono' => 0, 'bez_przeliczenia' => 0];
                }

                // Zapisujemy dokładnie to, co zatwierdził podgląd.
                $linie = $swieze;
            }

            $wczesniej = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
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
                $this->zapisz($user, $linia['tekst'], ShoppingListItem::SOURCE_RECIPE, $przepis, $pozycja++, $skala);
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
     * „Wyczyść odhaczone” — tylko pozycje tej osoby, tylko odhaczone. Przed
     * skasowaniem zapamiętuje je do krótkiego cofnięcia (zastępuje poprzednią
     * migawkę). Gdy nie ma odhaczonych, niczego nie zmienia — także migawki.
     */
    public function wyczyscOdhaczone(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $this->zablokujListe($user);

            $odhaczone = ShoppingListItem::query()
                ->where('user_id', $user->getKey())
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
     * @return array{wynik: string, przywrocono: int, zakres: ?string}
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
                return ['wynik' => self::COFNIECIE_BRAK, 'przywrocono' => 0, 'zakres' => null];
            }

            if ($migawka->wygasla()) {
                $migawka->delete();

                return ['wynik' => self::COFNIECIE_BRAK, 'przywrocono' => 0, 'zakres' => null];
            }

            $this->upewnijSieZeSaMiejscaNaPrzywrocenie($user, $migawka);

            /** @var list<array<string, mixed>> $pozycje */
            $pozycje = $migawka->items;
            $idPrzepisow = array_values(array_filter(array_column($pozycje, 'recipe_id')));
            $istniejace = $idPrzepisow === []
                ? []
                : DB::table('recipes')->whereIn('id', $idPrzepisow)->pluck('id')->all();

            usort($pozycje, fn (array $a, array $b): int => [(int) $a['position'], (string) $a['created_at']]
                <=> [(int) $b['position'], (string) $b['created_at']]);

            $przywrocono = 0;
            foreach ($pozycje as $p) {
                $przywrocono += DB::table('shopping_list_items')->insertOrIgnore([
                    'id' => $p['id'],
                    'user_id' => $user->getKey(),
                    'text' => $p['text'],
                    'source' => $p['source'],
                    'recipe_id' => in_array($p['recipe_id'], $istniejace, true) ? $p['recipe_id'] : null,
                    // Przeliczenie (#2489) wraca z pozycją; migawki sprzed niego go nie mają.
                    'scaled_servings' => $p['scaled_servings'] ?? null,
                    'position' => (int) $p['position'],
                    'checked_at' => $p['checked_at'],
                    'created_at' => $p['created_at'],
                    'updated_at' => now(),
                ]);
            }

            $zakres = $migawka->scope;
            $migawka->delete();

            return ['wynik' => self::COFNIECIE_PRZYWROCONO, 'przywrocono' => $przywrocono, 'zakres' => $zakres];
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

    private function zapisz(User $user, string $tekst, string $zrodlo, ?Recipe $przepis, int $pozycja, ?float $przeliczoneNa = null): ShoppingListItem
    {
        $wpis = new ShoppingListItem(['text' => $tekst]);
        $wpis->user_id = $user->getKey();
        $wpis->source = $zrodlo;
        // Poza `$fillable`: przeliczenie na porcje ustawia wyłącznie ta akcja (#2489).
        $wpis->scaled_servings = $przeliczoneNa;
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
