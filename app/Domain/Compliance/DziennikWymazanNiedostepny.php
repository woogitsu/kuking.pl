<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use RuntimeException;

/**
 * Wymazanie konta cofnięte, bo dziennik wymazań poza bazą nie przyjął wpisu
 * (issue #2038, wariant A). Osobna klasa, żeby egzekutor odróżnił tę przyczynę
 * od innych awarii i policzył kolejne noce dla alarmu
 * (`App\Domain\Monitoring\AlarmDziennikaWymazan`).
 */
final class DziennikWymazanNiedostepny extends RuntimeException {}
