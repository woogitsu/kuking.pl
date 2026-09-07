<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Próg podobieństwa trigramowego dla wyszukiwania i podpowiedzi.
 *
 * PO CO TA KLASA ISTNIEJE — BŁĄD, KTÓRY UDAWAŁ DZIAŁAJĄCĄ FUNKCJĘ
 * `App\Domain\Search\SearchQuery` miała stałą `SIMILARITY_THRESHOLD = 0.12`
 * z komentarzem „poniżej tego progu wyniki są już przypadkowe" — i NIGDY jej
 * nie używała. Operator `%` z pg_trgm nie przyjmuje progu jako argumentu:
 * bierze go z ustawienia sesji, którego nikt nie ustawiał, czyli
 * z domyślnego **0.3**.
 *
 * Zmierzone wprost w Postgresie na prawdziwym tytule przepisu:
 *
 *     similarity('sernik babci haliny', 'sernk')  = 0.1818
 *     'sernik babci haliny' % 'sernk'             = false
 *     SELECT set_limit(0.12);
 *     'sernik babci haliny' % 'sernk'             = true
 *
 * Czyli literówka „sernk" nie znajdowała „Sernika babci Haliny", mimo że
 * podobieństwo było POWYŻEJ udokumentowanego progu. Komentarz w kodzie
 * obiecywał odporność na literówki, a zapytanie jej nie miało.
 *
 * DLACZEGO TO BOLI WŁAŚNIE W TYM SERWISIE
 * Odporność na literówki nie jest tu wygodą, a warunkiem korzystania:
 * osoba, która wpisuje na telefonie jednym palcem, dostaje „nic nie
 * znaleźliśmy" i wyciąga wniosek, że przepisu nie ma. Nie spróbuje drugi
 * raz z inną pisownią — po prostu odejdzie.
 *
 * DLACZEGO `set_limit()`, A NIE `similarity(...) >= 0.12` W WARUNKU
 * Bo indeks trigramowy (`tags_name_trgm_idx`, `recipes_*_trgm_idx`
 * z migracji `fix_search_indexes`) obsługuje operator `%`, a nie porównanie
 * wyniku funkcji. Zamiana `%` na `similarity(...) >= ?` dałaby poprawny wynik
 * i pełny skan tabeli — czyli naprawiłaby jedną rzecz, psując drugą, o której
 * komentarz `SearchQuery` mówi wprost („PostgreSQL nie składa jednego planu
 * z kilku indeksów pod wspólnym OR").
 *
 * `set_limit()` działa na SESJI (połączeniu), więc wywołanie przed
 * zapytaniem jest tanie i idempotentne. Nie ustawiamy tego globalnie
 * w konfiguracji połączenia, bo wtedy zmiana progu wymagałaby wdrożenia
 * konfiguracji, a nie zmiany jednej liczby w jednym pliku.
 */
final class ProgPodobienstwa
{
    /**
     * Poniżej tego progu wyniki są już przypadkowe — liczba pochodzi
     * z pierwotnego komentarza `SearchQuery`, więc naprawiamy WYKONANIE
     * tamtej decyzji, a nie podejmujemy nowej.
     */
    public const PROG = 0.12;

    /**
     * Ustawia próg dla operatora `%` na czas tego połączenia.
     *
     * Cicho pomija bazy bez pg_trgm (SQLite w narzędziach pomocniczych) —
     * tam operatora `%` i tak nie ma, więc wyszukiwanie idzie inną gałęzią.
     */
    public static function ustaw(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::selectOne('SELECT set_limit(?)', [self::PROG]);
    }
}
