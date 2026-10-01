<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nowy status zdjęcia `secured` — ZABEZPIECZONE JAKO DOWÓD (ścieżka CSAM,
 * D-333 wiersz z 1 października 2026).
 *
 * Zdjęcie `secured` nie jest pokazywane nikomu — także właścicielowi
 * i moderatorowi (`Media::maWariantDoPokazania()`), nie wchodzi do żadnego
 * zapytania „status = ready” (eksport danych, odczyt przez AI, kolaże)
 * i nie wolno go skasować (`KasujZdjecie`). Pliki zostają w magazynie.
 *
 * Wzorzec DDL na żywej tabeli (AGENTS.md §6): CHECK jako NOT VALID, potem
 * osobno VALIDATE, poza transakcją. Nowy CHECK jest SZERSZY od starego,
 * więc walidacja nie może się nie udać na istniejących danych.
 *
 * ROLLBACK (D-088): odmawia, gdy w `media` jest zdjęcie `secured` — stary
 * CHECK go nie dopuszcza, a „naprawa” przez zmianę statusu zdjęłaby
 * zabezpieczenie dowodu.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'media_status_check';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE media ADD CONSTRAINT '.self::OGRANICZENIE
            ." CHECK (status IN ('pending','processing','ready','rejected','deleted','secured')) NOT VALID");
        DB::statement('ALTER TABLE media VALIDATE CONSTRAINT '.self::OGRANICZENIE);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $ile = DB::table('media')->where('status', 'secured')->count();

        if ($ile > 0) {
            throw new RuntimeException(
                'Odmawiam cofnięcia migracji: w media jest '.$ile.' zdjęć zabezpieczonych jako dowód (status secured). '
                .'Stary CHECK ich nie dopuszcza, a zmiana statusu zdjęłaby zabezpieczenie dowodu i znów pokazała plik. '
                .'CO ZROBIĆ: nie cofaj tej migracji; jeśli musisz, najpierw — za zgodą właściciela i po decyzji '
                .'prawnika, patrz docs/legal/MODERATION_PLAYBOOK.md §7.1a — rozstrzygnij los tych dowodów ręcznie.',
            );
        }

        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE media ADD CONSTRAINT '.self::OGRANICZENIE
            ." CHECK (status IN ('pending','processing','ready','rejected','deleted')) NOT VALID");
        DB::statement('ALTER TABLE media VALIDATE CONSTRAINT '.self::OGRANICZENIE);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
