<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Poczta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran „Nie pamiętam hasła" nie może obiecywać listu, który nie przyjdzie.
 *
 * CO BYŁO ZŁE
 * `MAIL_MAILER=log` zapisuje wiadomość do dziennika i zgłasza sukces. Dla
 * Laravela wysyłka „się udała", dla człowieka nie przyszło nic. Ekran mówił
 * mimo to „Wyślemy na niego wiadomość z linkiem do ustawienia nowego hasła",
 * a niżej doradzał sprawdzenie folderu „Spam" — czyli wysyłał osobę na
 * poszukiwanie listu, który nigdy nie powstał.
 *
 * DLACZEGO TO JEST POWAŻNE, A NIE KOSMETYCZNE
 * Przy grupie 50+ pierwsza osoba, która zapomni hasła, traci konto
 * bezpowrotnie: nie ma jak się zalogować i nie wie, że nie ma na co czekać.
 * Właściciel zapytany 7 września 2026 o dostawcę poczty odpowiedział „jeszcze
 * nie wybieram" — więc do tego czasu ekran musi mówić prawdę, a nie udawać,
 * że wszystko działa.
 */
class ResetHaslaMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /** KONTROLA. W testach poczta z definicji nie dostarcza (`array`). */
    public function test_kontrola_w_testach_poczta_nie_dostarcza(): void
    {
        $this->assertFalse(Poczta::dziala(), 'Kontrola: suita ma chodzić na sterowniku, który nie wysyła.');
        $this->assertSame('array', config('mail.default'));
    }

    /** WŁAŚCIWY POMIAR. Bez działającej poczty ekran nie obiecuje wiadomości. */
    public function test_bez_poczty_ekran_nie_obiecuje_wiadomosci(): void
    {
        $odpowiedz = $this->get(route('password.request'))->assertOk();

        $odpowiedz->assertSee('Nie wysyłamy jeszcze wiadomości e-mail', escape: false);
        $odpowiedz->assertSee((string) config('kuking.community.contact_email'), escape: false);

        // Żadnej obietnicy listu i żadnej rady, żeby szukać go w „Spamie".
        $odpowiedz->assertDontSee('Wyślemy na niego wiadomość', escape: false);
        $odpowiedz->assertDontSee('Sprawdź folder', escape: false);

        // I żadnego pola, które przyjmuje adres, a potem nic nie robi.
        $odpowiedz->assertDontSee('Wyślij link', escape: false);
    }

    /**
     * Trasa wysyłki zostaje osiągalna wprost (stary adres, zakładka), więc
     * i ona musi mówić prawdę.
     */
    public function test_bez_poczty_proba_wyslania_nie_udaje_sukcesu(): void
    {
        $this->post(route('password.email'), ['email' => 'basia@example.test'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'Nie wysyłamy jeszcze wiadomości e-mail',
            (string) session('status'),
            'Odpowiedź na próbę wysłania linku nadal udaje, że wiadomość poszła.',
        );
    }

    /**
     * KONTROLA DRUGIEJ STRONY — najważniejsza w tym pliku. Gdy dostawca
     * poczty zostanie wpisany, formularz musi WRÓCIĆ SAM, bez zmiany kodu.
     * Bez tego testu „naprawa" mogłaby po prostu na zawsze wyciąć reset
     * hasła z serwisu.
     */
    public function test_z_dziala_poczta_formularz_wraca(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertTrue(Poczta::dziala());

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Wyślij link', escape: false)
            ->assertSee('Wyślemy na niego wiadomość', escape: false)
            ->assertDontSee('Nie wysyłamy jeszcze wiadomości e-mail', escape: false);
    }

    /** `Poczta::dziala()` pyta o sterownik, nie o osobną flagę w konfiguracji. */
    public function test_sterownik_decyduje_o_odpowiedzi(): void
    {
        foreach (['log', 'array', ''] as $niedostarczajacy) {
            config(['mail.default' => $niedostarczajacy]);
            $this->assertFalse(Poczta::dziala(), "Sterownik „{$niedostarczajacy}” nie dostarcza, a klasa twierdzi inaczej.");
        }

        foreach (['smtp', 'ses', 'postmark', 'resend'] as $dostarczajacy) {
            config(['mail.default' => $dostarczajacy]);
            $this->assertTrue(Poczta::dziala(), "Sterownik „{$dostarczajacy}” dostarcza, a klasa twierdzi inaczej.");
        }
    }
}
