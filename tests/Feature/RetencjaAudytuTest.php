<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Models\AuditLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1).
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU: kategorie z `AuditLogEntry::NIGDY_NIE_KASUJ`
 * (dowód wykonania RODO art. 17 i dowód, że ktoś zgłosił/cofnął usunięcie
 * konta) NIE ZNIKAJĄ niezależnie od tego, jak bardzo są stare — to jest
 * jedyny ślad w całej bazie dla tych zdarzeń (patrz komentarz stałej).
 */
class RetencjaAudytuTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(string $action, \DateTimeInterface|string $createdAt): AuditLogEntry
    {
        $wpis = AuditLogEntry::record($action, metadata: ['test' => true]);

        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => $createdAt]);

        return $wpis->refresh();
    }

    public function test_wpis_starszy_niz_prog_znika_a_mlodszy_zostaje(): void
    {
        config(['kuking.audit_log.retention_months' => 24]);

        // Wyraźnie POZA i WEWNĄTRZ granicy — nie tylko "bardzo stary", żeby
        // test naprawdę pilnował progu, a nie dowolnej dużej różnicy.
        $stary = $this->wpis('post.hidden', now()->subMonths(24)->subDay());
        $mlody = $this->wpis('post.hidden', now()->subMonths(24)->addDay());

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(0, $wynik['niekasowalne']);
        $this->assertDatabaseMissing('audit_log', ['id' => $stary->getKey()]);
        // Asercja kontrolna: młodszy wpis naprawdę został, to nie jest test,
        // który przeszedłby też wtedy, gdyby komenda skasowała WSZYSTKO.
        $this->assertDatabaseHas('audit_log', ['id' => $mlody->getKey()]);
    }

    #[DataProvider('kategorieNiekasowalne')]
    public function test_kategoria_niekasowalna_zostaje_niezaleznie_od_wieku(string $action): void
    {
        config(['kuking.audit_log.retention_months' => 24]);

        // Ekstremalnie stary — 20 lat — żeby wykluczyć, że test przechodzi
        // przez przypadek (np. próg policzony błędnie o rząd wielkości).
        $niekasowalny = $this->wpis($action, now()->subYears(20));
        // Kontrola pozytywna: zwykła kategoria w tym samym wieku NAPRAWDĘ
        // znika — inaczej powyższe mogłoby przejść tylko dlatego, że komenda
        // nic nie robi.
        $zwykly = $this->wpis('post.hidden', now()->subYears(20));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(1, $wynik['niekasowalne']);
        $this->assertDatabaseHas('audit_log', ['id' => $niekasowalny->getKey()]);
        $this->assertDatabaseMissing('audit_log', ['id' => $zwykly->getKey()]);
    }

    public static function kategorieNiekasowalne(): array
    {
        return [
            'dowód wykonania usunięcia konta (RODO art. 17)' => ['account.data_erased'],
            'dowód zgłoszenia usunięcia konta' => ['account.delete_requested'],
            'dowód cofnięcia usunięcia konta' => ['account.delete_cancelled'],
        ];
    }

    /**
     * Stała w kodzie musi zawierać DOKŁADNIE te trzy kategorie — ani mniej
     * (dziura w ochronie), ani więcej bez zmierzonego powodu (rozdęcie
     * "bezterminowej" tabeli bez uzasadnienia, ADR §6).
     */
    public function test_lista_niekasowalnych_kategorii_jest_zamknieta_i_ma_dokladnie_trzy_pozycje(): void
    {
        $this->assertSame(
            ['account.data_erased', 'account.delete_requested', 'account.delete_cancelled'],
            AuditLogEntry::NIGDY_NIE_KASUJ,
        );
    }

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['kuking.audit_log.retention_months' => 24]);

        $this->wpis('post.hidden', now()->subYears(3));
        $this->wpis('account.data_erased', now()->subYears(3));

        $this->artisan('kuking:sprzataj-audyt', ['--na-sucho' => true])->assertSuccessful();
        // Kontrola: OBA wiersze wciąż tu są po na-sucho — nie tylko
        // "coś zostało", tylko dokładnie tyle, ile było.
        $this->assertDatabaseCount('audit_log', 2);

        $this->artisan('kuking:sprzataj-audyt')->assertSuccessful();
        // Po prawdziwym uruchomieniu: zwykły wpis zniknął, dowodowy został.
        $this->assertDatabaseCount('audit_log', 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.data_erased']);
    }
}
