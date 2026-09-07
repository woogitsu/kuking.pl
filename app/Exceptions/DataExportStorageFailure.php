<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Awaria konkretnie w kroku zapisu gotowej paczki RODO do magazynu plików
 * (`Storage::disk()->writeStream()` w `GenerateUserExport::handle()`), nie
 * gdziekolwiek indziej w tym samym jobie (audyt W7-07).
 *
 * DLACZEGO OSOBNA KLASA, A NIE ROZPOZNAWANIE PO TREŚCI KOMUNIKATU
 * `GenerateUserExport::reasonFor()` musi dać tę samą odpowiedź niezależnie
 * od tego, skąd jest wywołana — a wywołania są dwa, z dwóch różnych
 * instancji joba. `handle()` woła ją z tej SAMEJ instancji, w której
 * powstał wyjątek. `failed()` — hook wywoływany po wyczerpaniu prób —
 * dostaje jednak `$e` z powrotem po tym, jak `Illuminate\Queue\
 * CallQueuedHandler::failed()` odtworzył joba PONOWNIE z ładunku kolejki
 * (`unserialize()`), czyli z zupełnie nowej instancji. Żaden stan zapisany
 * na `$this` podczas nieudanego `handle()` tam nie dotrwa — jedyne, co
 * realnie przechodzi przez tę granicę, to sam obiekt wyjątku. Rozpoznanie
 * po jego KLASIE jest więc jedynym sposobem, żeby oba wywołania zgodnie
 * zwróciły `DataExport::REASON_STORAGE`.
 */
final class DataExportStorageFailure extends RuntimeException {}
