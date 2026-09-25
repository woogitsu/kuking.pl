<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\CorrelationServiceProvider;
use App\Providers\MonitoringServiceProvider;
use App\Providers\PocztaServiceProvider;

return [
    AppServiceProvider::class,
    CorrelationServiceProvider::class,
    MonitoringServiceProvider::class,
    PocztaServiceProvider::class,
];
