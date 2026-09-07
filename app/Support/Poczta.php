<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy serwis NAPRAWDĘ wysyła pocztę.
 *
 * PO CO TA KLASA ISTNIEJE
 * `MAIL_MAILER=log` zapisuje wiadomość do pliku i zwraca sukces. Dla Laravela
 * wysyłka „się udała", dla człowieka nie przyszło nic. Do 7 września 2026
 * ekran „Nie pamiętam hasła" mówił w tej sytuacji „Wyślemy na niego
 * wiadomość z linkiem", a potem doradzał sprawdzenie folderu „Spam" — czyli
 * wysyłał osobę na poszukiwanie listu, który nigdy nie powstał.
 *
 * Przy grupie 50+ to nie jest niedogodność. Pierwsza osoba, która zapomni
 * hasła, traci konto bezpowrotnie: nie ma jak się zalogować i nie wie, że
 * nie ma na co czekać.
 *
 * DLACZEGO STEROWNIK, A NIE OSOBNY PRZEŁĄCZNIK W KONFIGURACJI
 * Osobna flaga („czy poczta działa") to druga kopia tej samej prawdy i
 * rozjechałaby się z rzeczywistością pierwszego dnia, w którym ktoś zmieni
 * `MAIL_MAILER` i zapomni o niej. Pytamy więc wprost o to, czym Laravel
 * naprawdę wysyła — to jest ta sama zasada, dla której `Wersja` czyta skrót
 * commita z Railway zamiast trzymać własny numer.
 *
 * `log` i `array` NIE DOSTARCZAJĄ: pierwszy zapisuje do dziennika, drugi
 * trzyma w pamięci procesu (używa go suita testów). Puste ustawienie znaczy
 * to samo co brak wysyłki.
 */
final class Poczta
{
    /** @var list<string> */
    private const NIEDOSTARCZAJACE = ['log', 'array', ''];

    public static function dziala(): bool
    {
        $sterownik = config('mail.default');

        if (! is_string($sterownik)) {
            return false;
        }

        return ! in_array($sterownik, self::NIEDOSTARCZAJACE, true);
    }

    /**
     * Zdanie dla człowieka, gdy poczta nie działa. Jedno miejsce, bo ten sam
     * komunikat idzie na ekran „Nie pamiętam hasła" i w odpowiedź na próbę
     * wysłania linku.
     */
    public static function komunikatBrakuPoczty(): string
    {
        return 'Nie wysyłamy jeszcze wiadomości e-mail, więc link do nowego hasła nie przyjdzie — '
            .'nie czekaj na niego. Napisz do nas na '.(string) config('kuking.community.contact_email')
            .', a pomożemy Ci wrócić na konto. Odpisuje człowiek.';
    }
}
