<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Dziękuję" pod komentarzem (issue #2355, F11 z researchu 30.09).
 *
 * Jeden wiersz = autor treści (wpisu, przepisu albo wykonania) podziękował
 * jednym kliknięciem za jeden komentarz. `UNIQUE (comment_id, thanker_id)` —
 * to jest STAN („podziękowano"), nie zdarzenie: drugie kliknięcie nie tworzy
 * drugiego wiersza i nie wysyła drugiego powiadomienia. Wycofania nie ma
 * (decyzja w `App\Domain\Comments\Actions\ThankForComment`).
 *
 * Kto może podziękować, rozstrzyga `CommentPolicy::thank()` i akcja domenowa —
 * baza pilnuje tylko klucza. `thanker_id` to zawsze autor treści; osobna
 * kolumna (a nie wyliczanie z treści) zostaje po to, żeby wymazanie konta
 * i eksport mogły wskazać „podziękowania tej osoby" jednym zapytaniem.
 *
 * `ON DELETE CASCADE` działa przy TWARDYM usunięciu komentarza albo konta.
 * Komentarze mają soft delete, a konta są anonimizowane (D-022), więc
 * podziękowania wymazywanego konta kasuje jawnie `EraseAccountData`.
 *
 * BEZ LICZNIKA. Tej tabeli nie czyta żadne zapytanie układające listy ani
 * ranking — pilnuje `PodziekowaniaNieMajaLicznikaTest`.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w tabeli są podziękowania. To słowa
 * ludzi skierowane do innych ludzi; zrzucenie tabeli kasuje je bez śladu,
 * a `up()` ich nie odtworzy. Na pustej tabeli rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_thanks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignUuid('thanker_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['comment_id', 'thanker_id']);
            $table->index('thanker_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE comment_thanks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('comment_thanks')) {
            $ile = DB::table('comment_thanks')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    "Nie cofam tabeli comment_thanks: {$ile} podziękowań pod komentarzami. To słowa ludzi do innych ludzi "
                    .'i up() ich nie odtworzy (D-088). CO ZROBIĆ: zrób kopię (\\copy comment_thanks TO podziekowania.csv CSV HEADER), '
                    .'uzgodnij z właścicielem, czy podziękowania mają przepaść, i dopiero wtedy usuń wiersze ręcznie.',
                );
            }
        }

        Schema::dropIfExists('comment_thanks');
    }
};
