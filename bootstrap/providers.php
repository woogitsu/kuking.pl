<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\CorrelationServiceProvider;
use App\Providers\PocztaServiceProvider;

return [
    AppServiceProvider::class,
    CorrelationServiceProvider::class,
    PocztaServiceProvider::class,
];
