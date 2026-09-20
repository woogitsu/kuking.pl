<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #734 — „ZOBACZ" PRZY POWIADOMIENIU O OBSERWOWANIU MUSI PODĄŻAĆ ZA
 * SPRAWCĄ, NIE ZA NAPISEM SPRZED ZMIANY NAZWY.
 *
 * `FollowUser::handle()` zapisuje w `data.username` migawkę nazwy profilu
 * z DNIA obserwowania. Powiadomienie samo w sobie ma jednak stabilny klucz
 * `actor_id`. Gdy sprawca później zmienia nazwę w ustawieniach
 * (`ProfileSettingsController::update()`), stary kod liczył cel WYŁĄCZNIE
 * z migawki — „Zobacz" prowadziło pod nazwę, która albo nie istnieje (404),
 * albo — gorzej — należy już do KOGOŚ INNEGO, kto ją zdążył zająć. To jest
 * cicha pomyłka tożsamości, ta sama klasa błędu co „UUID w adresie to nie
 * autoryzacja" (AGENTS.md §7): odbiorca ufa, że przycisk prowadzi do osoby
 * opisanej w treści powiadomienia.
 */
class PowiadomienieOObserwowaniuPodazaZaAktoremTest extends TestCase
{
    use RefreshDatabase;

    public function test_zobacz_prowadzi_do_sprawcy_pod_nowa_nazwa_po_zmianie_profilu(): void
    {
        $sprawca = $this->user('basia_stara');
        $odbiorca = $this->user('marek');

        app(FollowUser::class)->handle($sprawca, $odbiorca);

        $powiadomienie = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FOLLOW)
            ->firstOrFail();

        $this->assertSame(
            'basia_stara',
            $powiadomienie->data['username'] ?? null,
            'Kontrola: powiadomienie nie zapisało oczekiwanej migawki nazwy — reszta testu nic by nie mierzyła.',
        );

        // Sprawca zmienia nazwę profilu PRZEZ RZECZYWISTĄ AKCJĘ USTAWIEŃ,
        // nie przez bezpośredni zapis w bazie — to ta sama droga, którą
        // przechodzi każda prawdziwa zmiana nazwy.
        $this->actingAs($sprawca)->put('/ustawienia/profil', [
            'username' => 'basia_nowa',
            'display_name' => $sprawca->profile->display_name,
            'bio' => '',
        ])->assertRedirect();

        $this->assertSame('basia_nowa', $sprawca->profile->refresh()->username);

        $odpowiedz = $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie));

        $odpowiedz->assertRedirect(route('profile.show', 'basia_nowa'));

        // Sprawdzamy końcową odpowiedź i tożsamość profilu, nie tylko
        // kształt adresu — kryterium odbioru issue #734.
        $this->followRedirects($odpowiedz)
            ->assertOk()
            ->assertSee($sprawca->profile->display_name);
    }

    public function test_stara_nazwa_zajeta_przez_kogos_innego_nie_przejmuje_powiadomienia(): void
    {
        $sprawca = $this->user('basia_stara');
        $odbiorca = $this->user('marek');

        app(FollowUser::class)->handle($sprawca, $odbiorca);

        $powiadomienie = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FOLLOW)
            ->firstOrFail();

        $this->actingAs($sprawca)->put('/ustawienia/profil', [
            'username' => 'basia_nowa',
            'display_name' => $sprawca->profile->display_name,
            'bio' => '',
        ])->assertRedirect();

        // Ktoś trzeci zajmuje zwolnioną, starą nazwę.
        $niewinna = $this->user('basia_stara');

        $odpowiedz = $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie));

        $odpowiedz->assertRedirect(route('profile.show', 'basia_nowa'));
        $this->assertNotSame(route('profile.show', 'basia_stara'), $odpowiedz->headers->get('Location'));

        // Kontrola dodatnia: niewinna trzecia osoba istnieje pod starą
        // nazwą, ale powiadomienie mimo to nie prowadzi do niej.
        $this->assertTrue(User::query()->whereKey($niewinna->getKey())->exists());
    }

    public function test_brak_aktora_daje_bezpieczny_brak_celu(): void
    {
        $odbiorca = $this->user('marek');

        $powiadomienie = Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_FOLLOW,
            'data' => ['username' => 'ktos'],
        ]);

        $this->assertNull(
            $powiadomienie->fresh()->adresDocelowy(),
            'Powiadomienie bez sprawcy nie ma dokąd prowadzić — nie wolno mu wracać do napisu z danych.',
        );
    }

    public function test_niezmieniona_nazwa_nadal_dziala(): void
    {
        $sprawca = $this->user('kasia');
        $odbiorca = $this->user('marek');

        app(FollowUser::class)->handle($sprawca, $odbiorca);

        $powiadomienie = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FOLLOW)
            ->firstOrFail();

        $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('profile.show', 'kasia'));
    }
}
