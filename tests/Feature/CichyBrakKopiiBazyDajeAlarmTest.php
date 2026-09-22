<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kopie\AlarmKopii;
use App\Domain\Kopie\StanKopiiBazy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Kopia, która po cichu przestała powstawać, MUSI dać alarm, a nie ciszę.
 *
 * DLACZEGO TO JEST OSOBNE ZABEZPIECZENIE, A NIE DUBLOWANIE TEGO,
 * CO ROBI SERWIS KOPII
 * Zrzut robi osobny serwis Railway (`docker/kopia/`, decyzja D-043) i on sam
 * alarmuje, gdy jego przebieg się nie udał. Nie zaalarmuje natomiast, gdy
 * przebiegu NIE BYŁO: serwis skasowany, harmonogram wyłączony, limit konta
 * wyczerpany, token wygasł. Kod, który wtedy nie chodzi, nie może o sobie
 * donieść — a issue #193 nazywa ten stan najgorszym z możliwych, bo
 * „myślisz, że masz kopię".
 *
 * Dlatego aplikacja patrzy na to z drugiej strony: raz na dobę listuje bucket
 * i dzwoni, gdy najnowsza kopia jest za stara albo gdy nie ma żadnej.
 *
 * CZEGO TEN TEST NIE DOWODZI: że kopia jest poprawna. Tego nie da się
 * sprawdzić z aplikacji — zrzut jest zaszyfrowany kluczem publicznym,
 * którego pary nie ma w żadnym środowisku uruchomieniowym. Poprawność
 * potwierdza człowiek ćwiczeniem odtworzenia (`KOPIE_I_ODTWORZENIE.md` §4).
 */
class CichyBrakKopiiBazyDajeAlarmTest extends TestCase
{
    private const PREFIKS = 'baza/';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_kopie');

        config()->set('kuking.kopie.dysk', 'r2_kopie');
        config()->set('kuking.kopie.prefiks', self::PREFIKS);
        config()->set('kuking.kopie.maks_wiek_godzin', 36);

