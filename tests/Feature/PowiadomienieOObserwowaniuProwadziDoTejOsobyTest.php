<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #734 — „X zaczyna Cię obserwować" prowadzi do X, także po zmianie nazwy.
 *
 * `FollowUser` zapisuje w `data.username` nazwę z chwili obserwowania,
 * a `adresDocelowy()` budował z niej link. Opis na liście bierze się
 * z AKTUALNEGO profilu sprawcy (`actor_id`), więc po zmianie nazwy opis
 * mówił o jednej osobie, a „Zobacz" prowadził na 404 — albo, gdy ktoś
 * zajął zwolnioną nazwę, do kogoś zupełnie innego.
 */
class PowiadomienieOObserwowaniuProwadziDoTejOsobyTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_zmianie_nazwy_zobacz_prowadzi_na_aktualny_profil(): void
    {
        [$basia, $odbiorca, $powiadomienie] = $this->basiaObserwuje();

        $this->zmienNazwe($basia, 'basia_nowa');

        $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('profile.show', 'basia_nowa'));
    }

    public function test_zwolniona_nazwa_zajeta_przez_kogos_innego_nie_prowadzi_do_niego(): void
    {
        [$basia, $odbiorca, $powiadomienie] = $this->basiaObserwuje();

        $this->zmienNazwe($basia, 'basia_nowa');
        $this->user('basia_stara', ['display_name' => 'Zupełnie Inna Osoba']);

        $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('profile.show', 'basia_nowa'));

        // Opis i cel mówią o tej samej osobie.
        $this->actingAs($odbiorca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia zaczyna Cię obserwować.', false)
            ->assertDontSee('Zupełnie Inna Osoba', false);
    }

    /** Kontrola dodatnia: bez zmiany nazwy wszystko działa jak dotąd. */
    public function test_bez_zmiany_nazwy_prowadzi_na_profil_obserwujacego(): void
    {
        [, $odbiorca, $powiadomienie] = $this->basiaObserwuje();

        $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('profile.show', 'basia_stara'));
    }

    /** Sprawca bez profilu: bezpieczny brak celu, nie zgadywanie po starej nazwie. */
    public function test_sprawca_bez_profilu_nie_ma_celu(): void
    {
        [$basia, $odbiorca, $powiadomienie] = $this->basiaObserwuje();

        Profile::query()->where('user_id', $basia->getKey())->delete();
        $this->user('basia_stara');

        $this->assertNull($powiadomienie->refresh()->adresDocelowy());

        $this->actingAs($odbiorca)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'));
    }

    /** @return array{User, User, Notification} */
    private function basiaObserwuje(): array
    {
        $basia = $this->user('basia_stara', ['display_name' => 'Basia']);
        $odbiorca = $this->user('odbiorca');

        app(FollowUser::class)->handle($basia, $odbiorca);

        $powiadomienie = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FOLLOW)
            ->firstOrFail();

        $this->assertSame(
            'basia_stara',
            $powiadomienie->data['username'] ?? null,
            'Kontrola: powiadomienie nie niesie starej nazwy, więc test nie odtwarza zgłoszenia.',
        );

        return [$basia, $odbiorca, $powiadomienie];
    }

    private function zmienNazwe(User $kto, string $nowa): void
    {
        Profile::query()->where('user_id', $kto->getKey())->update(['username' => $nowa]);
    }
}
