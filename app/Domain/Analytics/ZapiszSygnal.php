<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\ProductSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jedyne miejsce, które pisze do `product_signals` (issue #115).
 *
 * PO CO JEDNA KLASA, A NIE `DB::table()` W DWÓCH MIEJSCACH
 * `photo_upload_failed` powstaje w `StoreUploadedImage`, `search_performed`
 * w `SearchController` — dwa zupełnie niezwiązane miejsca w kodzie. Dwa
 * niezależne wywołania zapisu to dwie okazje, żeby jedno z nich zaczęło
 * pisać coś, czego nie powinno (nazwę pliku od użytkownika, frazę
 * wyszukiwania), i żeby nikt tego nie zauważył — nie byłoby jednego miejsca
 * do przeczytania i przetestowania, tylko dwa rozjeżdżające się z czasem.
 * Tu jest jedno: reguła prywatności („co WOLNO zapisać") mieszka w jednym
 * pliku, tak samo jak zestaw dozwolonych zdjęć mieszka w jednym miejscu
 * (`RozpoznanieZdjecia`) zamiast w dwóch kopiach.
 *
 * DLACZEGO ZAPIS SYGNAŁU NIGDY NIE RZUCA WYJĄTKU DALEJ
 * Sygnał analityczny jest EFEKTEM UBOCZNYM prawdziwej operacji, nie jej
 * warunkiem. Wyszukiwarka ma pokazać wyniki, a komunikat o nieudanym wgraniu
 * zdjęcia ma dojść do człowieka, NAWET jeśli akurat nie da się zapisać wiersza
 * w `product_signals` (np. padło połączenie z bazą, tabela zablokowana
 * migracją w locie). Dlatego `handle()` łapie KAŻDY wyjątek i tylko go
 * loguje — nigdy nie pozwala mu wypłynąć do wywołującego kodu. Retry też
 * nie ma sensu tutaj: to jest zdarzenie odczytowe typu „ile razy się zdarzyło",
 * a nie coś, co ktoś czeka, aż się zapisze — pojedyncza utrata jednego wiersza
 * nie jest warta ryzykowania głównej operacji.
 *
 * `DB::transaction()` WEWNĄTRZ TRY/CATCH, NIE SAM `ProductSignal::create()`
 * Samo try/catch WOKÓŁ zapisu NIE WYSTARCZA na PostgreSQL. Gdy `handle()`
 * jest wołane w trakcie SZERSZEJ transakcji (np. `StoreUploadedImage` albo
 * `SearchController` uruchomione wewnątrz `DB::transaction()` gdzie indziej
 * w żądaniu — a w testach: cała `RefreshDatabase` opakowuje test w jedną
 * transakcję), nieudany INSERT zatruwa CAŁĄ otaczającą transakcję: Postgres
 * odrzuca każde kolejne zapytanie tym samym połączeniem komunikatem
 * „current transaction is aborted" — także zapytania NIEZWIĄZANE z sygnałem,
 * czyli dokładnie tę operację, którą ten kod ma chronić. Złapanie wyjątku
 * w PHP nic tu nie daje, bo stan transakcji psuje się po stronie bazy, nie PHP.
 * `DB::transaction()` naprawia to: gdy jest już w trakcie transakcji, Laravel
 * otwiera SAVEPOINT zamiast nowej transakcji, a nieudany zapis cofa TYLKO ten
 * SAVEPOINT (`ROLLBACK TO SAVEPOINT`) — reszta połączenia zostaje zdrowa.
 * Znalezione i sprawdzone przez `SygnalyProduktoweTest::
 * test_awaria_zapisu_sygnalu_nie_wywraca_wgrywania_zdjecia` (bez tego zapis
 * Media PO nieudanym zapisie sygnału w tej samej transakcji faktycznie padał).
 */
final class ZapiszSygnal
{
    public const PHOTO_UPLOAD_FAILED = 'photo_upload_failed';

    public const SEARCH_PERFORMED = 'search_performed';

    /**
     * @param  array<string, mixed>  $properties  NIGDY frazy wyszukiwania, nazwy
     *                                            pliku ani innego tekstu wpisanego
     *                                            przez człowieka — patrz AGENTS.md §7.
     */
    public function handle(?User $user, string $signalName, array $properties = []): void
    {
        try {
            DB::transaction(function () use ($user, $signalName, $properties): void {
                ProductSignal::create([
                    'user_id' => $user?->getKey(),
                    'signal_name' => $signalName,
                    'properties' => $properties,
                ]);
            });
        } catch (Throwable $e) {
            // Patrz komentarz klasy: zapis sygnału nie może wywrócić operacji,
            // którą opisuje — tylko log, żeby wiedzieć, że coś umyka, gdyby to
            // zaczęło się zdarzać częściej niż pojedynczo.
            Log::warning('Nie udało się zapisać sygnału produktowego.', [
                'signal_name' => $signalName,
                'blad' => $e->getMessage(),
            ]);
        }
    }
}
