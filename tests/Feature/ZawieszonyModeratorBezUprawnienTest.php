<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Security\WyslijLinkDoLogowania;
use App\Domain\Users\Actions\ChangeUserRole;
use App\Models\ContactMessage;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as Powiadomienia;
use Tests\TestCase;

/**
 * UPRAWNIENIA MODERACJI TYLKO DLA CZYNNEGO KONTA (issue #1336, #1351).
 *
 * `suspend()` nie zmienia roli, a `EnsureAccountIsActive` przepuszcza
 * zawieszonym odczyt. Dopóki `User::isModerator()` i `isAdmin()` pytały
 * wyłącznie o rolę, zawieszony moderator czytał kolejkę zgłoszeń, karty
 * użytkowników, wiadomości „Napisz do nas", cudze szkice i prywatne
 * zdjęcia, a zawieszony administrator — odwołania. Zawiadomienie
 * `appeal.filed` zostawało też na liście osoby, której odebrano rolę.
 *
 * Każdy test ma kontrolę dodatnią: ta sama osoba z czynnym kontem (albo po
 * przywróceniu) widzi to samo. Bez niej `isModerator()` przerobione na
 * `return false` przeszłoby wszystkie odmowy.
 */
class ZawieszonyModeratorBezUprawnienTest extends TestCase
{
    use RefreshDatabase;

    public function test_zawieszony_moderator_nie_czyta_panelu_a_po_przywroceniu_czyta(): void
    {
        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create();
        $ekrany = [
            route('admin.reports'),
            route('admin.users'),
            route('admin.users.show', $this->user('halinka')),
            route('admin.contact'),
            route('admin.contact.show', $wiadomosc),
        ];

        foreach ($ekrany as $adres) {
            $this->actingAs($moderator)->get($adres)->assertOk();
        }

        $moderator->suspend(now()->addDays(3));
        $moderator->refresh();

        foreach ($ekrany as $adres) {
            $this->actingAs($moderator)->get($adres)->assertNotFound();
        }

        $moderator->reinstate();
        $moderator->refresh();

        foreach ($ekrany as $adres) {
            $this->actingAs($moderator)->get($adres)->assertOk();
        }
    }

    public function test_zawieszony_administrator_nie_czyta_odwolan(): void
    {
        $admin = $this->admin();
        // Drugi czynny administrator: ostatniego nie da się zawiesić (#1016).
        $this->admin();

        $this->actingAs($admin)->get(route('admin.appeals'))->assertOk();

        $admin->suspend(now()->addDays(3));

        $this->actingAs($admin->refresh())->get(route('admin.appeals'))->assertNotFound();
        $this->assertFalse(Gate::forUser($admin)->allows('resolveAppeals', User::class));
    }

    /**
     * Policy POZA trasą `/admin` — drugi endpoint nie może ominąć zakazu.
     */
    public function test_zawieszony_moderator_nie_widzi_cudzego_szkicu_ani_prywatnego_zdjecia(): void
    {
        $autorka = $this->user('autorka');
        $szkic = Recipe::factory()->draft()->create(['author_id' => $autorka->getKey()]);
        $zdjecie = Media::factory()->create([
            'owner_id' => $autorka->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $dostep = app(DostepDoZdjecia::class);

        $moderator = $this->moderator();

        $this->assertTrue(Gate::forUser($moderator)->allows('view', $szkic), 'Czynny moderator nie widzi szkicu.');
        $this->assertTrue($dostep->moze($moderator, $zdjecie), 'Czynny moderator nie widzi zdjęcia.');

        $moderator->suspend(now()->addDays(3));
        $moderator->refresh();

        $this->assertFalse(
            Gate::forUser($moderator)->allows('view', $szkic),
            'Zawieszony moderator dalej otwiera cudzy szkic przez RecipePolicy.',
        );
        $this->assertFalse(
            $dostep->moze($moderator, $zdjecie),
            'Zawieszony moderator dalej otwiera cudze nieprzypięte zdjęcie.',
        );
    }

    /**
     * Zdjęcie uprawnień NIE może zdejmować zabezpieczeń wejścia: konto obsługi
     * wchodzi tylko hasłem i 2FA także wtedy, gdy jest zawieszone — inaczej po
     * końcu kary sesja z linku miałaby pełne uprawnienia bez drugiego składnika.
     */
    public function test_zawieszony_moderator_dalej_nie_dostaje_linku_do_logowania(): void
    {
        config(['mail.default' => 'smtp']);
        Powiadomienia::fake();

        $moderator = $this->user('moderatorka', [
            'email' => 'moderatorka@example.com',
            'role' => User::ROLE_MODERATOR,
        ]);
        $moderator->suspend(now()->addDays(3));

        $this->assertFalse(app(WyslijLinkDoLogowania::class)->handle('moderatorka@example.com'));
        Powiadomienia::assertNothingSent();
        $this->assertDatabaseCount('login_link_tokens', 0);

        // KONTROLA DODATNIA: zawieszony zwykły użytkownik link dostaje —
        // zawieszenie to kara za pisanie, nie zakaz wejścia.
        $zwykla = $this->user('basia', ['email' => 'basia@example.com']);
        $zwykla->suspend(now()->addDays(3));

        $this->assertTrue(app(WyslijLinkDoLogowania::class)->handle('basia@example.com'));
    }

    public function test_zawiadomienie_o_odwolaniu_znika_po_odebraniu_roli_i_wraca_po_nadaniu(): void
    {
        $a = $this->admin();
        $this->admin();

        $zawiadomienie = app(NotifyUser::class)->handle(
            recipient: $a,
            type: Notification::TYPE_APPEAL_FILED,
            data: ['appeal_id' => '00000000-0000-0000-0000-000000000000', 'skladajacy' => 'Zenon Odwołujący', 'termin' => '1 października 2026'],
        );
        $this->assertNotNull($zawiadomienie);

        $this->actingAs($a)->get(route('notifications.index'))->assertOk()->assertSee('Zenon Odwołujący');
        $this->assertSame(1, $a->unreadNotificationsCount());

        app(ChangeUserRole::class)->handle($a, User::ROLE_USER);
        $a->refresh();

        $this->actingAs($a)->get(route('notifications.index'))->assertOk()->assertDontSee('Zenon Odwołujący');
        $this->assertSame(0, $a->unreadNotificationsCount());
        $this->actingAs($a)->post(route('notifications.open', $zawiadomienie))->assertNotFound();
        $this->assertNull($zawiadomienie->refresh()->read_at);

        // Wiersz zostaje — ponowne nadanie roli pokazuje go z powrotem.
        app(ChangeUserRole::class)->handle($a, User::ROLE_ADMIN);
        $a->refresh();

        $this->actingAs($a)->get(route('notifications.index'))->assertOk()->assertSee('Zenon Odwołujący');
        $this->assertSame(1, $a->unreadNotificationsCount());

        // Zawieszenie działa tak samo jak odebranie roli.
        $a->suspend(now()->addDays(3));
        $this->assertSame(0, $a->refresh()->unreadNotificationsCount());
    }
}
