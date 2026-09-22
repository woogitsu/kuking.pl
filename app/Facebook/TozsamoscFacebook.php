<?php

declare(strict_types=1);

namespace App\Facebook;

/**
 * To, czego dowiedzieliśmy się od Facebooka o jednym człowieku — i nic ponad to.
 *
 * Obiekt żyje przez JEDNO żądanie i częściowo (bez imienia) przez sesję
 * między powrotem z Facebooka a domknięciem konta albo połączeniem.
 * Do bazy trafia z tego `identyfikator` — i tylko on.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE MA TU POLA `emailPotwierdzony` — NAJWAŻNIEJSZE ZDANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `TozsamoscGoogle` ma pole `emailPotwierdzony` i jest ono WARUNKIEM wejścia
 * (D-069, reguła 1). Tutaj takiego pola NIE MA i to nie jest przeoczenie
 * ani uproszczenie: **Facebook nie mówi, czy adres e-mail jest
 * potwierdzony.** Pełny opis pola `email` w dokumentacji Graph API brzmi
 * „The User's primary email address listed on their profile. This field will
 * not be returned if no valid email address is available." — i to wszystko.
 * Ani słowa o potwierdzeniu.
 *
 * Gdyby to pole tu stało (choćby ustawione na `false`), pierwszy człowiek
 * czytający ten kod obok kodu Google'a zapytałby, skąd się bierze, i miałby
 * prawo uznać, że skoro jest, to coś znaczy. Nie znaczy nic, bo nie ma
 * z czego powstać. **Adres z Facebooka jest u nas ZAWSZE niepotwierdzony**
 * i przechodzi naszą zwykłą ścieżkę potwierdzenia adresu — dokładnie tak,
 * jak przy rejestracji hasłem.
 *
 * Skutek, którego nie wolno obejść (D-098): adres z Facebooka NIGDY nie
 * łączy z istniejącym kontem Kuking. Powiązanie powstaje po rozpoznaniu
 * `identyfikator`-a albo po jawnym „połącz" człowieka, który JUŻ jest
 * zalogowany na swoje konto.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO ADRES JEST `?string`, A NIE `string`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo naprawdę może nie przyjść — konto Facebooka na numer telefonu nie ma
 * adresu wcale, a człowiek może też odznaczyć zgodę na ekranie Facebooka.
 * `null` w typie zmusza każde miejsce w kodzie do rozstrzygnięcia tego
 * przypadku; `string` z pustym napisem pozwoliłby przejść dalej z pustym
 * adresem i założyć konto, na które nikt nigdy nie wejdzie (adres e-mail
 * jest u nas jedyną drogą odzyskania konta i jedyną drogą powiadomień).
 */
final readonly class TozsamoscFacebook
{
    public function __construct(
        /**
         * Identyfikator konta Facebooka UNIKALNY DLA NASZEJ APLIKACJI
         * („App-Scoped User ID": „This ID is unique to the app and cannot be
         * used by other apps"). Jedyna wartość, którą zapisujemy — i jedyna,
         * po której rozpoznajemy kolejne wejścia.
         */
        public string $identyfikator,
        /** Adres e-mail albo `null`, gdy Facebook go nie oddał. Nigdy potwierdzony. */
        public ?string $email,
        /** Imię do PODPOWIEDZENIA na ekranie domknięcia konta. Może być puste. */
        public string $imie,
    ) {}
}
