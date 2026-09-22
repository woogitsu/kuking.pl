<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AnalitykaCloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BEACON ANALITYKI NIE STOI NA STRONIE, KTÓREJ ADRES NIESIE SEKRET.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TU JEST PILNOWANE I DLACZEGO AKURAT TO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `beacon.min.js` (Cloudflare Web Analytics, D-092) melduje do Cloudflare
 * ścieżkę odwiedzanej strony, nie sam fakt wejścia. Odczytana wersja 2026.9.1
 * usuwa query i fragment, ale zostawia pathname. Przy `/wpisy/rosol`
 * jest to zwykły pomiar odwiedzin i dokładnie po to tę analitykę włączono.
 * Ale cztery adresy w tym serwisie niosą w sobie sekret:
 *
 *     /nowe-haslo/{token}?email=…   ← ŻYWY żeton resetu hasła + adres
 *     /logowanie/link/{token}       ← żeton, który DAJE SESJĘ
 *     /zaproszenie/{token}          ← żeton zakładania konta
 *     /potwierdz-email/{id}/{hash}  ← potwierdzenie adresu
 *
 * Beacon na takiej stronie nie wynosi metryki — wynosi DZIAŁAJĄCY KLUCZ do
 * czyjegoś konta, do usługi, nad którą nie mamy żadnej kontroli, i zostawia
 * go w cudzym panelu razem z czasem wejścia. To jest ta sama klasa usterki,
 * co komunikat `QueryException` na webhooku błędów (audyt A6-01,
 * `App\Logging\WebhookBleduHandler`): wychodzi coś, czego nie zbudowaliśmy
 * sami i czego nikt nie przejrzał.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO MUSI BYĆ TEST, A NIE AKAPIT W DOKUMENCIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo z serwera nie widać NICZEGO. Strona renderuje się poprawnie, dziennik
 * milczy, przeglądarka nie zgłasza usterki, a jedynym miejscem, w którym ten
 * wyciek jest widoczny, jest panel obcej firmy. Kanał jest przy tym dziś
 * domyślnie wyłączony lokalnie (`CLOUDFLARE_ANALYTICS_TOKEN` puste).
 * Ten test nie stwierdza stanu konfiguracji produkcji ani braku incydentu.
 *
 * KONTROLA DODATNIA JEST TU OBOWIĄZKOWA. Test sprawdzający wyłącznie
 * NIEOBECNOŚĆ znacznika przechodzi także wtedy, gdy analityki nie ma nigdzie
 * (`docs/PULAPKI_TESTOW.md`, pułapka 2 — strażnik, który przestał cokolwiek
 * znajdować). Dlatego każdy przypadek „nie ma beacona" ma tu parę
 * w `test_na_zwyklej_stronie_beacon_stoi`.
 */
final class BeaconNieWynosiZetonuZAdresuTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_TESTOWY = 'test-token-analityki-0000';

    protected function setUp(): void
    {
        parent::setUp();

        // Analityka musi być WŁĄCZONA, inaczej cały ten plik pilnowałby
        // nieobecności czegoś, czego i tak nigdy nie ma.
        config()->set('kuking.analytics.cloudflare.token', self::TOKEN_TESTOWY);
    }

    public function test_na_zwyklej_stronie_beacon_stoi(): void
    {
        $odpowiedz = $this->get(route('login'));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee(AnalitykaCloudflare::adresSkryptu(), escape: false);
    }

    public function test_ekran_ustawienia_nowego_hasla_nie_ma_beacona(): void
    {
        $odpowiedz = $this->get(route('password.reset', ['token' => 'zeton-resetu-0123456789']));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('cloudflareinsights', escape: false);
        $odpowiedz->assertDontSee('data-cf-beacon', escape: false);
    }

    public function test_ekran_wejscia_linkiem_nie_ma_beacona(): void
    {
        $odpowiedz = $this->get(route('login.link.confirm', ['token' => 'zeton-logowania-0123456789']));

        // Żeton jest zmyślony, więc ekran może odesłać dalej — pilnujemy
        // tego, co wyszło do przeglądarki, cokolwiek nim jest.
        $odpowiedz->assertDontSee('cloudflareinsights', escape: false);
    }

    public function test_ekran_zaproszenia_nie_ma_beacona(): void
    {
        $odpowiedz = $this->get(route('zaproszenie.pokaz', ['token' => 'zeton-zaproszenia-0123456789']));

        $odpowiedz->assertDontSee('cloudflareinsights', escape: false);
    }

    /**
     * Reguła chwyta po NAZWIE POLA, nie po liście tras — więc obejmuje także
     * adres, którego dziś nie ma, byle niósł pole o tej samej nazwie.
     */
    public function test_sam_parametr_email_w_adresie_zdejmuje_beacona(): void
    {
        $odpowiedz = $this->get(route('login').'?email=basia%40example.com');

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('cloudflareinsights', escape: false);
    }

    /**
     * Ta sama trasa, ten sam ekran, RÓŻNICA WYŁĄCZNIE W ADRESIE — czyli
     * dowód, że to adres, a nie przypadek, decyduje o obecności beacona.
     */
    public function test_ta_sama_trasa_bez_parametru_beacona_ma(): void
    {
        $bez = $this->get(route('login'));
        $z = $this->get(route('login').'?token=cokolwiek');

        $bez->assertSee('cloudflareinsights', escape: false);
        $z->assertDontSee('cloudflareinsights', escape: false);
    }
}
