<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RegistrationInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `registration_invites` nie kasuje po cichu zaproszeń,
 * które ktoś ma w tej chwili w skrzynce (D-085, D-088).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO AKURAT TA TABELA POTRZEBUJE STRAŻNIKA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wiersz z ważnym terminem to nie „rekord w bazie", tylko człowiek, który
 * poprosił o link, dostał wiadomość i jeszcze jej nie kliknął. `dropIfExists()`
 * zabiera mu drogę do konta BEZ SŁOWA: on zobaczy „ten link już nie działa",
 * czyli dokładnie to, co ta funkcja miała naprawić.
 *
 * I tak jak przy pozostałych strażnikach z D-088, `down()` prawie nigdy nie
 * występuje sam — po nim idzie kolejny `migrate`. Tabela wraca PUSTA, schemat
 * zgadza się co do CHECK-a, więc NIE MA BŁĘDU DO ZAUWAŻENIA. Zniknęły tylko
 * cudze zaproszenia, o których nikt się już nie dowie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TEN PLIK SPRAWDZA — I DLACZEGO ŻADNEJ Z TYCH CZTERECH RZECZY NIE DA
 *  SIĘ POMINĄĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. ODMOWA, gdy jest co stracić — razem z tym, że dane po odmowie NADAL SĄ.
 *     Odmowa, która zdążyła skasować tabelę, byłaby tylko ładniejszym
 *     komunikatem o stracie.
 *  2. KONTROLA DODATNIA na pustej tabeli. Bez niej ten plik przechodziłby
 *     także dla migracji, która nie cofa się NIGDY — a zablokowany na zawsze
 *     rollback jest błędem tej samej wagi w drugą stronę (D-088).
 *  3. WĄSKOŚĆ SKUTKÓW: strażnik broni swojej tabeli i nie rusza niczego obok.
 *  4. WĄSKOŚĆ SAMEGO WARUNKU: liczą się zaproszenia WAŻNE, nie „jakiekolwiek
 *     wiersze". Komunikat obiecuje to wprost („wygasłe wiersze odmowy nie
 *     wywołują"), a obietnica w komunikacie, która nie działa, jest gorsza
 *     niż jej brak.
 *
 * Ta migracja ŚWIADOMIE nie ma furtki przez zmienną środowiskową — w
 * odróżnieniu od `CofniecieDziennikaZgodOdmawiaTest`
 * i `CofniecieSkaliTekstuOdmawiaTest`. Drugie wyjście z komunikatu
 * (`kuking:sprzataj-zaproszenia --wszystkie`) jest tu pełnoprawnym
 * odpowiednikiem zgody wypowiedzianej wprost: operator kasuje zaproszenia
 * jawną komendą, a nie flagą w `.env`, więc zostaje po tym ślad w jego
 * historii powłoki, a nie w pliku, który ktoś kiedyś zapomni wyłączyć.
 */
class CofniecieZaproszenOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const PLIK = 'migrations/2026_09_10_400000_create_registration_invites_table.php';

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * Zaproszenie w bazie — dokładnie tak, jak zakłada je `EkranZaproszeniaTest`.
     *
     * Trzy CHECK-i tej tabeli nie przepuszczą wiersza zrobionego „na oko":
     * adres musi być małymi literami i niepusty, skrót musi mieć 64 znaki
     * `[0-9a-f]` (`RegistrationInvite::skrot()` daje dokładnie taki),
     * a `expires_at` musi być PÓŹNIEJSZY niż `created_at`. Dlatego wygasłe
     * zaproszenie cofamy OBIEMA kolumnami naraz — inaczej baza odrzuciłaby
     * wiersz i test oblewałby się z powodu, który nie ma nic wspólnego ze
     * strażnikiem.
     *
     * @return string skrót tokenu — po nim poznamy TEN SAM wiersz po odmowie
     */
    private function zaproszenie(string $adres, bool $wygasle = false): string
    {
        $wiersz = new RegistrationInvite;
        $wiersz->email = $adres;
        $wiersz->token_hash = RegistrationInvite::skrot(RegistrationInvite::nowyToken());
        $wiersz->created_at = $wygasle ? now()->subHours(30) : now();
        $wiersz->expires_at = $wygasle ? now()->subHours(6) : now()->addHours(24);
        $wiersz->save();

        return $wiersz->token_hash;
    }

    public function test_cofniecie_odmawia_gdy_sa_wazne_zaproszenia(): void
    {
        // PIĘĆ ważnych, bo komunikat odmienia liczebnik przez przypadek
        // („jest 5 WAŻNYCH zaproszeń") i przy jednym wierszu ta sama fraza
        // brzmiałaby po polsku błędnie. Asercja ma pasować do zdania, które
        // operator naprawdę zobaczy.
        // JEDNO WAŻNE ZAPROSZENIE, NIE PIĘĆ. Komunikat wklejał dotąd liczbę
        // w sztywną frazę („jest 1 WAŻNYCH zaproszeń"), więc test zakładał
        // pięć, żeby nie zamrażać w asercji błędnej polszczyzny — i przez to
        // przypadek najbardziej prawdopodobny na produkcji, czyli jedna osoba
        // czekająca na wiadomość, był jedynym nieprzetestowanym.
        $skroty = [$this->zaproszenie('basia@example.com')];

        // Dwa wygasłe leżą w TEJ SAMEJ tabeli i nie mają się liczyć. Dzięki
        // nim liczba w komunikacie (1) różni się od liczby wierszy (3), więc
        // asercja niżej dowodzi, że strażnik pyta o `expires_at > now()`,
        // a nie o „czy cokolwiek tu jest". Przy jednym wierszu w zakresie ta
        // kontrola waży więcej niż przy pięciu: różnica 1 kontra 3 jest
        // jedynym, co odróżnia dobry licznik od złego.
        $this->zaproszenie('stary@example.com', wygasle: true);
        $this->zaproszenie('starszy@example.com', wygasle: true);

        $this->assertSame(3, (int) DB::table('registration_invites')->count());

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM — i to nie
        // jest stylistyka. `$this->fail()` rzuca `AssertionFailedError`, a ta
        // dziedziczy przez `PHPUnit\Framework\Exception` po `RuntimeException`,
        // więc postawiona wewnątrz `try` wpadłaby do własnego `catch`. Tutaj
        // wyłapują ją dziś asercje na treść komunikatu, ale przy `catch` bez
        // asercji ten sam kształt daje test-atrapę: zielony także wtedy, gdyby
        // strażnika nie było wcale. Poza blokiem odmowa jest sprawdzana wprost.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało zaproszenia, na które ktoś jeszcze czeka.');

        $komunikat = $odmowa->getMessage();

        // ILU zaproszeń to dotyczy — i że policzone są tylko ważne.
        $this->assertStringContainsString(
            'Liczba WAŻNYCH zaproszeń do założenia konta w `registration_invites`: 1.',
            $komunikat,
            'Komunikat ma podać liczbę WAŻNYCH zaproszeń (1), a nie wszystkich wierszy (3).',
        );

        // Stara, niegramatyczna fraza nie ma prawa wrócić.
        $this->assertStringNotContainsString('jest 1 WAŻNYCH zaproszeń', $komunikat);

        // DLACZEGO — bo po drugiej stronie jest człowiek, nie rekord.
        $this->assertStringContainsString('ma w skrzynce wiadomość i jeszcze jej nie kliknął', $komunikat);

        // CO ZROBIĆ ZAMIAST TEGO — dwa ponumerowane wyjścia. Komunikat
        // bez nich zostawia operatora z samym „nie da się".
        $this->assertStringContainsString('Masz dwa wyjścia:', $komunikat);
        $this->assertStringContainsString('1. poczekać, aż zaproszenia wygasną', $komunikat);
        $this->assertStringContainsString('2. `php artisan kuking:sprzataj-zaproszenia --wszystkie`', $komunikat);

        // Obietnica sprawdzana osobno w
        // `test_wygasle_zaproszenie_nie_wywoluje_odmowy`.
        $this->assertStringContainsString('wygasłe wiersze odmowy nie wywołują', $komunikat);

        // NAJWAŻNIEJSZE: po odmowie dane NADAL SĄ. Tabela stoi, a wiersze to
        // te same wiersze — poznajemy je po skrócie tokenu, bo to jedyna
        // wartość, po której serwis odnajduje zaproszenie przy kliknięciu
        // w link. Wiersz „jakiś" nikomu nie wystarczy.
        $this->assertTrue(Schema::hasTable('registration_invites'),
            'Odmowa, która zdążyła skasować tabelę, jest tylko ładniejszym komunikatem o stracie.');

        $this->assertSame(3, (int) DB::table('registration_invites')->count());

        foreach ($skroty as $skrot) {
            $this->assertTrue(
                DB::table('registration_invites')->where('token_hash', $skrot)->exists(),
                'Zaproszenie zniknęło mimo odmowy — link z wiadomości przestał działać.',
            );
        }
    }

    public function test_na_pustej_tabeli_cofniecie_dziala_bez_pytania(): void
    {
        // Pusta tabela to świeże wdrożenie albo lokalna baza: nie ma czego
        // stracić i nie ma o co pytać. To jest KONTROLA DODATNIA dla
        // strażnika wyżej — bez niej cały ten plik przechodziłby także dla
        // migracji, która nie cofa się nigdy.
        $this->assertSame(0, (int) DB::table('registration_invites')->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasTable('registration_invites'),
            'Na pustej tabeli `down()` ma po prostu zadziałać i skasować tabelę.',
        );
    }

    public function test_wygasle_zaproszenie_nie_wywoluje_odmowy(): void
    {
        // Druga kontrola dodatnia — i zarazem sprawdzenie WĄSKOŚCI samego
        // warunku. Gdyby strażnik pytał „czy są jakiekolwiek wiersze",
        // ten przypadek by oblał, a komunikat z przypadku pierwszego
        // obiecywałby rzecz nieprawdziwą: że wystarczy poczekać do
        // wygaśnięcia.
        $this->zaproszenie('spozniony@example.com', wygasle: true);

        $this->assertSame(1, (int) DB::table('registration_invites')->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasTable('registration_invites'),
            'Wygasłe zaproszenie nikomu już nie służy — nie ma prawa blokować wycofania.',
        );
    }

    public function test_odmowa_nie_rusza_niczego_poza_zaproszeniami(): void
    {
        // Kontrola WĄSKOŚCI SKUTKÓW: strażnik ma bronić swojej tabeli, a nie
        // przy okazji przestawiać cokolwiek na kontach. Zaproszenia z natury
        // dotyczą adresów BEZ konta (D-085), więc konto stoi tu obok — i
        // właśnie dlatego jest dobrym miernikiem: nie ma żadnego powodu, dla
        // którego odmowa miałaby je dotknąć.
        $basia = User::factory()->create();
        $przed = DB::table('users')->where('id', $basia->getKey())->first();

        $this->zaproszenie('ktos-bez-konta@example.com');

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM — i to nie
        // jest stylistyka. `$this->fail()` rzuca `AssertionFailedError`, a ta
        // dziedziczy przez `PHPUnit\Framework\Exception` po `RuntimeException`
        // (`vendor/phpunit/phpunit/src/Framework/Exception/Exception.php`).
        // Postawiona wewnątrz `try` wpadłaby więc do własnego `catch`, a ten
        // przy teście wąskości nie ma asercji, bo sprawdzamy SKUTKI UBOCZNE —
        // czyli test byłby zielony także wtedy, gdyby strażnika nie było wcale.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło mimo ważnego zaproszenia.');

        $this->assertEquals(
            $przed,
            DB::table('users')->where('id', $basia->getKey())->first(),
            'Odmowa ruszyła konto, którego ta migracja w ogóle nie dotyczy.',
        );
    }
}
