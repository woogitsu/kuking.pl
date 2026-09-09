<?php

declare(strict_types=1);

namespace App\Turnstile;

/**
 * Trzy możliwe odpowiedzi na pytanie „czy ten token jest prawdziwy".
 *
 * DLACZEGO TRZY, A NIE DWA
 * Bo „nie wiem" jest tu osobnym, częstym i najważniejszym stanem — a przy
 * dwóch wartościach musiałby udawać jedną z nich. Cloudflare może nie
 * odpowiedzieć (timeout, 5xx, awaria), nasz sekret może być wpisany błędnie,
 * odpowiedź może przyjść w nieznanym kształcie. W każdym z tych przypadków
 * problem jest PO NASZEJ albo po ich stronie, nie po stronie człowieka
 * wysyłającego formularz — i wtedy formularz musi przejść.
 *
 * Gdyby „nie wiem" wpadało do `Odrzucony`, awaria cudzej usługi zamykałaby
 * rejestrację w Kuking. Gdyby wpadało do `Przeszedl`, nie wiedzielibyśmy
 * o niej wcale. Osobna wartość pozwala zrobić jedno i drugie naraz:
 * przepuścić człowieka i zapisać ostrzeżenie dla właściciela.
 */
enum WynikTurnstile
{
    /** Cloudflare potwierdził token. */
    case Przeszedl;

    /** Cloudflare powiedział „nie" o samym tokenie — podrobiony, zużyty albo wygasły. */
    case Odrzucony;

    /**
     * Nie wiemy: Cloudflare nie odpowiedział, odpowiedział błędem po naszej
     * stronie (zły sekret) albo czymś, czego nie rozumiemy. Formularz
     * przechodzi, ostrzeżenie idzie do dziennika.
     */
    case Nierozstrzygniety;
}
