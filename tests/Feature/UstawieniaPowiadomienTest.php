<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Notifications\Push\ZapiszSubskrypcjePush;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UstawieniaPowiadomienZewnetrznych;
use Illuminate\Database\QueryException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `/ustawienia/powiadomienia` (issue #35, D-303): przełącznik pushu per
 * urządzenie, cisza nocna, dzienny limit — oraz to, co z tymi danymi dzieje
 * się w paczce RODO, przy wymazaniu konta i przy wycofaniu migracji.
 */
final class UstawieniaPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'migrations/2026_09_26_100000_utworz_powiadomienia_push.php';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
        ]);
    }

    public function test_gosc_trafia_do_logowania(): void
    {
        $this->get(route('settings.notifications'))->assertRedirect(route('login'));
    }

    public function test_ekran_mowi_ze_powiadomienia_w_serwisie_dzialaja_zawsze_i_nie_pyta_o_zgode_sam(): void
    {
        $basia = $this->user('basia_ekran');

        $odpowiedz = $this->actingAs($basia)->get(route('settings.notifications'))->assertOk();

        $odpowiedz->assertSee('Powiadomienia w Kuking — lista pod dzwonkiem — działają zawsze.', false);
        $odpowiedz->assertSee('data-push-klucz="BTestowyKluczPublicznyNieDoWysylki"', false);
        // Przyciski czekają na skrypt (D-053) — bez niego nikt nie widzi martwego przycisku.
        $odpowiedz->assertSee('data-push-przycisk-wlacz hidden', false);
        $odpowiedz->assertSee('Powiadomienia poza Kuking są wyłączone na wszystkich urządzeniach.');
        // Domyślne wartości formularza.
        $odpowiedz->assertSee('<option value="21" selected>21:00</option>', false);
        $odpowiedz->assertSee('<option value="8" selected>8:00</option>', false);
    }

    public function test_zapis_ciszy_nocnej_i_limitu(): void
    {
        $basia = $this->user('basia_zapis');

        $this->actingAs($basia)
            ->from(route('settings.notifications'))
            ->put(route('settings.notifications.update'), ['cisza_od' => 22, 'cisza_do' => 7, 'dzienny_limit' => 3])
            ->assertRedirect(route('settings.notifications'))
            ->assertSessionHas('status', 'Zapisane.');

        $zapisane = UstawieniaPowiadomienZewnetrznych::query()->find($basia->getKey());
        $this->assertNotNull($zapisane);
        $this->assertSame([22, 7, 3], [$zapisane->cisza_od, $zapisane->cisza_do, $zapisane->dzienny_limit]);

        // Drugi zapis aktualizuje ten sam wiersz, nie dokłada nowego.
        $this->actingAs($basia)->put(route('settings.notifications.update'), ['cisza_od' => 20, 'cisza_do' => 9, 'dzienny_limit' => 1]);
        $this->assertSame(1, UstawieniaPowiadomienZewnetrznych::query()->count());
        $this->assertSame(20, $zapisane->fresh()->cisza_od);
    }

    public function test_limit_spoza_listy_jest_odrzucony_po_polsku_i_nic_sie_nie_zapisuje(): void
    {
        $basia = $this->user('basia_limit');

        $this->actingAs($basia)
            ->from(route('settings.notifications'))
            ->put(route('settings.notifications.update'), ['cisza_od' => 22, 'cisza_do' => 7, 'dzienny_limit' => 50])
            ->assertSessionHasErrors(['dzienny_limit' => 'Wybierz z listy, ile powiadomień dziennie możemy wysłać.']);

        $this->assertSame(0, UstawieniaPowiadomienZewnetrznych::query()->count());
    }

    public function test_wlaczenie_na_urzadzeniu_zapisuje_subskrypcje(): void
    {
        $basia = $this->user('basia_sub');

        $this->actingAs($basia)
            ->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji('https://fcm.googleapis.com/fcm/send/abc123'))
            ->assertCreated();

        $sub = PushSubscription::query()->sole();
        $this->assertSame($basia->getKey(), $sub->user_id);
        $this->assertSame('aes128gcm', $sub->kodowanie);

        $this->actingAs($basia)->get(route('settings.notifications'))
            ->assertSee('Powiadomienia poza Kuking są włączone na 1 urządzeniu:')
            ->assertSee('Chrome, Edge albo inna przeglądarka oparta na Chrome');
    }

    public function test_adres_spoza_uslug_push_jest_odrzucony_ssrf(): void
    {
        $basia = $this->user('basia_ssrf');

        foreach ([
            'http://fcm.googleapis.com/fcm/send/x',          // bez TLS
            'https://169.254.169.254/latest/meta-data',      // metadane chmury
            'https://fcm.googleapis.com.zly.pl/x',           // podszywający się host
            'https://login:haslo@fcm.googleapis.com/x',      // login w adresie
            'https://fcm.googleapis.com:8443/x',             // inny port
            'https://kuking.railway.internal/x',             // sieć wewnętrzna
        ] as $adres) {
            $this->actingAs($basia)
                ->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji($adres))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('endpoint');
        }

        $this->assertSame(0, PushSubscription::query()->count());

        // Kontrola dodatnia: poddomena znanej usługi przechodzi.
        $this->actingAs($basia)
            ->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji('https://web.push.apple.com/QAbc'))
            ->assertCreated();
    }

    public function test_ta_sama_przegladarka_po_zmianie_konta_przechodzi_na_nowe_konto(): void
    {
        $basia = $this->user('basia_wspolny');
        $zenek = $this->user('zenek_wspolny');
        $adres = 'https://fcm.googleapis.com/fcm/send/wspolny-komputer';

        $this->actingAs($basia)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji($adres))->assertCreated();
        $this->actingAs($zenek)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji($adres))->assertCreated();

        $this->assertSame($zenek->getKey(), PushSubscription::query()->sole()->user_id);
    }

    public function test_wylaczenie_na_urzadzeniu_kasuje_tylko_wlasny_wiersz(): void
    {
        $basia = $this->user('basia_wyl');
        $zenek = $this->user('zenek_wyl');
        $adresZenka = 'https://fcm.googleapis.com/fcm/send/zenek';
        $this->actingAs($zenek)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji($adresZenka))->assertCreated();

        // Basia podaje adres Zenka — nie ma prawa go skasować.
        $this->actingAs($basia)->deleteJson(route('settings.notifications.unsubscribe'), ['endpoint' => $adresZenka])->assertNoContent();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($zenek)->deleteJson(route('settings.notifications.unsubscribe'), ['endpoint' => $adresZenka])->assertNoContent();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_wylaczenie_wszedzie_dziala_bez_javascriptu_i_nie_rusza_cudzych(): void
    {
        $basia = $this->user('basia_wszedzie');
        $zenek = $this->user('zenek_wszedzie');
        foreach (['telefon', 'laptop'] as $urzadzenie) {
            $this->actingAs($basia)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji('https://fcm.googleapis.com/fcm/send/'.$urzadzenie))->assertCreated();
        }
        $this->actingAs($zenek)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji('https://fcm.googleapis.com/fcm/send/zenka'))->assertCreated();

        $this->actingAs($basia)
            ->from(route('settings.notifications'))
            ->delete(route('settings.notifications.disable-all'))
            ->assertRedirect(route('settings.notifications'));

        $this->assertSame(0, $basia->pushSubscriptions()->count());
        $this->assertSame(1, $zenek->pushSubscriptions()->count());
    }

    public function test_ponad_limit_urzadzen_znika_najstarsze(): void
    {
        config(['kuking.notifications.zewnetrzne.push_maks_urzadzen' => 2]);
        $basia = $this->user('basia_limit_urzadzen');

        foreach (['pierwsze', 'drugie', 'trzecie'] as $i => $urzadzenie) {
            $this->travelTo(Carbon::parse('2026-09-25 10:00:00', 'UTC')->addMinutes($i));
            $this->actingAs($basia)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji('https://fcm.googleapis.com/fcm/send/'.$urzadzenie))->assertCreated();
        }

        $this->assertEqualsCanonicalizing(
            ['https://fcm.googleapis.com/fcm/send/drugie', 'https://fcm.googleapis.com/fcm/send/trzecie'],
            $basia->pushSubscriptions()->pluck('endpoint')->all(),
        );
    }

    public function test_zapis_nowego_endpointu_trzyma_wspolna_blokade_konta_i_endpointu(): void
    {
        $user = $this->user('blokady_push');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/blokady-push';
        $domyslna = config('database.default');
        config(['database.connections.push_probe' => config("database.connections.$domyslna")]);

        $zapytania = [];
        $sprawdzonoDrugiPolaczenie = false;
        DB::listen(function (QueryExecuted $query) use (&$zapytania, &$sprawdzonoDrugiPolaczenie, $endpoint): void {
            if ($query->connectionName === 'push_probe') {
                return;
            }
            $zapytania[] = $query->sql;
            if (! $sprawdzonoDrugiPolaczenie && str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $sprawdzonoDrugiPolaczenie = true;
                $taken = DB::connection('push_probe')->selectOne(
                    'SELECT pg_try_advisory_xact_lock(?, hashtext(?))::int AS taken', [1998, $endpoint],
                );
                $this->assertSame(0, (int) $taken->taken, 'Drugie połączenie nie może przejąć endpointu w trakcie zapisu.');
            }
        });

        try {
            app(ZapiszSubskrypcjePush::class)->handle($user, $endpoint, 'klucz', 'auth', 'aes128gcm');
        } finally {
            DB::disconnect('push_probe');
        }

        $this->assertTrue($sprawdzonoDrugiPolaczenie);
        $endpointLock = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'), $zapytania), true);
        $userLock = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, '"users"') && str_contains($sql, 'for update'), $zapytania), true);
        $this->assertIsInt($endpointLock);
        $this->assertIsInt($userLock);
        $this->assertLessThan($userLock, $endpointLock, 'Endpoint musi być zablokowany przed kontem.');
        $this->assertSame(1, $user->pushSubscriptions()->count());
    }

    public function test_transfer_blokuje_obu_wlascicieli_w_stalej_kolejnosci(): void
    {
        config(['kuking.notifications.zewnetrzne.push_maks_urzadzen' => 1]);
        $first = $this->user('pierwszy_transfer');
        $second = $this->user('drugi_transfer');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/transfer-z-limitem';
        $zapisz = app(ZapiszSubskrypcjePush::class);
        $zapisz->handle($first, $endpoint, 'klucz', 'auth', 'aes128gcm');
        $zapisz->handle($second, 'https://fcm.googleapis.com/fcm/send/stary', 'klucz', 'auth', 'aes128gcm');
        PushSubscription::query()->where('endpoint', 'https://fcm.googleapis.com/fcm/send/stary')
            ->update(['updated_at' => now()->subDay()]);

        $lockedIds = [];
        DB::listen(function (QueryExecuted $query) use (&$lockedIds): void {
            if (str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
                $lockedIds[] = (string) $query->bindings[0];
            }
        });
        $zapisz->handle($second, $endpoint, 'klucz2', 'auth2', 'aes128gcm');

        $expected = [(string) $first->getKey(), (string) $second->getKey()];
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $lockedIds);
        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame([$endpoint], $second->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_paczka_rodo_ma_ustawienia_i_urzadzenia_bez_adresu_i_kluczy(): void
    {
        $basia = $this->user('basia_rodo');
        $adres = 'https://fcm.googleapis.com/fcm/send/SEKRETNY-ADRES-SUBSKRYPCJI';
        $this->actingAs($basia)->postJson(route('settings.notifications.subscribe'), $this->daneSubskrypcji($adres))->assertCreated();
        $basia->ustawieniaPowiadomienZewnetrznych()->create(['cisza_od' => 22, 'cisza_do' => 7, 'dzienny_limit' => 2]);

        $paczka = app(CollectUserExportData::class)->handle($basia->fresh(), new ExportPhotoPlan($basia), Carbon::parse('2026-09-26 12:00:00', 'UTC'));
        $sekcja = $paczka['powiadomienia_poza_serwisem'];

        $this->assertSame([22, 7, 2], [$sekcja['cisza_nocna_od_godziny'], $sekcja['cisza_nocna_do_godziny'], $sekcja['dzienny_limit']]);
        $this->assertCount(1, $sekcja['urzadzenia']);
        $this->assertSame('fcm.googleapis.com', $sekcja['urzadzenia'][0]['usluga_push']);

        $json = (string) json_encode($paczka, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('SEKRETNY-ADRES-SUBSKRYPCJI', $json);
        $this->assertStringNotContainsString(str_repeat('A', 87), $json);
    }

    public function test_wymazanie_konta_kasuje_subskrypcje_i_ustawienia(): void
    {
        $odchodzi = $this->user('odchodzi_push', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $zostaje = $this->user('zostaje_push');
        foreach ([$odchodzi, $zostaje] as $i => $konto) {
            $sub = new PushSubscription;
            $sub->forceFill([
                'user_id' => $konto->getKey(),
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/konto'.$i,
                'klucz_p256dh' => str_repeat('A', 87),
                'klucz_auth' => str_repeat('B', 22),
            ])->save();
            $konto->ustawieniaPowiadomienZewnetrznych()->create(['cisza_od' => 22, 'cisza_do' => 7, 'dzienny_limit' => 2]);
        }

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(0, PushSubscription::query()->where('user_id', $odchodzi->getKey())->count());
        $this->assertNull(UstawieniaPowiadomienZewnetrznych::query()->find($odchodzi->getKey()));
        // Kontrola dodatnia: cudze dane zostają.
        $this->assertSame(1, PushSubscription::query()->where('user_id', $zostaje->getKey())->count());
        $this->assertNotNull(UstawieniaPowiadomienZewnetrznych::query()->find($zostaje->getKey()));
    }

    public function test_wycofanie_migracji_odmawia_gdy_ktos_wybral_wlasna_cisze_nocna(): void
    {
        $basia = $this->user('basia_migracja');
        $basia->ustawieniaPowiadomienZewnetrznych()->create(['cisza_od' => 23, 'cisza_do' => 6, 'dzienny_limit' => 1]);
        $migracja = require database_path(self::MIGRACJA);

        try {
            $migracja->down();
            $this->fail('Wycofanie przeszło, mimo że po ponownym migrate cisza nocna Basi wróciłaby do 21–8.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('D-088', $e->getMessage());
        }

        // Odmowa niczego nie zdjęła.
        $this->assertSame(23, UstawieniaPowiadomienZewnetrznych::query()->find($basia->getKey())?->cisza_od);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('notifications', 'push_wyslano_at'));
    }

    public function test_wycofanie_migracji_przechodzi_gdy_nikt_nic_nie_wybral(): void
    {
        $migracja = require database_path(self::MIGRACJA);

        $migracja->down();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('push_subscriptions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('ustawienia_powiadomien_zewnetrznych'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('notifications', 'push_wyslano_at'));

        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('push_subscriptions'));
    }

    public function test_baza_pilnuje_zakresu_godzin_limitu_i_https(): void
    {
        $basia = $this->user('basia_check');

        foreach ([['cisza_od' => 24, 'cisza_do' => 7, 'dzienny_limit' => 1], ['cisza_od' => 22, 'cisza_do' => 7, 'dzienny_limit' => 0]] as $zle) {
            try {
                DB::transaction(fn () => DB::table('ustawienia_powiadomien_zewnetrznych')->insert(['user_id' => $basia->getKey(), ...$zle]));
                $this->fail('CHECK przepuścił '.json_encode($zle));
            } catch (QueryException $e) {
                $this->assertStringContainsString('check', strtolower($e->getMessage()));
            }
        }

        try {
            DB::transaction(fn () => DB::table('push_subscriptions')->insert([
                'user_id' => $basia->getKey(), 'endpoint' => 'http://fcm.googleapis.com/x',
                'klucz_p256dh' => 'a', 'klucz_auth' => 'b',
            ]));
            $this->fail('CHECK przepuścił adres bez https.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('push_subscriptions_endpoint_https_check', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function daneSubskrypcji(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
