<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PragmaRX\Google2FA\Google2FA;

/**
 * OPÓŹNIONE WEJŚCIE NA EKRAN WŁĄCZENIA 2FA NIE PODMIENIA POTWIERDZONEGO
 * SEKRETU (issue #2061).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PRZEPLOT, KTÓRY TO ROZSTRZYGA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Dwie karty z ekranem włączenia 2FA na koncie bez 2FA. Karta B wczytuje
 * konto (`two_factor_secret = NULL`) i bariera zatrzymuje ją ZARAZ PO tym
 * odczycie. W tym czasie karta A wchodzi na ekran (sekret A), właściciel
 * skanuje kod QR i potwierdza go hasłem i kodem. Dopiero potem B rusza.
 *
 *   dziś (świeży wiersz pod blokadą)       stary kod (decyzja na starym modelu)
 *   ───────────────────────────────────    ───────────────────────────────────
 *   B pod blokadą widzi sekret A           B widzi NULL w pamięci i zapisuje
 *   i potwierdzone 2FA → nic nie pisze,       sekret B; `confirmed_at` i kody
 *   odsyła na ekran ustawień                   nie są „dirty", więc zostają
 *   → sekret A, kody dla A, telefon działa → potwierdzone 2FA z sekretem,
 *                                              którego nie ma żaden telefon
 *
 * Drugi test ustawia barierę PO ponownym odczycie pod blokadą — tam, gdzie
 * sam ponowny odczyt bez blokady nie wystarcza: B widzi NULL, a A musi
 * poczekać, zamiast zapisać i potwierdzić sekret, który B zaraz nadpisze.
 *
 * KONTROLE DODATNIE: A naprawdę potwierdził (przekierowanie na kody
 * zapasowe, a nie błąd walidacji), B naprawdę doszedł do końca, a osobny
 * test pokazuje, że samo wejście i potwierdzenie tym przyrządem włącza 2FA
 * sekretem z ekranu — więc zgodność sekretów nie bierze się z zepsutego
 * scenariusza.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Nie mierzy świadomego „wyłącz i włącz od nowa" — to jest POST z hasłem
 * i zmiana sekretu jest tam zamierzona.
 */
#[Group('dwa-polaczenia')]
final class OpoznionyEkran2faNieNadpisujeSekretuTest extends TestDwochPolaczen
{
    private const HASLO = 'haslo-testowe-123';

    /** @var list<ProcesRownolegly> */
    private array $uczestnicy = [];

    protected function tearDown(): void
    {
        foreach ($this->uczestnicy as $uczestnik) {
            $uczestnik->zabij();
        }
        $this->uczestnicy = [];

        parent::tearDown();
    }

    public function test_opozniony_ekran_nie_podmienia_sekretu_potwierdzonego_w_drugiej_karcie(): void
    {
        $konto = $this->konto();
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2061, 1)', []);

        $opozniony = $this->uczestnik('wejdz', $konto, ['pauza' => 'po_odczycie']);
        $this->czekajNaZablokowane(1);

        // A przechodzi całe włączenie, póki B stoi po odczycie NULL.
        $wlaczenie = $this->uczestnik('wejdz_i_potwierdz', $konto)->wynik();
        $sekretA = $this->sekretPotwierdzony($wlaczenie);
        $this->assertTrue($konto->fresh()?->hasTwoFactorConfirmed(), 'Kontrola dodatnia: A włączył 2FA.');

        $this->zwolnijBariere($bariera);
        $wynikB = $opozniony->wynik();

        $this->assertBezZakleszczenia($wynikB, 'opóźnione wejście na ekran');
        $this->assertTrue($wynikB['ok'], 'Opóźnione wejście padło: '.$wynikB['komunikat']);

        $this->assertSpojne2fa($konto, $sekretA);

