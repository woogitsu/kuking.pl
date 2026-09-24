<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use Illuminate\Support\Facades\DB;

/** Czytanie słownika przy zapisie nie może przeciąć scalenia tagów. */
final class TagMutationLock
{
    public static function forPost(): void
    {
        // Współdzielona blokada transakcyjna pozwala zapisywać różne wpisy
        // równolegle; jedynie scalenie słownika wymaga wyłączności.
        DB::select('SELECT pg_advisory_xact_lock_shared(647, 1)');
    }

    public static function forMerge(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(647, 1)');
    }

    /**
     * Lista tagów promowanych: każda zmiana czyta stan całej listy, więc
     * zmiany idą jedna po drugiej (#1308, `PromowaneTagi`). Osobny klucz niż
     * scalenie — scalenie bierze najpierw swój, potem ten; odwrotnej
     * kolejności nie ma nigdzie.
     */
    public static function forPromotions(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(647, 2)');
    }
}
