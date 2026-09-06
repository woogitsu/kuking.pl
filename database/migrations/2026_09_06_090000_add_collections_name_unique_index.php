<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nazwa zeszytu unikalna w obrębie jednej osoby, bez rozróżniania
 * wielkości liter (issue #43).
 *
 * CO SIĘ DZIAŁO
 * `collections` nie miało żadnego ograniczenia na `(owner_id, name)`.
 * Jedna osoba mogła założyć dwa zeszyty „Obiady” — a lista zeszytów pokazuje
 * nazwę, liczbę przepisów i widoczność. Dwa wiersze wyglądają identycznie
 * i nie da się zgadnąć, w którym jest szukany przepis. Trzeba wejść do obu.
 *
 * Zwykle nie jest to złośliwość ani pomyłka w nazewnictwie, tylko podwójne
 * wysłanie formularza: człowiek klika „Załóż zeszyt”, strona myśli chwilę,
 * klika drugi raz. Dla grupy 50+ to norma, nie wyjątek.
 *
 * DLACZEGO OGRANICZENIE STOI W BAZIE, A NIE TYLKO W WALIDACJI
 * Między `SELECT` walidatora a `INSERT`-em jest okno, w które wchodzą dwa
 * równoczesne żądania z podwójnego kliknięcia. Poza tym walidator nie
 * obowiązuje seedera, konsoli ani importu (AGENTS.md §6). Walidacja w PHP
 * jest po to, żeby człowiek dostał zdanie po polsku zamiast błędu 500 —
 * gwarancją jest indeks.
 *
 * DLACZEGO BEZ ROZRÓŻNIANIA WIELKOŚCI LITER
 * „Obiady” i „obiady” to dla człowieka ta sama nazwa; na liście różnią się
 * jedną literą, której nikt nie zauważy. Indeks jest funkcyjny — na
 * `lower(name)`, a nie na `name` — więc nazwa zostaje zapisana tak, jak ktoś
 * ją wpisał: „Na Święta” zostaje „Na Święta”. Rozróżnienie dotyczy tylko
 * tego, czy nazwa jest już zajęta. Ten sam wzorzec co
 * `2026_09_05_220000_add_username_case_insensitive_unique_index`.
 *
 * CO Z ISTNIEJĄCYMI DUPLIKATAMI: MIGRACJA PRZERYWA, NIE SPRZĄTA
 * Świadomie NIE kasujemy ani nie przemianowujemy tu niczyjego zeszytu.
 * Dwa zeszyty o tej samej nazwie to DWA RÓŻNE POJEMNIKI z różną zawartością
 * i różną widocznością — jeden może być prywatny, drugi publiczny. Skrypt nie
 * wie, który z nich jest „tym właściwym”, a scalenie ich zawartości albo
 * skasowanie młodszego jest nieodwracalne i może opublikować prywatne zapisy.
 * Dlatego migracja pokazuje kolizje i oddaje decyzję człowiekowi.
 *
 * (Inaczej niż przy `collection_items`, gdzie duplikat byłby bezspornie tą
 * samą rzeczą zapisaną dwa razy i sprzątanie byłoby bezpieczne — ale tam
 * `PRIMARY KEY (collection_id, recipe_id)` stoi od pierwszej migracji
 * i duplikat nigdy nie mógł powstać.)
 *
 * ROLLBACK
 * `DROP INDEX`. Migracja nie zmienia ani jednego wiersza, więc wycofanie
 * jest pełne i bezstratne.
 */
return new class extends Migration
{
    private const INDEX = 'collections_owner_name_lower_unique';

    public function up(): void
    {
        $kolizje = DB::table('collections')
            ->selectRaw('owner_id, lower(name) as nazwa, count(*) as ile')
            ->groupByRaw('owner_id, lower(name)')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($kolizje->isNotEmpty()) {
            $opis = $kolizje
                ->map(fn ($wiersz): string => "użytkownik {$wiersz->owner_id}: „{$wiersz->nazwa}” ({$wiersz->ile} razy)")
                ->implode('; ');

            throw new RuntimeException(
                'Są już zeszyty o powtórzonej nazwie u tej samej osoby: '.$opis.'. '
                .'Zeszyty mogą mieć różną zawartość i różną widoczność, więc migracja ich nie scala. '
                .'Zmień nazwy tak, żeby były różne, i uruchom migrację ponownie.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON collections (owner_id, lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
