<?php

declare(strict_types=1);

namespace App\Moderacja;

use RuntimeException;

/**
 * „Oceny NIE BYŁO, ale za chwilę może się udać" (#1662).
 *
 * Timeout, zerwane połączenie, HTTP 429 i przejściowe 5xx. Odróżnia to od
 * dwóch pozostałych wyników `KlientOpenAI`: poprawnej oceny (także bez
 * trafień) i `null` — braku oceny, którego ponowienie nie naprawi (brak
 * klucza, 400/401, odpowiedź w nieznanym kształcie).
 *
 * Wiadomość jest stała i nie niesie niczego z żądania ani odpowiedzi: ten
 * wyjątek może trafić do `failed_jobs`, a tam nie ma miejsca na cudze treści.
 */
final class ModelChwilowoNiedostepny extends RuntimeException
{
    /**
     * @param  ?int  $ponowZaSekund  wartość `Retry-After` z odpowiedzi 429/503,
     *                               jeśli dostawca ją podał w sekundach
     */
    public function __construct(public readonly ?int $ponowZaSekund = null)
    {
        parent::__construct('Model moderacji chwilowo niedostępny.');
    }
}
