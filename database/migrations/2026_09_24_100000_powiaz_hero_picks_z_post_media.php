<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wybór do kolażu wskazuje PARĘ: zdjęcie oraz wpis, przy którym ono wisi.
 * Dwa osobne klucze obce dowodziły dotąd tylko, że oba wiersze istnieją.
 * Nie dowodziły, że zdjęcie należy do tego wpisu, choć
 * `DostepDoZdjecia` ufa właśnie tej parze przy wyborze Policy rodzica.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'hero_picks_post_media_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('hero_picks') || ! Schema::hasTable('post_media')) {
            throw new RuntimeException(
                'Nie można powiązać wyborów kolażu z wpisami: brakuje tabeli `hero_picks` '
                .'albo `post_media`. Uruchom wcześniejsze migracje i ponów próbę.',
            );
        }

        // Stabilny obraz na czas kontroli i ADD CONSTRAINT. Bez tej blokady
        // nowy zapis mógłby wejść między pomiar a założenie klucza obcego.
        DB::statement('LOCK TABLE hero_picks IN SHARE ROW EXCLUSIVE MODE');

        if ($this->constraintIstnieje()) {
            return;
        }

        $niespojne = (int) DB::table('hero_picks as hp')
            ->leftJoin('post_media as pm', function ($join): void {
                $join->on('pm.post_id', '=', 'hp.post_id')
                    ->on('pm.media_id', '=', 'hp.media_id');
            })
            ->whereNull('pm.post_id')
            ->count();

        if ($niespojne > 0) {
            throw new RuntimeException(
                'Nie dodano klucza obcego `hero_picks(post_id, media_id) -> post_media`: '
                .'w bazie są wybory kolażu, których zdjęcie nie należy do wskazanego wpisu. '
                .'Liczba niespójnych par: '.$niespojne.".\n\n"
                ."CO ZROBIĆ\n"
                .'Sprawdź każdą parę ręcznie; migracja nie przepina zdjęć i niczego nie kasuje. '
                .'Lista do przeglądu: SELECT hp.id, hp.post_id, hp.media_id FROM hero_picks hp '
                .'LEFT JOIN post_media pm ON pm.post_id = hp.post_id AND pm.media_id = hp.media_id '
                .'WHERE pm.post_id IS NULL ORDER BY hp.created_at, hp.id;',
            );
        }

        DB::statement(
            'ALTER TABLE hero_picks '
            .'ADD CONSTRAINT '.self::CONSTRAINT.' '
            .'FOREIGN KEY (post_id, media_id) '
            .'REFERENCES post_media (post_id, media_id) '
            .'ON DELETE CASCADE',
        );
    }

    /**
     * Rollback usuwa wyłącznie nową gwarancję schematu. Nie zmienia ani nie
     * kasuje wyborów kolażu, wpisów, zdjęć ani relacji `post_media`.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hero_picks')) {
            return;
        }

        DB::statement('ALTER TABLE hero_picks DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }

    private function constraintIstnieje(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
            ['hero_picks', self::CONSTRAINT],
        ) !== null;
    }
};
