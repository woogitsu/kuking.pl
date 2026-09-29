<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Models\AuditLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Polityka prywatności mówi tyle, ile robi kod (audyt B5, znaleziska 7, 8, 10).
 *
 *  - pkt 7: tabela `sessions` (zgrubny IP, przeglądarka) nie była opisana,
 *    a realny okres to czas życia sesji z produkcji (30 dni), nie 7;
 *  - pkt 8: R2 trzyma też paczki eksportu i zaszyfrowane zrzuty bazy,
 *    a okres własnych kopii da się podać;
 *  - pkt 10: wpisy dowodowe `audit_log` trzymały skrót IP na zawsze.
 *
 * Liczby w polityce są porównywane z konfiguracją i z planem wdrożenia
 * (`.railway/railway.ts`), nie przepisane z palca — tak jak w
 * `DokumentyPrawneNieKlamiaTest` (D-024).
 */
class PolitykaOpisujeSesjeKopieIR2Test extends TestCase
{
    use RefreshDatabase;

    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    private function wiersz(string $poczatek): string
    {
        $wiersze = array_values(array_filter(
            explode("\n", $this->polityka()),
            static fn (string $l): bool => str_starts_with($l, $poczatek),
        ));

        $this->assertCount(1, $wiersze, "Kontrola: w polityce ma być dokładnie jeden wiersz „{$poczatek}”.");

        return $wiersze[0];
    }

    private function zmiennaWdrozenia(string $nazwa): int
    {
        $plan = (string) file_get_contents(base_path('.railway/railway.ts'));
        $this->assertSame(1, preg_match('/\b'.$nazwa.':\s*"(\d+)"/', $plan, $m), "Kontrola: brak {$nazwa} w .railway/railway.ts.");

        return (int) $m[1];
    }

    public function test_sesja_ma_wiersz_z_okresem_rownym_zyciu_sesji_na_produkcji(): void
    {
        $dni = max(
            (int) config('kuking.sessions.retention_days'),
            (int) ceil($this->zmiennaWdrozenia('SESSION_LIFETIME') / 1440),
        );
        $this->assertGreaterThan(0, $dni);

        $wiersz = $this->wiersz('| Utrzymanie zalogowania');

        $this->assertStringContainsString("Do **{$dni} dni** od ostatniej aktywności", $wiersz);
        $this->assertStringContainsString('**zgrubny** adres IP', $wiersz);
        $this->assertStringContainsString('przeglądarki', $wiersz);
    }

    public function test_r2_ma_trzy_zastosowania_a_kopie_okres_z_planu_wdrozenia(): void
    {
        $r2 = $this->wiersz('| Cloudflare R2 ');
        $ttl = (int) config('kuking.exports.ttl_days');

        $this->assertStringContainsString('zdjęć', $r2);
        $this->assertStringContainsString("paczek z Twoimi danymi do pobrania (najwyżej {$ttl} dni", $r2);
        $this->assertStringContainsString('kopii zapasowych bazy danych', $r2);

        $kopie = $this->zmiennaWdrozenia('KOPIA_RETENCJA_DNI');
        $this->assertStringContainsString("trzymamy najwyżej **{$kopie} dni**", $this->polityka());
        $this->assertStringNotContainsString('nie ustaliliśmy jej jeszcze z dostawcą', $this->polityka());
    }

    public function test_skrot_ip_we_wpisach_dowodowych_znika_po_okresie_retencji_a_wpis_zostaje(): void
    {
        $stary = AuditLogEntry::record('account.delete_requested', ip: '203.0.113.7');
        $mlody = AuditLogEntry::record('account.delete_cancelled', ip: '203.0.113.8');
        $zwykly = AuditLogEntry::record('post.hidden', ip: '203.0.113.9');
        DB::table('audit_log')->whereIn('id', [$stary->getKey(), $zwykly->getKey()])->update(['created_at' => now()->subMonths(13)]);

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzataj(12);

        $this->assertSame(1, $wynik['wyczyszczono_ip']);
        $this->assertDatabaseHas('audit_log', ['id' => $stary->getKey(), 'action' => 'account.delete_requested', 'ip_hash' => null]);
        // Kontrola dodatnia: świeży wpis dowodowy ma skrót, zwykły stary znika.
        $this->assertNotNull(DB::table('audit_log')->where('id', $mlody->getKey())->value('ip_hash'));
        $this->assertDatabaseMissing('audit_log', ['id' => $zwykly->getKey()]);

        $wiersz = $this->wiersz('| Bezpieczeństwo');
        $this->assertStringContainsString('Skrót adresu IP usuwamy także z nich po tych samych 12 miesiącach', $wiersz);
    }
}
