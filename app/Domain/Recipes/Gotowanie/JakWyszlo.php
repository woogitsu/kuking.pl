<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;

/**
 * „Jak wyszło?” — domknięcie trybu gotowania (F1, research 30.09.2026, D-333).
 *
 * PROBLEM
 * Na ostatnim kroku trybu gotowania stoi „Ugotowałem”, ale w kuchni ręce są
 * mokre, obiad trzeba podać, a telefon gaśnie. Kto nie kliknął wtedy, nie
 * miał drugiej okazji — autor przepisu nie dostawał najcenniejszego
 * powiadomienia w serwisie.
 *
 * JAK DZIAŁA
 * Gdy zalogowana osoba, która może zapisać wykonanie (`RecipePolicy::cook`),
 * otworzy ostatni krok, zapamiętujemy w JEJ SESJI jedno „gotowanie”: przepis,
 * konto i chwilę dojścia. Jeśli w ciągu {@see self::DNI} dni nie zapisze
 * wykonania tego przepisu, Start pokazuje jedno spokojne zdanie z dwoma
 * przyciskami: „Pokaż zdjęcie” (prowadzi do formularza „Ugotowałem”) i „Nie
 * teraz”. Po jednym albo drugim, po zapisaniu wykonania albo po upływie
 * tych dni zdanie znika i dla tego samego gotowania już nie wraca.
 *
 * BEZ PRESJI (AGENTS.md, RETENTION_LOOPS.md §3.3): bez maila, bez pusha, bez
 * licznika dni, bez „Nie zapomnij!”. Jedno zdanie naraz, najnowsze gotowanie.
 *
 * DLACZEGO SESJA, NIE TABELA
 * Postęp kroków żyje już w sesji (`CookingModeController`, domyślna droga bez
 * synchronizacji; `SESSION_DRIVER=database`, tydzień życia). Znacznik
 * „doszło do końca” jest tej samej natury: tymczasowy, prywatny, tylko dla
 * tej osoby. Sesja jest per konto — wylogowanie ją unieważnia, a każdy wpis
 * i tak niesie identyfikator konta, więc na wspólnym tablecie cudze gotowanie
 * nie wyskoczy innej osobie. Cena: znacznik nie przechodzi między urządzeniami
 * (wariant z tabelą to koszt M z karty — świadomie nie teraz).
 *
 * POMIAR (`kuking:raport`)
 * Liczniki idą do `product_signals` BEZ `user_id` i bez przepisu — raport
 * potrzebuje samych liczb (patrz `DojsciaDoKoncaGotowania`).
 */
final class JakWyszlo
{
    /** Tyle dni po dojściu do ostatniego kroku zdanie może się pokazać. */
    public const DNI = 3;

    private const KLUCZ = 'gotowanie.jak_wyszlo';

    /** Ile gotowań trzymamy naraz w sesji — starsze wypadają. */
    private const NAJWYZEJ = 10;

    private const OTWARTE = 'otwarte';

    private const KLIK = 'klik';

    private const ZAMKNIETE = 'zamkniete';

    private const UGOTOWANE = 'ugotowane';

    public function __construct(private readonly ZapiszSygnal $sygnal) {}

    /**
     * Osoba otworzyła ostatni krok. Nowe gotowanie zaczyna się, gdy dla tego
     * przepisu nie ma świeżego wpisu albo poprzednie skończyło się
     * „Ugotowałem” — inaczej to wciąż to samo gotowanie (odświeżenie,
     * powrót do kroku) i niczego nie zmieniamy, także zamkniętego „Nie teraz”.
     */
    public function zanotujKoniec(Session $sesja, User $osoba, Recipe $przepis): void
    {
        if (! Gate::forUser($osoba)->allows('cook', $przepis)) {
            return;
        }

        $wpisy = $this->wpisy($sesja, $osoba);
        $obecny = $wpisy[$przepis->getKey()] ?? null;

        if ($obecny !== null && $obecny['stan'] !== self::UGOTOWANE) {
            return;
        }

        $wpisy[$przepis->getKey()] = [
            'konto' => (string) $osoba->getKey(),
            'od' => now()->getTimestamp(),
            'stan' => self::OTWARTE,
            'pokazane' => false,
        ];

        $this->zapisz($sesja, $wpisy);
        $this->sygnal->handleAnonimowo($osoba, ZapiszSygnal::COOKING_LAST_STEP_REACHED);
    }

