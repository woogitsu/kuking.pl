<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\DziennikWymazan;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Awaria dziennika wymazań TUŻ PRZED odtworzeniem kopii (issue #2038).
 *
 * Okno: `EraseAccountData` zatwierdza wymazanie, a zapis do dziennika poza
 * bazą pada. Brakujący wpis dopisuje dopiero nocne `kuking:dziennik-wymazan`
 * (05:30), które szuka kont po `users.data_erased_at` — w BIEŻĄCEJ bazie.
 * Kopia sprzed wymazania, odtworzona przed tą nocą, tego znacznika nie ma,
 * więc noc nie ma czego dopisać, a `kuking:wymaz-ponownie` nie ma wejścia.
 * Konto wraca z prawdziwym e-mailem i treściami, bez śladu prośby.
 *
 * Każdy test przechodzi całą sekwencję: awaria zapisu → wymazanie →
 * „odtworzenie kopii sprzed wymazania” → nocne uzupełnienie → wymaż ponownie
 * — i sprawdza WYNIK: konto znów `erased`, nie sam fakt zapisu.
 */
class DziennikWymazanPrzezOdtworzenieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.dziennik_wymazan.dysk' => 'dziennik_test']);
        config(['filesystems.disks.dziennik_test' => ['driver' => 'local', 'root' => storage_path('framework/testing/dziennik')]]);
        Storage::fake('dziennik_test');
        Sleep::fake();
    }

    /**
     * Dysk dziennika, który rzuca przy pierwszych `$awarie` zapisach
     * (PHP_INT_MAX = niedostępny przez całe wymazanie).
     */
    private function dyskZAwaria(int $awarie): void
    {
        $prawdziwy = Storage::disk('dziennik_test');
        $licznik = 0;

        $dysk = Mockery::mock($prawdziwy)->makePartial();
        $dysk->shouldReceive('put')->andReturnUsing(function (...$argumenty) use ($prawdziwy, $awarie, &$licznik) {
            if ($licznik++ < $awarie) {
                throw new RuntimeException('R2 nie odpowiada');
            }

            return $prawdziwy->put(...$argumenty);
        });

        Storage::set('dziennik_test', $dysk);
    }

    /** Konto wymazane przy (częściowo) niedostępnym dzienniku; zwraca też stan z „kopii”. */
    private function wymazaneKonto(string $zakres): array
    {
        $konto = User::factory()->create(['email' => 'basia@example.com']);
        $wpis = Post::factory()->create(['author_id' => $konto->getKey()]);

        $kopia = [
            'users' => (array) DB::table('users')->where('id', $konto->getKey())->first(),
            'profiles' => (array) DB::table('profiles')->where('user_id', $konto->getKey())->first(),
            'posts' => (array) DB::table('posts')->where('id', $wpis->getKey())->first(),
            'potwierdzenia' => DB::table('potwierdzenia_zadan_rodo')->pluck('id')->all(),
        ];

        $konto->fresh()->markForDeletion($zakres);
        $this->assertTrue(app(EraseAccountData::class)->handle($konto->fresh()));
        // Kontrola dodatnia: awaria dziennika nie zatrzymała wymazania.
        $this->assertSame(User::STATUS_ERASED, $konto->fresh()->status);

        return [$konto, $wpis, $kopia];
    }

    /** „Odtworzenie kopii sprzed wymazania” — ślad wymazania w bazie znika razem z nią. */
    private function odtworz(array $kopia): void
    {
        $kopia = array_map(fn (array $w) => array_is_list($w) ? $w : array_filter($w, fn ($k) => ! str_ends_with((string) $k, '_search'), ARRAY_FILTER_USE_KEY), $kopia);

        DB::table('users')->where('id', $kopia['users']['id'])->update($kopia['users']);
        DB::table('profiles')->where('user_id', $kopia['users']['id'])->update($kopia['profiles']);
        DB::table('posts')->updateOrInsert(['id' => $kopia['posts']['id']], $kopia['posts']);
        DB::table('potwierdzenia_zadan_rodo')->whereNotIn('id', $kopia['potwierdzenia'])->delete();
    }

    public function test_chwilowa_awaria_dziennika_przed_odtworzeniem_nie_gubi_wymazania(): void
    {
        $this->dyskZAwaria(1);

        [$konto, $wpis, $kopia] = $this->wymazaneKonto(User::DELETE_SCOPE_EVERYTHING);
        $this->odtworz($kopia);
        $this->assertSame('basia@example.com', $konto->fresh()->email);

        $this->artisan('kuking:dziennik-wymazan')->assertSuccessful();
        $this->artisan('kuking:wymaz-ponownie')->assertSuccessful();

        $poWymazaniu = $konto->fresh();
        $this->assertSame(User::STATUS_ERASED, $poWymazaniu->status);
        $this->assertNotSame('basia@example.com', $poWymazaniu->email);
        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);
    }

    public function test_trwala_awaria_dziennika_zostawia_w_logu_komplet_do_recznego_dopisania(): void
    {
        $this->dyskZAwaria(PHP_INT_MAX);
        $zLogu = null;
        Log::listen(function ($zdarzenie) use (&$zLogu): void {
            if (str_starts_with($zdarzenie->message, 'Dziennik wymazań: nie udało się zapisać')) {
                $zLogu = ['poziom' => $zdarzenie->level, ...$zdarzenie->context];
            }
        });

        [$konto, , $kopia] = $this->wymazaneKonto(User::DELETE_SCOPE_MINIMUM);

        // Wszystkie próby padły, więc w dzienniku pusto — to jest reszta okna.
        Storage::fake('dziennik_test');
        $this->assertSame([], app(DziennikWymazan::class)->wpisyOd());

        $this->odtworz($kopia);
        $this->artisan('kuking:dziennik-wymazan')->assertSuccessful();
        $this->assertSame([], app(DziennikWymazan::class)->wpisyOd(), 'Noc nie ma skąd wziąć wpisu — kontrola, że test odtwarza okno z #2038.');

        // Linia logu (stderr → dziennik Railwaya) jest jedynym śladem poza bazą.
        $this->assertNotNull($zLogu);
        $this->assertSame('error', $zLogu['poziom']);
        $this->assertSame((string) $konto->getKey(), $zLogu['user_id']);
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, $zLogu['zakres']);

        $this->artisan('kuking:dziennik-wymazan', [
            '--dopisz' => $zLogu['user_id'],
            '--zakres' => $zLogu['zakres'],
            '--kiedy' => $zLogu['wymazano_at'],
        ])->assertSuccessful();
        $this->artisan('kuking:wymaz-ponownie')->assertSuccessful();

        $this->assertSame(User::STATUS_ERASED, $konto->fresh()->status);
        $this->assertNotSame('basia@example.com', $konto->fresh()->email);
    }

    public function test_reczne_dopisanie_odrzuca_bledne_dane_i_nic_nie_zapisuje(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => 'nie-uuid', '--zakres' => User::DELETE_SCOPE_MINIMUM, '--kiedy' => now()->toIso8601ZuluString()])->assertFailed();
        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => $uuid, '--zakres' => 'polowa', '--kiedy' => now()->toIso8601ZuluString()])->assertFailed();
        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => $uuid, '--zakres' => User::DELETE_SCOPE_MINIMUM])->assertFailed();
        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => $uuid, '--zakres' => User::DELETE_SCOPE_MINIMUM, '--kiedy' => ''])->assertFailed();
        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => $uuid, '--zakres' => User::DELETE_SCOPE_MINIMUM, '--kiedy' => now()->addDay()->toIso8601ZuluString()])->assertFailed();

        $this->assertSame([], app(DziennikWymazan::class)->wpisyOd());

        $this->artisan('kuking:dziennik-wymazan', ['--dopisz' => $uuid, '--zakres' => User::DELETE_SCOPE_EVERYTHING, '--kiedy' => now()->subHour()->toIso8601ZuluString()])->assertSuccessful();
        $this->assertSame([$uuid], array_column(app(DziennikWymazan::class)->wpisyOd(), 'user_id'));
    }

    public function test_reczne_dopisanie_nie_nadpisuje_istniejacego_wymazania_innym_zakresem(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000002';
        $klucz = DziennikWymazan::PREFIKS.$uuid.'.json';
        $istniejacyWpis = json_encode([
            'user_id' => $uuid,
            'wymazano_at' => now()->subDays(2)->toIso8601ZuluString(),
            'zakres' => User::DELETE_SCOPE_EVERYTHING,
        ], JSON_THROW_ON_ERROR);
        Storage::disk('dziennik_test')->put($klucz, $istniejacyWpis);

        $this->artisan('kuking:dziennik-wymazan', [
            '--dopisz' => $uuid,
            '--zakres' => User::DELETE_SCOPE_MINIMUM,
            '--kiedy' => now()->subDay()->toIso8601ZuluString(),
        ])->assertFailed();

        $this->assertSame($istniejacyWpis, Storage::disk('dziennik_test')->get($klucz));
    }
}
