<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stan konta i kary czasowe (issues #39 i #40).
 *
 * Te dwie rzeczy testujemy razem, bo osobno nie mają sensu: termin wygaśnięcia
 * bez sprawdzania statusu przy każdym żądaniu niczego nie zmienia (kara i tak
 * działa dopiero po wylogowaniu), a sprawdzanie statusu bez terminu zamienia
 * każdą blokadę czasową w dożywotnią.
 */
class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // #39 — status sprawdzany przy KAŻDYM żądaniu
    // -----------------------------------------------------------------

    public function test_zbanowanie_odcina_przy_nastepnym_zadaniu_a_nie_po_wylogowaniu(): void
    {
        $basia = $this->user('basia');

        // Sesja jest już aktywna — dokładnie ta sytuacja, w której ban
        // wcześniej nie robił nic przez siedem dni.
        $this->actingAs($basia)->get(route('home'))->assertOk();

        $basia->ban();

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_konto_oznaczone_do_usuniecia_tez_zostaje_odciete(): void
    {
        $basia = $this->user('basia');
        $basia->markForDeletion();

        $this->actingAs($basia)->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_zawieszone_konto_moze_czytac(): void
    {
        $basia = $this->user('basia');
        $basia->suspend();

        // Odczyt zostaje: inaczej kara czasowa jest nie do odróżnienia od
        // zniknięcia serwisu, a osoba nie ma jak sprawdzić, za co ją spotkała.
        $this->actingAs($basia)->get(route('home'))->assertOk();
    }

    public function test_zawieszone_konto_nie_opublikuje_wpisu(): void
    {
        $basia = $this->user('basia');
        $basia->suspend();

        $this->actingAs($basia)
            ->from(route('home'))
            ->post(route('posts.store'), ['body' => 'Dziś rosół.'])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('konto');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_zawieszone_konto_moze_sie_wylogowac(): void
    {
        $basia = $this->user('basia');
        $basia->suspend();

        // Bez tego osoba zawieszona jest uwięziona w serwisie: nie opublikuje
        // niczego i nie ma jak wyjść.
        $this->actingAs($basia)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_zawieszone_konto_nadal_moze_poprosic_o_swoje_dane(): void
    {
        $basia = $this->user('basia');
        $basia->suspend();

        // RODO art. 15 i 20 nie znikają przez decyzję moderacyjną.
        $this->actingAs($basia)->get(route('settings.data'))->assertOk();
    }

    public function test_ban_kasuje_istniejace_sesje_uzytkownika(): void
    {
        config(['session.driver' => 'database']);

        $basia = $this->user('basia');

        DB::table('sessions')->insert([
            'id' => 'sesja-basi',
            'user_id' => $basia->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $basia->ban();

        // Sesja z INNEJ przeglądarki. Auth::logout() jej nie dotyka — moderator
        // nie siedzi w sesji karanego użytkownika.
        $this->assertDatabaseMissing('sessions', ['user_id' => $basia->getKey()]);
    }

    public function test_komunikat_po_zbanowaniu_nie_uzywa_gry_slowem_kuking(): void
    {
        $basia = $this->user('basia');
        $basia->ban();

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $blad = session('errors')->getBag('default')->first('login');

        $this->assertNotEmpty($blad, 'Odcięte konto musi dostać wyjaśnienie, nie samo przekierowanie.');

        // D-009: gra słowem NIGDY w wiadomości moderacyjnej.
        //
        // Sprawdzamy grę słowem, nie samo wystąpienie liter: adres kontaktowy
        // to `kontakt@kuking.pl` i nazwa domeny nie jest żartem o użytkowniku.
        // Dlatego najpierw wycinamy adresy e-mail, a dopiero potem pytamy
        // o „kuking" w zdaniu.
        $bezAdresu = preg_replace('/\S+@\S+/', '', $blad);

        $this->assertStringNotContainsStringIgnoringCase(
            'kuking',
            $bezAdresu,
            'Wiadomość moderacyjna nie może grać słowem kuKING (D-009).',
        );
    }

    // -----------------------------------------------------------------
    // #40 — kara ma termin i sama wygasa
    // -----------------------------------------------------------------

    public function test_konto_wraca_do_active_po_uplywie_terminu(): void
    {
        $basia = $this->user('basia');
        $basia->suspend(now()->subDay());

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $basia->refresh();

        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }

    public function test_konto_nie_wraca_przed_uplywem_terminu(): void
    {
        $basia = $this->user('basia');
        $basia->suspend(now()->addDay());

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->refresh()->status);
    }

    public function test_zawieszenie_bezterminowe_nigdy_nie_wygasa_samo(): void
    {
        $basia = $this->user('basia');
        $basia->suspend();

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->refresh()->status);
    }

    public function test_kara_z_minionym_terminem_konczy_sie_juz_przy_wejsciu_na_strone(): void
    {
        $basia = $this->user('basia');
        $basia->suspend(now()->subMinute());

        // Bez czekania na zadanie w harmonogramie: kara „do 12 września" ma się
        // skończyć 12 września, a nie wtedy, gdy akurat przejdzie cron.
        $this->actingAs($basia)->get(route('home'))->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $basia->refresh()->status);
    }

    public function test_baza_nie_przyjmie_terminu_bez_zawieszenia(): void
    {
        $basia = $this->user('basia');

        // Walidacja w PHP jest do obejścia nowym endpointem. CHECK w bazie nie
        // jest (AGENTS.md §6).
        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $basia->getKey())
            ->update(['status_expires_at' => now()->addDays(7)]);
    }

    public function test_ban_kasuje_termin_po_wczesniejszym_zawieszeniu(): void
    {
        $basia = $this->user('basia');
        $basia->suspend(now()->addDays(7));

        // Eskalacja kary czasowej do bana. Gdyby termin został, zadanie
        // w harmonogramie przywróciłoby dostęp osobie właśnie zbanowanej —
        // a baza i tak odrzuciłaby taki wiersz (CHECK).
        $basia->ban();
        $basia->refresh();

        $this->assertSame(User::STATUS_BANNED, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }

    public function test_zawieszony_widzi_date_konca_kary_po_polsku(): void
    {
        // ZEGAR ZATRZYMANY, BO INACZEJ TEN TEST JEST BOMBĄ Z OPÓŹNIONYM
        // ZAPŁONEM. Data końca kary stała tu na sztywno („2026-09-12 10:00"),
        // a asercja niżej sprawdza jej polski zapis — więc daty nie da się
        // po prostu policzyć od `now()`, bo wtedy oczekiwany tekst liczyłby
        // się z tego samego źródła i test nie sprawdzałby już niczego.
        //
        // Termin w przeszłości znaczy karę WYGASŁĄ: strona główna nie ma
        // wtedy o czym informować i test padał — po raz pierwszy 12 września
        // 2026 po godzinie 10:00, czyli w dniu, do którego był napisany.
        // Zatrzymanie zegara zostawia literalną asercję i odbiera jej termin
        // ważności.
        $this->travelTo('2026-09-01 08:00:00');

        $basia = $this->user('basia');
        $basia->suspend(now()->parse('2026-09-12 10:00:00'));

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            // Data czytelna dla osoby, która nie czyta ISO 8601 (UX_50_PLUS).
            ->assertSee('12 września 2026')
            ->assertDontSee('2026-09-12T');
    }

    public function test_moderator_wybiera_dlugosc_zawieszenia(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $report = Report::create([
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'target_type' => 'user',
            'target_id' => $basia->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)->post(route('admin.reports.decide', $report), [
            'action' => 'suspend',
            'reason_code' => 'nekanie',
            'suspend_days' => '7',
        ])->assertRedirect();

        $basia->refresh();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->status);
        $this->assertNotNull($basia->status_expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $basia->status_expires_at->timestamp,
            5,
        );
    }

    public function test_moderator_moze_zawiesic_bezterminowo(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $report = Report::create([
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'target_type' => 'user',
            'target_id' => $basia->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)->post(route('admin.reports.decide', $report), [
            'action' => 'suspend',
            'reason_code' => 'nekanie',
            'suspend_days' => 'bezterminowo',
        ])->assertRedirect();

        $basia->refresh();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }
}
