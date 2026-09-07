<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klucz wysłania dla zgłoszeń BEZ KONTA (DSA art. 16 ust. 2 lit. c).
 *
 * Decyzja właściciela z 7 września 2026, ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` — pytanie P4, krok 4 z §8.1.
 *
 * PO CO OSOBNA OCHRONA, SKORO JEST JUŻ `reports_one_open_per_pair`
 * Bo tamten indeks tych wierszy NIE WIDZI i to jest zmierzone (ADR §1.4.3,
 * POMIAR 3f): przy zgłoszeniu bez konta `reporter_id` jest `NULL`, a warunek
 * indeksu wymaga `reporter_id IS NOT NULL`. Dwa anonimowe zgłoszenia tego
 * samego celu przechodziły. Nie da się tego naprawić, poszerzając tamten
 * indeks: bez konta nie ma czym zidentyfikować zgłaszającego, a dwa
 * zgłoszenia tej samej treści od DWÓCH różnych osób muszą przejść — każdej
 * należy się osobna odpowiedź (`ZglosNielegalnaTresc`, „NIE SCALAMY
 * DUPLIKATÓW").
 *
 * DLACZEGO WŁAŚNIE TU, A NIE „to tylko droga brzegowa"
 * To są zgłoszenia o najwyższej stawce w całym serwisie: prawnik, rodzic,
 * osoba, która rozpoznała siebie na cudzym zdjęciu. Duplikat tworzy DRUGĄ
 * sprawę moderacyjną z własnym terminem odpowiedzi z DSA art. 16 i drugą
 * decyzją do wydania tam, gdzie sprawa jest jedna — przy zespole moderacji
 * liczącym 1-2 osoby (D-012).
 *
 * INDEKS JEST NA SAMYM KLUCZU, nie na parze (osoba, klucz) jak w `posts`
 * i `cooked_events` — bo przy zgłoszeniu bez konta nie ma kolumny, która
 * mówiłaby, kto to wysłał. Konsekwencja jest obsłużona w kodzie, nie
 * przemilczana: gdy `INSERT` odbije się o klucz, którego wiersza nie da się
 * przypisać do TEGO zgłaszającego, akcja zapisuje zgłoszenie BEZ klucza
 * (zawodzenie otwarte, ADR §4.3) — nigdy nie oddaje cudzego wiersza ani
 * cudzego numeru sprawy. Pilnuje tego `IdempotencjaZgloszeniaTest::
 * test_klucz_z_cudzego_formularza_nie_pokazuje_cudzej_sprawy`.
 *
 * `NULL` I INDEKS CZĘŚCIOWY: zgłoszenia społecznościowe (`reports.store`)
 * klucza nie wysyłają — chroni je `reports_one_open_per_pair` plus dedup
 * w PHP, zmierzony jako skuteczny w zwykłym ruchu (POMIAR 3). Ich wiersze
 * mają `klucz_wyslania` `NULL` i zostają poza tym indeksem.
 *
 * ROLLBACK: `DROP INDEX`, potem `DROP COLUMN`. Bezstratnie i dlatego `down()`
 * niczego nie odmawia — w kolumnie nie ma ani jednego słowa napisanego przez
 * człowieka, a samo zgłoszenie (adres, uzasadnienie, dobra wiara, numer
 * sprawy) zostaje nietknięte. To jest inna sytuacja niż
 * `collection_items_accept_posts`, gdzie cofnięcie kasowałoby wiersze,
 * których nikt by nie odtworzył.
 */
return new class extends Migration
{
    private const INDEKS = 'reports_one_per_klucz_wyslania';

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->uuid('klucz_wyslania')->nullable()->after('reporter_id');
        });

        if ($this->isPostgres()) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEKS.' ON reports (klucz_wyslania) '
                .'WHERE klucz_wyslania IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('klucz_wyslania');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
