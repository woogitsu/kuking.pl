<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\AuditLogEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Group;

/**
 * JEDEN LINK RESETU, DWA RÓWNOLEGŁE ŻĄDANIA (issue #2055).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PRZEPLOT, KTÓRY TO ROZSTRZYGA
 * ══════════════════════════════════════════════════════════════════════
 *
 * `PasswordBroker::reset()` sprawdza token ZANIM zawoła nasze wywołanie
 * zwrotne, a kasuje go dopiero PO nim. Blokada konta (`ZamekKonta`) stoi
 * w środku, więc obie prośby z tym samym linkiem mogą przejść sprawdzenie
 * i ustawić się w kolejce po blokadę z tokenem, który „był ważny".
 *
 * Bariera przyrządu trzyma wiersz konta pod `FOR UPDATE`. Oba procesy
 * przechodzą walidację brokera i stają w kolejce. Po zwolnieniu bariery:
 *
 *   dziś (token sprawdzany pod blokadą)    stary kod
 *   ───────────────────────────────────    ────────────────────────────────
 *   pierwszy: zapis hasła, token znika     pierwszy: zapis hasła, token znika
 *   drugi: pod blokadą nie ma tokenu       drugi: zapisuje SWOJE hasło
 *   → odmowa jak przy nieważnym linku      → dwa sukcesy, wygrywa drugie
 *
 * KONTROLE DODATNIE: oba procesy naprawdę stanęły w kolejce po blokadę
 * (czyli oba przeszły walidację brokera — inaczej nie byłoby wyścigu), a
 * zwycięzca naprawdę zmienił hasło. Który wygrał, czytamy z wyniku, a nie
 * zakładamy z kolejności kolejki.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Drugiego żądania, które przyszło CAŁE po pierwszym — to odrzuca już
 * broker, bo tokenu nie ma od razu. Tu mierzymy okno między walidacją
 * brokera a blokadą konta.
 */
#[Group('dwa-polaczenia')]
final class JedenTokenResetuDwaZadaniaTest extends TestDwochPolaczen
{
    private const HASLO_A = 'pierwszenowehaslo-2055';

    private const HASLO_B = 'drugienowehaslo-2055';

    private const KOMUNIKAT_NIEWAZNEGO = 'Ten link do ustawienia hasła jest już nieaktualny. Poproś o nowy.';

    /** @var list<string> */
    private array $adresyZTokenem = [];

    protected function tearDown(): void
    {
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
            $this->adresyZTokenem = [];
        }

        parent::tearDown();
    }

    public function test_ten_sam_link_ustawia_haslo_tylko_raz(): void
    {
        $konto = $this->konto();
        $adres = (string) $konto->email;
        $token = Password::createToken($konto);
        $this->adresyZTokenem[] = $adres;

        // Bariera na wierszu konta — dokładnie tym, który bierze `ZamekKonta`.
        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [(string) $konto->getKey()]);

        $argumenty = ['konto' => (string) $konto->getKey(), 'droga' => 'reset', 'token' => $token, 'email' => $adres];

        $a = $this->wTle('ustaw-haslo', [...$argumenty, 'haslo' => self::HASLO_A]);
        $this->czekajNaZablokowane(1);

        $b = $this->wTle('ustaw-haslo', [...$argumenty, 'haslo' => self::HASLO_B]);
        $this->czekajNaZablokowane(2);

        // Oba stoją w kolejce, więc oba przeszły już walidację brokera,
        // a token wciąż leży w tabeli — to jest okno z issue #2055.
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $adres)->count());

        $this->zwolnijBariere($bariera);

        $wynikA = $a->wynik();
        $wynikB = $b->wynik();

        $this->assertBezZakleszczenia($wynikA, 'pierwsze żądanie resetu');
        $this->assertBezZakleszczenia($wynikB, 'drugie żądanie resetu');
        $this->assertTrue($wynikA['ok'], 'Pierwsze żądanie padło: '.$wynikA['komunikat']);
        $this->assertTrue($wynikB['ok'], 'Drugie żądanie padło: '.$wynikB['komunikat']);

        $bledyA = $wynikA['wartosc']['bledy'] ?? null;
        $bledyB = $wynikB['wartosc']['bledy'] ?? null;

        // SEDNO: dokładnie jeden sukces.
        $sukcesy = (int) ($bledyA === []) + (int) ($bledyB === []);
        $this->assertSame(1, $sukcesy, 'Ten sam link ustawił hasło '.$sukcesy.' razy. Błędy: '
            .json_encode(['A' => $bledyA, 'B' => $bledyB], JSON_UNESCAPED_UNICODE));

        [$hasloZwyciezcy, $bledyPrzegranego] = $bledyA === [] ? [self::HASLO_A, $bledyB] : [self::HASLO_B, $bledyA];

        // Przegrany dostaje to samo zdanie, co przy zużytym linku.
        $this->assertSame([self::KOMUNIKAT_NIEWAZNEGO], $bledyPrzegranego);

        // Hasło jest zwycięzcy — odrzucone żądanie niczego nie nadpisało.
        $haslo = (string) $konto->fresh()?->password;
        $this->assertTrue(Hash::check($hasloZwyciezcy, $haslo), 'Hasło po wyścigu nie jest hasłem zwycięzcy.');
        $this->assertFalse(Hash::check($hasloZwyciezcy === self::HASLO_A ? self::HASLO_B : self::HASLO_A, $haslo));

        // Token zużyty, a dziennik opisuje jeden reset, nie dwa.
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $adres)->count());
        $this->assertSame(1, AuditLogEntry::query()
            ->where('action', 'account.password_reset')
            ->where('subject_id', $konto->getKey())
            ->count());
    }
}
