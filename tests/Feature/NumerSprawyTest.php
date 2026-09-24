<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Numer sprawy jest unikalny — bo wcześniej nie był.
 *
 * CO BYŁO ZMIERZONE (7 września 2026, HANDOVER §7.4 pkt 4)
 * Numer pokazywany zgłaszającemu był ośmioma pierwszymi znakami UUID-a v7
 * wiersza. W UUID-zie v7 pierwsze 48 bitów to znacznik czasu w milisekundach,
 * więc osiem znaków szesnastkowych to jego 32 GÓRNE bity — zmieniają się raz
 * na 2^16 ms, czyli raz na 65,5 sekundy. Dwie sprawy przyjęte w tym samym
 * okienku dostawały ten sam numer.
 *
 * DLACZEGO TO WAŻNIEJSZE, NIŻ WYGLĄDA
 * Dla zgłaszającego BEZ KONTA numer jest jedynym śladem sprawy: nie ma konta,
 * nie ma listy zgłoszeń, a poczty serwis dziś nie wysyła. Numer powtórzony
 * znaczy, że ani on, ani moderator nie umie powiedzieć, o którą z dwóch spraw
 * chodzi — a każda ma własny termin odpowiedzi z DSA art. 16.
 *
 * `test_stary_sposob_wyliczania_numeru_naprawde_dawal_kolizje` jest tu
 * ASERCJĄ KONTROLNĄ całego pliku: pilnuje, że opisany wyżej defekt istniał
 * naprawdę. Gdyby UUID v7 kiedyś zmienił kształt i przestał kolidować, ten
 * test padnie i powie, że reszta pliku broni już czegoś innego, niż myśli.
 *
 * `test_check_w_bazie_zna_ten_sam_alfabet_co_stala_w_php` jest drugą taką
 * asercją, tyle że w drugą stronę: pilnuje, że ograniczenie w BAZIE nadal
 * mówi to samo, co stała w PHP. CHECK dostał treść alfabetu wklejoną raz,
 * w dniu migracji, i od tamtej pory jest zamrożony — więc sama edycja
 * `NumerSprawy::ALFABET` cicho psuje przyjmowanie zgłoszeń.
 */
class NumerSprawyTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszeniePrawne(array $atrybuty = []): Report
    {
        return Report::create(array_merge([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ], $atrybuty));
    }

    /**
     * Zgłoszenie z numerem podstawionym WPROST.
     *
     * `numer_sprawy` nie jest w `$fillable`, więc `create()` go po cichu
     * pomija — a bez tej drogi nie da się sprawdzić samych ograniczeń w bazie
     * (UNIQUE i CHECK), tylko zachowanie haka w modelu. To jedyne miejsce
     * w kodzie, które omija `$fillable`, i po to właśnie istnieje.
     */
    private function zgloszeniePrawneZNumerem(string $numer): Report
    {
        $zgloszenie = new Report;

        $zgloszenie->forceFill([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'spam',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
            'numer_sprawy' => $numer,
        ])->save();

        return $zgloszenie;
    }

    /**
     * ASERCJA KONTROLNA — patrz komentarz klasy.
     *
     * DLACZEGO ZNACZNIK UNIX, A NIE `now()->setDateTime(...)`
     * Kolizja zależy od MOMENTU w czasie, nie od cyfr na ścianie zegara,
     * bo UUID v7 koduje znacznik UTC w milisekundach. `now()` stoi w strefie
     * aplikacji, więc te same cyfry dają inny moment przy innym `timezone`
     * w `config/app.php` — a to przewracało cały sens tego testu:
     *
     *     UTC:           19:00:30 → 01a07d3e,  19:01:10 → 01a07d3e  (KOLIZJA)
     *     Europe/Warsaw: 19:00:30 → 01a07cd0,  19:01:10 → 01a07cd1  (brak)
     *
     * Policzone wprost: 1 788 807 630 000 ms / 2^16 = 27 295 038 = 0x01A07D3E,
     * a reszta w okienku to 19 632 ms — po dodaniu 40 000 ms wciąż mniej niż
     * 65 536 ms, więc górne 32 bity się nie zmieniają. W Europe/Warsaw (UTC+2)
     * ta sama godzina na ścianie to moment o 7 200 000 ms wcześniejszy, reszta
     * w okienku wynosi 28 592 ms i 40 sekund JĄ PRZEKRACZA — numery wychodzą
     * różne, test przechodziłby, nie sprawdzając niczego.
     *
     * Dlatego chwile budujemy przez `Carbon::createFromTimestampUTC()`.
     * Nie „posprzątaj" tego z powrotem do `now()` — dziś ratuje to wyłącznie
     * `'timezone' => 'UTC'` w `config/app.php`, a to jest ustawienie, nie
     * gwarancja.
     */
    public function test_stary_sposob_wyliczania_numeru_naprawde_dawal_kolizje(): void
    {
        // 1 788 807 630 = 2026-09-07 19:00:30 UTC.
        $chwila = Carbon::createFromTimestampUTC(1788807630);

        $pierwszy = (string) Str::uuid7($chwila);
        $drugi = (string) Str::uuid7($chwila->copy()->addSeconds(40));

        // Kontrola samego stanowiska pomiarowego: gdyby `createFromTimestampUTC()`
        // kiedyś przestało zwracać moment w UTC, reszta tego testu mierzyłaby
        // znów strefę aplikacji, a nie kształt UUID-a v7.
        //
        // Sprawdzamy PRZESUNIĘCIE, nie nazwę strefy. `createFromTimestampUTC()`
        // zwraca strefę nazwaną `+00:00`, nie `UTC` — pierwsza wersja tej
        // asercji porównywała nazwę i przez to padała, choć chwila była
        // dokładnie taka, jak trzeba. Znaczenie ma zero, nie napis.
        $this->assertSame(0, $chwila->getOffset(), 'Chwila pomiaru nie stoi w UTC.');
        $this->assertSame(1788807630, $chwila->getTimestamp());

        $this->assertNotSame($pierwszy, $drugi, 'Dwa UUID-y v7 z różnych chwil muszą być różne.');

        $this->assertSame(
            mb_strtoupper(mb_substr($pierwszy, 0, 8)),
            mb_strtoupper(mb_substr($drugi, 0, 8)),
            'Osiem pierwszych znaków UUID-a v7 przestało kolidować w odstępie 40 sekund — '
            .'reszta tego pliku broni wtedy czegoś innego, niż opisuje.',
        );
    }

    public function test_dwie_sprawy_w_tym_samym_okienku_maja_rozne_numery(): void
    {
        $pierwsza = $this->zgloszeniePrawne();
        $druga = $this->zgloszeniePrawne(['reason' => 'spam']);

        // Kontrola, że test nie przechodzi „bo tabela jest pusta".
        $this->assertSame(2, Report::query()->count());

        $this->assertNotSame(
            $pierwsza->numer_sprawy,
            $druga->numer_sprawy,
            'Dwie sprawy przyjęte jedna po drugiej dostały ten sam numer.',
        );
    }

    public function test_numer_ma_format_do_przepisania_z_ekranu(): void
    {
        $numer = $this->zgloszeniePrawne()->numer_sprawy;

        $this->assertMatchesRegularExpression(NumerSprawy::WZOR, (string) $numer);

        // Znaki mylące sprawdzamy WPROST NA ALFABECIE, nie na wylosowanej
        // wartości. Pierwsza wersja tego testu patrzyła na wynik losowania
        // i dlatego przepuściła `U` w alfabecie: szansa, że osiem losowych
        // znaków go nie zawiera, to (29/30)^8 ≈ 76%. Test, który przechodzi
        // w trzech na cztery przebiegi, nie broni niczego.
        foreach (NumerSprawy::ZNAKI_MYLACE as $znak) {
            $this->assertStringNotContainsString(
                $znak,
                NumerSprawy::ALFABET,
                "Alfabet numeru sprawy zawiera mylący znak „{$znak}\".",
            );
        }

        $this->assertSame(30, mb_strlen(NumerSprawy::ALFABET), 'Alfabet zmienił rozmiar — przelicz szansę kolizji.');

        // I kontrola w drugą stronę: każdy znak numeru pochodzi z alfabetu.
        foreach (mb_str_split(str_replace(['KU', '-'], '', (string) $numer)) as $znak) {
            $this->assertStringContainsString($znak, NumerSprawy::ALFABET);
        }
    }

    public function test_baza_nie_przyjmuje_drugiej_sprawy_z_tym_samym_numerem(): void
    {
        $pierwsza = $this->zgloszeniePrawne();

        $this->expectException(QueryException::class);

        // Numer podstawiony wprost — hak modelu nadpisuje tylko brak, więc
        // to jest jedyna droga, żeby sprawdzić samo ograniczenie w bazie.
        $this->zgloszeniePrawneZNumerem((string) $pierwsza->numer_sprawy);
    }

    public function test_baza_nie_przyjmuje_numeru_w_innym_formacie(): void
    {
        $this->expectException(QueryException::class);

        // CHECK w bazie, nie sam wzór w PHP: wartość w innym formacie nie ma
        // prawa wejść żadną drogą, także przez `tinker` albo przyszłe API.
        $this->zgloszeniePrawneZNumerem('AB-0000-0000');
    }

    /**
     * DRUGA ASERCJA KONTROLNA: wzór w bazie kontra stała w PHP.
     *
     * Migracja `2026_09_07_910000_add_numer_sprawy_to_reports` wkleiła
     * `NumerSprawy::ALFABET` do treści CHECK-a JEDEN RAZ. PostgreSQL trzyma
     * gotowe wyrażenie, więc od tamtej chwili wzór w bazie jest ZAMROŻONY,
     * a stała w PHP liczy się od nowa przy każdym uruchomieniu. Te dwa wzory
     * mogą się rozjechać i nic w kodzie samo tego nie zauważy.
     *
     * Cena rozjazdu: `wygeneruj()` losuje ze zmienionego alfabetu, baza
     * odrzuca `INSERT`, a KAŻDE nowe zgłoszenie — łącznie z drogą DSA art. 16
     * dla osoby bez konta, która nie ma innego sposobu założenia sprawy —
     * kończy się `QueryException` i błędem 500.
     *
     * Dlatego czytamy RZECZYWISTĄ definicję ograniczenia z katalogu systemowego,
     * a nie plik migracji. Ten test ma paść, gdy ktoś zmieni `ALFABET` bez
     * migracji przebudowującej CHECK.
     */
    public function test_check_w_bazie_zna_ten_sam_alfabet_co_stala_w_php(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dotyczy wyłącznie PostgreSQL.');
        }

        $wiersz = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definicja FROM pg_constraint WHERE conname = ?',
            ['reports_numer_sprawy_check'],
        );

        // KONTROLA: bez tego test przeszedłby PUSTO na bazie, w której CHECK-a
        // w ogóle nie ma — czyli dokładnie tam, gdzie najbardziej by się
        // przydał (rollback migracji, ręcznie zdjęte ograniczenie, świeża baza
        // postawiona ze zrzutu bez ograniczeń).
        $this->assertNotNull(
            $wiersz,
            'W bazie nie ma ograniczenia `reports_numer_sprawy_check`. '
            .'Format numeru sprawy nie jest niczym pilnowany po stronie bazy.',
        );

        $definicja = (string) ($wiersz->definicja ?? '');

        $this->assertNotSame(
            '',
            $definicja,
            '`pg_get_constraintdef()` zwrócił pustą definicję dla `reports_numer_sprawy_check`.',
        );

        // Alfabet wyłuskujemy z definicji, zamiast tylko szukać w niej całego
        // wzoru: dzięki temu komunikat porażki pokazuje OBA alfabety obok
        // siebie, a nie każe porównywać trzydziestoznakowych ciągów wzrokiem.
        $this->assertSame(
            1,
            preg_match('/\^KU-\[([^\]]+)\]\{4\}-\[([^\]]+)\]\{4\}\$/', $definicja, $dopasowanie),
            'Definicja CHECK-a nie wygląda już jak wzór numeru sprawy: '.$definicja,
        );

        $this->assertSame(
            NumerSprawy::ALFABET,
            $dopasowanie[1],
            'Alfabet zamrożony w bazie różni się od `NumerSprawy::ALFABET`. '
            .'Zmiana stałej wymaga migracji przebudowującej `reports_numer_sprawy_check` — '
            .'bez niej `wygeneruj()` produkuje numery, których baza nie przyjmie.',
        );

        $this->assertSame(
            $dopasowanie[1],
            $dopasowanie[2],
            'Dwie połówki numeru mają w bazie różne alfabety.',
        );

        // I cały wzór dokładnie tak, jak składa go migracja — na wypadek,
        // gdyby rozjechała się nie sama lista znaków, lecz reszta wyrażenia
        // (długość członów, przedrostek, kotwice).
        $this->assertStringContainsString(
            '^KU-['.NumerSprawy::ALFABET.']{4}-['.NumerSprawy::ALFABET.']{4}$',
            $definicja,
            'CHECK w bazie ma inny wzór niż składa `NumerSprawy`. Definicja z bazy: '.$definicja,
        );

        // Domknięcie: świeżo wylosowany numer przechodzi przez TEN wzór
        // z bazy, a nie tylko przez wzór z PHP. Gdyby generator i CHECK już
        // się rozjechały, `INSERT` rzuci `QueryException` i test padnie tutaj —
        // czyli tam, gdzie w produkcji padłoby zgłoszenie z DSA art. 16.
        // Wzór składany z alfabetu ODCZYTANEGO Z BAZY, nie ze stałej w PHP.
        $wzorZBazy = '/^KU-['.$dopasowanie[1].']{4}-['.$dopasowanie[2].']{4}$/';

        $this->assertMatchesRegularExpression(
            $wzorZBazy,
            (string) $this->zgloszeniePrawne()->numer_sprawy,
        );
    }

    public function test_numeru_nie_da_sie_podstawic_z_zadania(): void
    {
        // `numer_sprawy` nie jest w `$fillable` — tożsamość sprawy nadaje
        // serwer, nie człowiek (AGENTS.md §7, ta sama zasada co `status`).
        // Ciche odrzucenie jak w produkcji, nie wyjątek trybu ścisłego (#976).
        $this->mierzMasowePrzypisanieJakWProdukcji();
        $zgloszenie = new Report;
        $zgloszenie->fill(['numer_sprawy' => 'KU-2222-3333', 'reason' => 'spam']);

        $this->assertNull($zgloszenie->numer_sprawy, '`numer_sprawy` da się ustawić przez `fill()`.');
        $this->assertSame('spam', $zgloszenie->reason, 'Kontrola: `fill()` w ogóle działa na tym modelu.');
    }

    public function test_zgloszenie_bez_konta_widzi_na_ekranie_numer_ze_swojego_wiersza(): void
    {
        $formularz = $this->get(route('zglos.nielegalna'));
        $formularz->assertOk();

        $odpowiedz = $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
            'klucz_wyslania' => (string) Str::uuid7(),
        ]);

        $zgloszenie = Report::query()->sole();
        $odpowiedz->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $ekran = $this->get($odpowiedz->headers->get('Location'))->assertOk();

        $this->assertSame(
            $zgloszenie->numer_sprawy,
            $ekran->viewData('numer'),
            'Ekran potwierdzenia pokazuje inny numer, niż stoi w wierszu sprawy.',
        );

        // I ten sam numer musi być widoczny na ekranie — nie tylko w sesji.
        $ekran->assertSee((string) $zgloszenie->numer_sprawy);
    }

    public function test_kazda_droga_przez_model_daje_numer_a_baza_pilnuje_reszty(): void
    {
        // Droga przez model (formularz, seeder, komenda, `tinker`) numer
        // dostaje z haka `creating`.
        $zgloszenie = $this->zgloszeniePrawne();

        $this->assertNotNull($zgloszenie->numer_sprawy, 'Zgłoszenie z `create()` nie dostało numeru.');
        $this->assertSame(
            0,
            DB::table('reports')->whereNull('numer_sprawy')->count(),
            'W tabeli jest wiersz bez numeru sprawy.',
        );

        // A droga OMIJAJĄCA model — surowy `INSERT` — nie przechodzi wcale,
        // bo kolumna jest `NOT NULL`. To jest ta połowa ochrony, której hak
        // w modelu dać nie może, i dlatego jedno nie zastępuje drugiego.
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            'id' => (string) Str::uuid7(),
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'spam',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
