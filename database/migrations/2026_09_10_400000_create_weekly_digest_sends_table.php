<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `weekly_digest_sends` — trwały klucz idempotencji tygodniowego
 * podsumowania: JEDEN list na parę (osoba, tydzień), pilnowany przez bazę
 * (audyt 10.09.2026 QUEUE-01 / MAIL-02 / RACE-04, `docs/DECISIONS.md` D-077).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO BYŁO ZEPSUTE — KOLEJNOŚĆ ZDARZEŃ, NIE BRAK SPRAWDZENIA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `WyslijPodsumowaniaTygodnia` robiło dla każdej osoby w pętli:
 *
 *   1. `Mail::to(...)->queue($list)`  ← SKUTEK ZEWNĘTRZNY JUŻ SIĘ STAŁ,
 *   2. zajęcie miejsca w dobowym budżecie,
 *   3. sygnał `weekly_digest_sent`,
 *   4. dopisanie osoby do tablicy `$wyslane`,
 *
 * a znacznik `users.weekly_digest_sent_at` stawiał JEDNYM zapytaniem
 * **po całej pętli**. Awaria po zakolejkowaniu N wiadomości, ale przed tym
 * zbiorczym zapisem, zostawiała N listów w kolejce i ZERO śladu w bazie —
 * następny przebieg kwalifikował te same osoby jeszcze raz i pisał do nich
 * drugi raz.
 *
 * `withoutOverlapping()` z harmonogramu tego nie łapie i nigdy nie łapało:
 * chroni przed dwoma przebiegami JEDNOCZEŚNIE, a nie przed kolejnym
 * przebiegiem PO awarii.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OGRANICZENIE W BAZIE, A NIE `exists()` W PHP
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo sprawdzenie w PHP jest ODCZYTEM, po którym następuje zapis, a między
 * nimi jest luka. Dwa przebiegi na dwóch kontenerach (albo ręczny przebieg
 * właściciela obok harmonogramu, gdy blokada `withoutOverlapping()` już
 * wygasła) przechodzą oba przez ten sam `SELECT`, oba widzą „jeszcze nie
 * wysłano" i oba wysyłają. `UNIQUE` nie ma tej luki: drugi `INSERT` odbija
 * się w bazie, niezależnie od tego, co akurat myśli PHP.
 *
 * Sprawdzenie w PHP zostaje — ale jako sposób na ŁADNE zachowanie
 * (pominięcie osoby bez błędu i bez listu), nie jako gwarancja.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KLUCZ TO (OSOBA, PONIEDZIAŁEK TYGODNIA) — I DLACZEGO WŁAŚNIE TO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `week_start` jest DATĄ PONIEDZIAŁKU liczoną w strefie człowieka
 * (`App\Support\Czas::poczatekTygodniaData()`), nie numerem tygodnia ISO.
 * Numer tygodnia nie jest sam z siebie identyfikatorem — `2026-12-28` należy
 * do tygodnia 1 **roku 2027**, więc numer wymagałby pary (rok ISO, tydzień)
 * i pierwszego dnia, w którym ktoś zapisze ją niepełną, klucz przestaje być
 * unikalny. Data poniedziałku to jedna kolumna `date`: porównywalna,
 * sortowalna, czytelna w zrzucie bazy i zgodna z tym, co w PostgreSQL znaczy
 * `date_trunc('week', …)`.
 *
 * Strefa jest istotna, a nie ozdobna: poniedziałek UTC zaczyna się w Polsce
 * w niedzielę o 22:00. Bez `Czas` przebieg uruchomiony w poniedziałek nad
 * ranem trafiałby do tygodnia POPRZEDNIEGO, czyli do klucza, który dla
 * części osób jest już zajęty — i zamiast bariery dostalibyśmy pominięcie.
 *
 * KALENDARZOWY TYDZIEŃ NIKOGO NIE OPÓŹNIA. Odstęp siedmiu dni
 * (`kuking.digest.odstep_dni`) i tydzień kalendarzowy nie kłócą się, bo dzień
 * `x` i dzień `x + 7` zawsze mają różne poniedziałki: kto dostał list
 * w sobotę, dostanie następny najwcześniej w kolejną sobotę, a to jest już
 * inny tydzień i inny wiersz. Dwie warstwy razem dają zdanie mocniejsze niż
 * każda z osobna: **najwyżej jeden list na tydzień kalendarzowy i nie
 * częściej niż raz na siedem dni.**
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST W SCHEMACIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * KLUCZ GŁÓWNY JEST ZŁOŻONY I TO JEST CAŁA TABELA. Para (osoba, tydzień)
 * jest tożsamością wiersza, więc nie ma tu sztucznego `id`: dodatkowa
 * kolumna UUID byłaby drugim identyfikatorem czegoś, co ma już swój własny,
 * i pozwoliłaby wstawić dwa wiersze różniące się wyłącznie nią, gdyby ktoś
 * kiedyś zdjął `UNIQUE`. Klucz główny jest w PostgreSQL indeksem unikalnym,
 * więc `UNIQUE (user_id, week_start)` z zadania jest tu spełniony.
 *
 * CHECK „to musi być poniedziałek" nie jest ozdobą. Gdyby jakiś przyszły
 * wywołujący podał datę środy, klucz przestałby oznaczać tydzień i ta sama
 * osoba miałaby w jednym tygodniu dwa różne, oba wolne klucze — czyli dwa
 * listy, przy zachowanym `UNIQUE`. Bariera bez tego CHECK-a broni się sama
 * przed powtórzeniem, ale nie przed pomyłką w kluczu.
 *
 * `ON DELETE CASCADE` jest drugą linią, nie pierwszą: kont z Kuking się nie
 * KASUJE, tylko anonimizuje (D-022), więc kaskada zadziała najwyżej przy
 * czyszczeniu bazy testowej albo przy ręcznym usunięciu wiersza.
 *
 * CZEGO TU ŚWIADOMIE NIE MA
 * Ani treści listu, ani adresu, ani liczników — wiersz mówi wyłącznie
 * „ta osoba ma ten tydzień obsłużony". To jest ta sama klasa faktu co
 * `users.weekly_digest_sent_at`, więc nie dokłada do bazy nowej kategorii
 * danych osobowych. Nie ma też retencji ani komendy sprzątającej: przy
 * przepustowości 420 osób tygodniowo (D-057) to jakieś 22 tysiące wierszy
 * po dwóch kolumnach na rok, a wiersz starszy niż tydzień nadal odpowiada
 * na pytanie „czy do tej osoby kiedykolwiek poszedł list za tamten tydzień".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — CO SIĘ DZIEJE PO WYCOFANIU
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` kasuje tabelę. Nie ginie ani jedno słowo od człowieka i nie ginie
 * pamięć o wysyłce (ta jest też w `users.weekly_digest_sent_at`) — ale GINIE
 * BARIERA. Trzeba to nazwać wprost, bo `down()` nie ma prawa cicho
 * przywracać stanu groźnego: po wycofaniu jedyną ochroną przed drugim listem
 * zostaje porównanie w PHP, czyli dokładnie ten mechanizm, którego luka jest
 * powodem tej migracji.
 *
 * Dlatego rollback robi się WYŁĄCZNIE razem z `KUKING_DIGEST_WLACZONY=false`,
 * nigdy „przy okazji" innej zmiany.
 *
 * KOLEJNOŚĆ: NAJPIERW KOD, POTEM MIGRACJA. Kod po tej zmianie wstawia wiersz
 * rezerwacji przed każdym listem — bez tabeli komenda pada na pierwszej
 * osobie i nie wysyła NIKOMU nic (kierunek awarii bezpieczny, ale wysyłka
 * staje). Wycofanie samej migracji przy nowym kodzie jest więc wyłączeniem
 * digestu okrężną drogą; jeśli o to chodzi, właściwym narzędziem jest
 * zmienna środowiskowa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_digest_sends', function (Blueprint $table): void {
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Poniedziałek tygodnia, za który poszedł list — patrz komentarz
            // wyżej, dlaczego data, a nie numer tygodnia ISO.
            $table->date('week_start');

            // Kiedy zajęto klucz. Nie „kiedy list doszedł" — tego Kuking nie
            // wie i nie ma się dowiadywać (`docs/decyzje/POCZTA.md` §5 pkt 6,
            // otwarta sprawa #204: żadnego śledzenia doręczeń i otwarć).
            $table->timestampTz('reserved_at')->useCurrent();

            $table->primary(['user_id', 'week_start']);
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE weekly_digest_sends
            ADD CONSTRAINT weekly_digest_sends_week_start_monday_check
            CHECK (extract(isodow from week_start) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_digest_sends');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
