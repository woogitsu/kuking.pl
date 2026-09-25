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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as Powiadomienia;
use Illuminate\Support\Facades\Storage;
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
    public function test_zawieszony_moderator_nie_widzi_ukrytego_przepisu_ani_jego_zdjecia(): void
    {
        // Przepis UKRYTY, a nie szkic: szkicu nie widzi nawet czynny
        // moderator (#1359), więc na szkicu ten test nie miałby kontroli
        // dodatniej. Zdjęcie jest przypięte do tego przepisu — bez rodzica
        // moderator go nie widzi w ogóle (#1360).
        $autorka = $this->user('autorka');
        $zdjecie = Media::factory()->create([
            'owner_id' => $autorka->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $ukryty = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'status' => Recipe::STATUS_HIDDEN,
            'hero_media_id' => $zdjecie->getKey(),
        ]);
        $dostep = app(DostepDoZdjecia::class);

        $moderator = $this->moderator();

        $this->assertTrue(Gate::forUser($moderator)->allows('view', $ukryty), 'Czynny moderator nie widzi ukrytego przepisu.');
        $this->assertTrue($dostep->moze($moderator, $zdjecie), 'Czynny moderator nie widzi zdjęcia ukrytego przepisu.');

        $moderator->suspend(now()->addDays(3));
        $moderator->refresh();

        $this->assertFalse(
            Gate::forUser($moderator)->allows('view', $ukryty),
            'Zawieszony moderator dalej otwiera ukryty przepis przez RecipePolicy.',
        );
        $this->assertFalse(
            $dostep->moze($moderator, $zdjecie),
            'Zawieszony moderator dalej otwiera zdjęcie ukrytego przepisu.',
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

    /**
     * `post.first` — ta sama zasada co `appeal.filed` (issue #1351): alert
     * prowadzi do kolejki „Bez odpowiedzi", więc widzi go tylko osoba, która
     * TERAZ ma `moderate`. Wiersz i `first_post_events` zostają.
     */
    public function test_alert_o_pierwszym_wpisie_znika_po_odebraniu_roli_i_wraca_po_nadaniu(): void
    {
        $m = $this->moderator();
        $this->moderator();

        $zawiadomienie = app(NotifyUser::class)->handle(
            recipient: $m,
            type: Notification::TYPE_FIRST_POST,
            actor: $this->user('nowahalina', ['display_name' => 'Halina Debiutantka']),
            data: ['post_id' => '00000000-0000-0000-0000-000000000000', 'display_name' => 'Halina Debiutantka'],
        );
        $this->assertNotNull($zawiadomienie);

        $this->actingAs($m)->get(route('notifications.index'))->assertOk()->assertSee('Halina Debiutantka');
        $this->assertSame(1, $m->unreadNotificationsCount());

        app(ChangeUserRole::class)->handle($m, User::ROLE_USER);
        $m->refresh();

        $this->actingAs($m)->get(route('notifications.index'))->assertOk()->assertDontSee('Halina Debiutantka');
        $this->assertSame(0, $m->unreadNotificationsCount());
        $this->actingAs($m)->post(route('notifications.open', $zawiadomienie))->assertNotFound();
        $this->assertNull($zawiadomienie->refresh()->read_at);

        app(ChangeUserRole::class)->handle($m, User::ROLE_MODERATOR);
        $m->refresh();

        $this->actingAs($m)->get(route('notifications.index'))->assertOk()->assertSee('Halina Debiutantka');
        $this->assertSame(1, $m->unreadNotificationsCount());

        $m->suspend(now()->addDays(3));
        $this->assertSame(0, $m->refresh()->unreadNotificationsCount());
    }

    /**
     * `host_username` wskazujący zwykłe konto: `PublishPost` zapisuje alert
     * i zdarzenie (bez zmian), ale na liście i w liczniku go nie ma, bo
     * „Zobacz" prowadziłoby do odmowy dostępu.
     */
    public function test_gospodarz_bez_prawa_moderacji_nie_widzi_alertu_o_pierwszym_wpisie(): void
    {
        Storage::fake('public');
        $gospodarz = $this->user('gospodarz');
        config(['kuking.community.host_username' => 'gospodarz']);

        $this->actingAs($this->user('nowa', ['display_name' => 'Halina Debiutantka']))->post(route('posts.store'), [
            'body' => 'Mój pierwszy rosół',
            'visibility' => 'public',
        ])->assertRedirect();

        $this->assertSame(1, Notification::query()->where('user_id', $gospodarz->getKey())
            ->where('type', Notification::TYPE_FIRST_POST)->count());
        $this->assertSame(1, DB::table('first_post_events')->count());

        $this->assertSame(0, $gospodarz->unreadNotificationsCount());
        $this->actingAs($gospodarz)->get(route('notifications.index'))->assertOk()->assertDontSee('Halina Debiutantka');
    }
}
