<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #770 — pierwsze „Zobacz" przy ugotowaniu pokazuje ekran „Komuś wyszło".
 *
 * `NotificationController::open()` ustawia `read_at` PRZED przekierowaniem,
 * a `CookedEventController::celebrate()` traktował ustawione `read_at` jako
 * dowód, że ekran już był. Skutek: z listy powiadomień — jedynej drogi, którą
 * autor przepisu tam trafia — celebracji nie było widać NIGDY.
 *
 * `KomusWyszloTest` tego nie łapał, bo wchodzi GET-em wprost na
 * `cooked.celebrate`. Ten plik zaczyna od POST-a z listy i idzie za
 * przekierowaniem do końcowego widoku.
 */
class PierwszeZobaczPokazujeKomusWyszloTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwsze_zobacz_z_listy_pokazuje_celebracje(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();

        $this->actingAs($autor)
            ->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertOk()
            ->assertViewIs('pages.cooked.celebrate')
            ->assertSee('Halina', false)
            ->assertSee('Wyszło pięknie!', false)
            ->assertSee('Podziękuj', false);

        $this->assertNotNull($powiadomienie->refresh()->read_at);
    }

    /** Ekran dalej pokazuje się RAZ — drugie kliknięcie idzie na zwykły wpis. */
    public function test_drugie_zobacz_prowadzi_na_zwykly_wpis(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();

        $this->actingAs($autor)->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertViewIs('pages.cooked.celebrate');

        $this->actingAs($autor)->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertOk()
            ->assertViewIs('pages.cooked.show');
    }

    /** Odświeżenie ekranu celebracji po pierwszym otwarciu też jej nie powtarza. */
    public function test_odswiezenie_celebracji_nie_pokazuje_jej_drugi_raz(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();

        $this->actingAs($autor)->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertViewIs('pages.cooked.celebrate');

        $this->actingAs($autor)
            ->get(route('cooked.celebrate', $wykonanie))
            ->assertRedirect(route('cooked.show', $wykonanie));
    }

    /**
     * ŚWIADOMA GRANICA: „Oznacz wszystkie jako przeczytane" to jawna decyzja
     * człowieka, że widział. Późniejsze „Zobacz" prowadzi na zwykły wpis —
     * zapamiętanie „celebracja pokazana" osobno od `read_at` wymagałoby
     * nowej kolumny, a tego ta poprawka nie wprowadza.
     */
    public function test_po_oznacz_wszystkie_zobacz_prowadzi_na_zwykly_wpis(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();

        $this->actingAs($autor)->post(route('notifications.read'));

        $this->actingAs($autor)->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertOk()
            ->assertViewIs('pages.cooked.show');
    }

    /** Pierwsze otwarcie nie przesuwa znacznika ustawionego przez „Zobacz" (D-079). */
    public function test_celebracja_nie_przesuwa_znacznika_ustawionego_przez_zobacz(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();

        $this->actingAs($autor)->post(route('notifications.open', $powiadomienie));

        $znacznik = now()->subMinutes(5);
        $powiadomienie->forceFill(['read_at' => $znacznik])->save();

        // Ten sam flash, jakby przekierowanie dopiero szło do celebracji.
        $this->actingAs($autor)
            ->withSession([Notification::SESJA_PIERWSZE_OTWARCIE => (string) $powiadomienie->getKey()])
            ->get(route('cooked.celebrate', $wykonanie))
            ->assertOk()
            ->assertViewIs('pages.cooked.celebrate');

        $this->assertSame(
            $znacznik->format('Y-m-d H:i:s'),
            $powiadomienie->refresh()->read_at?->format('Y-m-d H:i:s'),
        );
    }

    /** Flash z cudzego powiadomienia nie otwiera celebracji tego wykonania. */
    public function test_flash_innego_powiadomienia_nie_otwiera_celebracji(): void
    {
        [$autor, $wykonanie, $powiadomienie] = $this->ugotowane();
        $powiadomienie->forceFill(['read_at' => now()])->save();

        $this->actingAs($autor)
            ->withSession([Notification::SESJA_PIERWSZE_OTWARCIE => '00000000-0000-0000-0000-000000000000'])
            ->get(route('cooked.celebrate', $wykonanie))
            ->assertRedirect(route('cooked.show', $wykonanie));
    }

    /** @return array{User, CookedEvent, Notification} */
    private function ugotowane(): array
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz', ['display_name' => 'Halina']);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);

        $wykonanie = app(RecordCookedEvent::class)->handle($kucharz, $przepis, note: 'Wyszło pięknie!');

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();

        $this->assertTrue($powiadomienie->isUnread(), 'Kontrola: powiadomienie ma startować jako nieprzeczytane.');

        return [$autor, $wykonanie, $powiadomienie];
    }
}
