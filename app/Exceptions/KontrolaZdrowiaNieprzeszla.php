<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Nieudane sprawdzenie w `/health`, opisane KODEM — nie zdaniem.
 *
 * PO CO OSOBNA KLASA
 * `/health` jest publiczny i bez logowania (Railway odpytuje go przy każdym
 * wdrożeniu, zewnętrzny monitoring co kilka minut — `docs/infra/
 * INFRA_DECISION.md` §11). Do niedawna wkładał do odpowiedzi `$e->getMessage()`
 * wprost, czyli przy awarii bazy pokazywał całemu światu adres hosta, port,
 * nazwę bazy i nazwę użytkownika z komunikatu PDO:
 *
 *   SQLSTATE[08006] [7] connection to server at "…", port … failed:
 *   FATAL: password authentication failed for user "…"
 *
 * To jest dokładnie ta sama usterka, którą audyt W7-07 znalazł w
 * `failure_reason` eksportu RODO, i domykamy ją tym samym wzorcem:
 * na zewnątrz idzie kod z zamkniętego zbioru (`HealthController::POWODY`),
 * a do logu klasa, SQLSTATE i klasy przyczyn (`BezpiecznyBlad`) — też bez
 * komunikatu, bo stderr czyta Railway (#973).
 *
 * DLACZEGO KOD SIEDZI W WYJĄTKU, A NIE JEST ZGADYWANY Z TREŚCI
 * Rozpoznawanie awarii po treści komunikatu to ta sama kruchość, która raz
 * już przepuściła surowy wyjątek na ekran (patrz `DataExportStorageFailure`).
 * Sonda, która wie, co poszło nie tak, mówi to wprost; wszystko, czego nie
 * rozpoznajemy, dostaje powód domyślny danego sprawdzenia.
 */
final class KontrolaZdrowiaNieprzeszla extends RuntimeException
{
    public function __construct(
        public readonly string $kod,
        string $doLogu,
        ?\Throwable $poprzedni = null,
    ) {
        parent::__construct($doLogu, 0, $poprzedni);
    }
}
