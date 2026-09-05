<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Dzięki temu każdy kontroler ma $this->authorize(...).
     *
     * Zasada: KAŻDY endpoint dotykający treści użytkownika wywołuje
     * authorize() albo jawnie dokumentuje, dlaczego nie musi.
     * UUID w adresie nie jest autoryzacją (AGENTS.md → Security).
     */
    use AuthorizesRequests;
}
