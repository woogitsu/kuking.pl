<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Social\ListyWidza;
use App\Models\User;
use App\Support\PamiecZadania;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * „Czy między tymi dwiema osobami jest blokada" — odpowiedź z JEDNEJ pamięci
 * blokad w żądaniu (audyt wydajności, P3 W8 + W7).
 *
 * `RecipePolicy::view()` woła `User::hasBlockRelationWith()` za każdym razem,
 * gdy ktoś pyta o ten przepis: `authorize()` w kontrolerze, `@can` w widoku,
 * zapowiedzi… Na stronie `/przepisy/{slug}` to cztery identyczne `SELECT EXISTS`
 * o tę samą parę osób.
 *
 * JEDNA PAMIĘĆ, NIE DWIE
 * Wcześniej W7 (`ListyWidza::blokady()`, lista osób w blokadzie z widzem) i W8
 * (pamięć par tutaj) trzymały blokady osobno, każda z własnym zasadami
 * unieważniania. Teraz pamięcią jest wyłącznie `ListyWidza`; ta klasa zostaje
 * cienką bramką: rozstrzyga, CZY wolno użyć pamięci (poniżej), a odpowiedź
 * bierze z listy widza (`in_array` po identyfikatorze drugiej osoby).
 *
 * DLACZEGO TO NIE JEST RYZYKO NIEŚWIEŻOŚCI
 *  - Pamięć żyje w atrybutach bieżącego `Request` (`PamiecZadaniaHttp`, #970),
 *    więc następne żądanie zaczyna od pustej. Ten sam obiekt `User` NIE niesie
 *    wyniku między żądaniami.
 *  - Działa tylko w dopasowanym żądaniu HTTP (`rozpocznij()` z nasłuchu
 *    `RouteMatched`). Zadanie z kolejki albo polecenie konsolowe dzielą jeden
 *    obiekt `Request` przez wiele zadań, więc tam zawsze pytamy bazę.
 *  - NIGDY pod transakcją otwartą w trakcie żądania (bezpiecznik W8, pilnuje go
 *    także `ListyWidza::blokady()` dla pozostałych czytelników listy). Akcje,
 *    które pod zamkiem wiersza sprawdzają uprawnienie jeszcze raz świeżymi
 *    danymi (#1022), muszą zobaczyć blokadę założoną przez inny proces po
 *    pierwszym sprawdzeniu w kontrolerze.
 *  - Każda zmiana blokady w tym samym żądaniu czyści pamięć: `BlockUser`
 *    i `UnblockUser` wołą `ListyWidza::uniewaznij()` (W7) i ogłaszają
 *    `BlokadyZmienione`, a nasłuch w `AppServiceProvider` też woła
 *    `uniewaznij()` — zmiana blokady z dowolnej innej drogi, która ogłosi
 *    zdarzenie, też czyści. (`EraseAccountData` chodzi w kolejce, czyli bez
 *    trasy — tam pamięć jest wyłączona.)
 *  - Wynik dotyczy wyłącznie widza, o którego pytamy (pamięć jest osobna dla
 *    każdego widza), a blokada działa w obie strony.
 *
 * Z tej bramki korzystają `RecipePolicy::view()` i `UserPolicy::follow()`
 * (ta sama para na stronie przepisu). Pozostałe wywołania
 * `hasBlockRelationWith()` (akcje zapisujące, powiadomienia) pytają bazę
 * wprost — pod zamkiem pary, z pełną świeżością.
 */
final class BlokadyWZadaniu
{
    public static function miedzy(User $jedna, ?User $druga): bool
    {
        if ($druga === null || $druga->getKey() === $jedna->getKey()) {
            return false;
        }

        $poziomZadania = app(PamiecZadania::class)->pobierz(ListyWidza::KLUCZ_POZIOMU_TRANSAKCJI);

        // Bez zapamiętanego poziomu (konsola, kolejka) albo GŁĘBIEJ niż na
        // wejściu żądania — czyli pod transakcją i blokadą wiersza, gdzie akcja
        // sprawdza uprawnienie PONOWNIE świeżymi danymi (#1022,
        // `ZamekZapisuDoZeszytu`) — zawsze pytamy bazę.
        if ($poziomZadania === null || DB::transactionLevel() > $poziomZadania) {
            return $jedna->hasBlockRelationWith($druga);
        }

        return in_array((string) $druga->getKey(), app(ListyWidza::class)->blokady($jedna), true);
    }

    /**
     * Woła nasłuch `RouteMatched`: zapamiętuje poziom transakcji na WEJŚCIU
     * żądania (0 na produkcji, 1 w teście pod `RefreshDatabase`) i zaczyna od
     * pustej pamięci list. Tylko dopasowane żądanie HTTP ma pamięć blokad.
     */
    public static function rozpocznij(Request $zadanie): void
    {
        $zadanie->attributes->set(ListyWidza::KLUCZ_POZIOMU_TRANSAKCJI, self::poziomTransakcjiNaWejsciu());
        ListyWidza::uniewaznij();
    }

    /**
     * Poziom transakcji na wejściu — BEZ otwierania połączenia z bazą.
     *
     * `DB::transactionLevel()` woła `connection()`, czyli łączy się z bazą.
     * `RouteMatched` leci dla KAŻDEGO dopasowanego żądania, także `/up` i
     * `/health`, a te mają działać, gdy bazy nie ma (`/up` odpowiada 200,
     * `/health` mówi 503 zamiast 500 — `HealthKontraktOdpowiedziTest`). Połączenie,
     * którego jeszcze nie otwarto, nie ma otwartej transakcji: poziom 0. Otwarte
     * (test pod `RefreshDatabase`, wcześniejszy middleware) pytamy wprost.
     */
    private static function poziomTransakcjiNaWejsciu(): int
    {
        $otwarte = DB::getConnections();
        $polaczenie = $otwarte[DB::getDefaultConnection()] ?? null;

        return $polaczenie === null ? 0 : $polaczenie->transactionLevel();
    }
}
