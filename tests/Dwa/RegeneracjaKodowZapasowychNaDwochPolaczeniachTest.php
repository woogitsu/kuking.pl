<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * DWA RÓWNOLEGŁE „WYGENERUJ NOWE KODY ZAPASOWE" (issue #2057).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PRZEPLOT, KTÓRY TO ROZSTRZYGA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Człowiek ma ustawienia 2FA otwarte w dwóch kartach i w obu wysyła
 * poprawne hasło do `POST /ustawienia/2fa/nowe-kody`. Proces A idzie przez
 * prawdziwy kontroler i staje (bariera przyrządu) ZARAZ PO zapisie swojego
 * kompletu. W tym oknie proces B robi to samo.
 *
 *   po naprawie (blokada wiersza konta)     stary kod (bez blokady)
 *   ─────────────────────────────────────   ────────────────────────────────
 *   A: blokada konta, zapis A, stoi         A: zapis A (zatwierdzony), stoi
 *   B: czeka na blokadę konta               B: zapisuje B, pokazuje B
 *   zwolnienie bariery:                     zwolnienie bariery:
 *   A zatwierdza i pokazuje A               A pokazuje A — komplet, którego
 *   B pod blokadą widzi, że komplet            w bazie już nie ma
 *   zmienił się w trakcie żądania i
 *   NIE pokazuje żadnego nowego
 *
 * SEDNO: każdy komplet pokazany człowiekowi na ekranie jednorazowym
 * (flash `kody_zapasowe`) musi działać na końcu przeplotu. Nie mierzymy,
 * KTÓRE żądanie wygrywa — mierzymy, że żadne nie pokazuje martwego zestawu.
 *
 * KONTROLE DODATNIE: A naprawdę doszedł do ekranu z kodami; aktywny
 * komplet w bazie jest w całości jednym z pokazanych (nie pustką ani
 * starym kompletem); a osobny test pokazuje, że pojedyncza regeneracja
 * tym samym przyrządem daje działające kody i gasi stare — czyli
 * „komplet działa" nie bierze się z zepsutego scenariusza.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Dwie regeneracje JEDNA PO DRUGIEJ (druga zaczęta po zakończeniu pierwszej)
 * gaszą pierwszy komplet celowo — to jest zwykła wymiana kodów. Mierzymy
 * wyłącznie żądania nakładające się w czasie.
 */
