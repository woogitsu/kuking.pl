<?php

declare(strict_types=1);

use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CorrelationServiceProvider;
use App\Providers\PocztaServiceProvider;

return [
    AppServiceProvider::class,
    ApiServiceProvider::class,
    CorrelationServiceProvider::class,
    PocztaServiceProvider::class,
];
