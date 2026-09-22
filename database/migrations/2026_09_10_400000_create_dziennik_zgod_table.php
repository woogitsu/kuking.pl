<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `dziennik_zgod` — kiedy i skąd zgoda została udzielona, a kiedy wycofana
 * (audyt 10.09.2026, znaleziska DB1 i RODO §4; `docs/DECISIONS.md` D-072).
 *
 * PO CO TO JEST: RODO ART. 7 UST. 1 KAŻE ZGODĘ *WYKAZAĆ*, A NIE MIEĆ
 * Do tej pory całym dowodem był jeden boolean `users.wants_weekly_digest`.
 * „Dziś pole ma wartość `true`" nie odpowiada na żadne z pytań, które przy
 * sporze zada UODO albo sam człowiek: KIEDY to kliknął, CZY wcześniej tego
 * nie odklikał, i CZY wysyłka po wycofaniu nie szła dalej. Boolean nie ma
 * historii — jest ostatnią klatką filmu, którego nikt nie nagrywał.
 *
 * DLACZEGO TABELA, A NIE DWIE KOLUMNY Z DATAMI
 * Audyt dopuszczał `weekly_digest_consented_at` + `weekly_digest_withdrawn_at`
 * jako minimum i to minimum jest za małe: przy ciągu włącz → wyłącz → włącz
 * druga para wartości NADPISUJE pierwszą, więc dokładnie ta historia, o którą
 * chodzi, ginie. Dwie kolumny odpowiadają na „kiedy ostatnio", a pytanie
 * dowodowe brzmi „co się działo".
 *
 * To jest ODWROTNE rozstrzygnięcie niż przy `users.weekly_digest_sent_at`
 * (migracja `2026_09_10_100000_add_weekly_digest_sent_at_to_users_table`),
 * gdzie świadomie wybrano kolumnę zamiast tabeli wysyłek — i nie ma tu
 * sprzeczności, bo tam pytanie NAPRAWDĘ brzmiało „kiedy ostatnio", a
 * poprzednia wartość nie służyła niczemu. Tutaj poprzednia wartość jest
 * całym dowodem.
 *
 * DLACZEGO NIE JSONB Z HISTORIĄ NA `users`
 * AGENTS.md §6: JSONB tylko dla danych półstrukturalnych. Zdarzenie zgody ma
 * cztery zawsze te same pola i zamknięte zbiory wartości — to są dane
 * strukturalne, więc dostają kolumny i CHECK-i, a nie tablicę w jednym polu,
 * której baza nie umie sprawdzić ani zindeksować.
 *
 * CZEGO TU CELOWO NIE MA: ADRESU IP I `User-Agent`
 * Wiele bibliotek do zgód zapisuje jedno i drugie „na wszelki wypadek".
 * Do wykazania zgody nie są potrzebne: dowodem jest FAKT, MOMENT, CEL
 * i DROGA (ekran ustawień albo odnośnik z listu), a nie numer, z którego
 * ktoś wtedy korzystał. AGENTS.md §7 zabrania PII w logach, a `audit_log`
 * trzyma IP wyłącznie jako HMAC i tylko tam, gdzie służy wykrywaniu nadużyć.
 * Zgoda nie jest nadużyciem, więc nie ma czego wykrywać. Brak tych kolumn
 * pilnuje `DowodZgodyNaDigestTest` — asercją na LISTĘ KOLUMN, nie na
 * przekonanie autora.
 *
 * APPEND-ONLY WYMUSZONE PRZEZ BAZĘ, NIE PRZEZ DOBRE INTENCJE
 * Wyzwalacz `dziennik_zgod_bez_zmian` odrzuca `UPDATE`, `DELETE` i `TRUNCATE`
 * na tej tabeli. Dziennik, w którym wiersz da się cicho poprawić, nie jest
 * dowodem — jest notatką. Reguła po stronie aplikacji (model `WpisZgody`
 * blokuje `update`/`delete`) łapie POMYŁKĘ programisty, ale nie łapie
 * `DB::table('dziennik_zgod')->update(...)` ani ręcznego `psql` — dlatego
 * druga linia jest w bazie. To ten sam wzorzec, którym `product_signals`
 * pilnuje braku frazy wyszukiwania: liczy się to, czego baza NIE MOGŁA
 * przyjąć.
 *
 * Wyzwalacz NIE blokuje `DROP TABLE` — i tak ma być: `down()` niżej,
 * `migrate:refresh` w CI i `RefreshDatabase` w testach muszą działać.
 * Append-only dotyczy WIERSZY w działającym serwisie, nie istnienia schematu.
 *
 * KLUCZ OBCY Z `RESTRICT`, I TO JEST ROZSTRZYGNIĘCIE NAPIĘCIA
 * „DOWÓD ZGODY vs PRAWO DO USUNIĘCIA" (pełne uzasadnienie: D-072)
 * Konta w Kuking się NIE KASUJE — `EraseAccountData` je ANONIMIZUJE (D-022),
 * bo wiersz `users` jest potrzebny pod zanonimizowanymi treściami. Po
 * anonimizacji nie ma tam już adresu e-mail, hasła, nazwy ani awatara,
 * więc `user_id` w tym dzienniku wskazuje na wiersz, który sam z siebie
 * nikogo nie identyfikuje — a dowód zgody zostaje kompletny. Dlatego
 * usunięcie konta NIE kasuje tu niczego i nie musi.
 *
 * `restrictOnDelete()`, a nie `cascadeOnDelete()` ani `nullOnDelete()`:
 *   - kaskada skasowałaby dowód dokładnie w chwili, w której zwykle jest
 *     potrzebny (spór po usunięciu konta);
 *   - `SET NULL` byłby `UPDATE` na tej tabeli, czyli zderzyłby się
 *     z wyzwalaczem — i zostawiłby wiersze bez wskazania na kogokolwiek,
 *     czyli dowód niczyj, a więc żaden.
 * Skutek uboczny, który trzeba nazwać: `DELETE FROM users` dla konta,
 * które kiedykolwiek ruszyło tę zgodę, ODMÓWI wykonania. W tym serwisie nic
 * takiego nie robi (kasowanie konta to anonimizacja), a gdyby kiedyś było
 * naprawdę potrzebne — jest to decyzja człowieka, świadoma, po zdjęciu
 * wyzwalacza, nie skutek uboczny kaskady.
 *
 * INDEKS JEDEN, BO PYTANIE JEST JEDNO
 * „Pokaż historię zgody TEJ osoby na TEN cel, od najnowszej" — tak wygląda
 * odpowiedź na żądanie z art. 7 ust. 1 i tak wygląda asercja w testach.
 * Drugiego indeksu (np. po samym czasie, pod retencję) nie zakładamy, bo
 * nie ma dziś ani jednego zapytania, które by go czytało: dziennik zgód
 * świadomie NIE MA sprzątania (D-072).
 *
 * ROLLBACK — `down()` ODMAWIA, GDY JEST CO STRACIĆ (D-088)
 * `down()` kasuje tabelę razem z wyzwalaczem i funkcją. Dla DZIAŁANIA
 * serwisu jest to bezpieczne — wysyłka digestu nie czyta tej tabeli ani
 * razu, więc nic nie przestanie chodzić. I właśnie dlatego było groźne:
 * razem z tabelą znikał JEDYNY zapis o tym, kto i kiedy wyraził zgodę,
 * a boolean na `users` tego nie odtworzy. Po `down()` prawie zawsze idzie
 * kolejny `migrate` — tabela wracała PUSTA, aplikacja chodziła dalej
 * i nie było błędu do zauważenia.
 *
 * Ten akapit stał tu od 10 września i ostrzegał, a kod robił
 * `dropIfExists` bez słowa. Ostrzeżenie w dokumencie nie jest
 * zabezpieczeniem — zabezpieczeniem jest `down()`, który odmawia.
 *
 * Odmowa mówi ILU osób to dotyczy, co zrobić zamiast tego (eksport
 * `\copy dziennik_zgod to 'dziennik_zgod.csv' csv header`) i jak
 * powiedzieć wprost „wiem, co robię". Na pustej tabeli — czyli na świeżym
 * wdrożeniu — cofnięcie przechodzi bez pytania, bo nie ma czego stracić.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dziennik_zgod', function (Blueprint $table): void {
            // `bigserial`, nie UUID — ten sam wybór i ten sam powód co
            // w `audit_log` i `product_signals`: wiersz nigdy nie jest
            // adresowany z zewnątrz ani pokazywany człowiekowi, więc UUID
            // z AGENTS.md §6 (dla encji publicznych) go nie dotyczy.
            // Rosnący klucz ma tu drugą zaletę: przy dwóch zdarzeniach w tej
            // samej sekundzie zachowuje KOLEJNOŚĆ, której `wystapilo_at`
            // sam by nie rozstrzygnął.
            $table->bigIncrements('id');

            // NOT NULL — zgoda zawsze należy do konkretnego konta. Zgoda
            // niczyja nie jest dowodem niczego, więc nie ma dla niej wiersza.
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            // CEL, nie „nazwa listy". Dziennik jest przygotowany na drugą
            // zgodę (np. przyszłe powiadomienia e-mail), ale zbiór wartości
            // jest ZAMKNIĘTY CHECK-iem: nowa zgoda to nowa migracja i nowa
            // recenzja, a nie dowolny napis wpisany przez kod z przyszłości.
            $table->string('cel', 40);

            // `udzielona` / `wycofana`. Dwie wartości, bo to są dwie rzeczy,
            // które RODO każe umieć wykazać (art. 7 ust. 1 i art. 7 ust. 3).
            $table->string('czynnosc', 20);

            // SKĄD przyszła decyzja — ekran ustawień, odnośnik ze stopki
            // listu (RFC 8058), przycisk powrotny na ekranie po wypisaniu
            // albo usunięcie konta. To jest część dowodu: „gdzie człowiek
            // wtedy był", a nie ciekawostka produktowa.
            $table->string('zrodlo', 30);

            // WERSJA POLITYKI PRYWATNOŚCI obowiązująca w chwili zdarzenia.
            // Bez niej dowód mówi „zgodził się", ale nie mówi NA CO — a to
            // jest pierwsze pytanie przy sporze o zakres zgody. Wartość
            // z `config('kuking.zgody.wersja_polityki')`, trzymana w repo
            // (nie w `.env`), żeby jej zmiana przechodziła przez recenzję.
            $table->string('wersja_polityki', 20);

            // `timestamptz` (AGENTS.md §6). `useCurrent()` po to, żeby wiersz
            // wstawiony ręcznie w `psql` też miał prawdziwy czas — moment
            // zdarzenia nie jest polem do wypełnienia, jest faktem.
            $table->timestampTz('wystapilo_at')->useCurrent();
        });

        if (! $this->isPostgres()) {
            return;
        }

        // ZAMKNIĘTE ZBIORY WARTOŚCI W BAZIE, nie tylko w PHP — ten sam
        // argument co przy `reports.status` i `product_signals.signal_name`:
        // walidator da się ominąć nowym endpointem, `CHECK` nie.
        DB::statement(
            'ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_cel_check '
            ."CHECK (cel IN ('tygodniowy_digest'))",
        );

        DB::statement(
            'ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_czynnosc_check '
            ."CHECK (czynnosc IN ('udzielona', 'wycofana'))",
        );

        DB::statement(
            'ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_zrodlo_check '
            ."CHECK (zrodlo IN ('ustawienia', 'link_wypisania', 'link_powrotny', 'usuniecie_konta'))",
        );

        // Jedyne zapytanie, jakie ta tabela obsługuje: historia jednej osoby
        // dla jednego celu, od najnowszej.
        DB::statement(
            'CREATE INDEX dziennik_zgod_konto_cel_idx ON dziennik_zgod (user_id, cel, wystapilo_at DESC)',
        );

        // APPEND-ONLY. `RAISE EXCEPTION` zamiast `DO INSTEAD NOTHING` (reguła
        // Postgresa) jest wyborem świadomym: reguła POŁKNĘŁABY zmianę bez
        // słowa, czyli kod „poprawiający" dziennik działałby dalej w błędnym
        // przekonaniu, że coś zmienił. Wyjątek zatrzymuje wdrożenie i pokazuje
        // plik, który to zrobił.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION dziennik_zgod_tylko_dopisywanie() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'dziennik_zgod jest tylko do dopisywania: operacja % jest zablokowana (D-072).', TG_OP
                    USING HINT = 'Zgode wycofuje sie NOWYM wierszem czynnosc=wycofana, nie zmiana starego.';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement(
            'CREATE TRIGGER dziennik_zgod_bez_zmian BEFORE UPDATE OR DELETE ON dziennik_zgod '
            .'FOR EACH ROW EXECUTE FUNCTION dziennik_zgod_tylko_dopisywanie()',
        );

        // `TRUNCATE` nie przechodzi przez wyzwalacz wierszowy — potrzebuje
        // własnego, na poziomie polecenia. Bez niego jedno polecenie kasuje
        // cały dowód, mijając wszystkie zabezpieczenia wyżej.
        DB::statement(
            'CREATE TRIGGER dziennik_zgod_bez_czyszczenia BEFORE TRUNCATE ON dziennik_zgod '
            .'FOR EACH STATEMENT EXECUTE FUNCTION dziennik_zgod_tylko_dopisywanie()',
        );
    }

    public function down(): void
    {
        $zapisow = $this->ileZapisowZgody();

        if ($zapisow > 0 && ! $this->wolnoKasowacDziennik()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby dziennik zgód. Liczba zapisów, '
                .'które znikną: '.$zapisow.'. To JEDYNY dowód na to, kto i kiedy zgodził '
                .'się na cotygodniowy przegląd. Boolean `users.wants_weekly_digest` tego nie '
                ."odtworzy: pokazuje stan na dziś, nie historię (RODO art. 7 ust. 1).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Wyeksportuj dziennik poza bazę, zanim go skasujesz: '
                ."\\copy dziennik_zgod to 'dziennik_zgod.csv' csv header\n\n"
                ."JEŚLI DZIENNIK MA NAPRAWDĘ ZNIKNĄĆ\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_DZIENNIK_ZGOD=true '
                .'php artisan migrate:rollback',
            );
        }

        Schema::dropIfExists('dziennik_zgod');

        if ($this->isPostgres()) {
            // Po tabeli, bo wyzwalacze na niej zależą od tej funkcji.
            DB::statement('DROP FUNCTION IF EXISTS dziennik_zgod_tylko_dopisywanie()');
        }
    }

    /**
     * Zero także wtedy, gdy tabeli nie ma — cofnięcie migracji, która nigdy
     * nie doszła do skutku, nie ma czego bronić i nie może się wywalić na
     * liczeniu wierszy nieistniejącej tabeli.
     */
    private function ileZapisowZgody(): int
    {
        if (! Schema::hasTable('dziennik_zgod')) {
            return 0;
        }

        return (int) DB::table('dziennik_zgod')->count();
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji, a zgoda na skasowanie dowodu z art. 7 nie
     * ma prawa zależeć od tego, czy ktoś uruchomił wcześniej `config:cache`.
     */
    private function wolnoKasowacDziennik(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_DZIENNIK_ZGOD'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
