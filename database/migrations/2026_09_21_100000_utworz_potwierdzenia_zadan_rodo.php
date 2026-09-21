<?php

declare(strict_types=1);

use App\Domain\Compliance\SlownikPotwierdzenRodo;
use App\Support\NumerSprawy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `potwierdzenia_zadan_rodo` — MINIMALNE potwierdzenie obsługi żądania
 * usunięcia konta, zamiast bezterminowego dziennika osobowego.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C podważa nie LICZBĘ, tylko
 * KSZTAŁT dzisiejszego rozwiązania. Dziś dowodem obsługi żądania z art. 17
 * RODO są trzy kategorie `audit_log`, których retencja nie rusza NIGDY
 * (`App\Models\AuditLogEntry::NIGDY_NIE_KASUJ`). Ocena mówi o tym wprost:
 * „Nie ma uzasadnienia dla bezterminowego zachowywania wszystkich trzech
 * zdarzeń wraz z dowolnymi metadanymi" — i każe je ZASTĄPIĆ minimalnym
 * potwierdzeniem, przechowywanym 36 miesięcy od zakończenia obsługi.
 *
 * Różnica nie jest kosmetyczna. Wpis `audit_log` niesie `actor_id` (klucz
 * obcy do `users`), `ip_hash`, `subject_id` i dowolne `metadata jsonb` —
 * czyli powiązanie z kontem, skrót adresu IP i worek, do którego następny
 * kod dopisze, co uzna za potrzebne. I to wszystko BEZ DATY KOŃCA. Ta tabela
 * ma zamknięty zestaw kolumn, każda z powodem przy sobie, oraz datę,
 * po której wiersz przestaje istnieć.
 *
 * TA MIGRACJA NICZEGO NIE KASUJE I NICZEGO NIE PRZENOSI. Zakłada pustą
 * tabelę obok istniejącego dziennika. Przeniesienie istniejących wpisów
 * `account.*` i późniejsze skasowanie ich pełnych kopii to OSOBNY krok,
 * który uruchamia właściciel — rozpisany w
 * `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KOLUMNA PO KOLUMNIE — DLACZEGO JEST I DLACZEGO MNIEJ NIE WYSTARCZA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `numer` — losowy numer sprawy, wymieniony w §C jako pierwsza pozycja.
 * Jest JEDYNYM powiązaniem wiersza z człowiekiem po wykonaniu usunięcia
 * i człowiek dostaje go na piśmie, zanim jego dane znikną. Mniej nie
 * wystarczy: bez żadnego identyfikatora potwierdzenie nie odpowiada na
 * pytanie „czy MOJE żądanie zostało obsłużone", czyli nie jest dowodem dla
 * osoby, tylko statystyką. Uzasadnienie długości i alfabetu:
 * `App\Support\NumerZadaniaRodo`.
 *
 * `rodzaj` — §C: „rodzaj żądania". Dziś jedna wartość, ale rejestr bez tej
 * kolumny nie dałby się rozszerzyć bez zgadywania, czego dotyczą stare
 * wiersze. CHECK zamiast wiary w PHP (AGENTS.md §6).
 *
 * `wynik` — §C: „wynik". To jest kolumna, przez którą ta tabela zastępuje
 * TRZY kategorie dziennika jednym wierszem: cofnięcie żądania jest tu
 * WYNIKIEM, nie osobnym zdarzeniem. Ocena mówi: „Przechowuj właściwy stan
 * końcowy, nie trzy niekasowalne kopie wszelkich danych".
 *
 * `zakres` — §C: „zakres wykonania". Bez niej „wykonane" nie mówi, CO
 * wykonano, a różnica jest dokładnie tą, o którą toczy się spór: przy
 * `minimum` teksty osoby zostają widoczne pod zanonimizowanym autorem, przy
 * `everything` znikają na stałe (D-022). Wyboru dokonał człowiek i to jego
 * wybór jest dowodem. NULL-owalna, bo dla `w_toku`, `cofniete` i `odmowa`
 * nic nie wykonano — a wpisanie tam `minimum` byłoby zapisem nieprawdy.
 *
 * `otrzymano` — §C: „daty otrzymania". Bez daty wpływu nie da się wykazać
 * dotrzymania terminu z art. 12 ust. 3 RODO.
 *
 * `zakonczono` — §C: „daty […] zakończenia". Druga połowa tego samego
 * dowodu, a jednocześnie POCZĄTEK ZEGARA RETENCJI: 36 miesięcy liczy się od
 * niej, nie od `created_at`. NULL znaczy „sprawa w toku" i jest jedynym
 * stanem, w którym wiersz nie ma jeszcze daty własnego końca.
 *
 * DLACZEGO `date`, A NIE `timestamptz` — TO JEST MINIMALIZACJA, NIE LENISTWO.
 * Sekunda zamknięcia sprawy jest sama w sobie identyfikatorem: da się ją
 * zestawić z chwilą, w której czyjeś wpisy w serwisie zmieniły autora na
 * „konto usunięte", i rozpoznać osobę bez żadnej kolumny z jej danymi.
 * Doba tego nie usuwa, ale rozmywa: tego samego dnia konto usuwa zwykle
 * więcej niż jedna osoba, a do wykazania terminu z art. 12 ust. 3 (miesiąc)
 * i do policzenia 36 miesięcy rozdzielczość dobowa wystarcza w zupełności.
 * Reszta schematu używa `timestamptz` i to zostaje regułą — tu jest wyjątek
 * z nazwanym powodem.
 *
 * `wersja_procedury` — §C: „wersja procedury". Bez niej „wykonane" znaczy
 * tyle, co „zrobiliśmy wtedy to, co wtedy robiliśmy" — a procedura z 2026
 * roku nie jest procedurą z 2029. To jest kolumna, która pozwala odpowiedzieć
 * regulatorowi BEZ odtwarzania z pamięci albo z gita, co dokładnie wtedy
 * kasowaliśmy.
 *
 * `wyjatki` — §C: „informacja o wyjątkach". Usunięcie konta w tym serwisie
 * NIE jest usunięciem wszystkiego: przy zakresie `minimum` treści zostają
 * zanonimizowane (`docs/legal/COMPLIANCE.md` §2), a dokumentacja sprawy
 * moderacyjnej ma własne 36 miesięcy. Potwierdzenie, które o tym milczy,
 * mówi nieprawdę przez przemilczenie. Tekst ma wskazywać REGUŁĘ, z której
 * wyjątek wynika — nie opowiadać o człowieku; pilnuje tego
 * `MinimalnePotwierdzenieRodoTest` i przegląd kodu.
 *
 * `konto_id` — §C: „minimalny sposób powiązania z wnioskodawcą potrzebny do
 * rozpatrzenia sporu". Kolumna jest NULL-owalna i CHECK zabrania jej
 * wypełnienia przy `wynik = 'wykonane'`. Pełne rozstrzygnięcie tej sprawy —
 * razem ze słabościami — stoi w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`,
 * bo jest decyzją projektową, a nie szczegółem schematu. W skrócie: dopóki
 * żądanie biegnie albo zostało COFNIĘTE, konto istnieje w pełni i wskazanie
 * go nie ujawnia niczego, czego baza już nie wie; z chwilą wykonania konto
 * jest anonimizowane, a `konto_id` związałoby dowód usunięcia ze wszystkimi
 * treściami, które po tym koncie zostały. Dlatego zerowanie tej kolumny
 * NIE jest obietnicą w komentarzu — jest CHECK-iem w bazie.
 *
 * `wstrzymanie_do` + `wstrzymanie_sprawa` — §C: „Po 36 miesiącach usuń
 * potwierdzenie osobowe, O ILE konkretna udokumentowana sprawa nie wymaga
 * dalszego zachowania". Dwie kolumny, nie jedna, i CHECK wymuszający parę:
 * wstrzymanie bez wskazanej sprawy to znów retencja bezterminowa, tylko
 * pisana inną kolumną. `wstrzymanie_do` jest DATĄ, nie flagą, żeby blokada
 * wygasała sama, a nie trwała, dopóki ktoś o niej nie zapomni.
 *
 * `created_at` / `updated_at` — kiedy wiersz powstał i kiedy ostatnio się
 * zmienił. To NIE jest to samo co `otrzymano`: rozjazd między nimi jest
 * jedynym sygnałem, że datę wpływu wpisano wstecz.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO W TEJ TABELI NIE MA — ŚWIADOMIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ocena wylicza to wprost: „Nie kopiuj starego profilu, hasha hasła,
 * fotografii ani całej korespondencji". Nie ma tu więc adresu e-mail (ani
 * jawnego, ani jako skrót — patrz projekt), nazwy, biogramu, zdjęć, treści
 * wniosku, korespondencji ani `ip_hash`. Nie ma też `metadata jsonb`:
 * to jest ta jedna kolumna, przez którą wszystkie powyższe wróciłyby tylnymi
 * drzwiami, bez migracji i bez recenzji schematu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  WYCOFANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` kasuje tabelę, ale ODMAWIA, gdy stoi w niej choć jeden wiersz
 * z wypełnionym `zakonczono` — czyli sprawa zamknięta, której potwierdzenie
 * to jedyny ślad po tym, że żądanie obsłużono. Odmowa jest WĄSKA (AGENTS.md
 * §6, D-088): pusta tabela i tabela z samymi sprawami w toku cofają się bez
 * pytania, bo sprawa w toku żyje nadal w `users.delete_requested_at`.
 *
 * Kolejność przy wycofywaniu: NAJPIERW KOD, POTEM MIGRACJA. Dziś nie ma
 * jeszcze kodu, który do tej tabeli pisze — ta tura celowo go nie dodaje —
 * więc wycofanie samej migracji na tym etapie nie zostawia niczego wiszącego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potwierdzenia_zadan_rodo', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Numer, który dostaje człowiek. Unikalność pilnuje BAZA —
            // losowanie może się powtórzyć, indeks nie pozwoli zapisać
            // powtórzenia (ta sama zasada co przy `reports.numer_sprawy`).
            // 19 znaków: `RODO-` + 4 + `-` + 4 + `-` + 4.
            $table->string('numer', 19)->unique();

            // Rodzaj żądania. 40 znaków z zapasem — dzisiejsza jedyna
            // wartość ma 15.
            $table->string('rodzaj', 40);

            // Stan końcowy obsługi.
            $table->string('wynik', 30);

            // Co dokładnie wykonano. NULL dla `w_toku`, `cofniete`, `odmowa`.
            $table->string('zakres', 20)->nullable();

            // Daty, nie znacznik czasu — uzasadnienie w komentarzu klasy.
            $table->date('otrzymano');
            $table->date('zakonczono')->nullable();

            // Która wersja procedury wykonała to żądanie.
            $table->string('wersja_procedury', 20);

            // Czego NIE usunięto i z jakiej reguły to wynika.
            $table->text('wyjatki')->nullable();

            // Powiązanie z kontem — TYLKO dopóki konto nie zostało wymazane.
            // `nullOnDelete`, choć `users` dziś nie ma ścieżki kasowania
            // wiersza (ADR §3.2): potwierdzenie ma przeżyć konto, a nie
            // zablokować jego usunięcie, gdyby taka ścieżka kiedyś powstała.
            $table->foreignUuid('konto_id')->nullable()->constrained('users')->nullOnDelete();

            // Udokumentowane wstrzymanie kasowania.
            $table->date('wstrzymanie_do')->nullable();
            $table->string('wstrzymanie_sprawa', 100)->nullable();

            $table->timestampsTz();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE potwierdzenia_zadan_rodo ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Wzór numeru wklejony NA STAŁE w chwili migracji — tak samo jak
        // w `reports_numer_sprawy_check`. Późniejsza zmiana
        // `NumerSprawy::ALFABET` tego wyrażenia nie ruszy, a test
        // `test_check_w_bazie_zna_ten_sam_wzor_co_stala_w_php` zapali się,
        // zanim baza zacznie odrzucać świeżo wylosowane numery.
        $alfabet = NumerSprawy::ALFABET;

        DB::statement(
            'ALTER TABLE potwierdzenia_zadan_rodo ADD CONSTRAINT potwierdzenia_zadan_rodo_numer_check CHECK ('
            ."numer ~ '^RODO-[".$alfabet.']{4}-['.$alfabet.']{4}-['.$alfabet."]{4}$'"
            .')',
        );

        DB::statement(
            'ALTER TABLE potwierdzenia_zadan_rodo ADD CONSTRAINT potwierdzenia_zadan_rodo_rodzaj_check CHECK ('
            .'rodzaj IN ('.$this->lista(SlownikPotwierdzenRodo::RODZAJE).')'
            .')',
        );

        DB::statement(
            'ALTER TABLE potwierdzenia_zadan_rodo ADD CONSTRAINT potwierdzenia_zadan_rodo_wynik_check CHECK ('
            .'wynik IN ('.$this->lista(SlownikPotwierdzenRodo::WYNIKI).')'
            .')',
        );

        DB::statement(
            'ALTER TABLE potwierdzenia_zadan_rodo ADD CONSTRAINT potwierdzenia_zadan_rodo_zakres_check CHECK ('
            .'zakres IS NULL OR zakres IN ('.$this->lista(SlownikPotwierdzenRodo::zakresy()).')'
            .')',
        );

        // Sprawa w toku NIE MA daty zakończenia, a każda zamknięta MA ją.
        // Bez tego wiersz „wykonane" bez `zakonczono` nigdy nie trafiłby pod
        // retencję — czyli bezterminowość wróciłaby tylnymi drzwiami, przez
        // pustą kolumnę zamiast przez listę wyjątków.
        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_koniec_check
            CHECK ((wynik = 'w_toku') = (zakonczono IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_kolejnosc_dat_check
            CHECK (zakonczono IS NULL OR zakonczono >= otrzymano)
        SQL);

        // NAJWAŻNIEJSZY CHECK W TEJ TABELI.
        // Z chwilą, w której wynikiem jest wykonane usunięcie, powiązanie
        // z kontem musi zniknąć. Gdyby to była reguła w PHP, wystarczyłby
        // drugi zapis w innym miejscu, żeby dowód usunięcia danych osobowych
        // sam stał się wskaźnikiem na wszystko, co po tym koncie zostało.
        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_wykonane_bez_konta_check
            CHECK (wynik <> 'wykonane' OR konto_id IS NULL)
        SQL);

        // ZAKRES ISTNIEJE DOKŁADNIE WTEDY, GDY COŚ WYKONANO — w obie strony.
        // „Wykonane" bez zakresu nie mówi, CO wykonano; zakres przy żądaniu
        // cofniętym albo odmowie jest zapisem o czynności, której nie było.
        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_zakres_tylko_wykonane_check
            CHECK ((wynik = 'wykonane') = (zakres IS NOT NULL))
        SQL);

        // Wstrzymanie kasowania zawsze w parze ze wskazaną sprawą.
        DB::statement(<<<'SQL'
            ALTER TABLE potwierdzenia_zadan_rodo
            ADD CONSTRAINT potwierdzenia_zadan_rodo_wstrzymanie_check
            CHECK ((wstrzymanie_do IS NULL) = (wstrzymanie_sprawa IS NULL))
        SQL);

        // Pod retencję: „co jest starsze niż próg i nie jest wstrzymane".
        // Częściowy, bo sprawy w toku nie są kandydatem w ogóle.
        DB::statement(<<<'SQL'
            CREATE INDEX potwierdzenia_zadan_rodo_retencja_idx
            ON potwierdzenia_zadan_rodo (zakonczono)
            WHERE zakonczono IS NOT NULL
        SQL);

        // Pod przegląd zaległości (ocena §F.7: otwarte sprawy przeglądać
        // regularnie). Częściowy z tego samego powodu, w drugą stronę.
        DB::statement(<<<'SQL'
            CREATE INDEX potwierdzenia_zadan_rodo_w_toku_idx
            ON potwierdzenia_zadan_rodo (otrzymano)
            WHERE zakonczono IS NULL
        SQL);

        // Pod domknięcie sprawy: znajdź wiersz żądania tego konta.
        // Częściowy, bo po wykonaniu `konto_id` jest NULL-em z definicji,
        // więc pełny indeks byłby w większości pustymi wskaźnikami.
        DB::statement(<<<'SQL'
            CREATE INDEX potwierdzenia_zadan_rodo_konto_idx
            ON potwierdzenia_zadan_rodo (konto_id)
            WHERE konto_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // ODMOWA WĄSKA: blokuje tylko wtedy, gdy w tabeli stoi zamknięta
        // sprawa, czyli dowód, którego nie ma gdzie indziej. Uzasadnienie
        // w komentarzu nad klasą.
        if (Schema::hasTable('potwierdzenia_zadan_rodo')) {
            $zamkniete = DB::table('potwierdzenia_zadan_rodo')->whereNotNull('zakonczono')->count();

            if ($zamkniete > 0) {
                throw new RuntimeException(
                    'Odmawiam wycofania migracji. Liczba zamkniętych potwierdzeń obsługi żądań RODO: '
                    .$zamkniete.'. Każde z nich jest dowodem, że żądanie z art. 17 RODO zostało '
                    .'obsłużone, a skasowanie tabeli usunęłoby ten dowód bez śladu. '
                    ."\n\nCO ZROBIĆ: wyeksportuj te wiersze poza bazę i zachowaj eksport razem "
                    .'z dokumentacją zgodności, a dopiero potem powtórz wycofanie. Jeśli wycofujesz '
                    .'wdrożenie, a nie usuwasz rejestru — cofnij sam KOD, nie tę migrację.',
                );
            }
        }

        Schema::dropIfExists('potwierdzenia_zadan_rodo');
    }

    /**
     * @param  list<string>  $wartosci
     */
    private function lista(array $wartosci): string
    {
        return implode(', ', array_map(
            static fn (string $wartosc): string => "'".$wartosc."'",
            $wartosci,
        ));
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
