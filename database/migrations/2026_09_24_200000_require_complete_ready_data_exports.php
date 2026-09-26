<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gotowa paczka danych ma komplet metadanych (issue #1365).
 *
 * Do tej migracji `data_exports_status_check` pilnował tylko nazwy stanu.
 * Baza przyjmowała `ready` bez `disk`, `object_key`, `bytes` albo
 * `completed_at`, a `DataExport::isDownloadable()` pokazywał taki wiersz
 * jako gotową paczkę — człowiek klikał „Pobierz" i dostawał 404.
 * `GenerateUserExport::finalize()` zapisuje komplet jednym `update()`; ten
 * CHECK jest granicą dla każdego INNEGO zapisu (regresja, ręczna naprawa).
 *
 * Czego CHECK celowo NIE wymaga:
 *  - relacji `expires_at` do `completed_at` — `EraseAccountData` unieważnia
 *    gotową paczkę, przestawiając `expires_at` w przeszłość (`now() - 1 s`),
 *    co dla paczki sprzed sekundy daje `expires_at < completed_at`; taki
 *    CHECK wywróciłby wymazanie konta;
 *  - `expires_at > now()` — PostgreSQL zakłada niezmienność wyrażeń CHECK,
 *    a gotowa paczka naturalnie staje się przeterminowana z upływem czasu
 *    (o dostępności decyduje `isDownloadable()`, nie baza);
 *  - niczego od `expired` — `CleanUpDataExports` celowo zostawia adres, gdy
 *    usunięcie pliku się nie udało, żeby sprzątanie ponowić, a przegrany
 *    wyścig z wymazaniem konta (#1307) zapisuje `expired` z adresem.
 *
 * AUDYT: przed dołożeniem CHECK migracja liczy `ready` bez kompletu i ODMAWIA,
 * zamiast zgadywać dysk, klucz albo rozmiar paczki.
 *
 * ROLLBACK: `down()` zdejmuje CHECK bez odmowy. Ograniczenie nie przechowuje
 * żadnej wartości (niczyjej decyzji, zgody ani zakresu w rozumieniu D-088) —
 * po cofnięciu baza wraca do poprzedniej, luźniejszej granicy, dane zostają.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $niekompletne = DB::table('data_exports')
            ->where('status', 'ready')
            ->where(function ($query): void {
                $query->whereNull('disk')->orWhere('disk', '')
                    ->orWhereNull('object_key')->orWhere('object_key', '')
                    ->orWhereNull('bytes')->orWhere('bytes', '<=', 0)
                    ->orWhereNull('completed_at')
                    ->orWhereNull('expires_at');
            })
            ->count();

        if ($niekompletne > 0) {
            throw new RuntimeException(
                "Liczba gotowych eksportów (status = 'ready') bez kompletu metadanych w tabeli `data_exports`: "
                .$niekompletne.'. Takiej paczki nie da się pobrać, a ekran ustawień pokazuje ją jako gotową '
                .'(issue #1365). Migracja nie zgaduje dysku, klucza ani rozmiaru pliku i odmawia.'
                ."\n\nCO ZROBIĆ:\n"
                ."  - obejrzyj wiersze:\n"
                ."      SELECT id, user_id, disk, object_key, bytes, completed_at, expires_at FROM data_exports\n"
                ."      WHERE status = 'ready' AND (disk IS NULL OR disk = '' OR object_key IS NULL OR object_key = ''\n"
                ."        OR bytes IS NULL OR bytes <= 0 OR completed_at IS NULL OR expires_at IS NULL);\n"
                ."  - jeśli plik paczki istnieje w magazynie, uzupełnij brakujące kolumny prawdziwymi wartościami;\n"
                ."  - jeśli nie istnieje, oznacz wiersz jako nieudany — człowiek zamówi paczkę ponownie:\n"
                ."      UPDATE data_exports SET status = 'failed', failure_reason = 'unknown' WHERE id = '<id>';\n"
                .'  - potem uruchom `php artisan migrate` jeszcze raz.',
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE data_exports
            ADD CONSTRAINT data_exports_ready_complete_check
            CHECK (
                status <> 'ready'
                OR (
                    disk IS NOT NULL AND disk <> ''
                    AND object_key IS NOT NULL AND object_key <> ''
                    AND bytes IS NOT NULL AND bytes > 0
                    AND completed_at IS NOT NULL
                    AND expires_at IS NOT NULL
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('data_exports')) {
            return;
        }

        DB::statement('ALTER TABLE data_exports DROP CONSTRAINT IF EXISTS data_exports_ready_complete_check');
    }
};
