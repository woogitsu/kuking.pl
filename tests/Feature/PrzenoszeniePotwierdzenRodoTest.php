<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzenoszeniePotwierdzenRodo;
use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Models\AuditLogEntry;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Przeniesienie starych wpisów `audit_log` `account.*` do potwierdzeń RODO
 * (#2708, krok 5 PROJEKT_POTWIERDZENIA_RODO.md): liczby po przebiegu, dry-run,
 * idempotencja, brak dubli z wierszami zapisanymi przez nowy kod i bramka dla
 * retencji audytu.
 */
class PrzenoszeniePotwierdzenRodoTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(string $action, User $konto, string $dzien, ?string $zakres = null): void
    {
        $wpis = AuditLogEntry::record($action, null, $konto, metadata: $zakres !== null ? ['zakres' => $zakres] : [], ip: '203.0.113.9');
        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => $dzien.' 12:00:00']);
    }

    /**
     * @return array{wykonane: User, cofniete: User, otwarte: User, dwa: User, nowy: User}
     */
    private function scena(): array
    {
        $wykonane = User::factory()->create();
        $this->wpis('account.delete_requested', $wykonane, '2026-03-01', 'everything');
        $this->wpis('account.data_erased', $wykonane, '2026-03-31', 'everything');

        $cofniete = User::factory()->create();
        $this->wpis('account.delete_requested', $cofniete, '2026-04-02', 'minimum');
        $this->wpis('account.delete_cancelled', $cofniete, '2026-04-05');

        $otwarte = User::factory()->create();
        $this->wpis('account.delete_requested', $otwarte, '2026-09-20', 'minimum');

        // Dwie sprawy jednego konta: cofnięta, potem wykonana.
        $dwa = User::factory()->create();
        $this->wpis('account.delete_requested', $dwa, '2026-05-01', 'minimum');
        $this->wpis('account.delete_cancelled', $dwa, '2026-05-03');
        $this->wpis('account.delete_requested', $dwa, '2026-06-01', 'minimum');
        $this->wpis('account.data_erased', $dwa, '2026-07-01', 'minimum');

        // Sprawa zamknięta już nowym kodem: potwierdzenie istnieje.
        $nowy = User::factory()->create();
        $this->wpis('account.delete_requested', $nowy, '2026-09-25', 'minimum');
        $this->wpis('account.data_erased', $nowy, '2026-09-26', 'minimum');
        (new RejestrPotwierdzenRodo)->dopiszZAudytu([
            'rodzaj' => 'usuniecie_konta',
            'wynik' => 'wykonane',
            'zakres' => 'minimum',
            'otrzymano' => '2026-09-25',
            'zakonczono' => '2026-09-26',
            'wersja_procedury' => '2026-09-06',
            'wyjatki' => null,
            'konto_id' => $nowy->getKey(),
        ]);

        return compact('wykonane', 'cofniete', 'otwarte', 'dwa', 'nowy');
    }

    public function test_dry_run_wypisuje_liczby_i_niczego_nie_zapisuje(): void
    {
        $this->scena();
        $przed = PotwierdzenieZadaniaRodo::query()->count();

        $this->artisan('kuking:przenies-potwierdzenia-rodo', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN (nic nie zapisano). Zamknięte sprawy w audit_log: 5.')
            ->assertSuccessful();

        $this->assertSame($przed, PotwierdzenieZadaniaRodo::query()->count());
        // Zamknięć w audycie 5, potwierdzeń 1 => brakuje 4.
        $this->assertSame(4, app(PrzenoszeniePotwierdzenRodo::class)->ileBrakuje());
    }

    public function test_przebieg_tworzy_brakujace_potwierdzenia_a_liczby_sie_zgadzaja(): void
    {
        $k = $this->scena();

        $this->artisan('kuking:przenies-potwierdzenia-rodo')
            ->expectsOutputToContain('Brakuje po przebiegu: 0')
            ->assertSuccessful();

        $zamkniecia = AuditLogEntry::query()->whereIn('action', ['account.data_erased', 'account.delete_cancelled'])->count();
        $potwierdzenia = PotwierdzenieZadaniaRodo::query()->whereIn('wynik', ['wykonane', 'cofniete'])->count();
        $this->assertSame($zamkniecia, $potwierdzenia, 'Liczba potwierdzeń musi zgadzać się z liczbą zamknięć w audycie.');
        $this->assertSame(0, app(PrzenoszeniePotwierdzenRodo::class)->ileBrakuje());

        $w = PotwierdzenieZadaniaRodo::query()->where('konto_id', $k['wykonane']->getKey())->sole();
        $this->assertSame('wykonane', $w->wynik);
        $this->assertSame('everything', $w->zakres);
        $this->assertSame('2026-03-01', $w->otrzymano->toDateString());
        $this->assertSame('2026-03-31', $w->zakonczono->toDateString());
        $this->assertMatchesRegularExpression('/^RODO-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $w->numer);
        $this->assertNotNull($w->wyjatki);

        $c = PotwierdzenieZadaniaRodo::query()->where('konto_id', $k['cofniete']->getKey())->sole();
        $this->assertSame('cofniete', $c->wynik);
        $this->assertNull($c->zakres);
        $this->assertSame('2026-04-02', $c->otrzymano->toDateString());

        // Dwie sprawy jednego konta zostają dwiema sprawami, z własnymi datami.
        $dwa = PotwierdzenieZadaniaRodo::query()->where('konto_id', $k['dwa']->getKey())->orderBy('zakonczono')->get();
        $this->assertSame(['cofniete', 'wykonane'], $dwa->pluck('wynik')->all());
        $this->assertSame(['2026-05-01', '2026-06-01'], $dwa->map(fn ($x) => $x->otrzymano->toDateString())->all());

        // Sprawa otwarta nie dostaje wiersza; sprawa już obecna nie jest zdublowana.
        $this->assertSame(0, PotwierdzenieZadaniaRodo::query()->where('konto_id', $k['otwarte']->getKey())->count());
        $this->assertSame(1, PotwierdzenieZadaniaRodo::query()->where('konto_id', $k['nowy']->getKey())->count());
    }

    public function test_drugi_przebieg_niczego_nie_dubluje(): void
    {
        $this->scena();
        $this->artisan('kuking:przenies-potwierdzenia-rodo')->assertSuccessful();
        $po = PotwierdzenieZadaniaRodo::query()->count();

        $this->artisan('kuking:przenies-potwierdzenia-rodo')
            ->expectsOutputToContain('Utworzono: 0')
            ->assertSuccessful();

        $this->assertSame($po, PotwierdzenieZadaniaRodo::query()->count());
    }

    public function test_potwierdzenie_z_inna_data_niz_wpis_audytu_nie_jest_dublowane(): void
    {
        // Wpis audytu i potwierdzenie z tej samej sprawy mogą różnić się datą
        // (strefa czasowa, ręczna korekta). Liczy się liczba na parze konto + wynik.
        $konto = User::factory()->create();
        $this->wpis('account.data_erased', $konto, '2026-09-26', 'minimum');
        (new RejestrPotwierdzenRodo)->dopiszZAudytu([
            'rodzaj' => 'usuniecie_konta',
            'wynik' => 'wykonane',
            'zakres' => 'minimum',
            'otrzymano' => '2026-09-26',
            'zakonczono' => '2026-09-27',
            'wersja_procedury' => '2026-09-06',
            'wyjatki' => null,
            'konto_id' => $konto->getKey(),
        ]);

        $this->artisan('kuking:przenies-potwierdzenia-rodo')->expectsOutputToContain('Utworzono: 0')->assertSuccessful();

        $this->assertSame(1, PotwierdzenieZadaniaRodo::query()->where('konto_id', $konto->getKey())->count());
        $this->assertSame(0, app(PrzenoszeniePotwierdzenRodo::class)->ileBrakuje());
    }

    public function test_retencja_audytu_czeka_na_przeniesienie_i_potem_kasuje_stare_wpisy(): void
    {
        $this->scena();
        config(['kuking.audit_log.retention_months' => 1]);
        $kont = AuditLogEntry::query()->where('action', 'like', 'account.%')->count();

        // Przed przeniesieniem: nic z account.* nie znika, mimo że wpisy są stare.
        $this->artisan('kuking:sprzataj-audyt')->expectsOutputToContain('kuking:przenies-potwierdzenia-rodo')->assertSuccessful();
        $this->assertSame($kont, AuditLogEntry::query()->where('action', 'like', 'account.%')->count());

        // Po przeniesieniu zwykła retencja zabiera wpisy starsze niż próg.
        $this->artisan('kuking:przenies-potwierdzenia-rodo')->assertSuccessful();
        $this->artisan('kuking:sprzataj-audyt')->assertSuccessful();
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'like', 'account.%')->where('created_at', '<', now()->subMonth())->count());
        // Potwierdzenia zostały — to teraz jedyny dowód.
        $this->assertGreaterThanOrEqual(5, PotwierdzenieZadaniaRodo::query()->count());
    }
}
