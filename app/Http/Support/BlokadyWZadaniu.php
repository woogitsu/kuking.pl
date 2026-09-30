<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * „Czy między tymi dwiema osobami jest blokada" — policzone RAZ NA ŻĄDANIE
 * (audyt wydajności, P3 W8).
 *
 * `RecipePolicy::view()` woła `User::hasBlockRelationWith()` za każdym razem,
 * gdy ktoś pyta o ten przepis: `authorize()` w kontrolerze, `@can` w widoku,
 * zapowiedzi… Na stronie `/przepisy/{slug}` to cztery identyczne `SELECT EXISTS`
 * o tę samą parę osób.
 *
 * DLACZEGO TO NIE JEST RYZYKO NIEŚWIEŻOŚCI
 *  - Pamięć żyje w atrybutach bieżącego `Request` (jak `PamiecZadaniaHttp`,
 *    #970), więc następne żądanie zaczyna od pustej. Ten sam obiekt `User`
 *    NIE niesie wyniku między żądaniami (dlatego nie zapamiętujemy na modelu).
 *  - Działa tylko w dopasowanym żądaniu HTTP (`rozpocznij()` z nasłuchu
 *    `RouteMatched`). Zadanie z kolejki albo polecenie konsolowe dzielą jeden
 *    obiekt `Request` przez wiele zadań, więc tam zawsze pytamy bazę.
 *  - NIGDY pod transakcją otwartą w trakcie żądania. Akcje, które pod zamkiem
 *    wiersza sprawdzają uprawnienie jeszcze raz świeżymi danymi (#1022),
 *    muszą zobaczyć blokadę założoną przez inny proces po pierwszym sprawdzeniu
 *    w kontrolerze — dlatego pamięć obowiązuje tylko na poziomie transakcji,
 *    na którym żądanie weszło.
 *  - Każda zmiana blokady w tym samym żądaniu czyści pamięć: `BlockUser`
 *    i `UnblockUser` ogłaszają `BlokadyZmienione`, a nasłuch w
 *    `AppServiceProvider` woła `zapomnij()`. (`EraseAccountData` chodzi
 *    w kolejce, czyli bez trasy — tam pamięć jest wyłączona.)
 *  - Klucz jest symetryczny (blokada działa w obie strony), a wynik dotyczy
 *    wyłącznie tej pary.
 *
 * Z tej pamięci korzystają `RecipePolicy::view()` i `UserPolicy::follow()` (ta sama para na stronie przepisu). Pozostałe wywołania
 * `hasBlockRelationWith()` (akcje zapisujące, powiadomienia) pytają bazę
 * wprost — pod zamkiem pary, z pełną świeżością.
 */
final class BlokadyWZadaniu
{
    private const KLUCZ = 'kuking.blokady_pary';

    private const POZIOM = 'kuking.blokady_poziom_transakcji';

    public static function miedzy(User $jedna, ?User $druga): bool
    {
        if ($druga === null || $druga->getKey() === $jedna->getKey()) {
            return false;
        }

        $zadanie = request();
        $poziomZadania = $zadanie->attributes->get(self::POZIOM);

        // Bez zapamiętanego poziomu (konsola, kolejka) albo GŁĘBIEJ niż na
        // wejściu żądania — czyli pod transakcją i blokadą wiersza, gdzie akcja
        // sprawdza uprawnienie PONOWNIE świeżymi danymi (#1022,
        // `ZamekZapisuDoZeszytu`) — zawsze pytamy bazę.
        if ($poziomZadania === null || DB::transactionLevel() > $poziomZadania) {
            return $jedna->hasBlockRelationWith($druga);
        }

        $id = [(string) $jedna->getKey(), (string) $druga->getKey()];
        sort($id);
        $klucz = $id[0].'|'.$id[1];

        /** @var array<string, bool> $pamiec */
        $pamiec = $zadanie->attributes->get(self::KLUCZ, []);

        if (! array_key_exists($klucz, $pamiec)) {
            $pamiec[$klucz] = $jedna->hasBlockRelationWith($druga);
            $zadanie->attributes->set(self::KLUCZ, $pamiec);
        }

        return $pamiec[$klucz];
    }

    /**
     * Woła nasłuch `RouteMatched`: zapamiętuje poziom transakcji na WEJŚCIU
     * żądania (0 na produkcji, 1 w teście pod `RefreshDatabase`). Tylko dopasowane
     * żądanie HTTP ma pamięć.
     */
    public static function rozpocznij(Request $zadanie): void
    {
        $zadanie->attributes->set(self::POZIOM, DB::transactionLevel());
        $zadanie->attributes->remove(self::KLUCZ);
    }

    /** Czyści pamięć bieżącego żądania — po każdej zmianie blokady. */
    public static function zapomnij(): void
    {
        request()->attributes->remove(self::KLUCZ);
    }
}
