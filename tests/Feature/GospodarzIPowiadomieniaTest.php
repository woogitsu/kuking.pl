<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dwie decyzje właściciela z jednego dnia: gospodarz i powiadomienia
 * dla kont zawieszonych.
 *
 * Łączy je jedno pytanie: kiedy serwis podejmuje decyzję ZA człowieka
 * i kiedy odbiera mu informację, na którą liczył.
 */
class GospodarzIPowiadomieniaTest extends TestCase
{
    use RefreshDatabase;

    private function gospodarz(string $nazwa = 'woogitsu'): User
    {
        $user = User::factory()->create();
        $user->profile()->update(['username' => $nazwa]);
        config([
            'kuking.community.host_user_id' => $user->getKey(),
            'kuking.community.host_username' => $nazwa,
        ]);

        return $user->fresh();
    }

    private function zarejestruj(string $username = 'basia_z_podkarpacia'): void
    {
        $this->post(route('register'), [
            'display_name' => 'Basia',
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));
    }

    // ---------------------------------------------------------------
    // Gospodarz
    // ---------------------------------------------------------------

    public function test_nowe_konto_zaczyna_obserwowac_gospodarza(): void
    {
        $gospodarz = $this->gospodarz();

        $this->zarejestruj();

        $nowa = Profile::where('username', 'basia_z_podkarpacia')->firstOrFail()->user;

        // Feed obserwowanych nowego konta jest z definicji pusty, a pusty
        // ekran dla kogoś po sześćdziesiątce znaczy „to nie jest dla mnie".
        $this->assertTrue($nowa->isFollowing($gospodarz));
    }

    public function test_zmiana_nazwy_i_przejecie_starej_nie_zmieniaja_tozsamosci_gospodarza(): void
    {
        $gospodarz = $this->gospodarz();

        $this->actingAs($gospodarz)->put(route('settings.profile'), [
            'display_name' => $gospodarz->profile->display_name,
            'username' => 'ula_gospodyni',
            'bio' => null,
            'region' => null,
            'speciality' => null,
        ])->assertSessionHasNoErrors();

        $podszywajacy = $this->user('woogitsu');
        auth()->logout();
        $this->zarejestruj('nowa_po_zmianie');
        $nowa = Profile::where('username', 'nowa_po_zmianie')->firstOrFail()->user;

        $this->assertTrue($nowa->isFollowing($gospodarz->fresh()));
        $this->assertFalse($nowa->isFollowing($podszywajacy));
    }

    public function test_bledny_stabilny_id_nie_cofa_sie_do_nazwy_innego_konta(): void
    {
        $podszywajacy = $this->user('woogitsu');
        config([
            'kuking.community.host_user_id' => '10000000-0000-4000-8000-000000000001',
            'kuking.community.host_username' => $podszywajacy->profile->username,
        ]);

        $this->zarejestruj();
        $nowa = Profile::where('username', 'basia_z_podkarpacia')->firstOrFail()->user;

        $this->assertFalse($nowa->isFollowing($podszywajacy));
        $this->assertSame(0, $nowa->following()->count());
    }

    public function test_niepoprawny_format_uuid_nie_przerywa_rejestracji_i_nie_cofa_sie_do_nazwy(): void
    {
        $podszywajacy = $this->user('woogitsu');
        config([
            'kuking.community.host_user_id' => 'to-nie-jest-uuid',
            'kuking.community.host_username' => $podszywajacy->profile->username,
        ]);

        $this->zarejestruj('nowa_przy_blednej_konfiguracji');
        $nowa = Profile::where('username', 'nowa_przy_blednej_konfiguracji')->firstOrFail()->user;

        $this->assertFalse($nowa->isFollowing($podszywajacy));
        $this->assertSame(0, $nowa->following()->count());
    }

    public function test_da_sie_przestac_obserwowac_gospodarza(): void
    {
        $gospodarz = $this->gospodarz();
        $this->zarejestruj();
        $nowa = Profile::where('username', 'basia_z_podkarpacia')->firstOrFail()->user;

        // Automatyczne obserwowanie jest decyzją podjętą ZA człowieka.
        // Bez działającego cofnięcia nie wolno tego wdrażać (COLD_START.md).
        $this->actingAs($nowa)
            ->delete(route('social.unfollow', ['username' => 'woogitsu']))
            ->assertRedirect();

        $this->assertFalse($nowa->fresh()->isFollowing($gospodarz));
    }

    public function test_brak_gospodarza_nie_wywala_rejestracji(): void
    {
        config(['kuking.community.host_username' => 'nie-ma-takiego-konta']);

        // Rejestracja MUSI się udać. Zła wartość w konfiguracji nie może
        // kosztować człowieka konta.
        $this->zarejestruj();

        $this->assertNotNull(Profile::where('username', 'basia_z_podkarpacia')->first());
    }

    public function test_wylaczony_gospodarz_nie_obserwuje_nikogo(): void
    {
        config(['kuking.community.host_username' => '']);

        $this->zarejestruj();
        $nowa = Profile::where('username', 'basia_z_podkarpacia')->firstOrFail()->user;

        $this->assertSame(0, $nowa->following()->count());
    }

    // ---------------------------------------------------------------
    // Powiadomienia a zawieszenie
    // ---------------------------------------------------------------

    public function test_zawieszone_konto_dostaje_powiadomienia(): void
    {
        $halina = $this->user('halina');
        $marek = $this->user('marek');

        $halina->status = User::STATUS_SUSPENDED;
        $halina->save();

        app(NotifyUser::class)->handle(
            recipient: $halina->fresh(),
            type: Notification::TYPE_FOLLOW,
            actor: $marek,
        );

        // TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU.
        //
        // Zawieszenie jest karą czasową i tylko na PISANIE — `LoginController`
        // wpuszcza zawieszonych właśnie po to, żeby mogli czytać. Bramka
        // w `NotifyUser` pytała jednak o `isActive()`, więc powiadomienia
        // nie były opóźniane, tylko w ogóle nie powstawały.
        //
        // Ktoś ugotował z przepisu Haliny w czwartym dniu jej tygodniowego
        // zawieszenia. Halina nie dowiedziała się o tym nigdy — a to jest
        // najcenniejszy sygnał w tym produkcie i jedyny powód, dla którego
        // ludzie tu publikują.
        $this->assertSame(1, Notification::where('user_id', $halina->getKey())->count());
    }

    public function test_zablokowane_i_kasowane_konto_dalej_nie_dostaje_nic(): void
    {
        $marek = $this->user('marek');

        foreach ([User::STATUS_BANNED, User::STATUS_PENDING_DELETE] as $numer => $status) {
            $odbiorca = $this->user('odbiorca'.$numer);
            $odbiorca->status = $status;
            $odbiorca->save();

            app(NotifyUser::class)->handle(
                recipient: $odbiorca->fresh(),
                type: Notification::TYPE_FOLLOW,
                actor: $marek,
            );

            // Druga strona tej samej reguły. Bez tego testu „naprawa"
            // polegająca na skasowaniu całej bramki też by przechodziła —
            // i wysyłalibyśmy powiadomienia na konta, których już nie ma.
            $this->assertSame(
                0,
                Notification::where('user_id', $odbiorca->getKey())->count(),
                "Konto o statusie {$status} dostało powiadomienie.",
            );
        }
    }
}
