<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\PrzedawnioneSygnaly;
use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Compliance\PrzedawnioneSesje;
use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Domain\Compliance\UsuwanieWPartiach;
use App\Models\AuditLogEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * RETENCJA PROSTYCH TABEL IDZIE PARTIAMI, Z BUDŻETEM NA PRZEBIEG (#1657).
 *
 * Do września 2026 każda z tych klas robiła jeden `DELETE` na cały zaległy
 * backlog. Przerwany w połowie wycofywał się w całości, więc „kolejny
 * przebieg dobierze resztę" z komentarzy nie było prawdą. Te testy mierzą
 * trzy rzeczy: ile wierszy obejmuje jedna instrukcja, ile znika w jednym
 * przebiegu i czy zatwierdzona partia zostaje po awarii następnej.
 *
 * OGRANICZENIE: `RefreshDatabase` trzyma cały test w jednej transakcji, więc
 * „zatwierdzona partia" to tu zwolniony savepoint, nie COMMIT widoczny dla
 * innego połączenia. Test pokazuje, że awaria kolejnej partii nie cofa
 * poprzedniej w kodzie; prawdziwego restartu procesu nie symuluje.
 */
class RetencjaPartiamiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $usuniecia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.lifetime' => 120, 'kuking.retencja.partia' => 2, 'kuking.retencja.budzet' => 1000]);

        DB::listen(function (QueryExecuted $zapytanie): void {
            if (str_starts_with(strtolower(ltrim($zapytanie->sql)), 'delete')) {
                $this->usuniecia[] = $zapytanie->sql;
            }
        });
    }

    /** Wiersz sesji o zadanym `id` i wieku ostatniej aktywności. */
    private function sesja(string $id, int $dniTemu): string
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '203.0.113.0',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subDays($dniTemu)->getTimestamp(),
        ]);

        return $id;
    }

    /** @return list<string> */
    private function sesje(): array
    {
        return DB::table('sessions')->orderBy('id')->pluck('id')->all();
    }

    private function wpisAudytu(string $action, int $miesiecyTemu): AuditLogEntry
    {
        $wpis = AuditLogEntry::record($action, metadata: ['test' => true]);
        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => now()->subMonths($miesiecyTemu)->subDay()]);

        return $wpis;
    }

    public function test_siedem_wierszy_znika_w_czterech_partiach_bez_pominiec(): void
    {
        foreach (['s1', 's2', 's3', 's4', 's5', 's6', 's7'] as $id) {
            $this->sesja($id, 30);
        }
        $mloda = $this->sesja('s0-mloda', 1);

        $wynik = (new PrzedawnioneSesje)->posprzataj(7);

        $this->assertSame(7, $wynik['skasowano']);
        $this->assertSame([$mloda], $this->sesje());
        $this->assertCount(4, $this->usuniecia, 'Jedna instrukcja objęła więcej niż jedną partię.');
    }

    public function test_przebieg_nie_przekracza_budzetu_a_reszta_schodzi_nastepnym(): void
    {
        config(['kuking.retencja.budzet' => 5]);
        $log = Log::spy();

        foreach (['s1', 's2', 's3', 's4', 's5', 's6', 's7'] as $id) {
            $this->sesja($id, 30);
        }

        $this->assertSame(5, (new PrzedawnioneSesje)->posprzataj(7)['skasowano']);

        // Stały porządek po kluczu: znikają najniższe identyfikatory.
        $this->assertSame(['s6', 's7'], $this->sesje());

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $komunikat, array $kontekst = []): bool => $kontekst === [
                'tabela' => 'sessions',
                'skasowano' => 5,
                'budzet' => 5,
                'pozostalo' => 2,
                'stage' => 'retention_budget_exhausted',
            ],
        )->once();

        $this->assertSame(2, (new PrzedawnioneSesje)->posprzataj(7)['skasowano']);
        $this->assertSame([], $this->sesje());
    }

    public function test_budzet_rowny_zalegosci_nie_ostrzega(): void
    {
        config(['kuking.retencja.budzet' => 3]);
        $log = Log::spy();

        foreach (['s1', 's2', 's3'] as $id) {
            $this->sesja($id, 30);
        }

        $this->assertSame(3, (new PrzedawnioneSesje)->posprzataj(7)['skasowano']);
        $log->shouldNotHaveReceived('warning');
    }

    public function test_awaria_po_pierwszej_partii_nie_cofa_jej(): void
    {
        foreach (['s1', 's2', 's3', 's4', 's5'] as $id) {
            $this->sesja($id, 30);
        }

        $prog = now()->subDays(7)->getTimestamp();
        $wywolania = 0;

        try {
            (new UsuwanieWPartiach(2, 1000))->usun(function () use (&$wywolania, $prog) {
                // 1: wybór partii, 2: jej DELETE, 3: wybór następnej — tu pada.
                if (++$wywolania === 3) {
                    throw new RuntimeException('awaria po pierwszej partii');
                }

                return DB::table('sessions')->where('last_activity', '<', $prog);
            }, 'id', 'sessions');
            $this->fail('Wstrzyknięta awaria nie doszła.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['s3', 's4', 's5'], $this->sesje(), 'Awaria cofnęła już usuniętą partię.');

        $this->assertSame(3, (new PrzedawnioneSesje)->posprzataj(7)['skasowano']);
        $this->assertSame([], $this->sesje());
    }

    public function test_wiersz_zmieniony_miedzy_wyborem_a_delete_zostaje(): void
    {
        $this->sesja('s1', 30);
        $this->sesja('s2', 30);

        $prog = now()->subDays(7)->getTimestamp();
        $wywolania = 0;

        $skasowano = (new UsuwanieWPartiach(2, 1000))->usun(function () use (&$wywolania, $prog) {
            // Przed DELETE pierwszej partii sesja `s1` znów jest aktywna.
            if (++$wywolania === 2) {
                DB::table('sessions')->where('id', 's1')->update(['last_activity' => now()->getTimestamp()]);
            }

            return DB::table('sessions')->where('last_activity', '<', $prog);
        }, 'id', 'sessions');

        $this->assertSame(1, $skasowano);
        $this->assertSame(['s1'], $this->sesje(), 'Skasowano żywą sesję wybraną chwilę wcześniej jako przedawnioną.');
    }

    public function test_na_sucho_liczy_calosc_i_niczego_nie_kasuje(): void
    {
        config(['kuking.retencja.budzet' => 2]);

        foreach (['s1', 's2', 's3'] as $id) {
            $this->sesja($id, 30);
        }

        $this->assertSame(3, (new PrzedawnioneSesje)->posprzataj(7, naSucho: true)['skasowano']);
        $this->assertCount(3, $this->sesje());
        $this->assertSame([], $this->usuniecia);
    }

    public function test_audyt_partiami_nie_rusza_kategorii_niekasowalnych(): void
    {
        $protected = [];
        foreach (AuditLogEntry::NIGDY_NIE_KASUJ as $action) {
            $protected[] = $this->wpisAudytu($action, 30)->getKey();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->wpisAudytu('post.hidden', 30);
        }
        $mlody = AuditLogEntry::record('post.hidden', metadata: ['test' => true])->getKey();

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertSame(5, $wynik['skasowano']);
        $this->assertSame(3, $wynik['niekasowalne']);
        $this->assertEqualsCanonicalizing(
            [...$protected, $mlody],
            AuditLogEntry::query()->whereIn('id', [...$protected, $mlody])->pluck('id')->all(),
        );
        $this->assertCount(3, $this->usuniecia);
    }

    public function test_sygnaly_produktowe_ida_partiami(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('product_signals')->insert([
                'signal_name' => ZapiszSygnal::SEARCH_PERFORMED,
                'properties' => json_encode(['query_length' => 3, 'has_results' => true]),
                'occurred_at' => now()->subDays(100),
            ]);
        }

        $this->assertSame(5, (new PrzedawnioneSygnaly)->posprzataj(90));
        $this->assertSame(0, DB::table('product_signals')->count());
        $this->assertCount(3, $this->usuniecia);
    }
}
