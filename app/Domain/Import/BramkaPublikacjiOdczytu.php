<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Recipes\BramkaPublikacjiSzkicu;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use Illuminate\Validation\ValidationException;

/**
 * Bramka publikacji szkicu z odczytu zdjęcia kartki (decyzja właściciela
 * 26.09.2026, D-298).
 *
 * Tekst odczytany przez komputer nie wychodzi do ludzi, dopóki człowiek:
 *  1. nie zaznaczy „Odczytany tekst jest sprawdzony” (`odczyt_sprawdzony`),
 *  2. nie usunie wszystkich znaczników niepewnych słów `[?…?]`.
 *
 * WOŁANA Z `PublishRecipe`, NIE Z KONTROLERA — wtedy obejmuje kreator
 * (Livewire), formularz bez JavaScriptu i każdą przyszłą drogę zapisu
 * (AGENTS.md §4). Kreator sprawdza to samo wcześniej, żeby błąd stanął przy
 * właściwym wierszu; ta bramka jest ostatnią linią.
 *
 * DOTYCZY WYŁĄCZNIE PRZEPISU, KTÓRY MA ODCZYT (`importy_przepisow` ze
 * źródłem `zdjecie` i stanem `gotowy`). Zwykły przepis z nawiasem
 * kwadratowym w tekście publikuje się jak dotąd.
 */
final class BramkaPublikacjiOdczytu implements BramkaPublikacjiSzkicu
{
    public const ZNACZNIK = '[?';

    public const KOMUNIKAT_SPRAWDZENIE = 'Zaznacz „Odczytany tekst jest sprawdzony ze zdjęciem”, zanim opublikujesz. '
        .'Porównaj każdą linijkę ze zdjęciem kartki — tekst odczytał komputer i mógł się pomylić.';

    /**
     * Implementacja kontraktu `App\Domain\Recipes\BramkaPublikacjiSzkicu`
     * (wołana z `PublishRecipe` przez `AppServiceProvider`, issue #971) —
     * cienka nakładka na `sprawdz()`, żeby ta metoda statyczna zostawała
     * jedynym miejscem z regułą, jak dotąd.
     */
    public function sprawdz(Recipe $przepis, array $atrybuty, string $tytul, array $skladniki, array $kroki): void
    {
        self::sprawdzStatycznie($przepis, $atrybuty, $tytul, $skladniki, $kroki);
    }

    public static function maOdczyt(Recipe $przepis): bool
    {
        return ImportPrzepisu::query()
            ->where('recipe_id', $przepis->getKey())
            ->where('zrodlo', ImportPrzepisu::ZRODLO_ZDJECIE)
            ->where('status', ImportPrzepisu::STATUS_GOTOWY)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     * @param  list<array{ingredient_text: string}>  $skladniki  wiersze po `PublishRecipe::cleanIngredients()` (bez pustych)
     * @param  list<array{instruction: string}>  $kroki  wiersze już oczyszczone (bez pustych)
     *
     * @throws ValidationException
     */
    public static function sprawdzStatycznie(Recipe $przepis, array $atrybuty, string $tytul, array $skladniki, array $kroki): void
    {
        if (! self::maOdczyt($przepis)) {
            return;
        }

        $bledy = self::znaczniki($tytul, (string) ($atrybuty['summary'] ?? ''), array_column($skladniki, 'ingredient_text'), array_column($kroki, 'instruction'));

        if (! filter_var($atrybuty['odczyt_sprawdzony'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $bledy['odczyt_sprawdzony'] = self::KOMUNIKAT_SPRAWDZENIE;
        }

        if ($bledy !== []) {
            throw ValidationException::withMessages($bledy);
        }
    }

    /**
     * Gdzie jeszcze stoi znacznik `[?` — klucz pola i zdanie mówiące, co zrobić.
     * Numery wierszy liczone od 1, po pominięciu pustych, czyli tak, jak
     * człowiek widzi przepis w podglądzie.
     *
     * @param  list<string>  $skladniki
     * @param  list<string>  $kroki
     * @return array<string, string>
     */
    public static function znaczniki(string $tytul, string $opis, array $skladniki, array $kroki): array
    {
        $bledy = [];

        if (str_contains($tytul, self::ZNACZNIK)) {
            $bledy['title'] = 'Sprawdź słowo oznaczone [?] w nazwie przepisu i usuń znaczniki [? ?].';
        }

        if (str_contains($opis, self::ZNACZNIK)) {
            $bledy['summary'] = 'Sprawdź słowo oznaczone [?] w opisie przepisu i usuń znaczniki [? ?].';
        }

        foreach ($skladniki as $i => $tekst) {
            if (str_contains($tekst, self::ZNACZNIK)) {
                $bledy['ingredients'] = 'Sprawdź słowo oznaczone [?] w '.($i + 1).'. składniku i usuń znaczniki [? ?].';
                break;
            }
        }

        foreach ($kroki as $i => $tekst) {
            if (str_contains($tekst, self::ZNACZNIK)) {
                $bledy['steps'] = 'Sprawdź słowo oznaczone [?] w '.($i + 1).'. kroku przygotowania i usuń znaczniki [? ?].';
                break;
            }
        }

        return $bledy;
    }

    /** Ile niepewnych fragmentów jest jeszcze w tekstach. */
    public static function ileNiepewnych(string ...$teksty): int
    {
        $ile = 0;

        foreach ($teksty as $tekst) {
            $ile += substr_count($tekst, self::ZNACZNIK);
        }

        return $ile;
    }
}
