<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tagi promowane — lista gospodarza (D-021, decyzja właściciela z 2026-09-07,
 * druga część: „tag promowany — lista gospodarza").
 *
 * PO CO TO ISTNIEJE
 * Tematy, które ta funkcja zastępuje, niosły trzy rzeczy, których SAME
 * otwarte tagi nie niosą (`docs/product/COLD_START.md`):
 *
 *   1. gwarancję, że nowe konto nie widzi pustki (onboarding + feed tematów),
 *   2. „temat tygodnia" ogłaszany przez gospodarza,
 *   3. przygotowane tematy sezonowe (Wigilia, tłusty czwartek, Wielkanoc),
 *      planowane z wyprzedzeniem.
 *
 * Tag promowany przywraca te trzy rzeczy BEZ drugiego typu obiektu w
 * interfejsie: to jest zwykły wiersz w `tags`, który dodatkowo ma wiersz
 * tutaj. Usunięcie promocji nie kasuje tagu — tag żyje dalej jako zwykły,
 * otwarty tag, dokładnie tak jak wycofanie Tematu nie kasowało wpisów, które
 * go miały.
 *
 * DLACZEGO OSOBNA TABELA, A NIE KOLUMNY NA `tags`
 * Promocja niesie DWIE rzeczy, których zwykły tag nie ma i nie potrzebuje:
 * kolejność redakcyjną (`position`, dokładnie jak `topics.position`) i
 * opcjonalne jedno zdanie od gospodarza (`note`, dokładnie jak
 * `topics.description`). Trzymanie ich jako nullable kolumn na `tags`
 * zaśmiecałoby wiersz każdego zwykłego tagu dwoma polami, które ma sens
 * wypełnić dla garstki promowanych — a już samo query „lista promowanych
 * w kolejności" byłoby wtedy `WHERE is_promoted AND position IS NOT NULL`
 * zamiast czystego JOIN-a. Osobna tabela też czyni pytanie „czy ten tag jest
 * promowany" odpowiedzią na istnienie wiersza, nie na wartość flagi —
 * ten sam kształt co reszta relacji w tym pliku (`tag_follows`, `post_tags`).
 *
 * DLACZEGO `tag_id` JEST KLUCZEM GŁÓWNYM, A NIE OSOBNE `id`
 * Promocja jest atrybutem TAGU, nie samodzielną encją z własnym cyklem
 * życia — jeden tag ma najwyżej jedną promocję. Ten sam wzorzec co
 * `profiles.user_id` (`$table->foreignUuid('user_id')->primary()`):
 * klucz główny na kolumnie FK zamiast surogatu, bo relacja jest 1:1
 * i nie ma dnia, w którym potrzebny byłby drugi wiersz promocji tego
 * samego tagu.
 *
 * KTO MOŻE TO ZMIENIAĆ
 * Panel prowadzi `App\Http\Controllers\Admin\TagPromotionController`, za tą
 * samą bramką co tablica „kuKINGi na dziś" (`Gate` `moderate` na `User`) —
 * to jest ten sam rodzaj decyzji redakcyjnej gospodarza, nie osobny poziom
 * uprawnień do wymyślania od nowa. „Kto i kiedy" zmienił listę zapisuje
 * istniejący `audit_log` (`AuditLogEntry::record('tag_promotion...')`) —
 * bez dokładania tu kolumny `promoted_by`, z tego samego powodu, dla którego
 * R1 §7 odradza dokładanie `tag_merge_suggestions` na wyrost: `audit_log`
 * już rozwiązuje to pytanie dla każdej innej decyzji redakcyjnej w tym
 * serwisie (np. `daily_board.updated`).
 *
 * CZEGO TU ŚWIADOMIE NIE MA (i dlaczego to nie jest przeoczenie)
 * „Opiekun tagu" — nazwana osoba prowadząca temat, odpowiednik „ambasadora"
 * z `docs/product/COLD_START.md` — NIE wchodzi do tej migracji. To wymagałoby
 * dodatkowej kolumny (`curator_id` wskazujący na `users`, jak przy
 * `daily_picks.curator_id`) i ekranu do przypisywania osób, a właściciel
 * jeszcze tego nie zamówił jako część tego zadania. Dołożenie tej kolumny
 * później jest migracją dodającą nullable FK — nie łamie niczego istniejącego.
 *
 * ROLLBACK: `DROP TABLE tag_promotions` bez zastrzeżeń — to jest wybór
 * redakcyjny, odtwarzalny ręcznie przez gospodarza w minutę, nie treść
 * użytkownika.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_promotions', function (Blueprint $table): void {
            $table->foreignUuid('tag_id')->primary()->constrained('tags')->cascadeOnDelete();

            // Kolejność na liście — dokładnie `topics.position`. Alfabetyczna
            // postawiłaby przypadkowy tag na górze; ta liczba jest wyborem
            // gospodarza, nie właściwością tagu.
            $table->unsignedSmallInteger('position')->default(0);

            // Jedno zdanie od gospodarza, np. „Temat tygodnia: rozgrzewające
            // zupy na jesień" — dokładnie `topics.description`. Opcjonalne:
            // promocja bez notatki nadal ma sens (sama obecność na liście
            // już coś mówi).
            $table->string('note', 200)->nullable();

            $table->timestampsTz();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE tag_promotions ADD CONSTRAINT tag_promotions_position_check CHECK (position >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_promotions');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
