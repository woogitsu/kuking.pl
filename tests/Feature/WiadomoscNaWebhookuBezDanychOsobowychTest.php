<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\DzwonekOperatora;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Powiadomienie o nowej wiadomości NIE MOŻE wynieść treści na webhook.
 *
 * DLACZEGO TO JEST OSOBNY, WŁASNY PLIK TESTU
 * Bo `blad_webhook` (`config/logging.php`) to jedyny log w tym serwisie, który
 * wychodzi do usługi, nad którą nie mamy żadnej kontroli — i już raz o mało
 * co nie wyniósł danych osobowych. Audyt A6-01 (wrzesień 2026) pokazał, że
 * `QueryException` potrafi wstawić w komunikat wyjątku adres e-mail i hash
 * hasła prosto ze sterownika PostgreSQL-a, a `WebhookBleduHandler` wysyłał
 * wtedy `getMessage()` w całości. Naprawa polegała na tym, że treść buduje
 * się WYŁĄCZNIE Z LISTY DOZWOLONYCH PÓL.
 *
 * Formularz „Napisz do nas" jest pierwszą rzeczą, która pisze na ten kanał
 * CELOWO, a nie przy awarii — czyli pierwszą okazją, żeby tamtą naprawę
 * cofnąć przez nieuwagę („wrzucę treść wiadomości, będzie wygodniej").
 * Ten plik istnieje po to, żeby taka zmiana nie przeszła.
 *
 * Wiadomość ma być DZWONKIEM: „przyszło coś nowego, id X, zajrzyj do panelu".
 * Treść zostaje w Kuking.
 */
class WiadomoscNaWebhookuBezDanychOsobowychTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/testowy/slack';

    private const TRESC_WIADOMOSCI = 'Nie mogę się zalogować. Moja córka Kasia próbowała z Warszawy i też nie działa.';

    private const ADRES_CZLOWIEKA = 'basia.kowalska@example.com';

    private function wiadomosc(): ContactMessage
    {
        return ContactMessage::factory()->create([
            'kind' => ContactMessage::KIND_BLAD,
            'message' => self::TRESC_WIADOMOSCI,
            'contact_email' => self::ADRES_CZLOWIEKA,
            'page_path' => '/przepisy/rosol-babci',
        ]);
    }

    public function test_na_webhook_idzie_identyfikator_i_rodzaj_a_nie_tresc(): void
    {
        $wiadomosc = $this->wiadomosc();
        $tresc = app(DzwonekOperatora::class)->tresc($wiadomosc);

        // CO MA BYĆ — bo dzwonek bez identyfikatora jest bezużyteczny.
        $this->assertStringContainsString((string) $wiadomosc->getKey(), $tresc);
        $this->assertStringContainsString(ContactMessage::KIND_BLAD, $tresc);
        $this->assertStringContainsString(route('admin.contact.show', $wiadomosc), $tresc);

        // CZEGO BYĆ NIE MOŻE — cała reszta.
        foreach ([
            self::TRESC_WIADOMOSCI,
            'Kasia',
            'Warszawy',
            self::ADRES_CZLOWIEKA,
            'basia.kowalska',
            '/przepisy/rosol-babci',
        ] as $czegoNieWolno) {
            $this->assertStringNotContainsString(
                $czegoNieWolno,
                $tresc,
                'Na webhook wyszło „'.$czegoNieWolno.'". Ten kanał idzie do usługi, nad którą '
                .'nie mamy kontroli (audyt A6-01). Powiadomienie ma nieść „przyszło coś nowego, '
                .'id X", a nie treść wiadomości ani dane człowieka.',
            );
        }
    }

    public function test_bez_ustawionego_adresu_webhooka_nic_nie_wychodzi(): void
    {
        config(['logging.channels.blad_webhook.url' => null]);

        Http::fake();

        app(DzwonekOperatora::class)->zadzwon($this->wiadomosc());

        Http::assertNothingSent();
    }

    public function test_z_ustawionym_adresem_wychodzi_jedno_zadanie_z_bezpieczna_trescia(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);

        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);

        app(DzwonekOperatora::class)->zadzwon($this->wiadomosc());

        Http::assertSentCount(1);

        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('Napisz do nas', $wyslane);
            $this->assertStringNotContainsString(self::TRESC_WIADOMOSCI, $wyslane);
            $this->assertStringNotContainsString(self::ADRES_CZLOWIEKA, $wyslane);

            return true;
        });
    }

    /**
     * Ścieżka pełna: człowiek wysyła formularz, operator dostaje dzwonek,
     * a treść zostaje w bazie. To jest sprawdzenie KOLEJNOŚCI — zapis
     * najpierw, powiadomienie potem.
     */
    public function test_wyslany_formularz_dzwoni_dopiero_po_zapisaniu_wiadomosci(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);

        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);

        $this->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_BLAD,
            'message' => self::TRESC_WIADOMOSCI,
            'contact_email' => self::ADRES_CZLOWIEKA,
        ])->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $zapisana = ContactMessage::sole();

        $this->assertSame(self::TRESC_WIADOMOSCI, $zapisana->message);

        Http::assertSent(function (Request $zadanie) use ($zapisana): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            // Identyfikator z wiadomości, KTÓRA JUŻ JEST W BAZIE — czyli
            // dzwonek nie mógł zadzwonić przed zapisem.
            return str_contains($wyslane, (string) $zapisana->getKey())
                && ! str_contains($wyslane, self::TRESC_WIADOMOSCI);
        });
    }

    /**
     * Awaria webhooka nie ma prawa przewrócić wysłanego formularza —
     * wiadomość jest już zapisana, a człowiek ma zobaczyć potwierdzenie.
     */
    public function test_awaria_webhooka_nie_przewraca_wyslanego_formularza(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);

        Http::fake(fn () => throw new \RuntimeException('Discord nie odpowiada.'));

        $this->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_INNE,
            'message' => 'Chciałam tylko powiedzieć, że fajnie tu u Was.',
        ])->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $this->assertSame(1, ContactMessage::count());
    }
}
