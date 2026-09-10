<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TOŻSAMOŚCI U DOSTAWCÓW ZEWNĘTRZNYCH — jedna tabela na WSZYSTKICH (D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TABELA, A NIE DWIE KOLUMNY NA `users`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Pierwsza wersja tej migracji (D-069) dokładała `users.google_sub`
 * i `users.google_connected_at`. Było to rozstrzygnięcie świadome i wtedy
 * poprawne: jeden dostawca, dwie kolumny, a tabela byłaby budowaniem „na
 * przyszłość", czego zabrania AGENTS.md §3.
 *
 * Przyszłość została w tym czasie NAZWANA I ZAMÓWIONA: właściciel poprosił
 * wprost o logowanie kontem Google ORAZ kontem Facebooka. Przy dwóch
 * dostawcach byłyby cztery kolumny, przy trzecim sześć — i przy każdym
 * z nich osobny indeks częściowy oraz osobny CHECK „obie kolumny albo
 * żadna". D-069 samo wskazało ten kształt jako właściwy „przy drugim
 * dostawcy". Drugi dostawca jest zamówiony, a ta gałąź nie jest jeszcze
 * scalona, więc migrację wolno PRZEPISAĆ, zanim ktokolwiek uruchomi ją na
 * produkcji. To jest znacznie taniej niż migracja przenosząca dane później.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO PILNUJE BAZA, A CZEGO PHP PILNOWAĆ NIE MUSI (AGENTS.md §6)
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. `UNIQUE (dostawca, identyfikator)` — JEDNO konto u dostawcy prowadzi
 *     do najwyżej JEDNEGO konta Kuking. Bez tego dwa konta Kuking mogłyby
 *     dzielić jedno konto Google, czyli jedno kliknięcie wpuszczałoby
 *     w miejsce, którego człowiek nie wybierał.
 *  2. `UNIQUE (dostawca, user_id)` — jedno konto Kuking nie ma DWÓCH
 *     Google'i. To jest ograniczenie, którego wersja na kolumnach nie
 *     potrzebowała (kolumna jest jedna), i właśnie dlatego trzeba je tu
 *     napisać wprost: bez niego „połącz" wołane dwa razy dokładałoby drugi
 *     wiersz, a ekran ustawień pokazywałby dwa powiązania tego samego
 *     rodzaju.
 *  3. `CHECK` na nazwę dostawcy — literówka w kodzie („googel") nie ma
 *     prawa cicho założyć nowego rodzaju powiązania. Lista jest zamknięta
 *     i rozszerza ją MIGRACJA, czyli decyzja widoczna w przeglądzie kodu.
 *  4. `CHECK` na kształt identyfikatora — bez spacji, do 255 znaków,
 *     nigdy pusty. `sub` od Google to 21 cyfr, ale Google nie obiecuje
 *     ani długości, ani zbioru znaków; obiecuje tylko trwałość.
 *  5. `ON DELETE CASCADE` — powiązanie nie ma sensu bez konta. Uwaga:
 *     kont w Kuking się NIE KASUJE, tylko anonimizuje (D-022), więc
 *     kaskada nie jest tu drogą, którą powiązanie znika w praktyce — robi
 *     to jawnie `EraseAccountData`. Kaskada jest siatką na wypadek realnego
 *     `DELETE` (`migrate:fresh`, sprzątanie danych zasianych, przyszłe
 *     twarde usunięcie): wiersz-sierota trzymałby wtedy identyfikator
 *     konta Google wskazujący w pustkę i BLOKOWAŁBY ponowne połączenie
 *     tego konta Google z czymkolwiek.
 *
 * `dostawca` jest w nazwach ograniczeń PIERWSZY w każdej parze świadomie:
 * zapytania idą zawsze „ten dostawca, ten identyfikator", nigdy
 * „identyfikator u kogokolwiek", a lewa kolumna indeksu jest tą, po której
 * da się szukać samodzielnie.
 */
return new class extends Migration
{
    /** Nazwa tabeli w jednym miejscu — pojawia się w kilkunastu napisach niżej. */
    private const TABELA = 'tozsamosci_zewnetrzne';

    /**
     * Dostawcy, których baza przyjmuje. Facebooka NIE MA na tej liście
     * i to jest celowe: dokłada go migracja razem z jego kodem, bo warunki
     * wejścia są u niego INNE niż u Google (Facebook nie oddaje
     * `email_verified` — patrz D-098). Dopuszczenie go tutaj „na zapas"
     * byłoby zaproszeniem do skopiowania wzorca Google'a bez tej różnicy.
     */
    private const DOSTAWCY = ['google'];

    public function up(): void
    {
        Schema::create(self::TABELA, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('dostawca', 20);
            $table->string('identyfikator', 255);
            $table->timestampTz('connected_at')->useCurrent();
        });

        if (! $this->isPostgres()) {
            return;
        }

        $lista = "'".implode("', '", self::DOSTAWCY)."'";

        DB::statement(
            'ALTER TABLE '.self::TABELA.' ADD CONSTRAINT tozsamosci_dostawca_check '
            ."CHECK (dostawca IN ({$lista}))",
        );

        DB::statement(
            'ALTER TABLE '.self::TABELA.' ADD CONSTRAINT tozsamosci_identyfikator_check '
            ."CHECK (identyfikator ~ '^\\S{1,255}\$')",
        );

        DB::statement(
            'ALTER TABLE '.self::TABELA.' ADD CONSTRAINT tozsamosci_dostawca_identyfikator_unique '
            .'UNIQUE (dostawca, identyfikator)',
        );

        DB::statement(
            'ALTER TABLE '.self::TABELA.' ADD CONSTRAINT tozsamosci_dostawca_konto_unique '
            .'UNIQUE (dostawca, user_id)',
        );
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY KTOŚ TĄ DROGĄ WCHODZI — i to nie jest ostrożność
     * na zapas (D-069, przeniesione tu bez zmiany sensu).
     *
     * Konto założone drogą przez dostawcę NIGDY nie miało hasła: w kolumnie
     * `password` leży skrót wartości losowej, której nie zna nikt, także my.
     * `DROP TABLE` zabiera takiemu kontu jedyną drogę wejścia, jaką ta osoba
     * zna — i robi to nieodwracalnie, bo razem z tabelą znikają identyfikatory,
     * bez których powiązania nie da się odtworzyć.
     *
     * `DROP TABLE` jest przy tym ostrzejszy od dawnego `dropColumn`, więc
     * zgoda musi być wypowiedziana wprost zmienną środowiskową. Wyłączenie
     * funkcji BEZ migracji (`KUKING_WEJSCIE_GOOGLE=false`) jest niemal zawsze
     * tym, czego naprawdę chce ktoś, kto tu trafił.
     */
    public function down(): void
    {
        $powiazane = $this->ilePowiazanych();

        if ($powiazane > 0 && ! $this->wolnoKasowacPowiazania()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby powiązania z kontami u dostawców zewnętrznych dla '
                .$powiazane.' kont — dla części z nich to jedyna droga wejścia, jaką znają '
                ."(hasła nigdy nie ustawiały).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Wyłącz funkcję bez migracji: KUKING_WEJSCIE_GOOGLE=false i restart serwisu. '
                ."Przycisk znika, konta działają dalej, powiązania zostają nietknięte.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ SKASOWAĆ POWIĄZANIA\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE=true '
                .'php artisan migrate:rollback --step=1 — i uprzedź te osoby, '
                .'że wejdą hasłem („Nie pamiętam hasła”) albo linkiem e-mail.',
            );
        }

        Schema::dropIfExists(self::TABELA);
    }

    private function ilePowiazanych(): int
    {
        if (! Schema::hasTable(self::TABELA)) {
            return 0;
        }

        return (int) DB::table(self::TABELA)->count();
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem (`KUKING_ROLLBACK_KASUJE_*`).
     * `env()` oddaje `null` przy zbuforowanej konfiguracji, a zgoda na
     * skasowanie cudzych drzwi nie ma prawa zależeć od tego, czy ktoś
     * uruchomił wcześniej `config:cache`. Konfiguracji też tu nie ma po co
     * zaprzęgać: to jest przełącznik jednego uruchomienia, nie ustawienie
     * serwisu.
     */
    private function wolnoKasowacPowiazania(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
