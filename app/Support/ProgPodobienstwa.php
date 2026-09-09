<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Próg podobieństwa dla wyszukiwarki i podpowiedzi tagów — JEDNO miejsce,
 * JEDEN operator, JEDNA liczba.
 *
 * CO TU BYŁO WCZEŚNIEJ I DLACZEGO TO ZMIENIONO (issue #187)
 * Do 9 września 2026 ta klasa ustawiała `set_limit(0.12)` dla operatora `%`.
 * Operator `%` pyta o podobieństwo frazy do CAŁEGO tekstu, więc żeby literówka
 * w długim tytule („sernk" wobec „sernik babci haliny" = 0,18) w ogóle miała
 * szansę, próg musiał zjechać do 0,12. Cena była podwójna i zmierzona:
 *
 *   - TRAFNOŚĆ: przy 0,12 długa fraza jest podobna do prawie wszystkiego.
 *     Na bazie 40 000 przepisów, w której nie ma ANI JEDNEJ sajgonki, fraza
 *     „sajgonki z krewetkami" zwracała 1 526 „wyników" (m.in. „Knedle ze
 *     śliwkami", „Smalec ze skwarkami"). „rosół" znajdował „Rogaliki",
 *     „barszcz" znajdował „Bogracz", „pierogi" znajdowało „Piernik".
 *   - KOSZT: indeks GIN dla `%` jest stratny, więc przy tak niskim progu
 *     oddawał na typową frazę kilkanaście do dwudziestu tysięcy kandydatów
 *     z 40 000 wierszy (zmierzone: „żurek" 20 450, „pierogi" 18 178,
 *     „sernik" 12 765), a każdego z nich baza sprawdzała po raz drugi
 *     na wierszu tabeli i odrzucała 85–95%.
 *
 * DZIŚ: OPERATOR `<%` (`word_similarity`), PRÓG 0,5
 * `fraza <% tekst` pyta o podobieństwo frazy do NAJLEPIEJ PASUJĄCEGO
 * fragmentu tekstu, a nie do całości. Dzięki temu długość tytułu przestaje
 * karać trafienie: „sernk" wobec „sernik babci haliny" to 0,67 zamiast 0,18.
 * Próg może więc być wysoki, a wysoki próg wycina śmieci.
 *
 * DLACZEGO 0,5, A NIE DOMYŚLNE 0,6 — TO JEST ZMIERZONE, NIE PRZYJĘTE
 * Przy 0,6 (wartość domyślna PostgreSQL i ta z issue #187) z wyszukiwarki
 * znikają trafienia, których nikt nie chciał stracić — na bazie pomiarowej
 * 40 000 przepisów:
 *
 *     fraza      word_similarity   0,6      0,5
 *     „rosul"    0,50 do „rosół"  0 wyników   782 wyniki
 *     „piergi"   0,57 do „pierogi" 0 wyników  1 313 wyników
 *     „kotlet schabowy z ziemniakami"   45 wyników   232 wyniki
 *     „pierogi ruskie babci haliny"      9 wyników   246 wyników
 *
 * Przy 0,5 kanarek z issue #187 dalej milczy: „sajgonki z krewetkami" = 0
 * wyników, „kartacze" = 0, „tortilla z kurczakiem" = 0.
 *
 * CO 0,5 NADAL GUBI (świadomie, zapisane, żeby nikt tego nie odkrywał drugi raz)
 * Ciężką literówkę fonetyczną: „gołombki" wobec „gołąbki" to 0,417, czyli
 * poniżej progu — dziś ta fraza nie znajduje nic (przy `%` 0,12 znajdowała
 * 263 gołąbki i 248 śmieci). Zejście do 0,4 odzyskuje gołąbki, ale wraca
 * z nimi śmieć: „pierogi" dostaje wtedy 4 024 obce wiersze zamiast 760,
 * a „tortilla z kurczakiem" znów coś znajduje. Bilans wychodzi na minus,
 * więc próg zostaje na 0,5 — ale to jest JEDNA liczba w JEDNYM pliku
 * i właściciel może ją przesunąć bez ruszania zapytań.
 *
 * ⚠️ 0,5 LEŻY DOKŁADNIE NA GRANICY DLA „rosul" (word_similarity = 0,5000).
 * Zmierzone na PostgreSQL 16.13: `<%` porównuje `>=`, mimo że dokumentacja
 * mówi „greater than", więc trafienie o wartości równej progowi WCHODZI.
 * Gdyby to się kiedyś zmieniło, „rosuł" przestanie znajdować rosół —
 * i właśnie po to jest `TrafnoscWyszukiwarkiTest`.
 *
 * DLACZEGO USTAWIENIE SESJI, A NIE `word_similarity(...) >= 0.5` W WARUNKU
 * Bo indeksy GIN (`recipes_title_trgm_idx`, `tags_name_trgm_idx`) obsługują
 * operator `<%` (przez komutator `%>`), a nie porównanie wyniku funkcji.
 * Zamiana operatora na porównanie dałaby ten sam wynik i pełny skan tabeli.
 * Żaden nowy indeks nie był potrzebny: `gin_trgm_ops` obsługuje `%` i `<%`
 * tym samym indeksem, więc issue #187 nie ruszyło schematu bazy.
 *
 * Ustawienie działa na SESJI (połączeniu), więc wywołanie przed zapytaniem
 * jest tanie i idempotentne. Nie ustawiamy tego w konfiguracji połączenia,
 * bo wtedy zmiana progu wymagałaby wdrożenia konfiguracji, a nie zmiany
 * jednej liczby w jednym pliku.
 *
 * `set_limit()` (próg operatora `%`) nie jest już ustawiany, bo operatora `%`
 * nie ma w kodzie — pilnuje tego `TrafnoscWyszukiwarkiTest`. Dwa progi w dwóch
 * miejscach to dokładnie ta klasa rozjazdu, którą to repozytorium łapało już
 * kilka razy.
 */
final class ProgPodobienstwa
{
    /**
     * Próg dla operatora `<%` (`pg_trgm.word_similarity_threshold`).
     *
     * Uzasadnienie liczby i pełny pomiar: komentarz klasy oraz
     * `docs/research/WYDAJNOSC.md` §3.4b.
     */
    public const PROG = 0.5;

    /**
     * Ustawia próg dla operatora `<%` na czas tego połączenia.
     *
     * Cicho pomija bazy bez pg_trgm (SQLite w narzędziach pomocniczych) —
     * tam operatora `<%` i tak nie ma, więc wyszukiwanie idzie inną gałęzią.
     */
    public static function ustaw(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // `set_config`, a nie `SET ... = ?`: parametru nie da się podstawić
        // w poleceniu `SET`, a sklejanie liczby ze stałej w SQL to nawyk,
        // którego nie chcemy mieć w repozytorium.
        DB::selectOne(
            "SELECT set_config('pg_trgm.word_similarity_threshold', ?, false)",
            [(string) self::PROG],
        );
    }
}
