<?php

declare(strict_types=1);

namespace App\Moderacja;

use Throwable;

final class ExceptionContext
{
    /**
     * Etap podaje kod wywołujący, nigdy żądanie ani wyjątek.
     * Wiadomość, kod, poprzedni wyjątek i stos mogą nieść cudze dane.
     * Status HTTP jest logowany osobno przez KlientOpenAI, bez ciała odpowiedzi.
     *
     * @return array{exception_class: class-string<Throwable>, stage: string}
     */
    public static function forStage(Throwable $exception, string $stage): array
    {
        return ['exception_class' => $exception::class, 'stage' => $stage];
    }
}
