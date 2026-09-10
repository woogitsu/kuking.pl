<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