        // Nazwa bucketu decyduje o tym, czy czujka jest włączona — bez niej
        // klasa świadomie nic nie robi (umowa „brak zmiennej = zero efektu").
        config()->set('filesystems.disks.r2_kopie.bucket', 'kuking-kopie-test');
    }

    private function polozKopie(string $znacznik): void
    {
        Storage::disk('r2_kopie')->put(
            self::PREFIKS."kuking-{$znacznik}Z.dump.cms",
            'to-udaje-szyfrogram',
        );
    }

    #[Test]
    public function swieza_kopia_nie_alarmuje(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260909-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::AKTUALNA, $wynik['stan']);
        $this->assertSame(1, $wynik['liczba']);
        $this->assertFalse(app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik));
    }

    #[Test]
    public function kopia_starsza_niz_prog_jest_przestarzala(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        // Ostatnia kopia z 7 września rano — czyli dwa przebiegi wypadły.
        $this->polozKopie('20260907-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::PRZESTARZALA, $wynik['stan']);
        $this->assertSame(53, $wynik['wiek_godzin']);
    }

    #[Test]
    public function kopia_ktora_wypadla_raz_jeszcze_nie_alarmuje(): void
    {
        // Granica ma znaczenie: przy kopii raz na dobę jeden przebieg ma prawo
        // wypaść (restart, chwilowa niedostępność R2). Alarm po każdym
        // pojedynczym potknięciu uczy ignorowania alarmów — a to jest ta
        // sama lekcja, co z licznika szybkich śmierci w entrypoincie.
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260908-021700');

        $this->assertSame(StanKopiiBazy::AKTUALNA, app(StanKopiiBazy::class)->sprawdz()['stan']);
    }

    #[Test]
    public function pusty_bucket_to_brak_kopii_a_nie_porzadek(): void
    {
        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::BRAK_KOPII, $wynik['stan']);
        $this->assertSame(0, $wynik['liczba']);
    }

    #[Test]
    public function wiek_liczy_sie_od_najnowszej_kopii_nie_od_najstarszej(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260801-021700');
        $this->polozKopie('20260909-021700');
        $this->polozKopie('20260905-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::AKTUALNA, $wynik['stan']);
        $this->assertSame(3, $wynik['liczba']);
    }

    #[Test]
    public function plik_o_nierozpoznanej_nazwie_nie_udaje_swiezej_kopii(): void
    {
        // Pomyłka w tę stronę kosztuje jeden zbędny alarm. W drugą —
        // fałszywy spokój, czyli dokładnie to, przed czym stoi ten test.
        Carbon::setTestNow('2026-09-09 08:00:00');
        Storage::disk('r2_kopie')->put(self::PREFIKS.'recznie-zrobiona-kopia.dump.cms', 'x');

        $this->assertSame(StanKopiiBazy::BRAK_KOPII, app(StanKopiiBazy::class)->sprawdz()['stan']);
    }

    #[Test]
    public function plik_meta_nie_jest_liczony_jako_kopia(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        Storage::disk('r2_kopie')->put(self::PREFIKS.'kuking-20260909-021700Z.meta', 'znacznik: x');

        $this->assertSame(StanKopiiBazy::BRAK_KOPII, app(StanKopiiBazy::class)->sprawdz()['stan']);
    }

    #[Test]
    public function brak_skonfigurowanego_bucketu_wylacza_czujke_ale_tego_nie_ukrywa(): void
    {
        config()->set('filesystems.disks.r2_kopie.bucket', null);

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::WYLACZONA, $wynik['stan']);
        $this->assertFalse(app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik));

        // Komenda MUSI o tym powiedzieć wprost. Cisza z powodu braku
        // konfiguracji wygląda identycznie jak cisza z powodu „wszystko
        // w porządku" — i to jest cała pułapka tego issue.
        $this->artisan('kuking:sprawdz-kopie')
            ->expectsOutputToContain('WYŁĄCZONA')
            ->assertSuccessful();
    }

    #[Test]
    public function komenda_konczy_sie_bledem_gdy_swiezej_kopii_nie_ma(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260901-021700');

        $this->artisan('kuking:sprawdz-kopie --bez-alarmu')->assertFailed();
    }

    #[Test]
    public function komenda_konczy_sie_sukcesem_gdy_kopia_jest_swieza(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260909-021700');

        $this->artisan('kuking:sprawdz-kopie --bez-alarmu')->assertSuccessful();
    }

    // =========================================================================
    //  TREŚĆ ALARMU — audyt A6-01
    //
    //  Kanał `blad_webhook` wychodzi do Discorda albo Slacka, czyli do usługi,
    //  nad którą nie mamy żadnej kontroli. Handler przeszedł we wrześniu 2026
    //  naprawę prywatnościową: przestał wysyłać komunikat wyjątku, bo
    //  `QueryException` potrafił wnieść w nim adres e-mail i hash hasła
    //  prosto ze sterownika bazy. Ten alarm dokłada się do TEJ SAMEJ listy
    //  dozwolonych pól i musi się do niej stosować.
    // =========================================================================

    #[Test]
    public function alarm_mowi_co_zrobic_i_nie_wynosi_nazwy_bucketu(): void
    {
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260901-021700');

        $tresc = app(AlarmKopii::class)->tresc(app(StanKopiiBazy::class)->sprawdz());

        $this->assertStringContainsString('kopia bazy', $tresc);
        $this->assertStringContainsString('KOPIE_I_ODTWORZENIE.md', $tresc);

        // Nazwa bucketu, klucz obiektu i poświadczenia nie kupują ani jednej
        // minuty przy diagnozie (i tak stoją w zmiennych środowiskowych),
        // a są podpowiedzią dla kogokolwiek, kto przejmie ten kanał.
        $this->assertStringNotContainsString('kuking-kopie-test', $tresc);
        $this->assertStringNotContainsString('.dump.cms', $tresc);
        $this->assertStringNotContainsString('baza/', $tresc);
    }

    #[Test]
    public function alarm_o_niedostepnym_buckecie_nie_wynosi_komunikatu_wyjatku(): void
    {
        // Wyjątek z warstwy S3 potrafi nieść nazwę bucketu, endpoint,
        // a przy złym podpisie także fragment identyfikatora klucza.
        $tresc = app(AlarmKopii::class)->tresc([
            'stan' => StanKopiiBazy::NIEDOSTEPNY,
            'wiek_godzin' => null,
            'liczba' => 0,
            'prog_godzin' => 36,
        ]);

        $this->assertStringContainsString('Nie udało się odpytać bucketu', $tresc);
        $this->assertStringNotContainsString('AccessDenied', $tresc);
        $this->assertStringNotContainsString('r2.cloudflarestorage.com', $tresc);
    }

    #[Test]
    public function alarm_milczy_gdy_kanal_webhooka_jest_wylaczony(): void
    {
        // Tak jest dziś na produkcji (`LOG_BLAD_WEBHOOK_URL` nie jest
        // ustawione). Brak kanału nie może kończyć się wyjątkiem w zadaniu
        // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś cały
        // kontener.
        config()->set('logging.channels.blad_webhook.url', null);
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260901-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::PRZESTARZALA, $wynik['stan']);
        $this->assertFalse(app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik));
    }

    #[Test]
    public function stan_aktualny_nigdy_nie_dzwoni_nawet_z_wlaczonym_kanalem(): void
    {
        config()->set('logging.channels.blad_webhook.url', 'https://przyklad.invalid/webhook');
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260909-021700');

        $this->assertFalse(
            app(AlarmKopii::class)->zadzwonJesliTrzeba(app(StanKopiiBazy::class)->sprawdz()),
        );
    }

    // =========================================================================
    //  KONTROLA DODATNIA — CZY ALARM W OGÓLE DZWONI (pułapka 4)
    //
    //  Wszystkie asercje wyżej dotyczące `zadzwonJesliTrzeba()` są UJEMNE:
    //  „świeża kopia nie dzwoni", „wyłączony kanał nie dzwoni", „brak bucketu
    //  nie dzwoni". Taki zestaw przechodzi również wtedy, gdy metoda NIGDY nie
    //  dzwoni — zmierzone w tym repozytorium 10.09.2026: po wstawieniu
    //  `return false;` w pierwszej linii `zadzwonJesliTrzeba()` wszystkie
    //  czternaście testów tego pliku było zielonych. Czyli cały plik dowodził
    //  ciszy, a nie alarmu, w issue, którego jedynym tematem jest alarm.
    //
    //  Dokładnie ta pułapka jest opisana w `docs/PULAPKI_TESTOW.md` §4:
    //  asercja tylko negatywna przechodzi, gdy mechanizm nie działa wcale.
    //  Dlatego niżej są trzy pary „widoczne wyszło, ukryte nie wyszło":
    //  dla każdego alarmującego stanu sprawdzamy, że żądanie NAPRAWDĘ wyszło
    //  na kanał — i że nie wyniosło przy tym nazwy bucketu ani klucza obiektu.
    // =========================================================================

    private const ADRES_WEBHOOKA = 'https://discord.przyklad.invalid/api/webhooks/testowy/slack';

    /**
     * Kanał `blad_webhook` idzie przez `WebhookBleduHandler`, a ten wysyła
     * `Http::post()` — więc `Http::fake()` jest tu jedynym miejscem, w którym
     * widać RÓŻNICĘ między „metoda zwróciła true" a „coś naprawdę wyszło".
     * Ta sama droga i ten sam wzorzec, co w
     * `WiadomoscNaWebhookuBezDanychOsobowychTest`.
     */
    private function wlaczKanalAlarmu(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);

        // Kanał jest w Laravelu zapamiętywany po pierwszym użyciu, a inne testy
        // w tej klasie tworzą go z pustym adresem. Bez tego wiersza pamiętany
        // egzemplarz zostałby z `null` i test przechodziłby, nic nie wysyłając.
        Log::forgetChannel('blad_webhook');

        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    #[Test]
    public function przestarzala_kopia_naprawde_wysyla_alarm_na_kanal(): void
    {
        $this->wlaczKanalAlarmu();
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260901-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();
        $this->assertSame(StanKopiiBazy::PRZESTARZALA, $wynik['stan']);

        $this->assertTrue(
            app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik),
            'Przestarzała kopia MUSI zadzwonić. Cały #193 jest o tym jednym alarmie.',
        );

        Http::assertSentCount(1);

        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            // Kontrola DODATNIA: to naprawdę jest nasz alarm, a nie
            // jakiekolwiek żądanie, które przypadkiem poszło w tym teście.
            $this->assertStringContainsString('kopia bazy', $wyslane);
            $this->assertStringContainsString('KOPIE_I_ODTWORZENIE.md', $wyslane);

            // NAGŁÓWEK `[nazwa/środowisko]` DOKŁADA KANAŁ, NIE TEN ALARM.
            // Zmierzone na prawdziwym odbiorniku webhooka 17.09.2026:
            // `AlarmKopii::tresc()` doklejało go drugi raz i do odbiorcy
            // przychodziło „[Kuking/production] [Kuking/production] kopia
            // bazy: …". Asercja jest na LICZBĘ wystąpień, bo sama obecność
            // nagłówka przechodziła też przy dwóch.
            $naglowek = sprintf('[%s/%s]', config('app.name'), config('app.env'));
            $this->assertStringContainsString($naglowek, $wyslane);
            $this->assertSame(
                1,
                substr_count($wyslane, $naglowek),
                'Nagłówek [nazwa/środowisko] ma stać w wiadomości DOKŁADNIE raz — dokłada go kanał.',
            );

            // Kontrola UJEMNA w tym samym miejscu — dopiero para dowodzi,
            // że mechanizm pracował, gdy sprawdzamy, czego w treści nie ma
            // (audyt A6-01).
            $this->assertStringNotContainsString('kuking-kopie-test', $wyslane);
            $this->assertStringNotContainsString('.dump.cms', $wyslane);

            return true;
        });
    }

    #[Test]
    public function pusty_bucket_naprawde_wysyla_alarm_na_kanal(): void
    {
        $this->wlaczKanalAlarmu();

        $wynik = app(StanKopiiBazy::class)->sprawdz();
        $this->assertSame(StanKopiiBazy::BRAK_KOPII, $wynik['stan']);

        $this->assertTrue(app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zadanie): bool {
            $this->assertStringContainsString(
                'ANI JEDNEGO',
                (string) ($zadanie->data()['text'] ?? ''),
            );

            return true;
        });
    }

    /**
     * Bucket, który nie odpowiada, to trzeci alarmujący stan — i jedyny,
     * którego nie da się wywołać, kładąc (albo nie kładąc) plik w atrapie
     * dysku. Podstawiamy więc dysk, który rzuca wyjątkiem, bo gałąź
     * `catch (Throwable)` w `StanKopiiBazy::sprawdz()` inaczej nie jest
     * wykonana ANI RAZ w całym zestawie.
     */
    #[Test]
    public function niedostepny_bucket_daje_stan_niedostepny_i_dzwoni(): void
    {
        $this->wlaczKanalAlarmu();

        $dysk = Mockery::mock(Filesystem::class);
        $dysk->shouldReceive('files')
            ->andThrow(new RuntimeException(
                'AccessDenied: kuking-kopie-test.konto.r2.cloudflarestorage.com',
            ));

        Storage::set('r2_kopie', $dysk);

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::NIEDOSTEPNY, $wynik['stan']);
        $this->assertTrue(app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('Nie udało się odpytać bucketu', $wyslane);

            // Komunikat wyjątku niósł nazwę bucketu i endpoint. Nie ma prawa
            // wyjść na kanał, nad którym nie mamy kontroli (audyt A6-01).
            $this->assertStringNotContainsString('AccessDenied', $wyslane);
            $this->assertStringNotContainsString('r2.cloudflarestorage.com', $wyslane);

            return true;
        });
    }

    /**
     * Ta sama para na poziomie KOMENDY, bo `--bez-alarmu` jest przełącznikiem,
     * a przełącznik sprawdzony tylko w pozycji „wyłączone" nie dowodzi, że
     * pozycja „włączone" cokolwiek robi.
     */
    #[Test]
    public function komenda_bez_przelacznika_dzwoni_a_z_przelacznikiem_milczy(): void
    {
        $this->wlaczKanalAlarmu();
        Carbon::setTestNow('2026-09-09 08:00:00');
        $this->polozKopie('20260901-021700');

        $this->artisan('kuking:sprawdz-kopie')->assertFailed();
        Http::assertSentCount(1);

        $this->artisan('kuking:sprawdz-kopie --bez-alarmu')->assertFailed();
        Http::assertSentCount(1); // nadal jedno — drugi przebieg nie zadzwonił
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
