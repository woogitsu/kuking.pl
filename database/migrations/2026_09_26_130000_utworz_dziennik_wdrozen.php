<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DZIENNIK WDROŻEŃ — numer wersji z końcówką, np. „Alfa 0.68.005"
 * (issue #1932, decyzja D-318).
 *
 * PO CO TO JEST
 * `App\Support\Wersja::etykieta()` („Alfa 0.68") podbija się ręcznie, przy
 * większych zmianach — stoi tygodniami bez ruchu. Między dwoma podbiciami
 * ląduje na produkcji po kilkanaście wdrożeń dziennie, a stopka i strona
 * „Co nowego" (#1909) nie mają jak ich rozróżnić: dwie różne rzeczy
 * pokazane w tym samym dniu wyglądają jak ta sama wersja. Brakującą
 * końcówkę (`.NNN`) miał dać licznik z historii gita, ale build Railwaya
 * może mieć płytki klon (`git rev-list --count` liczyłby wtedy nie to, co
 * trzeba) — więc licznik siedzi w bazie, nie w gicie.
 *
 * DWIE TABELE, DWA RÓŻNE PYTANIA
 *
 *   `wdrozenia`          — „które wdrożenie to było": jeden wiersz na
 *                          KAŻDY commit, który realnie trafił na produkcję
 *                          pod daną etykietą. `numer` rośnie o 1 przy
 *                          każdym NOWYM commicie (nie przy każdym starcie
 *                          kontenera — replika, która tylko się
 *                          restartuje, nie ma nowego SHA).
 *
 *   `wdrozenia_funkcje`  — „pod jakim numerem funkcja pojawiła się
 *                          PIERWSZY RAZ": jeden wiersz na KAŻDY nagłówek
 *                          `###` z sekcji „## Najnowsze zmiany" pliku
 *                          `resources/nowosci/tresc.md`, zapisywany przy
 *                          TYM SAMYM przebiegu komendy, która dopisuje
 *                          wiersz do `wdrozenia`. Strona „Co nowego"
 *                          pokazuje przy takim nagłówku „od Alfa 0.68.NNN".
 *
 * BEZPIECZEŃSTWO PRZY RÓWNOLEGŁYM STARCIE
 * `numer` NIE jest `AUTO_INCREMENT` globalnym — jest policzony jako
 * `MAX(numer) WHERE etykieta = ?) + 1`, więc musi być liczony pod blokadą.
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie` bierze
 * `pg_advisory_xact_lock(hashtext(etykieta))` na całą transakcję — dwa
 * równoległe starty (np. redeploy uruchomiony tuż po poprzednim) nie mogą
 * dać tego samego numeru: drugi czeka, aż pierwszy zatwierdzi transakcję,
 * i dopiero wtedy liczy `MAX` na nowo. Test współbieżności na dwóch
 * połączeniach: `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`.
 *
 * `UNIQUE (commit)` daje IDEMPOTENCJĘ z drugiej strony: powtórne wdrożenie
 * TEGO SAMEGO commita (np. redeploy bez zmiany kodu) nie zakłada drugiego
 * wiersza i nie zużywa kolejnego numeru — `ON CONFLICT (commit) DO NOTHING`
 * w akcji czyta to jako „już zarejestrowane" i kończy bez błędu.
 *
 * `UNIQUE (etykieta, numer)` w `wdrozenia` i `UNIQUE (etykieta, naglowek_slug)`
 * w `wdrozenia_funkcje` pilnują tego samego niezmiennika z drugiej strony:
 * gdyby blokada doradcza kiedyś przestała działać (zły klucz, refaktor bez
 * testu), baza i tak nie przyjmie dwóch wierszy z tym samym numerem/tą samą
 * funkcją pod tą samą etykietą — zamiast cichej duplikacji dostaniemy
 * twardy błąd `23505`.
 *
 * ROLLBACK (D-088) — ODMAWIA, GDY W DZIENNIKU SĄ WIERSZE
 * Numer wdrożenia to WARTOŚĆ SEMANTYCZNA: mówi ludziom, gdzie w kolejności
 * wdrożeń jest to, na co patrzą, i wiąże z tym konkretny opis funkcji na
 * stronie „Co nowego". Cofnięcie tej migracji na bazie z choćby jednym
 * wierszem skasowałoby tę numerację bezpowrotnie — kolejny `migrate` zaczął
 * by liczyć od 1 dla KAŻDEJ etykiety, myląc numery już pokazane ludziom
 * (i w stopce, i pod „od Alfa …" na stronie „Co nowego") z numerami nowymi.
 * Zgodnie z AGENTS.md §6 (D-088): `down()` na WYPEŁNIONYM dzienniku ODMAWIA
 * z komunikatem, co zrobić ręcznie, zamiast ciągnąć DROP TABLE. Na świeżej
 * bazie (dziennik pusty — stan każdego świeżego `migrate:fresh`, każdego
 * środowiska lokalnego i testowego) `down()` przechodzi bez pytania.
 * Test odmowy i kontrola dodatnia:
 * `tests/Feature/DziennikWdrozenCofnieciePrzyWartosciachTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wdrozenia', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // 40 znaków: pełny SHA-1 gita, tak jak `Wersja::commit()` go czyta.
            $table->string('commit', 40)->unique();
            $table->string('etykieta', 40);
            $table->unsignedInteger('numer');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['etykieta', 'numer']);
        });

        Schema::create('wdrozenia_funkcje', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('etykieta', 40);
            // Slug GFM nagłówka `###` — patrz `App\Support\SlugGfm`, ten sam
            // algorytm, którego `App\Support\Wersja::kotwicaWydania()` używa
            // dla kotwic wydań (issue #1909).
            $table->string('naglowek_slug', 160);
            // Pełny tekst nagłówka, do czytelności w bazie i w diagnozie —
            // strona czyta slug, nie tę kolumnę.
            $table->string('naglowek_tekst', 300);
            $table->unsignedInteger('numer');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['etykieta', 'naglowek_slug']);
        });
    }

    public function down(): void
    {
        // STRAŻNIK D-088. Sprawdzenie MUSI stać przed każdym DROP — inaczej
        // chroni tylko komunikat, nie dane. `DB::table()`, nie surowe SQL:
        // działa na każdym sterowniku, nie tylko pod Postgresem.
        $wierszyWdrozen = DB::table('wdrozenia')->count();
        $wierszyFunkcji = DB::table('wdrozenia_funkcje')->count();

        if ($wierszyWdrozen > 0 || $wierszyFunkcji > 0) {
            throw new RuntimeException(
                "Dziennik wdrożeń nie jest pusty: `wdrozenia` ma {$wierszyWdrozen} wiersz(y), ".
                "`wdrozenia_funkcje` ma {$wierszyFunkcji} wiersz(y). Cofnięcie tej migracji "
                .'usunęłoby obie tabele razem z numeracją, którą ludzie już widzieli w stopce '
                .'i na stronie „Co nowego" (issue #1932, D-318, D-088) — kolejny `migrate` '
                .'zacząłby liczyć numery od 1, mieszając je ze starymi. Migracja odmawia, '
                ."zamiast ciągnąć DROP TABLE.\n\n"
                ."CO ZROBIĆ:\n"
                .'  - jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), '
                .'nie cofaj TEJ migracji — kod sprzed niej nie zna tych tabel i działa bez nich '
                ."bez zmian (numer z końcówką po prostu zniknie ze stopki, tak jak lokalnie);\n"
                .'  - jeśli naprawdę trzeba cofnąć SCHEMAT, zrób kopię obu tabel PRZED '
                ."cofnięciem:\n"
                ."      COPY wdrozenia TO '/tmp/wdrozenia.csv' CSV HEADER;\n"
                ."      COPY wdrozenia_funkcje TO '/tmp/wdrozenia_funkcje.csv' CSV HEADER;\n"
                .'    a po powrocie na tę wersję schematu odtwórz je z tych plików, zanim '
                .'ktokolwiek znowu odpali `kuking:zarejestruj-wdrozenie` (inaczej numeracja '
                .'zacznie się od nowa i będzie kłamać przy starych opisach na stronie „Co nowego").',
            );
        }

        Schema::dropIfExists('wdrozenia_funkcje');
        Schema::dropIfExists('wdrozenia');
    }
};
