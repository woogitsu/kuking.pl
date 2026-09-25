<?php

declare(strict_types=1);

namespace App\Poczta;

use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * API EmailLabs nie przyjęło wiadomości — albo nie dało się go dopytać.
 *
 * DLACZEGO DZIEDZICZY PO `TransportException`
 * Bo to jest awaria transportu i cała reszta świata ma ją tak potraktować:
 * `Illuminate\Mail\Mailer` jej nie łapie, zadanie w kolejce kończy się `FAIL`
 * i ląduje w `failed_jobs`, a `php artisan queue:failed` je pokazuje. Własny
 * typ wyjątku spoza tej hierarchii przeszedłby przez te mechanizmy inaczej,
 * a cicha porażka jest tu najgorszym możliwym skutkiem — 9 września zadanie
 * `UstawienieNowegoHasla` wchodziło w `RUNNING` i NIGDY się nie kończyło, ani
 * `DONE`, ani `FAIL`, więc monitoring nie miał czego pokazać.
 *
 * CZEGO W KOMUNIKACIE NIE MA I NIGDY NIE BĘDZIE
 * Treści listu, adresu odbiorcy, nazwiska, tematu ani żadnego klucza. Powód
 * jest ten sam, co w `App\Logging\WebhookBleduHandler` (audyt A6-01): komunikat
 * wyjątku wychodzi dalej, niż się autorowi wydaje — do `failed_jobs` i do
 * kanału `blad_webhook`, czyli na czyjś Slack albo Discord. Komunikat budujemy
 * więc z LISTY DOZWOLONYCH PÓL odpowiedzi (kod błędu, tytuł, nazwa parametru,
 * `uniqId`), a nie z tego, co dostawca akurat przysłał. Do 10 września 2026
 * stało tu, że komunikat idzie także „do Sentry" — nieprawda, Sentry'ego nie ma
 * w projekcie wcale (D-041). Lista odbiorców jest więc dziś krótsza, ale ani
 * o jedno pole mniej wrażliwa: to samo ograniczenie ma obowiązywać w dniu,
 * w którym Sentry dojdzie.
 *
 * CO SIĘ DZIEJE DALEJ — I DLACZEGO TO NIE KONIEC (issue #234, D-062)
 * `failed_jobs` był do 10 września 2026 KOŃCEM tej drogi: po trzeciej próbie
 * (`--tries=3 --backoff=10,60,300`, czyli po około sześciu minutach) list
 * przepadał i nie dowiadywał się o tym nikt — ani właściciel, ani człowiek,
 * który stał przed ekranem i czekał na potwierdzenie adresu. Dlatego ten
 * wyjątek niesie teraz KATEGORIĘ odmowy, a `App\Poczta\ZapiszNieudanyList`
 * zamienia jego ostatnią próbę w trwały wiersz `mail_failures`, o którym mówi
 * `/health`. Kategoria jest tu, a nie w tamtej klasie, bo tylko transport
 * widzi kod HTTP dostawcy.
 */
final class OdmowaEmailLabs extends TransportException
{
    /**
     * CO TO ZA ODMOWA — „nie wyszedł teraz" czy „nie wyjdzie nigdy"
     * (issue #234, D-062).
     *
     * Kategoria jest USTALANA W MIEJSCU ODMOWY, czyli w transporcie, bo tylko
     * on widzi kod HTTP i kody błędów dostawcy. Odczytanie jej później
     * z komunikatu wyjątku byłoby zgadywaniem z tekstu — dokładnie tak kruchym,
     * jak zgadywanie powodu awarii `/health` z treści `$e->getMessage()`
     * (audyt W7-07).
     *
     * Domyślna wartość jest `NIEZNANA`, a nie „przejściowa": wyjątek
     * zbudowany bez podania kategorii ma się przyznać do niewiedzy, a nie
     * obiecywać, że samo przejdzie.
     */
    private PowodOdmowy $powod = PowodOdmowy::NIEZNANA;

    /** Kod HTTP odpowiedzi dostawcy, jeśli w ogóle odpowiedział. */
    private ?int $statusHttp = null;

    private bool $confirmedRejection = false;

    /**
     * Nazwana wytwórnia — jedyna droga, którą kategoria wchodzi do wyjątku.
     *
     * Konstruktor `TransportException` zostaje nietknięty (`new
     * OdmowaEmailLabs('...')` dalej działa), żeby ta klasa pozostała zwykłym
     * wyjątkiem transportu Symfony także dla kodu, który o kategorii nic nie
     * wie.
     */
    public static function powodu(
        PowodOdmowy $powod,
        string $komunikat,
        ?int $statusHttp = null,
        ?Throwable $poprzedni = null,
        bool $confirmedRejection = false,
    ): self {
        $odmowa = new self($komunikat, previous: $poprzedni);
        $odmowa->powod = $powod;
        $odmowa->statusHttp = $statusHttp;
        $odmowa->confirmedRejection = $confirmedRejection;

        return $odmowa;
    }

    public function powod(): PowodOdmowy
    {
        return $this->powod;
    }

    /** Pewność odmowy jest niezależna od tego, czy awaria jest przejściowa. */
    public function isConfirmedRejection(): bool
    {
        return $this->confirmedRejection;
    }

    public function statusHttp(): ?int
    {
        return $this->statusHttp;
    }
}
