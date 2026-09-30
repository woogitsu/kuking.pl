<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\PotwierdzZgloszenieInnaDroga;
use App\Models\AuditLogEntry;
use App\Models\Report;
use App\Models\User;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use App\Support\AdresEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NARZĘDZIE OPERATORA: sprawa potwierdzona inną drogą (#2218, kryterium 3).
 *
 * Sufit prób listu zostawia sprawę zaległą na zawsze. Komenda
 * `kuking:potwierdz-zgloszenie-inna-droga` zamyka ją po ręcznym potwierdzeniu
 * (telefon, poczta papierowa…) i zostawia w dzienniku audytu, kto i kiedy.
 */
class PotwierdzenieInnaDrogaTest extends TestCase
{
    use RefreshDatabase;

    private const KOMENDA = 'kuking:potwierdz-zgloszenie-inna-droga';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    public function test_sprawa_na_suficie_dostaje_znacznik_i_wpis_audytu_z_operatorem(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $operator = $this->moderator();

        $this->artisan(self::KOMENDA, $this->opcje($sprawa, $operator))
            ->expectsOutputToContain('oznaczona jako potwierdzona')
            ->assertSuccessful();

        $this->assertNotNull($sprawa->fresh()->receipt_sent_at);

        $wpis = AuditLogEntry::query()->where('action', PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU)->sole();
        $this->assertSame($operator->getKey(), $wpis->actor_id);
        $this->assertSame('Report', $wpis->subject_type);
        $this->assertSame($sprawa->getKey(), $wpis->subject_id);
        $this->assertSame('telefon', $wpis->metadata['droga']);
        $this->assertTrue($wpis->metadata['na_suficie']);
        $this->assertNotNull($wpis->created_at, 'Dziennik ma zapisać KIEDY.');
        $this->assertStringNotContainsString('kancelaria', (string) json_encode($wpis->metadata), 'Adres zgłaszającego nie trafia do dziennika.');
    }

    public function test_sprawa_znika_z_zaleglych_i_sonda_health_pokazuje_zero(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        config(['kuking.health.token' => 't']);

        $this->assertSame(1, $this->get('/health', ['X-Kuking-Health-Token' => 't'])->json('informacje.potwierdzenia_dsa.na_suficie'));

        $this->artisan(self::KOMENDA, $this->opcje($sprawa, $this->moderator()))->assertSuccessful();

        $this->assertSame(0, $this->get('/health', ['X-Kuking-Health-Token' => 't'])->json('informacje.potwierdzenia_dsa.na_suficie'));
        $this->assertFalse(
            PotwierdzenieZgloszeniaNielegalnejTresci::ponawianieWstrzymane((string) $sprawa->getKey()),
            'Licznik porażek zamkniętej sprawy ma zniknąć.',
        );
    }

    public function test_numer_sprawy_bywa_wpisany_malymi_literami(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $opcje = $this->opcje($sprawa, $this->moderator());
        $opcje['numer'] = mb_strtolower((string) $sprawa->numer_sprawy);

        $this->artisan(self::KOMENDA, $opcje)->assertSuccessful();

        $this->assertNotNull($sprawa->fresh()->receipt_sent_at);
    }

    public function test_operator_bez_roli_moderatora_jest_odrzucony_i_nic_sie_nie_zmienia(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $zwykly = $this->user('zwyklyOperator');

        $this->artisan(self::KOMENDA, $this->opcje($sprawa, $zwykly))
            ->expectsOutputToContain('Tylko czynne konto moderatora albo administratora')
            ->assertFailed();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
        $this->assertSame(0, AuditLogEntry::query()->where('action', PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU)->count());
    }

    public function test_brak_operatora_i_brak_drogi_maja_komunikaty_po_polsku(): void
    {
        $sprawa = $this->sprawaNaSuficie();

        $this->artisan(self::KOMENDA, ['numer' => $sprawa->numer_sprawy, '--droga' => 'telefon', '--tak' => true])
            ->expectsOutputToContain('Podaj, kto to robi')
            ->assertFailed();

        $this->artisan(self::KOMENDA, ['numer' => $sprawa->numer_sprawy, '--operator' => $this->moderator()->email, '--tak' => true])
            ->expectsOutputToContain('Podaj, jak potwierdzono')
            ->assertFailed();

        $this->artisan(self::KOMENDA, ['numer' => $sprawa->numer_sprawy, '--operator' => 'nikt@nigdzie.example', '--droga' => 'telefon', '--tak' => true])
            ->expectsOutputToContain('Nie znaleziono konta operatora')
            ->assertFailed();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
    }

    public function test_nieznana_droga_jest_odrzucona(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $opcje = $this->opcje($sprawa, $this->moderator());
        $opcje['--droga'] = 'goniec z adresem anna@kancelaria.example';

        $this->artisan(self::KOMENDA, $opcje)
            ->expectsOutputToContain('Nieznana droga potwierdzenia')
            ->assertFailed();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
        $this->assertSame(0, AuditLogEntry::query()->where('action', PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU)->count());
    }

    public function test_nieistniejacy_numer_sprawy_ma_komunikat(): void
    {
        $this->artisan(self::KOMENDA, ['numer' => 'KU-ZZZZ-2222', '--operator' => $this->moderator()->email, '--droga' => 'telefon', '--tak' => true])
            ->expectsOutputToContain('Nie ma sprawy o numerze KU-ZZZZ-2222')
            ->assertFailed();
    }

    public function test_sprawa_juz_potwierdzona_nie_dostaje_drugiego_wpisu(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $opcje = $this->opcje($sprawa, $this->moderator());

        $this->artisan(self::KOMENDA, $opcje)->assertSuccessful();
        $znacznik = $sprawa->fresh()->receipt_sent_at;

        $this->artisan(self::KOMENDA, $opcje)
            ->expectsOutputToContain('ma już potwierdzenie przyjęcia')
            ->assertFailed();

        $this->assertEquals($znacznik, $sprawa->fresh()->receipt_sent_at);
        $this->assertSame(1, AuditLogEntry::query()->where('action', PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU)->count());
    }

    public function test_sprawa_po_doreczonej_decyzji_nie_jest_oznaczana(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        Report::query()->whereKey($sprawa->getKey())->update(['decision_sent_at' => now()]);

        $this->artisan(self::KOMENDA, $this->opcje($sprawa, $this->moderator()))
            ->expectsOutputToContain('dostał już informację o rozstrzygnięciu')
            ->assertFailed();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
    }

    public function test_sprawa_bez_adresata_nie_jest_oznaczana(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        Report::query()->whereKey($sprawa->getKey())->update(['notifier_email' => null]);

        $this->artisan(self::KOMENDA, $this->opcje($sprawa, $this->moderator()))
            ->expectsOutputToContain('nie ma adresata potwierdzenia')
            ->assertFailed();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
    }

    public function test_odmowa_pytania_potwierdzajacego_niczego_nie_zmienia(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $opcje = $this->opcje($sprawa, $this->moderator());
        unset($opcje['--tak']);

        $this->artisan(self::KOMENDA, $opcje)
            ->expectsConfirmation('Oznaczyć sprawę '.$sprawa->numer_sprawy.' jako potwierdzoną drogą „telefon” (operator: '
                .AdresEmail::maska((string) User::query()->where('role', User::ROLE_MODERATOR)->firstOrFail()->email).')? '
                .'Zgłaszający NIE dostanie z tego powodu żadnego listu.', 'no')
            ->expectsOutputToContain('Anulowano.')
            ->assertSuccessful();

        $this->assertNull($sprawa->fresh()->receipt_sent_at);
    }

    public function test_awaria_zapisu_audytu_cofa_tez_znacznik(): void
    {
        $sprawa = $this->sprawaNaSuficie();
        $operator = $this->moderator();

        // Wpis audytu jest częścią decyzji (D-249, klasa 1): gdy się nie
        // zapisze, znacznik nie może zostać bez śladu „kto i kiedy".
        DB::statement('ALTER TABLE audit_log ADD CONSTRAINT test_blokada_audytu CHECK (action <> \''.PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU.'\')');

        try {
            $this->expectException(QueryException::class);
            app(PotwierdzZgloszenieInnaDroga::class)->handle((string) $sprawa->numer_sprawy, $operator, 'telefon');
        } finally {
            $this->assertNull($sprawa->fresh()->receipt_sent_at, 'Znacznik został bez wpisu w dzienniku audytu.');
        }
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function opcje(Report $sprawa, User $operator): array
    {
        return [
            'numer' => $sprawa->numer_sprawy,
            '--operator' => $operator->email,
            '--droga' => 'telefon',
            '--tak' => true,
        ];
    }

    /** Zgłoszenie prawne bez konta po ostatecznej porażce listu: znacznik zdjęty, licznik na suficie. */
    private function sprawaNaSuficie(): Report
    {
        $this->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ])->assertSessionHasNoErrors();

        $sprawa = Report::query()->where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();
        Report::query()->whereKey($sprawa->getKey())->update(['receipt_sent_at' => null]);
        Cache::put(
            PotwierdzenieZgloszeniaNielegalnejTresci::kluczPorazek((string) $sprawa->getKey()),
            PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU,
            now()->addDay(),
        );

        return $sprawa->refresh();
    }
}