        // B nie pokazał nowego kodu QR, tylko odesłał na ekran ustawień.
        $this->assertSame('przekierowanie', $wynikB['wartosc']['typ'] ?? null);
        $this->assertStringEndsWith('/ustawienia/2fa', (string) ($wynikB['wartosc']['cel'] ?? ''));
    }

    public function test_druga_karta_czeka_az_pierwsza_zapisze_sekret_i_widzi_ten_sam(): void
    {
        $konto = $this->konto();
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2061, 1)', []);

        // B stoi po PONOWNYM odczycie konta w kontrolerze — pod blokadą.
        $pierwszy = $this->uczestnik('wejdz', $konto, ['pauza' => 'po_ponownym_odczycie']);
        $this->czekajNaZablokowane(1);

        $drugi = $this->uczestnik('wejdz_i_potwierdz', $konto);
        $this->czekajAzStanieAlboSkonczy($drugi, 2);

        $this->zwolnijBariere($bariera);
        $wynikB = $pierwszy->wynik();
        $wlaczenie = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikB, 'pierwsze wejście na ekran');
        $this->assertBezZakleszczenia($wlaczenie, 'wejście i potwierdzenie');
        $this->assertTrue($wynikB['ok'], 'Pierwsze wejście padło: '.$wynikB['komunikat']);

        $sekret = $this->sekretPotwierdzony($wlaczenie);

        $this->assertSpojne2fa($konto, $sekret);

        // Obie karty pokazały TEN SAM kod QR — i to on jest potwierdzony.
        $this->assertSame('widok', $wynikB['wartosc']['typ'] ?? null);
        $this->assertSame($sekret, $wynikB['wartosc']['sekret'] ?? null, 'Karty pokazały dwa różne sekrety.');
    }

    /**
     * Ten sam błąd po stronie POST-a (zgłoszone przy #2057): druga karta
     * wczytała konto z niepotwierdzonym 2FA i stoi. Pierwsza potwierdza
     * i człowiek zapisuje pokazane kody zapasowe. Druga rusza z ważnym kodem
     * z następnego kroku czasu — i dotąd zapisywała NOWY komplet kodów,
     * więc te z kartki przestawały działać, zanim ktokolwiek je zobaczył
     * drugi raz.
     */
    public function test_opoznione_potwierdzenie_nie_podmienia_kodow_zapasowych_juz_pokazanych(): void
    {
        $konto = $this->konto();
        $konto->beginTwoFactorSetup(app(TwoFactorAuthenticator::class)->generateSecret());
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2061, 1)', []);

        $opoznione = $this->uczestnik('potwierdz', $konto, ['pauza' => 'po_odczycie', 'przesuniecie' => '1']);
        $this->czekajNaZablokowane(1);

        $pierwsze = $this->uczestnik('potwierdz', $konto)->wynik();
        $this->assertTrue($pierwsze['ok'], 'Pierwsze potwierdzenie padło: '.$pierwsze['komunikat']);
        $this->assertStringEndsWith('/ustawienia/2fa/kody-zapasowe', (string) ($pierwsze['wartosc']['cel'] ?? ''));
        $pokazaneKody = $konto->fresh()?->two_factor_backup_codes;
        $this->assertNotEmpty($pokazaneKody, 'Kontrola dodatnia: pierwsze potwierdzenie zapisało kody.');

        $this->zwolnijBariere($bariera);
        $wynik = $opoznione->wynik();

        $this->assertBezZakleszczenia($wynik, 'opóźnione potwierdzenie');
        $this->assertTrue($wynik['ok'], 'Opóźnione potwierdzenie padło: '.($wynik['wyjatek'] ?? '').' '.$wynik['komunikat']);
        $this->assertSame(
            $pokazaneKody,
            $konto->fresh()?->two_factor_backup_codes,
            'Opóźnione potwierdzenie podmieniło kody zapasowe, które człowiek właśnie zapisał.',
        );
        $this->assertStringEndsWith('/ustawienia/2fa', (string) ($wynik['wartosc']['cel'] ?? ''));
    }

    public function test_samo_wejscie_i_potwierdzenie_tym_przyrzadem_wlacza_2fa(): void
    {
        $konto = $this->konto();

        $sekret = $this->sekretPotwierdzony($this->uczestnik('wejdz_i_potwierdz', $konto)->wynik());

        $this->assertSpojne2fa($konto, $sekret);
    }

    /**
     * @param  array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}  $wynik
     */
    private function sekretPotwierdzony(array $wynik): string
    {
        $this->assertTrue($wynik['ok'], 'Włączenie 2FA padło: '.($wynik['wyjatek'] ?? '').' '.$wynik['komunikat']);
        $this->assertSame('widok', $wynik['wartosc']['ekran']['typ'] ?? null, 'Ekran włączenia nie pokazał kodu QR.');
        $this->assertStringEndsWith(
            '/ustawienia/2fa/kody-zapasowe',
            (string) ($wynik['wartosc']['potwierdzenie']['cel'] ?? ''),
            'Potwierdzenie nie doszło do kodów zapasowych.',
        );

        return (string) $wynik['wartosc']['ekran']['sekret'];
    }

    /** SEDNO: sekret, potwierdzenie i kody zapasowe to jeden stan. */
    private function assertSpojne2fa(User $konto, string $sekretZTelefonu): void
    {
        $swieze = $konto->fresh();

        $this->assertNotNull($swieze);
        $this->assertTrue($swieze->hasTwoFactorConfirmed());
        $this->assertNotEmpty($swieze->two_factor_backup_codes);
        $this->assertSame(
            $sekretZTelefonu,
            $swieze->two_factor_secret,
            'Potwierdzone 2FA ma inny sekret niż ten, który właściciel zeskanował i potwierdził — '
            .'kody z jego aplikacji przestały działać.',
        );
        $this->assertTrue((new Google2FA)->verifyKey((string) $swieze->two_factor_secret, (new Google2FA)->getCurrentOtp($sekretZTelefonu)));
    }

    /** @param  array<string, string>  $dodatkowe */
    private function uczestnik(string $scenariusz, User $konto, array $dodatkowe = []): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/konfiguracja2fa.php', $scenariusz, [
            'konto' => (string) $konto->getKey(),
            'haslo' => self::HASLO,
            'name' => 'w2061-'.$scenariusz.'-'.bin2hex(random_bytes(3)),
            ...$dodatkowe,
        ], [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        $this->uczestnicy[] = $proces;

        return $proces;
    }

    /**
     * Naprawiony kod trzyma drugiego uczestnika w kolejce po blokadę konta,
     * kod bez blokady pozwala mu skończyć od razu. Czekamy na jedno albo
     * drugie, żeby kontrola ujemna oblewała na asercji o sekrecie, a nie na
     * „przeplot się nie ustawił".
     */
    private function czekajAzStanieAlboSkonczy(ProcesRownolegly $proces, int $ilu): void
    {
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        while (microtime(true) < $koniec) {
            if (! $proces->trwa()) {
                return;
            }

            $czekajacych = $this->obserwator->query(
                "SELECT count(*) FROM pg_stat_activity
                 WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'",
            );

            if ($czekajacych !== false && (int) $czekajacych->fetchColumn() >= $ilu) {
                return;
            }

            usleep(20_000);
        }

        $this->fail('Drugi uczestnik ani nie skończył, ani nie stanął w kolejce po blokadę konta.');
    }
}
