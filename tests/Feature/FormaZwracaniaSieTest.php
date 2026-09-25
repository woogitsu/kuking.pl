<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * „Jak mamy do Ciebie pisać?” — ustawienie formy zwracania się (D-268, #1752).
 *
 * Pilnuje całej drogi danej: CHECK w bazie, zapis z ustawień i z onboardingu,
 * domyślna forma neutralna (`NULL`), eksport RODO, anonimizacja przy usunięciu
 * konta i rollback, który odmawia przy zapisanych wyborach (D-088).
 *
 * Teksty według formy (helper, „Ugotowałam”) to #1753 — tu jest wyłącznie
 * zapis i widoczność samego wyboru.
 */
final class FormaZwracaniaSieTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'migrations/2026_09_25_140000_add_form_of_address_to_profiles.php';

    // -----------------------------------------------------------------
    // Domyślnie: forma neutralna, czyli dzisiejsze teksty bez rodzaju
    // -----------------------------------------------------------------

    public function test_nowe_konto_ma_forme_neutralna_zaznaczona_w_ustawieniach(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        $this->assertNull($basia->profile->form_of_address);

        $html = $this->actingAs($basia)->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Jak mamy do Ciebie pisać?')
            ->assertSee('To pytanie o brzmienie tekstów, nie o płeć.')
            // Forma jest widoczna dla innych — ekran musi to powiedzieć,
            // ZANIM ktoś wybierze (D-268 pkt 3).
            ->assertSee('co o Tobie czytają inni')
            ->getContent();

        $this->assertSame(1, preg_match_all('/<input[^>]*name="form_of_address"[^>]*\bchecked\b[^>]*>/s', $html, $zaznaczone));
        $this->assertStringContainsString('value="'.Profile::FORM_NEUTRAL.'"', $zaznaczone[0][0]);
        $this->assertStringNotContainsString('płeć:', mb_strtolower($html), 'Pole nie nazywa się „płeć”.');
    }

    // -----------------------------------------------------------------
    // Zapis z ustawień
    // -----------------------------------------------------------------

    public function test_zapis_formy_w_ustawieniach_i_powrot_do_neutralnej(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->put(route('settings.form_of_address'), ['form_of_address' => Profile::FORM_FEMININE])
            ->assertRedirect(route('settings.profile').'#forma-zwracania')
            ->assertSessionHasNoErrors();

        $this->assertSame(Profile::FORM_FEMININE, $basia->profile->fresh()->form_of_address);

        // Zapisany wybór jest zaznaczony po powrocie na ekran.
        $html = $this->get(route('settings.profile'))->assertOk()->getContent();
        preg_match_all('/<input[^>]*name="form_of_address"[^>]*\bchecked\b[^>]*>/s', $html, $zaznaczone);
        $this->assertCount(1, $zaznaczone[0]);
        $this->assertStringContainsString('value="'.Profile::FORM_FEMININE.'"', $zaznaczone[0][0]);

        // Forma neutralna to NULL w bazie, nie trzecia wartość.
        $this->put(route('settings.form_of_address'), ['form_of_address' => Profile::FORM_NEUTRAL])
            ->assertSessionHasNoErrors();

        $this->assertNull($basia->profile->fresh()->form_of_address);
    }

    public function test_zapis_formy_nie_rusza_pozostalych_pol_profilu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Profile::query()->whereKey($basia->getKey())->update(['bio' => 'Gotuję od zawsze.', 'speciality' => 'zupy']);

        $this->actingAs($basia)
            ->put(route('settings.form_of_address'), ['form_of_address' => Profile::FORM_MASCULINE])
            ->assertSessionHasNoErrors();

        $profil = $basia->profile->fresh();
        $this->assertSame(Profile::FORM_MASCULINE, $profil->form_of_address);
        $this->assertSame('basia', $profil->username);
        $this->assertSame('Basia', $profil->display_name);
        $this->assertSame('Gotuję od zawsze.', $profil->bio);
        $this->assertSame('zupy', $profil->speciality);
    }

    /** @return array<string, array{0: mixed}> */
    public static function zleWartosci(): array
    {
        return [
            'spoza listy' => ['kobieta'],
            'pusta' => [''],
            'tablica' => [[Profile::FORM_FEMININE]],
            'wartość kolumny zamiast pola' => [null],
        ];
    }

    #[DataProvider('zleWartosci')]
    public function test_zla_wartosc_nic_nie_zmienia_i_mowi_co_zrobic(mixed $wartosc): void
    {
        $basia = $this->user('basia');
        Profile::query()->whereKey($basia->getKey())->update(['form_of_address' => Profile::FORM_FEMININE]);

        $this->actingAs($basia)
            ->from(route('settings.profile'))
            ->put(route('settings.form_of_address'), ['form_of_address' => $wartosc])
            ->assertRedirect(route('settings.profile'))
            ->assertSessionHasErrors(['form_of_address' => 'Zaznacz jedną z trzech odpowiedzi i kliknij „Zapisz formę”. Jeśli nie chcesz wybierać, zaznacz formę neutralną.']);

        $this->assertSame(Profile::FORM_FEMININE, $basia->profile->fresh()->form_of_address);
    }

    public function test_gosc_nie_zapisze_formy(): void
    {
        $this->put(route('settings.form_of_address'), ['form_of_address' => Profile::FORM_FEMININE])
            ->assertRedirect(route('login'));

        $this->post(route('onboarding.form_of_address'), ['form_of_address' => Profile::FORM_FEMININE])
            ->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Onboarding: pytanie pomijalne
    // -----------------------------------------------------------------

    public function test_onboarding_zadaje_pytanie_i_pozwala_je_pominac(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->get(route('onboarding.done'))
            ->assertOk()
            ->assertSee('Jak mamy do Ciebie pisać?')
            // Oba wyjścia zostają — pytanie nie blokuje wejścia do serwisu.
            ->assertSee('Dodaj pierwsze zdjęcie')
            ->assertSee('Na razie tylko pooglądam');

        // Pominięcie = nic nie wysłane = forma neutralna.
        $this->assertNull($basia->profile->fresh()->form_of_address);

        $this->post(route('onboarding.form_of_address'), ['form_of_address' => Profile::FORM_MASCULINE])
            ->assertRedirect(route('onboarding.done'))
            ->assertSessionHas('status');

        $this->assertSame(Profile::FORM_MASCULINE, $basia->profile->fresh()->form_of_address);
    }

    // -----------------------------------------------------------------
    // Baza
    // -----------------------------------------------------------------

    public function test_baza_odrzuca_wartosc_spoza_listy(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK zakładamy tylko na PostgreSQL (testy chodzą na PostgreSQL).');
        }

        $basia = $this->user('basia');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('profiles_form_of_address_check');

        DB::table('profiles')->where('user_id', $basia->getKey())->update(['form_of_address' => Profile::FORM_NEUTRAL]);
    }

    public function test_rollback_odmawia_gdy_ktos_wybral_forme(): void
    {
        $basia = $this->user('basia');
        Profile::query()->whereKey($basia->getKey())->update(['form_of_address' => Profile::FORM_FEMININE]);

        try {
            $this->migracja()->down();
            $this->fail('Rollback przeszedł mimo zapisanego wyboru formy — wybór przepadłby po cichu (D-088).');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('profiles.form_of_address', $e->getMessage());
            $this->assertStringContainsString('Migracja odmawia', $e->getMessage());
        }

        // Odmowa PRZED zmianą schematu: kolumna i wybór są na miejscu.
        $this->assertTrue(Schema::hasColumn('profiles', 'form_of_address'));
        $this->assertSame(Profile::FORM_FEMININE, $basia->profile->fresh()->form_of_address);
    }

    public function test_rollback_przechodzi_gdy_nikt_nie_wybral_formy(): void
    {
        $this->user('basia');

        $this->migracja()->down();
        $this->assertFalse(Schema::hasColumn('profiles', 'form_of_address'));

        $this->migracja()->up();
        $this->assertTrue(Schema::hasColumn('profiles', 'form_of_address'));
    }

    // -----------------------------------------------------------------
    // RODO: eksport i anonimizacja
    // -----------------------------------------------------------------

    public function test_eksport_rodo_mowi_slowem_jaka_forme_wybrano(): void
    {
        $basia = $this->user('basia');

        $this->assertSame('neutralna', $this->paczka($basia)['profil']['forma_zwracania_sie'] ?? null);

        Profile::query()->whereKey($basia->getKey())->update(['form_of_address' => Profile::FORM_FEMININE]);
        $this->assertSame('żeńska', $this->paczka($basia->refresh())['profil']['forma_zwracania_sie'] ?? null);

        Profile::query()->whereKey($basia->getKey())->update(['form_of_address' => Profile::FORM_MASCULINE]);
        $this->assertSame('męska', $this->paczka($basia->refresh())['profil']['forma_zwracania_sie'] ?? null);
    }

    public function test_anonimizacja_konta_zeruje_forme(): void
    {
        $odchodzi = $this->user('odchodzi');
        Profile::query()->whereKey($odchodzi->getKey())->update(['form_of_address' => Profile::FORM_FEMININE]);
        $odchodzi->markForDeletion();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi->refresh()));

        $this->assertNull(Profile::query()->whereKey($odchodzi->getKey())->value('form_of_address'));
    }

    private function migracja(): object
    {
        return require database_path(self::MIGRACJA);
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());
    }
}
