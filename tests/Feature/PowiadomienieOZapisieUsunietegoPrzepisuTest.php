<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE #1034 — powiadomienie „ma Twój przepis w swoim zeszycie" po
 * usunięciu przepisu nie prowadzi na 404.
 *
 * `adresDocelowy()` budował cel z zamrożonego `data.recipe_slug`, a przepis
 * usuwa się miękko (`SoftDeletes`), więc „Zobacz" kończył się 404. Cel
 * rozstrzyga teraz stabilne `recipe_id`; slug służy tylko do adresu
 * przepisu, który jeszcze istnieje. Powiadomienie ZOSTAJE na liście
 * i w liczniku — to było prawdziwe zdarzenie — tylko traci „Zobacz".
 */
class PowiadomienieOZapisieUsunietegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_pelna_sciezka_zapis_usuniecie_lista_otwarcie(): void
    {
        [$autor, $przepis, $powiadomienie] = $this->zapisanyPrzepis();

        // Kontrola dodatnia: przepis istnieje, więc „Zobacz" jest i działa.
        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('>Zobacz</button>', false)
            ->assertDontSee('Ten przepis został usunięty.', false);

        $this->actingAs($autor)
            ->delete(route('recipes.destroy', $przepis->slug))
            ->assertRedirect(route('home'));
        $this->assertSoftDeleted($przepis);

        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Halina ma Twój przepis', false)
            ->assertSee('Ten przepis został usunięty.', false)
            ->assertDontSee('>Zobacz</button>', false)
            ->assertSee('Oznacz jako przeczytane', false);

        $this->assertSame(1, $autor->refresh()->unreadNotificationsCount());
        $this->assertNull($powiadomienie->refresh()->adresDocelowy());

        // Stara karta (wyrenderowana przed usunięciem) — kliknięcie wraca
        // z polskim komunikatem, nie 404, i gasi powiadomienie.
        $this->actingAs($autor)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', 'Ten przepis został usunięty.');

        $this->assertNotNull($powiadomienie->refresh()->read_at);
        $this->assertSame(0, $autor->refresh()->unreadNotificationsCount());
        // Historyczny wiersz zostaje na liście.
        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Halina ma Twój przepis', false);
    }

    public function test_po_zmianie_sluga_zobacz_prowadzi_pod_aktualny_adres(): void
    {
        [$autor, $przepis, $powiadomienie] = $this->zapisanyPrzepis();

        $staryAdres = route('recipes.show', $przepis->slug);
        $przepis->forceFill(['slug' => 'rosol-babci-haliny'])->save();

        $this->assertSame(route('recipes.show', 'rosol-babci-haliny'), $powiadomienie->refresh()->adresDocelowy());
        $this->assertNotSame($staryAdres, $powiadomienie->adresDocelowy());

        $this->actingAs($autor)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('recipes.show', 'rosol-babci-haliny'));

        $this->actingAs($autor)->get(route('recipes.show', 'rosol-babci-haliny'))->assertOk();
    }

    /** Sprawdzenie przepisów idzie jednym zapytaniem na stronę, nie jednym na powiadomienie. */
    public function test_liczba_zapytan_o_przepisy_nie_rosnie_z_liczba_powiadomien(): void
    {
        $autor = $this->user('autorka2');

        for ($i = 0; $i < 6; $i++) {
            $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
            $this->actingAs($this->user())->post(route('collections.save', $przepis->slug));

            if ($i % 2 === 0) {
                $przepis->delete();
            }
        }

        $licznik = 0;
        DB::listen(function ($zapytanie) use (&$licznik): void {
            if (preg_match('/^select [^()]* from "recipes"/', $zapytanie->sql) === 1) {
                $licznik++;
            }
        });

        $odpowiedz = $this->actingAs($autor)->get(route('notifications.index'))->assertOk();

        $this->assertSame(1, $licznik, 'Przy sześciu powiadomieniach lista pyta o przepisy więcej niż raz.');
        $this->assertSame(3, substr_count((string) $odpowiedz->getContent(), 'Ten przepis został usunięty.'));
        $this->assertSame(3, substr_count((string) $odpowiedz->getContent(), '>Zobacz</button>'));
    }

    /** @return array{User, Recipe, Notification} */
    private function zapisanyPrzepis(): array
    {
        $autor = $this->user('autorka');
        $zapisujaca = $this->user('zapisujaca', ['display_name' => 'Halina']);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);

        $this->actingAs($zapisujaca)
            ->post(route('collections.save', $przepis->slug))
            ->assertRedirect();

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->firstOrFail();

        return [$autor, $przepis, $powiadomienie];
    }
}
