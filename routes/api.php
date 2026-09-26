<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Publiczne API v1 — dla aplikacji mobilnej (D-014, D-270)
|--------------------------------------------------------------------------
|
| Każda trasa tutaj dostaje z `bootstrap/app.php` prefiks `/api/v1` i grupę
| `api`: `BramaApi` (wyłącznik `KUKING_API_ENABLED`, domyślnie zamknięty),
| limiter `api` (na token i na adres IP) i wiązanie modeli.
|
| ZASADY, KTÓRYCH TEN PLIK NIE ŁAMIE:
|
|  - kontroler API jest ADAPTEREM: waliduje, woła tę samą Akcję z
|    `app/Domain/…/Actions` co kontroler HTML i zwraca zasób JSON. Reguła
|    domenowa w kontrolerze API to reguła, którą da się obejść drugim
|    endpointem (AGENTS.md §4);
|  - każde wejście na cudzą treść przechodzi przez TĘ SAMĄ Policy co WWW —
|    UUID w adresie nie jest autoryzacją (AGENTS.md §7);
|  - każda trasa poza wydaniem tokenu ma `auth:sanctum`.
*/
