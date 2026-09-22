<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Mail;
use Throwable;

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
 *
 * ---
 *
 * DRUGIE PYTANIE, DOŁOŻONE 9 WRZEŚNIA 2026: CZY TRANSPORT W OGÓLE POWSTAJE
 *
 * Sama nazwa sterownika okazała się za słabym pomiarem, i to dwa razy tego
 * samego dnia:
 *
 * 1. `MAIL_SCHEME=tls` (poprawna intencja, nieobsługiwana wartość — Symfony
 *    przyjmuje wyłącznie `smtp` i `smtps`). Sterownik był `smtp`, więc ta
 *    klasa mówiła „poczta działa", a transportu NIE DAŁO SIĘ NAWET ZBUDOWAĆ:
 *    każde zadanie kończyło się `UnsupportedSchemeException`.
 * 2. Sterowniki `postmark` i `resend` przechodziły tę kontrolę, choć
 *    w `composer.json` nie ma ich paczek — pierwszy list padłby na
 *    „Class not found".
 *
 * Do tego dochodzi nowy sterownik `emaillabs`: z pustym kluczem API jest
 * dokładnie tak samo martwy, a wygląda tak samo dobrze.
 *
 * Dlatego `dziala()` pyta teraz o DWIE rzeczy naraz: czy sterownik dostarcza
 * ORAZ czy Laravel potrafi zbudować dla niego transport. Budowa transportu
 * jest lokalna i tania — nikt się nigdzie nie łączy, `EsmtpTransport` otwiera
 * gniazdo dopiero przy pierwszym liście — więc nadaje się do wywołania
 * w żądaniu HTTP.
 *
 * CZEGO TA KLASA NADAL NIE WIE I CZEGO NIE UDAJE
 * Że list dojdzie. Nie sprawdzamy połączenia z dostawcą, nie pytamy o klucz,
 * nie patrzymy na DNS, nie wiemy, czy chodzi worker. Zbudowany transport
 * znaczy tylko tyle, że wysyłka ma czym RUSZYĆ — resztę mierzy
 * `kuking:sprawdz-poczte`, wysyłając prawdziwą wiadomość.
 */
final class Poczta
{
    /** @var list<string> */
    private const NIEDOSTARCZAJACE = ['log', 'array', ''];

    public static function dziala(): bool
    {
        return self::przeszkoda() === null;
    }

    /**
     * Zdanie DLA KONSOLI o tym, co dokładnie stoi na drodze. `null` = nic nie
     * stoi.
     *
     * NIE POKAZUJ TEGO UŻYTKOWNIKOWI i nie wysyłaj na webhook. Ostatnia część
     * zdania bywa komunikatem wyjątku z cudzej biblioteki, a te potrafią nieść
     * więcej, niż autor kodu tam włożył (audyt A6-01,
     * `App\Logging\WebhookBleduHandler`). Dla człowieka jest
     * `komunikatBrakuPoczty()`, który mówi, co zrobić, i nic więcej.
     */
    public static function przeszkoda(): ?string
    {
        $sterownik = config('mail.default');

        if (! is_string($sterownik) || in_array($sterownik, self::NIEDOSTARCZAJACE, true)) {
            return match (true) {
                $sterownik === 'log' => 'Sterownik `log` zapisuje wiadomość do dziennika aplikacji i zgłasza sukces. Nikt jej nie dostanie.',
                $sterownik === 'array' => 'Sterownik `array` trzyma wiadomość w pamięci procesu (używa go suita testów). Nikt jej nie dostanie.',
                default => 'Zmienna MAIL_MAILER jest pusta, więc Laravel nie ma czym wysyłać.',
            };
        }

        try {
            Mail::mailer($sterownik)->getSymfonyTransport();
        } catch (Throwable $e) {
            // `Throwable`, nie `Exception`: brakująca paczka dostawcy kończy
            // się `Error: Class ... not found`, a to nie jest `Exception`.
            return "Sterownik „{$sterownik}” jest ustawiony, ale Laravel nie potrafi zbudować dla niego "
                .'transportu, więc żaden list nie ma czym wyjść. Powód: '.$e->getMessage();
        }

        return null;
    }

    /**
     * Zdanie dla człowieka, gdy poczta nie działa. Jedno miejsce, bo ten sam
     * komunikat idzie na ekran „Nie pamiętam hasła", na ekran „Wyślij mi link
     * do zalogowania" i w odpowiedź na próbę wysłania z obu tych formularzy.
     *
     * `$coNieDojdzie` nazywa RZECZ, NA KTÓRĄ CZŁOWIEK CZEKA, a nie
     * technologię. Domyślna wartość zostawia dotychczasowe brzmienie ekranu
     * „Nie pamiętam hasła" nietknięte; logowanie linkiem (issue #25) podaje
     * własną, bo „link do nowego hasła" nad formularzem, w którym o hasło
     * nikt nie prosił, kazałby szukać usterki gdzie indziej.
     */
    public static function komunikatBrakuPoczty(string $coNieDojdzie = 'link do nowego hasła'): string
    {
        return 'Nie wysyłamy jeszcze wiadomości e-mail, więc '.$coNieDojdzie.' nie przyjdzie — '
            .'nie czekaj na niego. Napisz do nas na '.(string) config('kuking.community.contact_email')
            .', a pomożemy Ci wrócić na konto. Odpisuje człowiek.';
    }
}
