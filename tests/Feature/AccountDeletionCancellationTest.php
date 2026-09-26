<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie zgłoszonego usunięcia konta — dla osoby, która nie może się
 * zalogować (audyt A8, RODO art. 17).
 *
 * Metoda `cancelDeletion()` istniała już w `User`, ale nie prowadziła do niej
 * żadna trasa ani przycisk — była martwym kodem. Te testy wchodzą FORMULARZEM,
 * jak człowiek, nie wołają metody bezpośrednio.
 */
class AccountDeletionCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_poprawne_haslo_cofa_usuniecie_konta(): void
    {
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('login'));

        $basia = $basia->fresh();

        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->delete_requested_at);
    }

    public function test_po_cofnieciu_da_sie_znow_zalogowac(): void
    {
        $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ]);

        $this->post('/login', ['login' => 'basia', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }

    public function test_cofniecie_dziala_takze_po_adresie_e_mail(): void
    {
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => $basia->email,
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('login'));

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
    }

    public function test_zle_haslo_nie_cofa_usuniecia(): void
    {
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'zle-haslo',
        ])->assertSessionHasErrors('login');

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
    }

    /**
     * Komunikat pisał „adres/nazwa" — jedyne miejsce w serwisie, które przy
     * tej samej parze pól (e-mail albo nazwa użytkownika) używało ukośnika
     * zamiast „albo": `LoginController` i `AppealController` już wtedy
     * mówiły „nazwa" albo „adres e-mail albo nazwę użytkownika" (issue #38).
     * Rozjazd bez powodu — ten sam formularz na dwóch ekranach powinien
     * brzmieć tak samo.
     */
    public function test_komunikat_nie_uzywa_ukosnika_do_nazwania_pola_login(): void
    {
        $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'zle-haslo',
        ])->assertSessionHasErrors('login');

        $komunikat = (string) session('errors')->getBag('default')->first('login');

        $this->assertStringNotContainsString('/', $komunikat);
        $this->assertStringContainsString('e-mail albo nazwa', $komunikat);
    }

    public function test_nieznany_login_dostaje_taki_sam_komunikat_jak_zle_haslo(): void
    {
        // Ten sam powód co w LoginController i formularzu odwołań #10: różne
        // komunikaty dla "nie ma takiego konta" i "złe hasło" pozwalałyby
        // sprawdzać, czy dany e-mail/nazwa ma tu konto (enumeracja kont).
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $odpowiedzZlyLogin = $this->post(route('account.delete.cancel.store'), [
            'login' => 'nikt-taki@example.com',
            'password' => 'cokolwiek',
        ]);
        $odpowiedzZlyLogin->assertSessionHasErrors('login');
        $komunikatZlyLogin = session('errors')->getBag('default')->first('login');

        $odpowiedzZleHaslo = $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'zle-haslo',
        ]);
        $odpowiedzZleHaslo->assertSessionHasErrors('login');
        $komunikatZleHaslo = session('errors')->getBag('default')->first('login');

        $this->assertSame($komunikatZlyLogin, $komunikatZleHaslo);
    }

    public function test_aktywne_konto_nie_ma_czego_cofac(): void
    {
        $basia = $this->user('basia');

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertSessionHasErrors('login');

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
    }

    public function test_konta_z_juz_wymazanymi_danymi_nie_da_sie_cofnac(): void
    {
        // Symulujemy stan PO egzekucji karencji: hasło już nie jest tym,
        // które ta osoba kiedyś znała — nie ma więc jak trafić tu z prawdziwym
        // hasłem. Test i tak sprawdza regułę wprost, ustawiając stan bazy
        // ręcznie, żeby nie zależeć od implementacji egzekutora.
        //
        // STATUS `erased`, NIE `pending_delete` (D-022). Stan po wykonanej
        // karencji ma od tej decyzji własną wartość, a CHECK w bazie wymaga
        // równoważności z `data_erased_at` — wiersz w starym kształcie
        // (`pending_delete` z wypełnionym `data_erased_at`) po prostu nie
        // przechodzi już zapisu, i o to chodziło.
        $basia = $this->user('basia', [
            'status' => User::STATUS_ERASED,
            'delete_requested_at' => now()->subDays(40),
            'data_erased_at' => now()->subDays(10),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertSessionHasErrors('login');

        $basia = $basia->fresh();

        $this->assertSame(User::STATUS_ERASED, $basia->status);
        $this->assertNotNull($basia->data_erased_at);
    }

    // ------------------------------------------------------------------
    // #1893 — awaria dziennika audytu po zatwierdzonym cofnięciu
    // ------------------------------------------------------------------

    /**
     * `CancelAccountDeletion::handle()` zapisywał `account.delete_cancelled`
     * PO zatwierdzonej transakcji (kontroler, poza `handle()`). Awaria tego
     * `INSERT`-a dawała HTTP 500 mimo już cofniętego usunięcia — a ponowienie
     * odbijało się o „to konto nie jest oznaczone do usunięcia — nie ma
     * czego cofać", bo konto było już `active`.
     *
     * D-249 (klasa 1): ten wpis jest — razem z `account.delete_requested` —
     * jedynym śladem w całej bazie, że ktoś zgłosił usunięcie i się rozmyślił
     * (`cancelDeletion()` zeruje `delete_requested_at`). Dlatego stoi w TEJ
     * SAMEJ transakcji: awaria cofa cofnięcie, konto zostaje
     * `pending_delete`, a formularz da się wysłać jeszcze raz.
     *
     * Kontrola ujemna (wykonana ręcznie): przeniesienie `AuditLogEntry::
     * record()` z powrotem do kontrolera, po `handle()`, daje na tym teście
     * HTTP 500 zamiast przekierowania z błędem — dokładnie to, co ten test
     * ma złapać.
     */
    public function test_awaria_dziennika_cofa_takze_cofniecie_a_ponowienie_daje_jeden_komplet(): void
    {
        Exceptions::fake();
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);
        $awaria = true;

        // Awaria PO wykonaniu INSERT-u (`DB::listen` woła się po zapytaniu).
        DB::listen(function ($zapytanie) use (&$awaria): void {
            if ($awaria
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array('account.delete_cancelled', $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika: account.delete_cancelled');
            }
        });

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertSessionHasErrors('login');

        $stan = $basia->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status, 'Cofnięcie miało się cofnąć razem z awarią dziennika.');
        $this->assertNotNull($stan->delete_requested_at);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'account.delete_cancelled')->count());
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Wstrzyknięta awaria dziennika'));

        // Awaria minęła — człowiek klika jeszcze raz.
        $awaria = false;

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.delete_cancelled')->count());
    }

    public function test_kontrola_dodatnia_cofniecie_bez_awarii_zapisuje_jeden_wpis(): void
    {
        Exceptions::fake();
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.delete_cancelled')->count());
        Exceptions::assertNotReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'dziennika audytu'));
    }
}