#[Group('dwa-polaczenia')]
final class RegeneracjaKodowZapasowychNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private const HASLO = 'haslo-testowe-123';

    private const STARY_KOD = 'STARY-KOD01';

    /** @var list<ProcesRownolegly> */
    private array $moje = [];

    protected function tearDown(): void
    {
        foreach ($this->moje as $proces) {
            $proces->zabij();
        }

        $this->moje = [];

        parent::tearDown();
    }

    public function test_rownolegla_regeneracja_nie_pokazuje_nieaktywnego_kompletu(): void
    {
        $konto = $this->kontoZ2fa();

        // Bariera: blokada doradcza, na którą A staje zaraz po zapisie kompletu.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2057, 1)', []);

        $a = $this->regeneracja($konto, 'a', pauza: true);
        $this->czekajNaZablokowane(1);

        $b = $this->regeneracja($konto, 'b', pauza: false);
        $this->czekajAzSkonczyAlboStanie($b);

        $this->zwolnijBariere($bariera);

        $wynikA = $a->wynik();
        $wynikB = $b->wynik();

        $this->assertBezZakleszczenia($wynikA, 'regeneracja A');
        $this->assertBezZakleszczenia($wynikB, 'regeneracja B');
        $this->assertTrue($wynikA['ok'], 'Regeneracja A padła: '.$wynikA['komunikat']);
        $this->assertTrue($wynikB['ok'], 'Regeneracja B padła: '.$wynikB['komunikat']);

        // Kontrola dodatnia: A naprawdę przeszedł hasło i doszedł do ekranu z kodami.
        $this->assertSame([], $wynikA['wartosc']['bledy'], 'Kontroler odmówił A.');
        $this->assertIsArray($wynikA['wartosc']['kody'], 'A nie dostało kompletu — przeplot nic nie zmierzył.');

        $swiezy = $konto->fresh();
        $this->assertInstanceOf(User::class, $swiezy);
        $this->assertTrue($swiezy->hasTwoFactorConfirmed(), 'Regeneracja zdjęła 2FA.');
        $this->assertFalse($this->dziala($swiezy, self::STARY_KOD), 'Stary komplet nadal działa.');

        $pokazane = 0;

        foreach (['A' => $wynikA, 'B' => $wynikB] as $kto => $wynik) {
            $kody = $wynik['wartosc']['kody'];

            if (! is_array($kody)) {
                // Żądanie bez kodów musi powiedzieć człowiekowi, co zrobić —
                // cisza albo pusty ekran to też zgubiony komplet.
                $this->assertIsString($wynik['wartosc']['status'], "Żądanie {$kto} nie pokazało ani kodów, ani komunikatu.");
                $this->assertNotSame('', trim($wynik['wartosc']['status']));

                continue;
            }

            $pokazane++;
            $martwe = array_values(array_filter($kody, fn (string $kod): bool => ! $this->dziala($swiezy, $kod)));

            $this->assertSame(
                [],
                $martwe,
                "Żądanie {$kto} pokazało na ekranie jednorazowym komplet kodów zapasowych, "
                .'którego nie ma w bazie — '.count($martwe).' z '.count($kody).' kodów nie działa. '
                .'Człowiek zapisał nieaktywny zestaw.',
            );
            $this->assertCount(count($swiezy->two_factor_backup_codes ?? []), $kody);
        }

        $this->assertGreaterThanOrEqual(1, $pokazane);

        // Kontrola dodatnia rewalidacji: B naprawdę dotarł pod blokadę,
        // zobaczył komplet A i powiedział człowiekowi, co zrobić — a nie
        // zniknął po drodze na przyrządzie.
        $this->assertNull($wynikB['wartosc']['kody'], 'B pokazał drugi komplet obok kompletu A.');
        $this->assertStringContainsString('w innym oknie lub karcie', (string) $wynikB['wartosc']['status']);
        $this->assertStringEndsWith('/ustawienia/2fa', (string) $wynikB['wartosc']['dokad']);
    }

    public function test_pojedyncza_regeneracja_tym_przyrzadem_daje_dzialajacy_komplet(): void
    {
        $konto = $this->kontoZ2fa();

        $wynik = $this->regeneracja($konto, 'sam', pauza: false)->wynik();

        $this->assertTrue($wynik['ok'], 'Regeneracja padła: '.$wynik['komunikat']);
        $this->assertIsArray($wynik['wartosc']['kody']);
        $this->assertStringEndsWith('/ustawienia/2fa/kody-zapasowe', (string) $wynik['wartosc']['dokad']);

        $swiezy = $konto->fresh();
        $this->assertInstanceOf(User::class, $swiezy);
        $this->assertFalse($this->dziala($swiezy, self::STARY_KOD));

        foreach ($wynik['wartosc']['kody'] as $kod) {
            $this->assertTrue($this->dziala($swiezy, $kod), "Świeżo pokazany kod {$kod} nie działa.");
        }
    }

    private function kontoZ2fa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);

        return $this->konto([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_backup_codes' => $totp->hashBackupCodes([self::STARY_KOD]),
        ]);
    }

    private function dziala(User $konto, string $kod): bool
    {
        return app(TwoFactorAuthenticator::class)->backupCodeMatches($konto, $kod);
    }

    private function regeneracja(User $konto, string $nazwa, bool $pauza): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(
            __DIR__.'/bin/kody-zapasowe.php',
            'regeneruj',
            [
                'konto' => (string) $konto->getKey(),
                'haslo' => self::HASLO,
                'nazwa' => $nazwa,
                'pauza' => $pauza ? '1' : '0',
            ],
            [
                'DB_DATABASE' => $this->baza,
                'APP_ENV' => 'testing',
                'BCRYPT_ROUNDS' => '4',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'KUKING_LOCK_TIMEOUT' => self::LOCK_TIMEOUT,
                'KUKING_STATEMENT_TIMEOUT' => self::STATEMENT_TIMEOUT,
            ],
        );

        $this->moje[] = $proces;

        return $proces;
    }

    /**
     * Stary kod pozwala B skończyć od razu, naprawiony trzyma go w kolejce
     * za A. Czekamy na jedno albo drugie, żeby na starym kodzie test oblał
     * się na asercji o komplecie, a nie na „przeplot się nie ustawił".
     */
    private function czekajAzSkonczyAlboStanie(ProcesRownolegly $proces): void
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

            if ($czekajacych !== false && (int) $czekajacych->fetchColumn() >= 2) {
                return;
            }

            usleep(20_000);
        }

        $this->fail('Regeneracja B ani nie skończyła się, ani nie stanęła w kolejce po blokadę.');
    }
}
