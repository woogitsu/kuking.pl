<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZDJĘCIE JAKO CEL OZNACZENIA — nowa wartość `media` w `reports.target_type`
 * (issue #237).
 *
 * PO CO
 * Zdjęcie profilowe nie przechodziło przez ocenę modelem w ogóle. Model
 * oceniał zdjęcia wpisów, ale awatar idzie inną drogą (`AvatarSettingsController`
 * → `StoreUploadedImage` → `ProcessUploadedImage`) i nikt na tej drodze nie
 * zlecał analizy. Awatar jest przy tym widoczny CZĘŚCIEJ niż jakikolwiek wpis:
 * chodzi za człowiekiem po całym serwisie, przy każdym komentarzu i na każdej
 * liście.
 *
 * DLACZEGO NOWY TYP CELU, A NIE `user`
 * Bo indeks `reports_jeden_automat_na_tresc` przepuszcza JEDNO oznaczenie
 * automatu na (typ, identyfikator) — na zawsze, także po odrzuceniu. Przy
 * celu `user` znaczyłoby to: pierwszy awatar oceniony, każdy następny tego
 * konta już nigdy. A podmiana zdjęcia to jedna sekunda pracy. Celem musi więc
 * być KONKRETNE ZDJĘCIE, bo tylko wtedy „jedno oznaczenie na treść" znaczy
 * to, co ma znaczyć.
 *
 * DLACZEGO `media`, A NIE `avatar`
 * `ModeratedContent::TYPY` mapuje KLASĘ modelu na nazwę typu, a klasa to
 * `App\Models\Media` — ta sama dla awatara i dla zdjęcia we wpisie. Nazwa
 * `avatar` byłaby więc prawdziwa dziś i kłamliwa pierwszego dnia, w którym
 * oznaczymy zdjęcie z wpisu osobno. Że w tym konkretnym wierszu chodzi
 * o zdjęcie profilowe, mówi treść powodu i podgląd w kolejce.
 *
 * ROLLBACK
 * `php artisan migrate:rollback --step=1` przywraca CHECK bez `media`.
 * Zadziała tylko wtedy, gdy w tabeli NIE MA ani jednego wiersza z tym typem —
 * PostgreSQL sprawdza istniejące dane przy zakładaniu CHECK-a. Migracja
 * świadomie nie kasuje takich wierszy sama: to są sprawy moderacyjne
 * z decyzjami i odwołaniami (DSA art. 17), a rollback schematu nie jest
 * decyzją o wyrzuceniu cudzych spraw. Gdy `down()` odbije się o dane,
 * komunikat mówi, co zrobić.
 */
return new class extends Migration
{
    /** Wartości `target_type` PO tej migracji. */
    private const PO = "'user','post','recipe','comment','cooked_event','media','unknown'";

    /** Wartości `target_type` PRZED tą migracją. */
    private const PRZED = "'user','post','recipe','comment','cooked_event','unknown'";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_target_type_check');
        DB::statement('ALTER TABLE reports ADD CONSTRAINT reports_target_type_check CHECK (target_type IN ('.self::PO.'))');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $zdjec = (int) DB::table('reports')->where('target_type', 'media')->count();

        if ($zdjec > 0) {
            throw new RuntimeException(
                'W `reports` leży '.$zdjec.' oznaczeń zdjęć (`target_type = media`). '
                .'Cofnięcie tej migracji odrzuciłoby te wiersze przez CHECK, a są to sprawy '
                .'moderacyjne z decyzjami i odwołaniami. Rozstrzygnij je i przenieś ręcznie '
                .'albo skasuj świadomie, potem cofnij migrację.',
            );
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_target_type_check');
        DB::statement('ALTER TABLE reports ADD CONSTRAINT reports_target_type_check CHECK (target_type IN ('.self::PRZED.'))');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
