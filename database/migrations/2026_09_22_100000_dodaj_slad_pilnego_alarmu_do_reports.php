<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ŚLAD PO PILNYM ALARMIE MODERACYJNYM (issue #1051).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO SIĘ DZIAŁO BEZ TYCH KOLUMN
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sprawa pilna (`KategorieModeracji::PILNE` — treść seksualna i wszystko,
 * co dotyczy dziecka) powstawała w `reports` we WŁASNEJ, zamkniętej
 * transakcji, a list do moderatora szedł linijkę PÓŹNIEJ, poza nią
 * (`OznaczDoPrzegladu` → `PrzeanalizujTresc` → `AlarmujModeratora`).
 * Wszystko, co zabiło workera w tej szczelinie — `timeout = 30`,
 * `tries = 1`, restart kontenera przy wdrożeniu, OOM — zostawiało sprawę
 * ZAPISANĄ i alarm NIEWYSŁANY. Po niej każda kolejna analiza tej samej
 * treści zatrzymywała się na `OznaczDoPrzegladu::juzOgladane()`, więc
 * zgubione zostawało zgubione.
 *
 * W bazie nie było ANI JEDNEGO pola, po którym dałoby się takie sprawy
 * odróżnić od spraw, przy których alarm poszedł. Cisza z powodu awarii
 * wyglądała dokładnie tak samo jak cisza z powodu „nic pilnego nie było" —
 * ta sama klasa błędu, co `StanKopiiBazy` przed wprowadzeniem stanu
 * `WYLACZONA` (pusty bucket wyglądał jak bucket w porządku).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO DWIE KOLUMNY, A NIE JEDNA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `alarm_pilny_stan` odpowiada na pytanie „czy ta sprawa W OGÓLE jest
 * pilna" — `null` znaczy „automat nie uznał jej za pilną", i to jest stan
 * ogromnej większości wierszy. Bez tej kolumny nie da się z bazy odtworzyć
 * pilności: decyzja żyje w liście obiektów `Sygnal` w pamięci workera,
 * a do `reports` trafia tylko najcięższy KOD powodu, ten sam dla sprawy
 * pilnej i niepilnej (`automat_model`).
 *
 * `alarm_pilny_zlecony_at` odpowiada na pytanie „czy alarm doszedł do
 * skutku". Rozdzielenie jest konieczne, bo interesuje nas RÓŻNICA: wiersz
 * ze stanem ustawionym i pustym znacznikiem to sprawa pilna, o której nikt
 * się nie dowiedział. Jedna kolumna tekstowa musiałaby tę różnicę kodować
 * wartością, a wtedy każde zapytanie sondy zależałoby od literówki
 * w napisie.
 *
 * NAZWA MÓWI `zlecony`, A NIE `wyslany`, I TO JEST CELOWE.
 * `PilnyAlarmModeracyjny implements ShouldQueue`, więc
 * `Notification::route('mail', …)->notify(…)` ZLECA list kolejce i wraca;
 * nie wie i nie może wiedzieć, czy EmailLabs go przyjął. Kolumna o nazwie
 * `wyslany_at` obiecywałaby wiedzę, której w tym miejscu nie ma — a cała
 * ta migracja powstała właśnie dlatego, że coś obiecywało więcej, niż
 * wiedziało. Za dalszy odcinek drogi odpowiadają `failed_jobs`
 * (sonda `kolejka`) i `mail_failures` (sonda `listy`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO BEZ WARTOŚCI DOMYŚLNEJ I BEZ WYPEŁNIANIA STARYCH WIERSZY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wierszom sprzed tej zmiany NIE WOLNO dopisać ani `'wyslany'`, ani
 * `'zalegly'`. Pierwsze byłoby kłamstwem (nikt tego nie zmierzył), drugie
 * zapaliłoby sondę na czerwono dla setek spraw, z których większość
 * alarmu nigdy nie potrzebowała — i nauczyłoby patrzeć na nią jak na szum.
 * `null` mówi prawdę: o tych sprawach nie wiemy nic i już się nie
 * dowiemy. Granica jest w dacie wdrożenia tej migracji.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO INDEKS CZĘŚCIOWY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sonda `/health` pyta o to przy KAŻDYM odpytaniu monitoringu, czyli co
 * kilka minut, i pyta o garstkę wierszy w tabeli, która rośnie z całym
 * serwisem. Warunek `WHERE alarm_pilny_stan IS NOT NULL AND
 * alarm_pilny_zlecony_at IS NULL` obejmuje dokładnie te wiersze, o które
 * pyta sonda, więc indeks jest mały i zostaje taki na zawsze: wiersz
 * z niego WYCHODZI w chwili, w której alarm dochodzi do skutku.
 */
return new class extends Migration
{
    private const TABELA = 'reports';

    private const KOLUMNA_STAN = 'alarm_pilny_stan';

    private const KOLUMNA_ZNACZNIK = 'alarm_pilny_zlecony_at';

    private const INDEKS = 'reports_pilny_alarm_bez_sladu';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA)) {
            return;
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABELA, self::KOLUMNA_STAN)) {
                $table->string(self::KOLUMNA_STAN, 20)->nullable();
            }

            if (! Schema::hasColumn(self::TABELA, self::KOLUMNA_ZNACZNIK)) {
                $table->timestampTz(self::KOLUMNA_ZNACZNIK)->nullable();
            }
        });

        DB::statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEKS.' ON '.self::TABELA
            .' ('.self::KOLUMNA_STAN.') WHERE '.self::KOLUMNA_STAN.' IS NOT NULL'
            .' AND '.self::KOLUMNA_ZNACZNIK.' IS NULL',
        );
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY JEST CO STRACIĆ (D-088, ten sam kształt co
     * `2026_09_11_700000_dodaj_znacznik_odebrania_dostepu`).
     *
     * Skasowanie kolumn kasuje jedyny zapis o tym, że pilna sprawa nie
     * dotarła do nikogo. Po `down()` prawie zawsze idzie kolejny `migrate`,
     * kolumny wracają PUSTE i nie ma błędu do zauważenia — a `/health`
     * świeci od tej chwili na zielono, bo zaległości „nie ma". To jest
     * dokładnie ta awaria, którą ta migracja naprawia, tylko wywołana
     * własnoręcznie.
     *
     * Gdy żaden wiersz nie ma zapisanego stanu, nie ma czego stracić
     * i cofnięcie przechodzi bez pytania.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABELA) || ! Schema::hasColumn(self::TABELA, self::KOLUMNA_STAN)) {
            return;
        }

        $zapisane = (int) DB::table(self::TABELA)->whereNotNull(self::KOLUMNA_STAN)->count();

        if ($zapisane > 0 && ! $this->wolnoSkasowacSlad()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby ślad po pilnych alarmach moderacyjnych. '
                .'Liczba spraw, których to dotyczy: '.$zapisane.".\n\n"
                ."CZYM TO GROZI\n"
                .'Po ponownym `migrate` kolumny wrócą puste, więc sonda `alarmy_moderacji` '
                .'w `/health` uzna, że żadna pilna sprawa nie czeka bez alarmu. Zgłoszenie, '
                ."które nie dotarło do nikogo, przestanie o sobie mówić.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW=true '
                .'php artisan migrate:rollback',
            );
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $kolumny = array_values(array_filter(
                [self::KOLUMNA_STAN, self::KOLUMNA_ZNACZNIK],
                static fn (string $kolumna): bool => Schema::hasColumn(self::TABELA, $kolumna),
            ));

            if ($kolumny !== []) {
                $table->dropColumn($kolumny);
            }
        });
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji.
     */
    private function wolnoSkasowacSlad(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
};
