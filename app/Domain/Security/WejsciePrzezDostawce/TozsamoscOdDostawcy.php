<?php

declare(strict_types=1);

namespace App\Domain\Security\WejsciePrzezDostawce;

use InvalidArgumentException;

/**
 * TOŻSAMOŚĆ JUŻ SPRAWDZONA PRZEZ ADAPTER DOSTAWCY (issue #1035).
 *
 * Do tej klasy trafia to, co zostało po protokole: `state`, PKCE, nonce,
 * token, Graph API i `appsecret_proof` są już za nami i zostały w adapterze
 * (`KlientGoogle`, `KlientFacebook`, kontrolery). Tu leży minimum, na którym
 * stoją reguły Kuking — i nic, co dałoby się pomylić z dowodem, którego nie ma.
 *
 * `emailPotwierdzony` JEST TYPOWANY I NIE JEST NAJSŁABSZYM WSPÓLNYM
 * MIANOWNIKIEM. Google potwierdza adres (`email_verified`), Facebook oddaje
 * adres bez tej gwarancji (D-098). Ta różnica ma zostać widoczna w każdym
 * miejscu, które z adresu korzysta — dlatego potwierdzony adres bez adresu
 * jest tu błędem programisty, a nie „pustym przypadkiem".
 */
final readonly class TozsamoscOdDostawcy
{
    public function __construct(
        /** Stały identyfikator konta u dostawcy (`sub` Google, App-Scoped ID Facebooka). */
        public string $identyfikator,
        /** Adres e-mail albo `null`, gdy dostawca go nie oddał. */
        public ?string $email,
        /** Czy DOSTAWCA dowiódł, że adres należy do tej osoby. */
        public bool $emailPotwierdzony,
        /** Imię do PODPOWIEDZENIA na ekranie domknięcia konta. Może być puste. */
        public string $imie,
    ) {
        if ($identyfikator === '') {
            throw new InvalidArgumentException('Tożsamość od dostawcy wymaga identyfikatora.');
        }

        if ($emailPotwierdzony && ($email === null || $email === '')) {
            throw new InvalidArgumentException('Potwierdzony adres e-mail wymaga adresu.');
        }
    }
}
