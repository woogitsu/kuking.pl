<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2443 (V2): poprawianie tekstu pozycji listy zakupów bez usuwania i
 * ponownego dopisywania.
 *
 * `edited_at` NULL = tekst jest taki, jak go dopisano albo skopiowano (stan
 * domyślny, także dla wszystkich istniejących wierszy). Wartość to chwila
 * ostatniej ręcznej korekty tekstu przez właściciela listy. To trwały znacznik
 * rozróżnienia „skopiowane dosłownie z przepisu” i „skopiowane, a potem
 * poprawione na liście użytkownika” — bez porównywania z aktualnym przepisem
 * (autor mógł zmienić składniki), więc ekran nie przypisze poprawionego tekstu
 * autorowi przepisu.
 *
 * Dodanie kolumny NULL bez wartości domyślnej nie przepisuje tabeli i nie
 * wymaga CHECK-a ani indeksu (nikt nie filtruje po tej kolumnie).
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jedna pozycja ma `edited_at`.
 * Usunięcie kolumny po cichu zatarłoby informację, że tekst nie jest już
 * dosłownym składnikiem autora — ekran znów udawałby, że jest. Na świeżej bazie
 * albo bez korekt przechodzi bez pytania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->timestampTz('edited_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shopping_list_items', 'edited_at')) {
            return;
        }

        $poprawione = DB::table('shopping_list_items')->whereNotNull('edited_at')->count();
        if ($poprawione > 0) {
            throw new RuntimeException(
                "Cofnięcie tej migracji zatarłoby informację o poprawionym tekście przy {$poprawione} pozycjach list zakupów ludzi. "
                .'Jeśli naprawdę trzeba: zrób kopię (pg_dump -t shopping_list_items), '
                .'wyczyść kolumnę ręcznie (UPDATE shopping_list_items SET edited_at = NULL) i uruchom rollback jeszcze raz.',
            );
        }

        Schema::table('shopping_list_items', function (Blueprint $table): void {
            $table->dropColumn('edited_at');
        });
    }
};
