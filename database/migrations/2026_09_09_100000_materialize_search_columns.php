<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Znormalizowany tekst leży w KOLUMNIE, a nie jest liczony przy każdym
 * porównaniu (issue #116).
 *
 * PROBLEM, KTÓRY TO ROZWIĄZUJE — ZMIERZONY, NIE ZGADYWANY
 * Indeksy trigramowe z migracji `2026_09_05_001300` stoją na WYRAŻENIU
 * `kuking_normalize(kolumna)`. Zapytanie pyta o dokładnie to samo wyrażenie,
 * więc indeks JEST używany — to działa i zostaje.
 *
 * Problem jest o krok dalej, w RECHECKU. Indeks GIN dla operatora `%` jest
 * stratny: oddaje kandydatów, a PostgreSQL musi każdego z nich sprawdzić
 * jeszcze raz, już na wierszu z tabeli. Przy progu podobieństwa 0,12
 * (`App\Support\ProgPodobienstwa`) kandydatów jest bardzo dużo — na bazie
 * 40 000 przepisów indeks oddawał ich 14 000–24 000, czyli 35–60% tabeli —
 * a każdy recheck wołał `kuking_normalize()`, czyli `unaccent()` po słowniku,
 * OD NOWA. To samo dzieje się w `ORDER BY similarity(kuking_normalize(...))`.
 *
 * Zmierzone (PostgreSQL 16.13, 10 000 kont / 40 000 przepisów / 80 000 wpisów,
 * bufory ciepłe, mediana z 5 przebiegów `EXPLAIN (ANALYZE, BUFFERS)`,
 * fraza „pierogi", sama gałąź trigramowa):
 *
 *     kuking_normalize(title) % 'pierogi'   153,9 ms
 *     title_search            % 'pierogi'    81,4 ms
 *
 * Ten sam zbiór kandydatów (17 644), ten sam wynik (1 783 wiersze) — różnicę
 * robi wyłącznie to, że recheck czyta gotowy tekst zamiast go liczyć.
 * Całe zapytanie wyszukiwarki: 169,7 ms → 59,1 ms.
 *
 * DLACZEGO KOLUMNA GENEROWANA, A NIE ZWYKŁA + TRIGGER
 * `GENERATED ALWAYS AS (...) STORED` nie da się rozjechać z kolumną źródłową:
 * nie ma drogi zapisu, którą można by ominąć. Trigger da się wyłączyć,
 * a `UPDATE ... SET title = ...` z pominięciem triggera zostawiłby
 * wyszukiwarkę szukającą po starym tytule — czyli usterkę widoczną dopiero
 * wtedy, gdy ktoś nie znajdzie własnego przepisu.
 *
 * DLACZEGO `public.kuking_normalize`, A NIE `kuking_normalize`
 * Ta sama pułapka, którą opisuje migracja `2026_09_05_001300`: od PostgreSQL 17
 * operacje utrzymaniowe chodzą z ograniczonym `search_path`. Wyrażenie kolumny
 * generowanej jest przechowywane po rozwiązaniu nazw, ale `ALTER TABLE`
 * przy przebudowie tabeli i tak je re-parsuje. Kwalifikujemy schematem,
 * bo to nic nie kosztuje, a bez tego błąd wychodzi dopiero na produkcyjnej
 * wersji bazy.
 *
 * CZEGO TA MIGRACJA NIE ZMIENIA
 * Wyniku wyszukiwania. `kuking_normalize()` jest `IMMUTABLE`, więc kolumna
 * zawiera dokładnie to, co dotąd liczyło wyrażenie — to jest zmiana KOSZTU,
 * nie znaczenia. Zbiór trafień i ich kolejność są identyczne
 * (`SearchTest`, `WyszukiwarkaWidocznoscTest` przechodzą bez poprawki).
 *
 * ROLLBACK
 * `down()` zdejmuje indeksy na kolumnach, kasuje kolumny i odtwarza indeksy
 * na wyrażeniu — czyli dokładny stan sprzed migracji. Bezstratny: kolumny są
 * wyliczone z danych, które zostają, więc nie ma czego stracić. Kosztuje
 * przepisanie trzech tabel (`ALTER TABLE` z kolumną `STORED` przepisuje
 * tabelę pod `ACCESS EXCLUSIVE`), tak samo jak `up()`.
 *
 * RYZYKO WDROŻENIA
 * `up()` przepisuje `recipes`, `recipe_ingredients` i `profiles` pod blokadą
 * wyłączną. Na zmierzonych 40 000 / 80 000 / 10 000 wierszy trwało to łącznie
 * ~3 s. Przy tabelach o rząd wielkości większych trzeba to robić oknem
 * serwisowym albo `ADD COLUMN` bez `STORED` + backfill — dziś nie ma po co,
 * bo produkcja jest mniejsza niż baza pomiarowa.
 *
 * ⚠️ Ta sama konsekwencja co przy indeksach na wyrażeniu, tylko droższa:
 * podmiana słownika `unaccent` wymaga nie `REINDEX`, lecz przeliczenia kolumn
 * (`DROP EXPRESSION` + ponowne `ADD`). Nie robimy tego.
 */
return new class extends Migration
{
    /**
     * Kolumna generowana → wyrażenie, z którego się liczy.
     *
     * @var array<string, array<string, string>>
     */
    private const KOLUMNY = [
        'recipes' => [
            'title_search' => 'public.kuking_normalize(title)',
            'summary_search' => "public.kuking_normalize(coalesce(summary, ''))",
        ],
        'recipe_ingredients' => [
            'ingredient_text_search' => 'public.kuking_normalize(ingredient_text)',
        ],
        'profiles' => [
            'display_name_search' => 'public.kuking_normalize(display_name)',
            'username_search' => 'public.kuking_normalize(username)',
            'speciality_search' => "public.kuking_normalize(coalesce(speciality, ''))",
        ],
    ];

    /**
     * Nazwa indeksu → [tabela, kolumna generowana, wyrażenie sprzed migracji].
     *
     * Nazwy indeksów zostają TE SAME. Opisują to samo („trigram po tytule"),
     * a zmiana nazwy wymusiłaby poprawkę w każdym runbooku i dokumencie,
     * który je wymienia — bez żadnego zysku.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const INDEKSY = [
        'recipes_title_trgm_idx' => ['recipes', 'title_search', 'kuking_normalize(title)'],
        'recipes_summary_trgm_idx' => ['recipes', 'summary_search', "kuking_normalize(coalesce(summary, ''))"],
        'recipe_ingredients_text_trgm_idx' => ['recipe_ingredients', 'ingredient_text_search', 'kuking_normalize(ingredient_text)'],
        'profiles_display_name_trgm_idx' => ['profiles', 'display_name_search', 'kuking_normalize(display_name)'],
        'profiles_username_trgm_idx' => ['profiles', 'username_search', 'kuking_normalize(username)'],
        'profiles_speciality_trgm_idx' => ['profiles', 'speciality_search', "kuking_normalize(coalesce(speciality, ''))"],
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (self::KOLUMNY as $tabela => $kolumny) {
            foreach ($kolumny as $kolumna => $wyrazenie) {
                if (Schema::hasColumn($tabela, $kolumna)) {
                    continue;
                }

                DB::statement(
                    "ALTER TABLE {$tabela} ADD COLUMN {$kolumna} text GENERATED ALWAYS AS ({$wyrazenie}) STORED",
                );
            }
        }

        foreach (self::INDEKSY as $indeks => [$tabela, $kolumna, $_]) {
            DB::statement("DROP INDEX IF EXISTS {$indeks}");
            DB::statement("CREATE INDEX {$indeks} ON {$tabela} USING gin ({$kolumna} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (self::INDEKSY as $indeks => [$tabela, $_, $wyrazenie]) {
            DB::statement("DROP INDEX IF EXISTS {$indeks}");
            DB::statement("CREATE INDEX {$indeks} ON {$tabela} USING gin ({$wyrazenie} gin_trgm_ops)");
        }

        foreach (self::KOLUMNY as $tabela => $kolumny) {
            foreach (array_keys($kolumny) as $kolumna) {
                DB::statement("ALTER TABLE {$tabela} DROP COLUMN IF EXISTS {$kolumna}");
            }
        }
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
