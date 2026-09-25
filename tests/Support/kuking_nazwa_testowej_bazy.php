<?php

declare(strict_types=1);

/**
 * Łącznik zgodności — reguła nazywania testowej bazy żyje w `tests/nazwa-bazy.php`.
 *
 * Ten plik wszedł na `main` 21.09.2026 (35df837c) jako „jedyne źródło" reguły
 * z issue #66. Równolegle ta sama reguła, z tym samym celem, przeszła na gałęzi
 * #966 do `tests/nazwa-bazy.php` — razem z nazwami baz wyścigów, próby
 * wycofania i rejestrem dla sprzątacza (D-228). Dwa pliki z tą samą funkcją
 * to dokładnie rozjazd, przed którym oba commity ostrzegały, a załadowane
 * naraz skończyłyby się „Cannot redeclare function".
 *
 * Zostaje więc JEDNA implementacja, a ta ścieżka tylko do niej prowadzi —
 * żeby nie wyłożyło się nic, co zdążyło ją wpisać na sztywno (skrypty floty
 * poza repozytorium, stare kopie hooka). Nowy kod woła `tests/nazwa-bazy.php`.
 */
require_once __DIR__.'/../nazwa-bazy.php';
