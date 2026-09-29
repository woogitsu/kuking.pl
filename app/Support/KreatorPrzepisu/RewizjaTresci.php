<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Dwa różne liczniki „rewizji” w kreatorze, które łatwo pomylić
 * (issue #1387, krok 9).
 *
 *  1. REWIZJA INTERFEJSU — `editRevision` rośnie po stronie przeglądarki
 *     z każdym wpisanym znakiem, `acknowledgedRevision` to ostatnia wartość,
 *     którą serwer zdążył obsłużyć. Plakietka „zapisane” świeci tylko wtedy,
 *     gdy `editRevision <= acknowledgedRevision`; znak dopisany po wysłaniu
 *     żądania wraca do „zapisuję…” (#892). Ten licznik nie zna bazy.
 *
 *  2. REWIZJA TREŚCI — `contentRevision` to `recipes.content_revision`
 *     z bazy, taka, jaką karta widziała przy otwarciu albo po własnym
 *     ostatnim zapisie. Wysyłana do `PublishRecipe` jako `oczekiwanaRewizja`
 *     chroni przed nadpisaniem nowszej wersji zapisanej w innej karcie:
 *     jeśli baza ma już inną rewizję, `PublishRecipe` odmawia, a kreator
 *     pokazuje polski komunikat zamiast po cichu zgubić cudzą edycję.
 *
 * Klasa nie zna Livewire'a ani bazy. Komponent trzyma liczniki w publicznych
 * polach (klient je czyta; dwa z nich są `#[Locked]`) i pyta tu, co z nimi
 * zrobić.
 */
final class RewizjaTresci
{
    /**
     * Wartość `acknowledgedRevision` po obsłużeniu żądania: dokładnie ta
     * rewizja interfejsu, którą to żądanie przyniosło — nie nowsza, bo
     * nowszej serwer jeszcze nie widział.
     */
    public static function potwierdzona(int $rewizjaInterfejsu): int
    {
        return $rewizjaInterfejsu;
    }

    /**
     * Czy plakietka może pokazać stan zapisu, a nie „zapisuję…”. Tak samo
     * liczy to przeglądarka w `x-show`; ta metoda jest jej odpowiednikiem
     * dla testów.
     */
    public static function plakietkaAktualna(int $rewizjaInterfejsu, int $potwierdzona): bool
    {
        return $rewizjaInterfejsu <= $potwierdzona;
    }

    /**
     * Rewizja treści, której oczekuje `PublishRecipe`. Nowy przepis (jeszcze
     * bez `recipeId`) niczego nie oczekuje: nie ma czego nadpisać. Nie wolno
     * tu zwrócić `0` zamiast `null` — `PublishRecipe` porównałby `0`
     * z bazą i odmówił pierwszego zapisu.
     */
    public static function oczekiwana(?string $recipeId, int $rewizjaTresci): ?int
    {
        return $recipeId === null ? null : $rewizjaTresci;
    }
}
