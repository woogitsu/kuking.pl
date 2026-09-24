<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Zdjęcie `ready`, które miało wejść do paczki RODO, nie dało się odczytać
 * z magazynu (`GenerateUserExport::copyToTemp()`, issue #1388).
 *
 * Do 23 września 2026 taki błąd kończył się `Log::warning` i pominięciem
 * zdjęcia — a paczka i tak dostawała `ready`, chociaż `dane.json`, strony
 * przepisów, `index.html` i `CZYTAJ-TO-NAJPIERW.txt` liczyły to zdjęcie
 * i odsyłały do pliku, którego w ZIP-ie nie było. Teraz brak zdjęcia znaczy
 * „paczka niegotowa": kolejka ponawia (awarie magazynu bywają chwilowe),
 * a po ostatniej próbie człowiek widzi `DataExport::REASON_PHOTO_UNREADABLE`.
 *
 * Osobna klasa z tego samego powodu co `DataExportStorageFailure`: kod
 * powodu rozpoznaje `reasonFor()` po KLASIE, także w `failed()`, które
 * działa na nowej instancji joba.
 *
 * Komunikat niesie tylko identyfikator zdjęcia i klasę pierwotnego błędu —
 * komunikat z magazynu bywa ścieżką albo szczegółem dostawcy.
 */
final class DataExportPhotoUnreadable extends RuntimeException {}
