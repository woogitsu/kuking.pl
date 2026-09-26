<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * „Ukryj wartości odżywcze w moim przepisie” (V2, D-299).
 *
 * `recipes.pokazuj_wartosci_odzywcze boolean NOT NULL DEFAULT true` —
 * domyślnie sekcja jest widoczna (decyzja właściciela z 26.09.2026), autor
 * może ją ukryć przy swoim przepisie jednym przyciskiem na stronie przepisu.
 *
 * `ADD COLUMN … NOT NULL DEFAULT <stała>` na PostgreSQL 11+ nie przepisuje
 * tabeli — zmienia tylko katalog, więc na gorącej tabeli `recipes` trwa
 * chwilę i mieści się w `lock_timeout` (AGENTS.md §6).
 *
 * WYCOFANIE ODMAWIA, GDY KTOŚ SEKCJĘ UKRYŁ (D-088). `false` jest decyzją
 * autora o tym, co pokazuje jego przepis. Po cofnięciu i ponownym `migrate`
 * kolumna wróciłaby z `DEFAULT true`, czyli sekcja pojawiłaby się sama pod
 * przepisem kogoś, kto ją świadomie schował. Przy samych wartościach
 * domyślnych (i na świeżej bazie) cofnięcie przechodzi bez pytania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->boolean('pokazuj_wartosci_odzywcze')->default(true);
        });
    }

    public function down(): void
    {
        // Strażnik PRZED `dropColumn` — po zdjęciu kolumny nie ma czego policzyć.
        $ukryte = DB::table('recipes')->where('pokazuj_wartosci_odzywcze', false)->count();

        if ($ukryte > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba przepisów z ukrytymi wartościami odżywczymi '.
                '(pokazuj_wartosci_odzywcze = false): '.$ukryte.'. To decyzja autora o tym, '.
                'co pokazuje jego przepis. Kolejny `migrate` odtworzyłby kolumnę z DEFAULT true, '.
                "czyli sekcja wróciłaby sama pod te przepisy (D-088, D-299).\n\n".
                "CO ZROBIĆ:\n".
                '  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '.
                "kolumny nie czyta i działa z nią bez zmian;\n".
                "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę PRZED cofnięciem:\n".
                "      SELECT id FROM recipes WHERE pokazuj_wartosci_odzywcze = false;\n".
                '    i po powrocie na tę wersję schematu odtwórz ją tym samym UPDATE.',
            );
        }

        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('pokazuj_wartosci_odzywcze');
        });
    }
};
