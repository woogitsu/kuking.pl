<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Eksport danych i usunięcie konta — RODO art. 15, 17 i 20.
 * W Kuking to część MVP, nie funkcja „na później”.
 */
class DataRightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uzytkownik_moze_poprosic_o_eksport_swoich_danych(): void
    {
        // Kolejka udawana: sprawdzamy TU samo przyjęcie żądania. Budowanie
        // paczki ma własny plik testów (DataExportTest), a bez tego job
        // wykonałby się w tym samym żądaniu i status byłby od razu `ready`.
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->assertDatabaseHas('data_exports', [
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        Queue::assertPushed(GenerateUserExport::class);
    }

    public function test_druga_prosba_nie_tworzy_kolejnego_zadania(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'));
        $this->actingAs($basia)->post(route('settings.data.export'));

        $this->assertSame(1, DataExport::count());
        Queue::assertPushed(GenerateUserExport::class, 1);
    }

    public function test_usuniecie_konta_wymaga_hasla_i_potwierdzenia(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('settings.data.delete'), ['password' => 'zle-haslo', 'confirm' => '1'])
            ->assertSessionHasErrors('password');

        $status = $basia->fresh()->status;
        $this->assertSame(User::STATUS_ACTIVE, $status);

        $this->actingAs($basia)
            ->post(route('settings.data.delete'), ['password' => 'haslo-testowe-123'])
            ->assertSessionHasErrors('confirm');

        $status = $basia->fresh()->status;
        $this->assertSame(User::STATUS_ACTIVE, $status);
    }

    public function test_konto_przechodzi_w_stan_oczekiwania_a_nie_znika_od_razu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.delete'), [
            'password' => 'haslo-testowe-123',
            'confirm' => '1',
        ])->assertRedirect(route('landing'));

        $basia = $basia->fresh();

        // Nieodwracalne usunięcie po jednym kliknięciu byłoby okrutne
        // wobec osoby, która pomyliła przycisk.
        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->status);
        $this->assertNotNull($basia->delete_requested_at);
    }

    public function test_konto_oznaczone_do_usuniecia_nie_moze_sie_zalogowac(): void
    {
        $basia = $this->user('basia', ['status' => User::STATUS_PENDING_DELETE]);

        $this->post('/login', ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Audyt A8 — ślepy zaułek kasowania konta.
    //
    // Zgłoszenie usunięcia wylogowywało od razu (test wyżej), ale komunikat
    // obiecywał "wystarczy, że się zalogujesz" — czyli drogę, która jest
    // zamknięta w tym samym pliku, dwa testy wyżej. Te testy pilnują, żeby
    // komunikat, który człowiek FAKTYCZNIE widzi, wskazywał drogę, która
    // NAPRAWDĘ działa.
    // -----------------------------------------------------------------

    public function test_zgloszenie_usuniecia_wylogowuje_w_tym_samym_zadaniu(): void
    {
        $basia = $this->user('basia');

        // Bez wylogowania TUTAJ, `EnsureAccountIsActive` zrobiłoby to samo
        // przy kolejnym żądaniu (przekierowaniu na `landing`) i PODMIENIŁO
        // komunikat ustawiony niżej na swój własny — flash z tego żądania
        // nigdy nie docierałby do człowieka.
        $this->actingAs($basia)->post(route('settings.data.delete'), [
            'password' => 'haslo-testowe-123',
            'confirm' => '1',
        ]);

        $this->assertGuest();
    }

    public function test_komunikat_po_zgloszeniu_wskazuje_dzialajaca_strone_cofniecia(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.delete'), [
            'password' => 'haslo-testowe-123',
            'confirm' => '1',
        ])->assertSessionHas('status');

        // Adres MUSI być tą stroną, na której cofnięcie faktycznie działa —
        // nie samym "zaloguj się", bo logowanie dla tego konta jest zamknięte
        // (test `test_konto_oznaczone_do_usuniecia_nie_moze_sie_zalogowac`).
        // Od #2245 adres jedzie osobno (`status_akcja`), nie w treści zdania.
        $this->assertSame(
            ['url' => route('account.delete.cancel'), 'etykieta' => 'Cofnij usunięcie konta'],
            session('status_akcja'),
        );
    }

    /**
     * #2245: droga powrotu jest KLIKALNA na stronie, na którą trafia człowiek.
     *
     * Adres strony cofnięcia był doklejony do treści `status`, a layout
     * wypisuje ją jako zwykły tekst — na telefonie trzeba było przepisać
     * adres ręcznie. Test idzie przez prawdziwe przekierowanie na stronę
     * główną i czyta końcowy HTML: pod komunikatem stoi odnośnik do
     * `account.delete.cancel`, a w samym zdaniu adresu już nie ma.
     */
    public function test_komunikat_po_zgloszeniu_ma_klikalny_odnosnik_do_cofniecia(): void
    {
        $basia = $this->user('basia');

        $html = (string) $this->actingAs($basia)->followingRedirects()->post(route('settings.data.delete'), [
            'password' => 'haslo-testowe-123',
            'confirm' => '1',
        ])->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);

        $zdania = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " flash ")]');
        $this->assertSame(1, $zdania->length, 'Na stronie po usunięciu konta nie ma komunikatu.');
        $this->assertStringContainsString('Konto zostało oznaczone do usunięcia', $zdania->item(0)->textContent);
        $this->assertStringNotContainsString(route('account.delete.cancel'), $zdania->item(0)->textContent,
            'Adres strony cofnięcia wrócił do treści komunikatu jako zwykły tekst.');

        $odnosniki = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " flash-akcja ")]//a[@href="'.route('account.delete.cancel').'"]');
        $this->assertSame(1, $odnosniki->length, 'Pod komunikatem nie ma klikalnego odnośnika do strony cofnięcia usunięcia konta.');
        $this->assertSame('Cofnij usunięcie konta', trim($odnosniki->item(0)->textContent));
    }

    public function test_odmowa_logowania_na_konto_do_usuniecia_wskazuje_strone_cofniecia(): void
    {
        $basia = $this->user('basia', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()]);

        $this->post('/login', ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertSessionHasErrors('login');

        $blad = session('errors')->getBag('default')->first('login');

        // Od D-333 (30.09.2026) adres nie stoi w zdaniu: wskazuje go przycisk
        // pod komunikatami (`status_akcja`), a zdanie mówi, gdzie go szukać.
        // Końcowy HTML sprawdza `OdmowaLogowaniaPrzyciskCofnieciaTest`.
        $this->assertStringContainsString('„Cofnij usunięcie konta”', $blad);
        $this->assertStringNotContainsString(route('account.delete.cancel'), $blad);
        $this->assertSame(
            ['url' => route('account.delete.cancel'), 'etykieta' => 'Cofnij usunięcie konta'],
            session('status_akcja'),
        );
    }
}
