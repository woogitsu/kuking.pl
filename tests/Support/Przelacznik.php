<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Flaga współdzielona między testem a domknięciem (`DB::listen`,
 * `Notification::creating` i tym podobne), którą test przełącza w trakcie.
 *
 * Zamiast `$flaga = true; … use (&$flaga)`: PHPStan nie śledzi zapisu przez
 * referencję z drugiego domknięcia, więc widział w środku domknięcia
 * wiecznie `true` i zgłaszał martwy warunek (#1731, poziom 4). Właściwość
 * obiektu ma zawsze typ `bool`, a domknięcie bierze obiekt przez wartość —
 * zachowanie testu jest to samo, tylko analiza mówi prawdę.
 */
final class Przelacznik
{
    public function __construct(public bool $wlaczony = true) {}
}
