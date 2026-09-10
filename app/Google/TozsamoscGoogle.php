<?php

declare(strict_types=1);

namespace App\Google;

/**
 * To, czego dowiedzieliśmy się od Google o jednym człowieku — i nic ponad to.
 *
 * Obiekt żyje przez JEDNO żądanie i częściowo (bez imienia) przez sesję
 * między powrotem z Google a domknięciem konta. Do bazy trafia z tego
 * `sub` — i tylko on (patrz migracja `add_google_account_to_users`).
 *
 * `emailPotwierdzony` NIE JEST tu ozdobą ani polem „na wszelki wypadek".
 * To jest warunek, bez którego cała ta droga zamienia się w przejmowanie
 * kont: konto Google założone na cudzy adres bez potwierdzenia go
 * (a takie da się zrobić na adresie, który nie jest w Gmailu) dostałoby
 * u nas wejście na konto prawdziwego właściciela tego adresu. Dlatego
 * wartość jedzie tu jako osobne pole, a nie jako coś, co „chyba było
 * w tokenie" — patrz D-069.
 */
final readonly class TozsamoscGoogle
{
    public function __construct(
        /** Trwały identyfikator konta Google (`sub`). Jedyna wartość, którą zapisujemy. */
        public string $sub,
        /** Adres e-mail z tokenu tożsamości, już znormalizowany małymi literami. */
        public string $email,
        /** Czy GOOGLE potwierdziło, że ten adres należy do właściciela tego konta. */
        public bool $emailPotwierdzony,
        /** Imię do PODPOWIEDZENIA na ekranie domknięcia konta. Może być puste. */
        public string $imie,
    ) {}
}
