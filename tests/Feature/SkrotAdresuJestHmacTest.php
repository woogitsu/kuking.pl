<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Support\KluczeLimitow;
use App\Support\Skrot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SKRÓT ADRESU IP MUSI BYĆ HMAC-EM W KAŻDYM MIEJSCU, KTÓRE GO LICZY
 *
 * Audyt kluczy limitera naprawił `App\Support\KluczeLimitow`: z `hash()`
 * z doklejonym sekretem na `hash_hmac`, z komentarzem tłumaczącym różnicę.
 * `App\Models\AuditLogEntry` liczył skrót adresu IP DALEJ starą, odrzuconą
 * konstrukcją — `hash('sha256', $ip.config('app.key'))`. Reguła istniała
 * w jednej warstwie i nie było jej w drugiej.
 *
 * Ten test pilnuje obu miejsc naraz i pilnuje ICH ZGODNOŚCI, a nie samego
 * faktu, że „coś jest zahaszowane". Kontrola idzie z drugiej strony: skrót
 * NIE MOŻE być równy staremu wzorowi — bez tej asercji test przechodziłby
 * na obu konstrukcjach i nie zauważyłby powrotu tej słabszej.
 */
class SkrotAdresuJestHmacTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '203.0.113.7';

    public function test_dziennik_zdarzen_liczy_skrot_adresu_hmac_em(): void
    {
        $wpis = AuditLogEntry::record('account.data_erased', null, null, [], self::ADRES);

        $oczekiwany = hash_hmac('sha256', self::ADRES, (string) config('app.key'));

        $this->assertSame($oczekiwany, $wpis->ip_hash, 'Skrót adresu w dzienniku zdarzeń nie jest HMAC-em.');
    }

    public function test_kontrola_skrot_nie_jest_stara_odrzucona_konstrukcja(): void
    {
        $wpis = AuditLogEntry::record('account.data_erased', null, null, [], self::ADRES);

        $stary = hash('sha256', self::ADRES.config('app.key'));

        // Bez tej asercji poprzedni test przechodziłby także wtedy, gdyby
        // ktoś przywrócił `hash()` z doklejoną solą — bo oba zwracają
        // 64 znaki szesnastkowe i oba „wyglądają jak skrót”.
        $this->assertNotSame($stary, $wpis->ip_hash, 'Wróciła konstrukcja hash($wartosc.$sekret), którą audyt kluczy limitera odrzucił.');
    }

    public function test_limiter_i_dziennik_licza_ten_sam_skrot_z_tego_samego_adresu(): void
    {
        $wpis = AuditLogEntry::record('account.data_erased', null, null, [], self::ADRES);

        $zLimitera = app(KluczeLimitow::class)->adres(self::ADRES);

        // Klucz limitera ma prefiks koszyka, więc porównujemy końcówkę —
        // chodzi o to, że obie warstwy liczą skrót TĄ SAMĄ funkcją, a nie
        // dwiema kopiami reguły, które mogą się rozjechać.
        $this->assertStringEndsWith((string) $wpis->ip_hash, $zLimitera, 'Limiter i dziennik liczą skrót adresu dwiema różnymi funkcjami.');
    }

    public function test_kontrola_dwa_rozne_adresy_daja_rozne_skroty(): void
    {
        // Gdyby ktoś „uprościł” Skrot do stałej albo do pustego stringa,
        // wszystkie asercje wyżej dalej by przechodziły.
        $this->assertNotSame(Skrot::hmac('203.0.113.7'), Skrot::hmac('203.0.113.8'));
        $this->assertSame(64, mb_strlen(Skrot::hmac(self::ADRES)), 'HMAC-SHA256 ma 64 znaki szesnastkowe.');
    }
}
