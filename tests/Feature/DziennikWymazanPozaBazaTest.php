<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\DziennikWymazan;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Odtworzenie bazy z kopii nie cofa wymazania konta (audyt B5, znalezisko 3).
 *
 * Scenariusz z audytu: konto X wymazane 10 października, awaria, odtworzenie
 * kopii z 5 października — X wraca z prawdziwym e-mailem i treściami, a w bazie
 * nie ma śladu prośby o usunięcie. Test odtwarza to wprost: zapamiętuje wiersze
 * sprzed wymazania, wymazuje, wkłada stare wiersze z powrotem („restore”)
 * i sprawdza, że `kuking:wymaz-ponownie` doprowadza konto do `erased` — tym
 * samym zakresem, który wykonaliśmy za pierwszym razem.
 */
class DziennikWymazanPozaBazaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.dziennik_wymazan.dysk' => 'dziennik_test']);
        config(['filesystems.disks.dziennik_test' => ['driver' => 'local', 'root' => storage_path('framework/testing/dziennik')]]);
        Storage::fake('dziennik_test');
    }

    /** Konto z wpisem, oznaczone do usunięcia i wymazane; zwraca „kopię” sprzed wymazania. */
    private function wymazaneKonto(string $zakres, string $email = 'basia@example.com'): array
    {
        $konto = User::factory()->create(['email' => $email]);
        $wpis = Post::factory()->create(['author_id' => $konto->getKey()]);

        // Stan z KOPII: konto aktywne, sprzed prośby o usunięcie.
        $kopia = [
            'users' => (array) DB::table('users')->where('id', $konto->getKey())->first(),
            'profiles' => (array) DB::table('profiles')->where('user_id', $konto->getKey())->first(),
            'posts' => (array) DB::table('posts')->where('id', $wpis->getKey())->first(),
            'potwierdzenia' => DB::table('potwierdzenia_zadan_rodo')->pluck('id')->all(),
        ];

        $konto->fresh()->markForDeletion($zakres);
        $this->assertTrue(app(EraseAccountData::class)->handle($konto->fresh()));

        return [$konto, $wpis, $kopia];
    }

    /** „Odtworzenie bazy z kopii” — wiersze wracają do stanu z kopii. */
    private function odtworz(array $kopia): void
    {
        // Kolumny generowane (`*_search`) liczy baza sama.
        $kopia = array_map(fn (array $w) => array_is_list($w) ? $w : array_filter($w, fn ($k) => ! str_ends_with((string) $k, '_search'), ARRAY_FILTER_USE_KEY), $kopia);

        DB::table('users')->where('id', $kopia['users']['id'])->update($kopia['users']);
        DB::table('profiles')->where('user_id', $kopia['users']['id'])->update($kopia['profiles']);
        DB::table('posts')->updateOrInsert(['id' => $kopia['posts']['id']], $kopia['posts']);
        // Ślad wymazania w bazie cofa się razem z nią — dokładnie to, przed
        // czym chroni dziennik.
        DB::table('potwierdzenia_zadan_rodo')->whereNotIn('id', $kopia['potwierdzenia'])->delete();
    }

    public function test_wymazanie_zapisuje_wpis_poza_baza_bez_danych_osobowych(): void
    {
        [$konto] = $this->wymazaneKonto(User::DELETE_SCOPE_EVERYTHING);

        $wpisy = app(DziennikWymazan::class)->wpisyOd();

        $this->assertCount(1, $wpisy);
        $this->assertSame((string) $konto->getKey(), $wpisy[0]['user_id']);
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $wpisy[0]['zakres']);

        $surowy = Storage::disk('dziennik_test')->get(DziennikWymazan::PREFIKS.$konto->getKey().'.json');
        $this->assertStringNotContainsString('basia', (string) $surowy);
        $this->assertSame(['user_id', 'wymazano_at', 'zakres'], array_keys(json_decode((string) $surowy, true)));
    }

    public function test_po_odtworzeniu_kopii_wymaz_ponownie_przywraca_stan_erased_z_tym_samym_zakresem(): void
    {
        [$konto, $wpis, $kopia] = $this->wymazaneKonto(User::DELETE_SCOPE_EVERYTHING);
        $this->odtworz($kopia);

        // Kontrola dodatnia: po „odtworzeniu” konto naprawdę wróciło.
        $wrocilo = $konto->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $wrocilo->status);
        $this->assertSame('basia@example.com', $wrocilo->email);
        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);

        $this->artisan('kuking:wymaz-ponownie', ['--od' => now()->subDay()->toDateTimeString()])->assertSuccessful();

        $poWymazaniu = $konto->fresh();
        $this->assertSame(User::STATUS_ERASED, $poWymazaniu->status);
        $this->assertNotNull($poWymazaniu->data_erased_at);
        $this->assertNotSame('basia@example.com', $poWymazaniu->email);
        // Zakres „wszystko” z dziennika — treść znika znowu.
        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);

        // Drugi przebieg niczego nie zmienia (idempotencja).
        $this->artisan('kuking:wymaz-ponownie')->expectsOutputToContain('Wymazano ponownie: 0')->assertSuccessful();
    }

    public function test_zakres_minimum_z_dziennika_zostawia_teksty(): void
    {
        [$konto, $wpis, $kopia] = $this->wymazaneKonto(User::DELETE_SCOPE_MINIMUM);
        $this->odtworz($kopia);

        $this->artisan('kuking:wymaz-ponownie')->assertSuccessful();

        $this->assertSame(User::STATUS_ERASED, $konto->fresh()->status);
        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
    }

    public function test_wymazanie_sprzed_daty_kopii_i_tryb_na_sucho_niczego_nie_ruszaja(): void
    {
        [$konto, , $kopia] = $this->wymazaneKonto(User::DELETE_SCOPE_MINIMUM);
        $this->odtworz($kopia);

        // Kopia z JUTRA zawiera to wymazanie, więc nie ma czego powtarzać.
        $this->artisan('kuking:wymaz-ponownie', ['--od' => now()->addDay()->toDateTimeString()])->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $konto->fresh()->status);

        $this->artisan('kuking:wymaz-ponownie', ['--na-sucho' => true])
            ->expectsOutputToContain('Do wymazania: 1')
            ->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $konto->fresh()->status);
    }

    public function test_awaria_dziennika_nie_zatrzymuje_wymazania_a_noc_dopisuje_brakujacy_wpis(): void
    {
        $dysk = Mockery::mock(Storage::disk('dziennik_test'))->makePartial();
        $dysk->shouldReceive('put')->andThrow(new RuntimeException('R2 nie odpowiada'));
        Storage::set('dziennik_test', $dysk);

        [$konto] = $this->wymazaneKonto(User::DELETE_SCOPE_MINIMUM);
        $this->assertSame(User::STATUS_ERASED, $konto->fresh()->status);

        Storage::fake('dziennik_test');
        $this->assertSame([], app(DziennikWymazan::class)->wpisyOd());

        $this->artisan('kuking:dziennik-wymazan')->assertSuccessful();

        $wpisy = app(DziennikWymazan::class)->wpisyOd();
        $this->assertCount(1, $wpisy);
        $this->assertSame((string) $konto->getKey(), $wpisy[0]['user_id']);
    }

    public function test_wpisy_starsze_niz_retencja_znikaja(): void
    {
        config(['kuking.dziennik_wymazan.retention_days' => 120]);
        $dziennik = app(DziennikWymazan::class);
        $dziennik->zapisz('00000000-0000-0000-0000-000000000001', User::DELETE_SCOPE_MINIMUM, now()->subDays(121));
        $dziennik->zapisz('00000000-0000-0000-0000-000000000002', User::DELETE_SCOPE_MINIMUM, now()->subDays(119));

        $this->artisan('kuking:dziennik-wymazan')->assertSuccessful();

        $this->assertSame(['00000000-0000-0000-0000-000000000002'], array_column($dziennik->wpisyOd(), 'user_id'));
    }

    public function test_pielegnacja_jest_w_harmonogramie(): void
    {
        $nazwy = array_map(fn ($e) => $e->description, app(Schedule::class)->events());

        $this->assertContains('kuking:dziennik-wymazan', $nazwy);
    }
}