    /**
     * Przepis, o który Start ma dziś zapytać, albo `null`. Najnowsze otwarte
     * gotowanie, które ta osoba nadal może zapisać i jeszcze nie zapisała.
     */
    public function doPokazania(Session $sesja, User $osoba): ?Recipe
    {
        $wpisy = $this->wpisy($sesja, $osoba);
        $otwarte = array_filter($wpisy, fn (array $w): bool => $w['stan'] === self::OTWARTE);

        if ($otwarte === []) {
            return null;
        }

        uasort($otwarte, fn (array $a, array $b): int => $b['od'] <=> $a['od']);
        $przepisy = Recipe::query()->whereKey(array_keys($otwarte))->get()->keyBy(fn (Recipe $r): string => (string) $r->getKey());
        $zmiana = false;
        $wynik = null;

        foreach ($otwarte as $id => $wpis) {
            $przepis = $przepisy->get((string) $id);

            if ($przepis === null || ! Gate::forUser($osoba)->allows('cook', $przepis)) {
                continue;
            }

            // Wykonanie zapisane gdziekolwiek — także na innym urządzeniu —
            // zamyka pytanie i liczy się jako domknięte gotowanie.
            if ($this->ugotowanoOd($osoba, $przepis, $wpis['od'])) {
                $wpisy[$id]['stan'] = self::UGOTOWANE;
                $zmiana = true;
                $this->sygnal->handleAnonimowo($osoba, ZapiszSygnal::COOKING_LAST_STEP_COOKED, ['po_pytaniu' => false]);

                continue;
            }

            if (! $wpis['pokazane']) {
                $wpisy[$id]['pokazane'] = true;
                $zmiana = true;
                $this->sygnal->handleAnonimowo($osoba, ZapiszSygnal::COOKING_FOLLOWUP_SHOWN);
            }

            $wynik = $przepis;

            break;
        }

        if ($zmiana) {
            $this->zapisz($sesja, $wpisy);
        }

        return $wynik;
    }

    /** „Pokaż zdjęcie” — pytanie znika, formularz „Ugotowałem” przed osobą. */
    public function pokaz(Session $sesja, User $osoba, Recipe $przepis): void
    {
        $this->zmienOtwarte($sesja, $osoba, $przepis, self::KLIK);
    }

    /** „Nie teraz” — pytanie znika i dla tego gotowania nie wraca. */
    public function zamknij(Session $sesja, User $osoba, Recipe $przepis): void
    {
        if ($this->zmienOtwarte($sesja, $osoba, $przepis, self::ZAMKNIETE)) {
            $this->sygnal->handleAnonimowo($osoba, ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED);
        }
    }

    /** Zapisano „Ugotowałem” z formularza — domyka gotowanie z tej sesji. */
    public function poUgotowaniu(Session $sesja, User $osoba, Recipe $przepis): void
    {
        $wpisy = $this->wpisy($sesja, $osoba);
        $wpis = $wpisy[$przepis->getKey()] ?? null;

        if ($wpis === null || ! in_array($wpis['stan'], [self::OTWARTE, self::KLIK], true)) {
            return;
        }

        $wpisy[$przepis->getKey()]['stan'] = self::UGOTOWANE;
        $this->zapisz($sesja, $wpisy);
        $this->sygnal->handleAnonimowo($osoba, ZapiszSygnal::COOKING_LAST_STEP_COOKED, ['po_pytaniu' => $wpis['stan'] === self::KLIK]);
    }

    private function zmienOtwarte(Session $sesja, User $osoba, Recipe $przepis, string $stan): bool
    {
        $wpisy = $this->wpisy($sesja, $osoba);

        if (($wpisy[$przepis->getKey()]['stan'] ?? null) !== self::OTWARTE) {
            return false;
        }

        $wpisy[$przepis->getKey()]['stan'] = $stan;
        $this->zapisz($sesja, $wpisy);

        return true;
    }

    private function ugotowanoOd(User $osoba, Recipe $przepis, int $od): bool
    {
        return CookedEvent::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->where('created_at', '>=', now()->setTimestamp($od))
            ->exists();
    }

    /**
     * Świeże wpisy TEJ osoby. Wpis innego konta, starszy niż {@see self::DNI}
     * dni albo o nieznanym kształcie po prostu się nie liczy.
     *
     * @return array<string, array{konto: string, od: int, stan: string, pokazane: bool}>
     */
    private function wpisy(Session $sesja, User $osoba): array
    {
        $surowe = $sesja->get(self::KLUCZ);
        $granica = now()->subDays(self::DNI)->getTimestamp();
        $wynik = [];

        if (! is_array($surowe)) {
            return [];
        }

        foreach ($surowe as $id => $wpis) {
            if (! is_array($wpis)
                || ($wpis['konto'] ?? null) !== (string) $osoba->getKey()
                || ! is_int($wpis['od'] ?? null)
                || $wpis['od'] < $granica
                || ! in_array($wpis['stan'] ?? null, [self::OTWARTE, self::KLIK, self::ZAMKNIETE, self::UGOTOWANE], true)) {
                continue;
            }

            $wynik[(string) $id] = [
                'konto' => $wpis['konto'],
                'od' => $wpis['od'],
                'stan' => $wpis['stan'],
                'pokazane' => (bool) ($wpis['pokazane'] ?? false),
            ];
        }

        return $wynik;
    }

    /** @param  array<string, array{konto: string, od: int, stan: string, pokazane: bool}>  $wpisy */
    private function zapisz(Session $sesja, array $wpisy): void
    {
        uasort($wpisy, fn (array $a, array $b): int => $b['od'] <=> $a['od']);
        $sesja->put(self::KLUCZ, array_slice($wpisy, 0, self::NAJWYZEJ, true));
    }
}
