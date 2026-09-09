<?php

declare(strict_types=1);

namespace App\Poczta;

use Symfony\Component\Mailer\Exception\TransportException;

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
 * wyjątku wychodzi dalej, niż się autorowi wydaje — do `failed_jobs`, do
 * Sentry, do kanału `blad_webhook`. Komunikat budujemy więc z LISTY
 * DOZWOLONYCH PÓL odpowiedzi (kod błędu, tytuł, nazwa parametru, `uniqId`),
 * a nie z tego, co dostawca akurat przysłał.
 */
final class OdmowaEmailLabs extends TransportException {}
