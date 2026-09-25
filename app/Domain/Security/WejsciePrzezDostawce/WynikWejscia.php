<?php

declare(strict_types=1);

namespace App\Domain\Security\WejsciePrzezDostawce;

/**
 * Rozstrzygnięcie `WejdzPrzezDostawce::wpusc()`.
 *
 * Przypadek użycia nie układa odpowiedzi HTTP: zdanie na ekranie mówi „kontem
 * Google" albo „kontem Facebooka", więc zamienia je na przekierowanie
 * kontroler dostawcy. Reguła — KTÓRE konto wchodzi i w jakiej kolejności
 * padają pytania — ma jedno źródło.
 */
enum WynikWejscia
{
    /** `banned`, `pending_delete`, `erased` — uzasadnienie z `KomunikatZamknietegoKonta`. */
    case KontoZamkniete;

    /** Moderator albo administrator — ta droga nie jest dla nich (D-056). */
    case KontoObslugi;

    /** Konto z 2FA: w sesji czeka samo oczekujące logowanie, nie zalogowana sesja. */
    case DrugiSkladnik;

    /** Zalogowany, sesja zregenerowana. */
    case Wpuszczony;
}
