<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Awaria LOKALNEGO dysku tymczasowego przy kopiowaniu zdjęcia do paczki RODO
 * (`GenerateUserExport::copyToTemp()`): nie udało się utworzyć, zapisać albo
 * domknąć kopii, np. przy pełnym dysku.
 *
 * Osobno od `DataExportPhotoUnreadable`, bo to NIE jest wina magazynu ani
 * zdjęcia — do 23 września 2026 obie awarie szły pod jednym kodem i ekran
 * mówił człowiekowi „nie udało się pobrać jednego z Twoich zdjęć", kiedy
 * zabrakło miejsca na naszym serwerze. Kod powodu: `DataExport::REASON_UNKNOWN`
 * (patrz `GenerateUserExport::reasonFor()`); szczegół zostaje w logu.
 */
final class DataExportTempFailure extends RuntimeException {}
