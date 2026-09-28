<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\SendQueuedNotifications;
use RuntimeException;

/**
 * `data.command` z ładunku zadania jako zserializowany tekst — także wtedy,
 * gdy zadanie jest szyfrowane (audyt A5-10).
 *
 * Powiadomienia niosące żywy żeton (`LinkDoLogowania`, `UstawienieNowegoHasla`,
 * `UstawienieHaslaZamiastLinku`, `ZaproszenieDoZalozeniaKonta`) mają
 * `ShouldBeEncrypted`, więc w `jobs` i `failed_jobs` leży szyfrogram, a nie
 * `O:…`. Trzy miejsca czytają z tego ODBIORCÓW (`kuking:martwe-zadania`,
 * `kuking:kto-nie-dostal-listu`, `ZapiszNieudanyList`) — bez odszyfrowania
 * przestałyby wiedzieć, komu list nie doszedł.
 *
 * Ta sama reguła co w `Illuminate\Queue\CallQueuedHandler::getCommand()`:
 * tekst zaczynający się od `O:` jest jawny, każdy inny odszyfrowuje klucz
 * aplikacji. Wynik zawiera żeton — wywołujący dalej nie drukuje ładunku
 * i odtwarza z niego tylko dozwolone klasy.
 *
 * JEDYNE `unserialize()` NA ŁADUNKU KOLEJKI (issue #1841). Do 26.09.2026
 * każde z trzech miejsc wołało je samo, a `ZapiszNieudanyList` — bez
 * `allowed_classes`: odtwarzało KAŻDĄ klasę opisaną w `failed_jobs`
 * i dopiero potem sprawdzało `instanceof`. Tego nie cofa żaden `try`:
 * `__wakeup`/`__destruct` obcej klasy wykonuje się w trakcie odtwarzania.
 * Teraz lista klas stoi tu raz (`WOLNO_ODTWORZYC`), a wszystkie trzy miejsca
 * wołają `powiadomienie()`. Brak listy gdziekolwiek w `app/` zapala
 * `tests/Feature/UnserializeTylkoZListaKlasTest.php`.
 */
final class PolecenieZadania
{
    /**
     * Klasy, które wolno odtworzyć z ładunku. Wszystko spoza tej listy wraca
     * jako `__PHP_Incomplete_Class` — bez konstruktora, `__wakeup`,
     * `__unserialize` i `__destruct`.
     *
     * `SendQueuedNotifications` musi tu być, bo to on niesie odbiorców.
     * `ModelIdentifier` i kolekcja Eloquenta — bo tak Laravel zapisuje modele
     * w kolejce (`SerializesModels`) i bez nich odbiorcy nie odtworzą się
     * wcale. Klas powiadomień na tej liście nie ma i nie wolno ich dopisywać:
     * niosą żetony, a żadne z miejsc czytających ładunek ich nie potrzebuje.
     *
     * @var list<class-string>
     */
    public const WOLNO_ODTWORZYC = [
        SendQueuedNotifications::class,
        ModelIdentifier::class,
        EloquentCollection::class,
    ];

    /**
     * Opakowanie powiadomienia z `data.command` — albo null, gdy ładunek
     * opisuje cokolwiek innego. Odtwarzane są wyłącznie `WOLNO_ODTWORZYC`.
     *
     * JSON-a tu nie ma i być nie może: format ładunku ustala Laravel
     * (`serialize()` polecenia w `Queue::createObjectPayload()`), a wiersze
     * już leżące w `failed_jobs` mają go takiego, jaki jest.
     *
     * `@` tłumi ostrzeżenie o uszkodzonym tekście — wtedy wynik to `false`,
     * czyli „nie powiadomienie".
     *
     * @throws RuntimeException gdy szyfrogramu nie da się odczytać
     */
    public static function powiadomienie(string $polecenie): ?SendQueuedNotifications
    {
        $obiekt = @unserialize(self::zserializowane($polecenie), ['allowed_classes' => self::WOLNO_ODTWORZYC]);

        return $obiekt instanceof SendQueuedNotifications ? $obiekt : null;
    }

    /**
     * @throws RuntimeException gdy szyfrogramu nie da się odczytać
     */
    public static function zserializowane(string $polecenie): string
    {
        if (str_starts_with($polecenie, 'O:')) {
            return $polecenie;
        }

        $odszyfrowane = app(Encrypter::class)->decrypt($polecenie);

        if (! is_string($odszyfrowane)) {
            throw new RuntimeException('Polecenie zadania nie jest tekstem.');
        }

        return $odszyfrowane;
    }
}
