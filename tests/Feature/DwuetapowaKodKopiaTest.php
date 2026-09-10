<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Copy przy polu `one-time-code" (issue audytu 60+: docs/research/AUDYT_60_PLUS.md,
 * ranking napraw pkt 1 i 2).
 *
 * DWA EKRANY, TA SAMA USTERKA
 * `two_factor_challenge.blade.php` (logowanie) i `settings/two_factor/enable.blade.php`
 * (włączanie) mają pole z `autocomplete="one-time-code"`, które NIE blokuje
 * wklejania — formularz od dawna wspiera łatwiejszą drogę. Copy obok pola
 * przez długi czas kazało wyłącznie „przepisać", czyli promowało trudniejszą
 * poznawczo transkrypcję ręczną, mimo że formularz jej nie wymaga.
 *
 * KONTRAKT PROJEKTOWY 60+ (audyt, „Co dopisać do scripts/dostepnosc.mjs",
 * pozycja 10 — `two-factor-copy`): instrukcja przy `one-time-code` nie może
 * ZNOWU nakazywać wyłącznie „przepisać"; musi dopuszczać wpisanie LUB
 * wklejenie/autouzupełnienie. Bez tego testu regresja wraca po cichu —
 * dokładnie tak, jak trafiła tu za pierwszym razem.
 *
 * SCOPING: `preg_match` na fragmencie strony wskazanym przez klasę/id
 * dodane w widoku, NIE na całym HTML-u odpowiedzi. Obie strony mają gdzie
 * indziej słowo „kod" wielokrotnie (etykieta pola, przyciski) — asercja na
 * całej stronie złapałaby literę słowa z zupełnie innego miejsca ekranu.
 */
class DwuetapowaKodKopiaTest extends TestCase
{
    use RefreshDatabase;

    private function totp(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /** Włącza 2FA na koncie bez przechodzenia przez HTTP. */
    private function wlacz2fa(User $user): string
    {
        $sekret = $this->totp()->generateSecret();
        $user->beginTwoFactorSetup($sekret);
        $user->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $user->refresh();

        return $sekret;
    }

    /** Wycina fragment HTML-a wskazanego elementu — szuka po klasie CSS. */
    private function fragmentPoKlasie(string $html, string $tag, string $klasa): string
    {
        $wzorzec = '/<'.$tag.'\b[^>]*\bclass="[^"]*\b'.preg_quote($klasa, '/').'\b[^"]*"[^>]*>(.*?)<\/'.$tag.'>/s';

        $this->assertSame(
            1,
            preg_match($wzorzec, $html, $trafienie),
            "Nie znalazłem <{$tag} class=\"{$klasa}\"> w odpowiedzi — asercja treści nie ma czego sprawdzić.",
        );

        return $trafienie[1];
    }

    /** Wycina fragment HTML-a wskazanej sekcji — szuka po `id`. */
    private function fragmentPoId(string $html, string $tag, string $id): string
    {
        $wzorzec = '/<'.$tag.'\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>(.*)<\/'.$tag.'>/s';

        $this->assertSame(
            1,
            preg_match($wzorzec, $html, $trafienie),
            "Nie znalazłem <{$tag} id=\"{$id}\"> w odpowiedzi — asercja treści nie ma czego sprawdzić.",
        );

        return $trafienie[1];
    }

    // -----------------------------------------------------------------
    // Regresja 3a — logowanie, ekran wyzwania 2FA
    // -----------------------------------------------------------------

    public function test_wyzwanie_logowania_dopuszcza_wpisanie_lub_wklejenie_kodu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $response = $this->get(route('login.two_factor'));
        $response->assertOk();

        $instrukcja = $this->fragmentPoKlasie($response->getContent(), 'p', 'instrukcja-2fa');

        $this->assertStringContainsString('wklej', $instrukcja, 'Instrukcja nie wspomina o wklejeniu kodu.');
        $this->assertStringContainsString('wpisz', mb_strtolower($instrukcja), 'Instrukcja nie wspomina o wpisaniu kodu.');

        // Pole dalej ma `autocomplete="one-time-code"` — to jest WARUNEK,
        // pod który ten test w ogóle ma sens (patrz nagłówek pliku).
        $response->assertSee('autocomplete="one-time-code"', false);
    }

    // -----------------------------------------------------------------
    // Regresja 3b — włączanie 2FA, sekcja awaryjna po nieudanym QR
    // -----------------------------------------------------------------

    public function test_wlaczenie_2fa_uzywa_jezyka_zadania_nie_zargonu(): void
    {
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->get(route('settings.two_factor.enable'));
        $response->assertOk();

        $sekcja = $this->fragmentPoId($response->getContent(), 'section', 'sekcja-recznego-wpisania');

        // Język zadania idzie PIERWSZY.
        $this->assertStringContainsString('kod do ręcznego wpisania', $sekcja);

        // Żargon zostaje — ale jako informacja DRUGORZĘDNA, czyli za językiem
        // zadania w tekście, nie przed nim.
        $pozycjaZadania = mb_strpos($sekcja, 'kod do ręcznego wpisania');
        $pozycjaZargonu = mb_strpos(mb_strtolower($sekcja), 'sekret');

        $this->assertNotFalse($pozycjaZadania);

        if ($pozycjaZargonu !== false) {
            $this->assertGreaterThan(
                $pozycjaZadania,
                $pozycjaZargonu,
                'Żargon „sekret" stoi przed językiem zadania — powinien być informacją drugorzędną.',
            );
        }

        // Krok 3 tej samej listy używa TEGO SAMEGO pola `one-time-code` —
        // kontrakt 10 (test niżej) dotyczy więc też tego ekranu, nie tylko
        // logowania.
        $krokTrzeci = $this->fragmentPoKlasie($response->getContent(), 'ol', 'lista-krokow');
        $this->assertStringContainsString('wklej', $krokTrzeci);
    }

    // -----------------------------------------------------------------
    // Kontrakt projektowy 60+ (audyt, pozycja 10 — „two-factor-copy")
    // -----------------------------------------------------------------

    /**
     * Sprawdzenie NIEZALEŻNE od dokładnego brzmienia wyżej: żadna instrukcja
     * przy polu `one-time-code` nie może zawierać słowa „przepisz"/„przepisać"
     * BEZ jednoczesnej wzmianki o wklejeniu/wpisaniu. To jest ten sam
     * kontrakt co dwa testy wyżej, ale sformułowany tak, żeby złapać
     * regresję nawet po zmianie dokładnych słów instrukcji.
     */
    public function test_kontrakt_two_factor_copy_nie_wraca_do_wylacznego_przepisywania(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $logowanie = $this->fragmentPoKlasie(
            $this->get(route('login.two_factor'))->getContent(),
            'p',
            'instrukcja-2fa',
        );

        $wlaczenie = $this->get(route('settings.two_factor.enable'));
        // Osobne konto: włączenie 2FA wymaga, żeby nie było już włączone.
        $marek = $this->user('marek');
        $wlaczenieTresc = $this->fragmentPoKlasie(
            $this->actingAs($marek)->get(route('settings.two_factor.enable'))->getContent(),
            'ol',
            'lista-krokow',
        );

        foreach (['logowanie' => $logowanie, 'włączenie (kroki)' => $wlaczenieTresc] as $nazwa => $tekst) {
            $zawieraPrzepisz = mb_stripos($tekst, 'przepis') !== false;
            $zawieraAlternatywe = mb_stripos($tekst, 'wklej') !== false || mb_stripos($tekst, 'wpisz') !== false;

            if ($zawieraPrzepisz) {
                $this->assertTrue(
                    $zawieraAlternatywe,
                    "Ekran „{$nazwa}\" wspomina o przepisywaniu kodu, ale nie dopuszcza wpisania/wklejenia — "
                    .'to jest dokładnie regresja, przed którą ma bronić ten kontrakt.',
                );
            }
        }
    }
}
