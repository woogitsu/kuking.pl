<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\ProductSignal;
use App\Models\User;
use Illuminate\Database\QueryException;
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

    public const PWA_PROMPT_SHOWN = 'pwa_prompt_shown';

    public const PWA_INSTALL_REQUESTED = 'pwa_install_requested';

    public const PWA_PROMPT_DISMISSED = 'pwa_prompt_dismissed';

    public const PWA_INSTALLED = 'pwa_installed';

    /**
     * Tygodniowe podsumowanie WPUSZCZONE DO KOLEJKI (issue #11, D-057;
     * przemianowany przy D-078, audyt 10.09.2026 ustalenie MAIL-03).
     *
     * NAZWA MÓWI DOKŁADNIE TYLE, ILE KUKING WIE. Wiersz powstaje zaraz po
     * `Mail::queue()` — worker jeszcze po list nie sięgnął, dostawca poczty
     * o jego istnieniu nie wie. Nazywało się to `weekly_digest_sent` i była
     * to obietnica bez pokrycia: jedna nazwa na trzy różne zdarzenia
     * (ZAKOLEJKOWANO, DOSTAWCA PRZYJĄŁ, DORĘCZONO), z których Kuking widzi
     * tylko pierwsze. List, który przewróci się w workerze i wyląduje
     * w `failed_jobs`, był wtedy nadal policzony jako wysłany — czyli metryka
     * zawyżała skuteczność wysyłki najbardziej właśnie wtedy, gdy wysyłka
     * przestawała działać.
     *
     * DLACZEGO NIE MA TU `..._delivered` ANI `..._opened` I NIE BĘDZIE
     * (otwarta sprawa #204). „Doręczono" wymaga webhooka o odbiciach od
     * dostawcy — to osobna, jeszcze niezrobiona robota
     * (`docs/decyzje/POCZTA.md` §5 pkt 6). „Otwarto" wymaga niewidzialnego
     * obrazka śledzącego w treści listu, czyli zapisywania, KIEDY konkretna
     * osoba czyta pocztę i z jakiego adresu IP. Polityka prywatności obiecuje
     * wprost tego nie robić (`resources/legal/polityka-prywatnosci.md`),
     * a własny transport ma nawet wyłącznik śledzenia po stronie dostawcy
     * (`X-TRACKING-OFF`, `App\Poczta\TransportEmailLabs`) — domyślnie
     * WŁĄCZONY. Ładniejsza metryka nie jest powodem, żeby cofać tamtą decyzję
     * tylnymi drzwiami; zatrzymujemy się na uczciwym „zakolejkowano".
     *
     * `properties` NIE NIESIE ADRESU ANI TREŚCI. Wystarczy `user_id`
     * (kolumna, nie właściwość) i liczba pozycji w każdej sekcji — po to,
     * żeby dało się zobaczyć, czy listy nie robią się puste.
     */
    public const WEEKLY_DIGEST_QUEUED = 'weekly_digest_queued';

    /**
     * Ktoś kliknął „nie chcę tych listów" (issue #11, D-057).
     *
     * Jedyny sygnał w tym zbiorze, który ma PRÓG DECYZYJNY:
     * `docs/product/RETENTION_LOOPS.md` §6 wiersz 5 — wypisy powyżej 1% na
     * wysyłkę znaczą, że list brzmi jak marketing albo przychodzi za często,
     * i wtedy się go skraca, a nie tłumaczy.
     */
    public const WEEKLY_DIGEST_UNSUBSCRIBED = 'weekly_digest_unsubscribed';

    /**
     * Zamknięty zbiór `properties.reason` dla `PHOTO_UPLOAD_FAILED`
     * (issue #115), rozszerzony o dwie drogi odrzucenia, które NIE
     * przechodzą przez `StoreUploadedImage::handle()` (audyt zewnętrzny,
     * punkt N05): walidację formularza i limit żądań.
     *
     * `REASON_UNREADABLE` i `REASON_TOO_LARGE` są tu JEDYNYM źródłem tych
     * dwóch wartości: `StoreUploadedImage` (droga domenowa) i
     * `ObslugiwaneZdjecie` (walidacja formularza) wskazują na te same stałe.
     * Wcześniej `StoreUploadedImage` miał własne, prywatne kopie o tych samych
     * wartościach — dwie kopie tej samej liczby w różnych miejscach rozjeżdżają
     * się osobno w każdym (ta sama pułapka, którą opisuje `LimityZdjec`), a tu
     * rozjazd byłby cichy: dashboard liczy `properties->>'reason'` i zamiast
     * błędu pokazałby dwa osobne słupki dla jednego powodu.
     *
     * Cztery powody treści zdjęcia (`not_an_image`, `unsupported_format`,
     * `too_many_megapixels`, `heic_unsupported` — ostatni doszedł przy #119,
     * D-064) NIE są tu duplikowane — `RozpoznanieZdjecia` już je eksportuje
     * publicznie i to jest ich jedyne źródło, używane zarówno przez
     * `StoreUploadedImage`, jak i przez `ObslugiwaneZdjecie`.
     *
     * `REASON_RATE_LIMITED` jest zupełnie nowy: żądanie ze zdjęciem odrzucone
     * limitem żądań (429, `bootstrap/app.php`) nie ma pliku do zbadania —
     * throttle działa PRZED kontrolerem, więc to jedyny powód z tego zbioru
     * bez żadnych dodatkowych właściwości w `properties`.
     */
    public const REASON_UNREADABLE = 'unreadable';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_RATE_LIMITED = 'rate_limited';

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
            //
            // KLASA I SQLSTATE, NIGDY `getMessage()`. To nie jest ostrożność
            // na zapas — zmierzone. Gdy CHECK w bazie odrzuca wiersz z frazą
            // wyszukiwania (czyli DOKŁADNIE wtedy, gdy ta ochrona działa),
            // komunikat wyjątku zawiera tę frazę DWA RAZY: raz w postgresowym
            // „DETAIL: Failing row contains (…)", raz w doklejonym przez
            // Laravela „SQL: insert into … values (…)" z wstawionymi
            // wartościami. Zalogowanie go znaczyłoby, że CHECK trzyma dane
            // osobowe poza TABELĄ, a my wkładamy je do LOGU — czyli ta sama
            // usterka co W7-07, tylko o warstwę dalej, i wprost wbrew
            // AGENTS.md §7.
            //
            // Klasa wyjątku plus SQLSTATE wystarczą, żeby odpowiedzieć na
            // jedyne pytanie, które ten log ma obsłużyć: „czy sygnały zaczęły
            // padać i mniej więcej dlaczego". Konkretny wiersz do niczego
            // się tu nie przydaje, bo i tak go nie ma.
            Log::warning('Nie udało się zapisać sygnału produktowego.', [
                'signal_name' => $signalName,
                'wyjatek' => $e::class,
                'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
            ]);
        }
    }
}
