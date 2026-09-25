<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reakcja „Smakowicie wygląda" (issue #1813, D-280) — lżejsza niż „Ugotowałem".
 *
 * Jeden wiersz = jedna osoba napisała „Smakowicie wygląda" pod jednym wpisem.
 * `UNIQUE (post_id, user_id)` — to jest STAN („podoba mi się"), nie zdarzenie;
 * inaczej niż `cooked_events`, gdzie każde ugotowanie jest osobnym wydarzeniem
 * (AGENTS.md §6 zabrania tam unikalności — tu jej wymaga).
 *
 * `notified_at` — kiedy reakcja weszła do zbiorczego powiadomienia autora
 * (raz dziennie, `kuking:powiadom-smakowicie`). `NULL` = czeka.
 *
 * BEZ LICZNIKA. Tej tabeli nie czyta żadne zapytanie układające listy — pilnuje
 * `FeedNieSortujePoMierzeReakcjiTest`. Nikt poza autorem wpisu nie widzi, kto
 * zareagował, i nikt — łącznie z autorem — nie widzi liczby.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w tabeli są reakcje. To są słowa
 * ludzi skierowane do autorów; zrzucenie tabeli kasuje je bez śladu, a `up()`
 * ich nie odtworzy. Na pustej tabeli rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['post_id', 'user_id']);
            $table->index('user_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE post_reactions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            // Zbiorcze powiadomienie czyta wyłącznie reakcje czekające.
            DB::statement('CREATE INDEX post_reactions_pending_idx ON post_reactions (created_at) WHERE notified_at IS NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('post_reactions')) {
            $ile = DB::table('post_reactions')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    "Nie cofam tabeli post_reactions: {$ile} reakcji „Smakowicie wygląda”. To słowa ludzi do autorów "
                    .'i up() ich nie odtworzy (D-088). CO ZROBIĆ: zrób kopię (\\copy post_reactions TO reakcje.csv CSV HEADER), '
                    .'uzgodnij z właścicielem, czy reakcje mają przepaść, i dopiero wtedy usuń wiersze ręcznie.',
                );
            }
        }

        Schema::dropIfExists('post_reactions');
    }
};
