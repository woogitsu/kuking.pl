<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE #771 — powiadomienie o usuniętym ugotowaniu nie obiecuje zdjęcia
 * i nie prowadzi na 404.
 *
 * Wykonanie kasuje się twardo, a identyfikator w `data` nie jest kluczem
 * obcym, więc powiadomienie zostaje (to było prawdziwe zdarzenie). Do tej
 * poprawki dalej mówiło „Jest zdjęcie." i miało „Zobacz" prowadzący do
 * wykonania, którego nie ma.
 *
 * Reguła jest jedna dla listy i licznika: powiadomienie ZOSTAJE na obu
 * (nie znika po cichu), zmienia się tylko to, co mówi i czy ma dokąd prowadzić.
 */
class PowiadomienieOUsunietymUgotowaniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_usunieciu_lista_mowi_prawde_i_nie_ma_zobacz(): void
    {
        [$autor, $kucharz, $wykonanie, $powiadomienie] = $this->ugotowaneZeZdjeciem();

        // Kontrola dodatnia przed usunięciem: obietnica i przycisk są.
        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Jest zdjęcie.', false)
            ->assertSee('>Zobacz</button>', false);

        $this->actingAs($kucharz)
            ->delete(route('cooked.destroy', $wykonanie))
            ->assertRedirect();

        $this->assertFalse(CookedEvent::query()->whereKey($wykonanie->getKey())->exists());

        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Halina — ugotowane z Twojego przepisu', false)
            ->assertSee('To ugotowanie zostało usunięte.', false)
            ->assertDontSee('Jest zdjęcie.', false)
            ->assertDontSee('>Zobacz</button>', false)
            // Nieprzeczytane da się zgasić — bez martwego przycisku.
            ->assertSee('Oznacz jako przeczytane', false);

        $this->assertSame(1, $autor->refresh()->unreadNotificationsCount());
        $this->assertNull($powiadomienie->refresh()->adresDocelowy());
    }

    /** Wyścig: wykonanie znika między wyświetleniem listy a kliknięciem. */
    public function test_zobacz_po_usunieciu_wraca_z_komunikatem_zamiast_404(): void
    {
        [$autor, $kucharz, $wykonanie, $powiadomienie] = $this->ugotowaneZeZdjeciem();

        $this->actingAs($kucharz)->delete(route('cooked.destroy', $wykonanie));

        $this->actingAs($autor)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', 'To ugotowanie zostało usunięte.');

        $this->assertNotNull($powiadomienie->refresh()->read_at);
    }

    /** Usunięcie jednego wykonania nie rusza drugiego tej samej osoby pod tym samym przepisem. */
    public function test_drugie_wykonanie_tej_samej_osoby_dalej_dziala(): void
    {
        [$autor, $kucharz, $pierwsze, $powiadomieniePierwsze, $przepis] = $this->ugotowaneZeZdjeciem();

        $drugie = app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'Drugi raz');
        $powiadomienieDrugie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('data->cooked_event_id', $drugie->getKey())
            ->firstOrFail();

        $this->actingAs($kucharz)->delete(route('cooked.destroy', $pierwsze));

        $this->assertNull($powiadomieniePierwsze->refresh()->adresDocelowy());
        $this->assertSame(route('cooked.celebrate', $drugie), $powiadomienieDrugie->refresh()->adresDocelowy());

        $this->actingAs($autor)->followingRedirects()
            ->post(route('notifications.open', $powiadomienieDrugie))
            ->assertOk()
            ->assertViewIs('pages.cooked.celebrate');
    }

    /** Sprawdzenie istnienia idzie jednym zapytaniem na stronę, nie jednym na powiadomienie. */
    public function test_lista_nie_pyta_o_kazde_wykonanie_osobno(): void
    {
        $autor = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $zapytania = function (int $ile) use ($autor, $przepis): int {
            for ($i = 0; $i < $ile; $i++) {
                app(RecordCookedEvent::class)->handle($this->user(), $przepis);
            }

            $licznik = 0;
            DB::listen(function ($zapytanie) use (&$licznik): void {
                // Tylko zapytania WPROST o wykonania — `visibleTo()` ma
                // `cooked_events` w podzapytaniu i to nie jest wachlarz.
                if (preg_match('/^select [^()]* from "cooked_events"/', $zapytanie->sql) === 1) {
                    $licznik++;
                }
            });

            $this->actingAs($autor)->get(route('notifications.index'))->assertOk();

            return $licznik;
        };

        $this->assertSame(1, $zapytania(5), 'Lista pyta o wykonania więcej niż raz na stronę.');
    }

    /** @return array{User, User, CookedEvent, Notification, Recipe} */
    private function ugotowaneZeZdjeciem(): array
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz', ['display_name' => 'Halina']);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);

        $wykonanie = app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'Wyszło!');

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();

        // Zdjęcie przez pipeline mediów to osobny temat; tu liczy się to,
        // co powiadomienie OBIECUJE (`has_photo` zapisuje `RecordCookedEvent`).
        $powiadomienie->forceFill(['data' => [...$powiadomienie->data, 'has_photo' => true]])->save();

        return [$autor, $kucharz, $wykonanie, $powiadomienie, $przepis];
    }
}
