<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Contracts\Notifications\Dispatcher as DyspozytorPowiadomien;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * PILNY ALARM MODERACYJNY NIE GINIE MIĘDZY ZAPISEM A WYSŁANIEM (issue #1051).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO DOKŁADNIE GINĘŁO
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Oznaczenie automatu powstawało we WŁASNEJ, zamkniętej transakcji
 * (`OznaczDoPrzegladu`), a alarm szedł linijkę PÓŹNIEJ, już poza nią
 * (`PrzeanalizujTresc::handle()`). Wszystko, co zabiło workera w tej
 * szczelinie — `timeout = 30`, `tries = 1`, restart kontenera przy
 * wdrożeniu, OOM — zostawiało sprawę ZAPISANĄ, a moderatora
 * NIEPOWIADOMIONEGO.
 *
 * I to była dopiero połowa: `OznaczDoPrzegladu` przy istniejącym wierszu
 * oddawał `null`, a oba zadania mają ten sam warunek
 * `if ($oznaczenie !== null) { $alarm->handle(...); }`. ISTNIENIE sprawy
 * WYŁĄCZAŁO więc alarm, a nie włączało go — czyli ponowna analiza tej samej
 * treści nie miała prawa niczego naprawić. Zgubione zostawało zgubione.
 *
 * Dotyczy to JEDYNYCH dwóch kategorii, przy których doba zwłoki jest realną
 * szkodą: treści seksualnych i wszystkiego, co dotyczy dziecka
 * (`KategorieModeracji::PILNE`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TEN PLIK MIERZY
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Jedno zdanie: sprawa pilna ALBO dociera, ALBO zostawia w bazie ślad, że
 * nie dotarła. Cisza nie ma prawa wyglądać tak samo jak brak zgłoszeń —
 * ta sama reguła, którą `StanKopiiBazy` egzekwuje stanem `WYLACZONA` przy
 * pustym buckecie.
 *
 * Testy patrzą więc NA WIERSZ (`reports.alarm_pilny_stan`,
 * `reports.alarm_pilny_zlecony_at`) i na sondę `/health`, a nie tylko na
 * `Notification::assertSentOnDemand()`. Asercja o wysłanym powiadomieniu
 * przechodziła na `main` przy pierwszym przebiegu i nie zauważała niczego
 * z tego, co tu jest opisane.
 */
class PilnyAlarmModeracyjnyNieGinieTest extends TestCase
{
    use RefreshDatabase;

    private const ALARM = 'moderacja@example.test';

    /** Treść, której model nie widzi — pilność wstrzykujemy sygnałem wprost. */
    private const TRESC = 'Wpis, przy którym zwłoka jednego dnia jest realną szkodą.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.alarm_email' => self::ALARM,
            'kuking.moderation.model.ocenia_zdjecia' => false,
        ]);
    }

    private function osoba(string $login): User
    {
        $user = $this->user($login);
        $user->forceFill(['created_at' => now()->subDays(400)])->save();

        return $user->refresh();
    }

    private function wpis(User $autor, string $tekst = self::TRESC): Post
    {
        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * Odpowiedź modelu, po której sprawa JEST pilna.
     *
     * `sexual/minors` powyżej `prog_pilny` (0,2) — jedna z dwóch kategorii
     * z `KategorieModeracji::PILNE`.
     */
    private function modelWidziPilne(): void
    {
        Http::fake([
            '*api.openai.com*' => Http::response([
                'results' => [['flagged' => false, 'category_scores' => ['sexual/minors' => 0.44]]],
            ]),
        ]);
    }

    /** Odpowiedź modelu, po której sprawa powstaje, ale pilna NIE jest. */
    private function modelWidziZwykle(): void
    {
        Http::fake([
            '*api.openai.com*' => Http::response([
                'results' => [['flagged' => false, 'category_scores' => ['hate' => 0.91]]],
            ]),
        ]);
    }

    private function analizuj(Post $wpis): void
    {
        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));
    }

    private function oznaczenie(): ?Report
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->first();
    }

    /**
     * Kanał powiadomień, który PADA — bez udawania SMTP.
     *
     * `AnonymousNotifiable::notify()` woła dyspozytora z kontenera, więc
     * podmiana kontraktu jest najkrótszą drogą do awarii dokładnie w tym
     * miejscu, w którym pada prawdziwa poczta.
     */
    private function poczcieBrakujeSkrzydel(): void
    {
        $this->app->bind(DyspozytorPowiadomien::class, fn () => new class
        {
            public function __call(string $metoda, array $argumenty): never
            {
                throw new RuntimeException('Kanał pocztowy nie odpowiada (awaria udawana w teście).');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. ZAPIS SIĘ UDAJE I POWIADOMIENIE IDZIE
    // ═══════════════════════════════════════════════════════════════════

    public function test_sprawa_pilna_dociera_i_zostawia_znacznik(): void
    {
        Notification::fake();
        $this->modelWidziPilne();

        $this->analizuj($this->wpis($this->osoba('pilna')));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);

        $sprawa = $this->oznaczenie();
        $this->assertNotNull($sprawa, 'Sprawa pilna nie powstała w ogóle.');
        $this->assertSame(Report::ALARM_ZLECONY, $sprawa->alarm_pilny_stan);
        $this->assertNotNull(
            $sprawa->alarm_pilny_zlecony_at,
            'Alarm poszedł, a wiersz o tym nie wie — po restarcie nikt już tego nie odtworzy.',
        );

        $this->assertSame(0, Report::query()->pilneBezAlarmu()->count());
        $this->assertTrue($this->get('/health')->json('checks.alarmy_moderacji.ok'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. ZAPIS SIĘ UDAJE, POWIADOMIENIE PADA — MA ZOSTAĆ ŚLAD
    // ═══════════════════════════════════════════════════════════════════

    public function test_gdy_powiadomienie_pada_sprawa_zostaje_i_mowi_ze_nie_dotarla(): void
    {
        $this->modelWidziPilne();
        $this->poczcieBrakujeSkrzydel();

        $this->analizuj($this->wpis($this->osoba('padnieta')));

        $sprawa = $this->oznaczenie();

        // GRANICA, KTÓRA ZOSTAJE: awaria powiadomienia nie ma prawa zabrać
        // sprawy z kolejki moderatora.
        $this->assertNotNull($sprawa, 'Awaria poczty zabrała pozycję z kolejki — a miała zabrać tylko list.');
        $this->assertSame(Report::ALARM_NIEUDANY, $sprawa->alarm_pilny_stan);
        $this->assertNull($sprawa->alarm_pilny_zlecony_at);

        // TO JEST CAŁE ZADANIE: cisza ma inny kształt niż brak zgłoszeń.
        $odpowiedz = $this->get('/health');
        $this->assertFalse($odpowiedz->json('checks.alarmy_moderacji.ok'));
        $this->assertSame('pilny_alarm_nie_dotarl', $odpowiedz->json('checks.alarmy_moderacji.error'));
        $this->assertSame('degraded', $odpowiedz->json('status'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. TRANSAKCJA WYWRACA SIĘ W POŁOWIE
    // ═══════════════════════════════════════════════════════════════════

    public function test_wywrocona_transakcja_nie_zostawia_ani_sprawy_ani_falszywego_sladu(): void
    {
        Notification::fake();
        $this->modelWidziPilne();

        /*
         * Wyjątek leci ZE ŚRODKA `DB::transaction()` w `OznaczDoPrzegladu`,
         * czyli po `INSERT`-cie, a przed `COMMIT`-em — dokładnie „w połowie".
         *
         * `Event::listen`/`Event::forget` zamiast `Report::created()`
         * i `flushEventListeners()`: cała suita chodzi w JEDNYM procesie,
         * więc słuchacz zostawiony po sobie wywracałby każdy następny test,
         * a `flushEventListeners()` zabrałby przy okazji hak `creating`
         * z `Report::booted()`, który nadaje numer sprawy.
         */
        $zdarzenie = 'eloquent.created: '.Report::class;

        Event::listen($zdarzenie, static function (): never {
            throw new RuntimeException('Awaria w połowie transakcji (udawana w teście).');
        });

        try {
            $this->analizuj($this->wpis($this->osoba('wywrotka')));
        } finally {
            Event::forget($zdarzenie);
        }

        $this->assertSame(0, Report::query()->count(), 'Wycofana transakcja zostawiła po sobie wiersz.');
        Notification::assertNothingSent();

        // Sprawy nie ma, więc nie ma też o czym alarmować — sonda MUSI być
        // zielona. Czerwień w tym miejscu nauczyłaby na nią nie patrzeć.
        $this->assertTrue($this->get('/health')->json('checks.alarmy_moderacji.ok'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. SEDNO REGRESJI: SPRAWA JUŻ JEST, ALARMU NIE BYŁO
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Worker ginie w szczelinie między zatwierdzeniem sprawy a alarmem —
     * odtworzone WPROST, bez udawania sygnałów: wołamy samo
     * `OznaczDoPrzegladu`, czyli dokładnie tyle, ile zdążył zrobić.
     *
     * Na `main` ten test jest czerwony w linijce
     * `assertSentOnDemandTimes(..., 1)`: ponowna analiza widziała istniejący
     * wiersz, dostawała `null` i milczała. Na zawsze.
     */
    public function test_ponowna_analiza_dosyla_alarm_ktory_zginal_przy_pierwszym_przebiegu(): void
    {
        Notification::fake();
        $this->modelWidziPilne();

        $autor = $this->osoba('szczelina');
        $wpis = $this->wpis($autor);

        // Tyle, ile zdążył martwy worker: sprawa zapisana, alarm nie.
        $sprawa = app(OznaczDoPrzegladu::class)->handle($wpis, [
            new Sygnal('automat_model', 'Model wskazał treść seksualną z udziałem dziecka.', pilny: true),
        ]);

        $this->assertNotNull($sprawa);
        Notification::assertNothingSent();

        // ŚLAD JEST OD RAZU, bo obowiązek alarmu zapisuje ta sama transakcja,
        // która zapisała sprawę. Bez tego nie dałoby się odróżnić sprawy
        // pilnej bez alarmu od sprawy, która pilna nigdy nie była.
        $this->assertSame(Report::ALARM_ZALEGLY, $sprawa->refresh()->alarm_pilny_stan);
        $this->assertSame(1, Report::query()->pilneBezAlarmu()->count());
        $this->assertFalse($this->get('/health')->json('checks.alarmy_moderacji.ok'));

        // A TERAZ NAPRAWA: ta sama treść, ta sama analiza, drugi przebieg.
        $this->analizuj($wpis);

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);

        $sprawa->refresh();
        $this->assertSame(Report::ALARM_ZLECONY, $sprawa->alarm_pilny_stan);
        $this->assertNotNull($sprawa->alarm_pilny_zlecony_at);

        // Jedna treść — jedna pozycja w kolejce. Dosłanie alarmu nie ma
        // prawa postawić drugiej (`reports_jeden_automat_na_tresc`).
        $this->assertSame(1, Report::query()->where('source', Report::SOURCE_AUTOMAT)->count());
        $this->assertTrue($this->get('/health')->json('checks.alarmy_moderacji.ok'));
    }

    public function test_trzecia_analiza_nie_doklada_drugiego_listu(): void
    {
        Notification::fake();
        $this->modelWidziPilne();

        $wpis = $this->wpis($this->osoba('dwarazy'));

        $this->analizuj($wpis);
        $this->analizuj($wpis);
        $this->analizuj($wpis);

        // Alarm, który przychodzi trzy razy, uczy moderatora go przewijać.
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(1, Report::query()->where('source', Report::SOURCE_AUTOMAT)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. KANAŁ ALARMOWY WYŁĄCZONY — CISZA Z BRAKU KONFIGURACJI
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Na produkcji `KUKING_MODEL_ALARM_EMAIL` jest dziś PUSTY, a rejestracja
     * stoi otworem dla każdego. Sprawa, która tu przepadnie, nie dotrze
     * nigdzie — i do 22 września 2026 wyglądało to identycznie jak dzień bez
     * ani jednego pilnego zgłoszenia.
     */
    public function test_pusty_adres_alarmowy_ma_wlasny_nazwany_stan(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => null]);
        $this->modelWidziPilne();

        $this->analizuj($this->wpis($this->osoba('bezadresu')));

        $sprawa = $this->oznaczenie();
        $this->assertNotNull($sprawa, 'Brak adresu zabrał pozycję z kolejki — a miał zabrać tylko list.');
        Notification::assertNothingSent();

        $this->assertSame(Report::ALARM_BEZ_ADRESU, $sprawa->alarm_pilny_stan);
        $this->assertNull($sprawa->alarm_pilny_zlecony_at);

        // OSOBNY KOD POWODU: operator naprawia to wpisaniem adresu, nie
        // szukaniem błędu w kodzie ani ponowieniem zadania.
        $odpowiedz = $this->get('/health');
        $this->assertFalse($odpowiedz->json('checks.alarmy_moderacji.ok'));
        $this->assertSame('kanal_alarmowy_wylaczony', $odpowiedz->json('checks.alarmy_moderacji.error'));
    }

    public function test_po_wpisaniu_adresu_zalegly_alarm_da_sie_doslac(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => null]);
        $this->modelWidziPilne();

        $wpis = $this->wpis($this->osoba('doslanie'));
        $this->analizuj($wpis);

        $this->assertSame(Report::ALARM_BEZ_ADRESU, $this->oznaczenie()?->alarm_pilny_stan);

        config(['kuking.moderation.model.alarm_email' => self::ALARM]);
        $this->analizuj($wpis);

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(Report::ALARM_ZLECONY, $this->oznaczenie()?->alarm_pilny_stan);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. SPRAWA NIEPILNA NIE ZAPALA NICZEGO
    // ═══════════════════════════════════════════════════════════════════

    public function test_zwykle_oznaczenie_nie_zostawia_stanu_alarmu(): void
    {
        Notification::fake();
        $this->modelWidziZwykle();

        $this->analizuj($this->wpis($this->osoba('zwykla')));

        $sprawa = $this->oznaczenie();
        $this->assertNotNull($sprawa);
        Notification::assertNothingSent();

        // `null` znaczy „automat nie uznał tej sprawy za pilną" i tak wygląda
        // ogromna większość wierszy. Gdyby zwykłe oznaczenia zapalały sondę,
        // nikt by na nią nie patrzył po tygodniu.
        $this->assertNull($sprawa->alarm_pilny_stan);
        $this->assertSame(0, Report::query()->pilneBezAlarmu()->count());
        $this->assertTrue($this->get('/health')->json('checks.alarmy_moderacji.ok'));
    }

    public function test_alarm_oddaje_nazwany_stan_zamiast_golego_false(): void
    {
        Notification::fake();

        $wpis = $this->wpis($this->osoba('nazwy'));

        $sprawa = app(OznaczDoPrzegladu::class)->handle($wpis, [
            new Sygnal('automat_wzorzec', 'Znany wzorzec spamu.'),
        ]);

        $this->assertNotNull($sprawa);

        $alarm = app(AlarmujModeratora::class);

        // DWIE RÓŻNE CISZE, DWIE RÓŻNE NAZWY. Przedtem obie oddawały `false`,
        // a obaj wołający ten wynik ignorowali — więc różnica nie miała gdzie
        // się objawić.
        $this->assertSame(
            AlarmujModeratora::NIEPILNE,
            $alarm->handle($sprawa, [new Sygnal('automat_wzorzec', 'Znany wzorzec spamu.')]),
        );

        config(['kuking.moderation.model.alarm_email' => null]);

        $this->assertSame(
            Report::ALARM_BEZ_ADRESU,
            $alarm->handle($sprawa, [new Sygnal('automat_model', 'Pilne.', pilny: true)]),
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  7. AWARIA MIĘDZY ZAPISEM SPRAWY A ALARMEM (D-249)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Awaria `audit_log` PO zatwierdzeniu sprawy, a PRZED alarmem.
     *
     * Na gałęzi przed scaleniem z D-249 `OznaczDoPrzegladu` wołał gołe
     * `record()` za transakcją: wyjątek wracał do blankietowego `catch`
     * w `PrzeanalizujTresc` i alarm nie szedł wcale — dokładnie szczelina
     * z #1051, tylko otwarta przez dziennik zamiast przez martwego workera.
     */
    public function test_awaria_dziennika_audytu_nie_zjada_pilnego_alarmu(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->modelWidziPilne();

        DB::listen(static function ($zapytanie): void {
            if (str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array('content.flagged_by_automat', $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika: content.flagged_by_automat');
            }
        });

        $this->analizuj($this->wpis($this->osoba('dziennik')));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(Report::ALARM_ZLECONY, $this->oznaczenie()?->alarm_pilny_stan);

        // Brak wpisu nie jest cichy: idzie do `report()` z nazwą akcji.
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'content.flagged_by_automat')->count());
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '„content.flagged_by_automat"'));
    }

    public function test_kontrola_dodatnia_bez_awarii_dziennik_ma_wpis_oznaczenia(): void
    {
        Notification::fake();
        $this->modelWidziPilne();

        $this->analizuj($this->wpis($this->osoba('dziennikok')));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'content.flagged_by_automat')->count());
    }

    /**
     * Dwa zadania o tej samej sprawie: pierwsze zleciło alarm, drugie
     * (z wierszem wczytanym wcześniej) trafia na padniętą pocztę.
     * Porażka nie ma prawa nadpisać sukcesu — sonda zapaliłaby się przy
     * sprawie, o której moderator już wie, a CHECK
     * `reports_alarm_pilny_spojny_check` i tak by ten zapis odrzucił.
     */
    public function test_spozniona_porazka_nie_nadpisuje_zleconego_alarmu(): void
    {
        $wpis = $this->wpis($this->osoba('wyscig'));
        $pilne = [new Sygnal('automat_model', 'Model wskazał treść seksualną z udziałem dziecka.', pilny: true)];

        $stara = app(OznaczDoPrzegladu::class)->handle($wpis, $pilne);
        $this->assertNotNull($stara);

        // Pierwsze zadanie zdążyło: w bazie alarm zlecony, `$stara` o tym nie wie.
        Report::query()->whereKey($stara->getKey())->update([
            'alarm_pilny_stan' => Report::ALARM_ZLECONY,
            'alarm_pilny_zlecony_at' => now(),
        ]);

        $this->poczcieBrakujeSkrzydel();

        $this->assertSame(AlarmujModeratora::JUZ_ZLECONY, app(AlarmujModeratora::class)->handle($stara, $pilne));

        $sprawa = $this->oznaczenie();
        $this->assertSame(Report::ALARM_ZLECONY, $sprawa?->alarm_pilny_stan);
        $this->assertNotNull($sprawa?->alarm_pilny_zlecony_at);
        $this->assertTrue($this->get('/health')->json('checks.alarmy_moderacji.ok'));
    }
}
