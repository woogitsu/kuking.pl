<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #771 — POWIADOMIENIE O USUNIĘTYM WYKONANIU NIE MA OBIECYWAĆ ZDJĘCIA
 * I PROWADZIĆ DO 404.
 *
 * Usunięcie wykonania (`CookedEventController::destroy()`) nie rusza wiersza
 * `notifications` — `cooked_event_id` w `data` nie jest kluczem obcym
 * z kaskadą, tylko zwykłą kolumną JSON. Autor przepisu widział więc dalej
 * „Jest zdjęcie" z przyciskiem „Zobacz" prowadzącym donikąd.
 *
 * NAPRAWA JEST DWUWARSTWOWA:
 *  1. `Notification::scopeVisibleTo()` chowa z LISTY (i z licznika
 *     nieprzeczytanych — to samo zapytanie, ta sama reguła) powiadomienia
 *     TYPE_COOKED/TYPE_SAVED, których cel już nie istnieje.
 *  2. `NotificationController::open()` sprawdza istnienie celu PONOWNIE,
 *     w chwili kliknięcia — bo cel mógł zniknąć MIĘDZY wyświetleniem listy
 *     a tym kliknięciem (stary link, druga karta). Bez tego drugiego
 *     sprawdzenia sam filtr listy nie chroni przed 404 z issue.
 */
class PowiadomienieOUsunietymWykonaniuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function ugotowanie(User $kucharz, Recipe $przepis): CookedEvent
    {
        return app(RecordCookedEvent::class)->handle($kucharz, $przepis, 'Pyszne.');
    }

    public function test_powiadomienie_o_usunietym_wykonaniu_znika_z_listy_i_z_licznika(): void
    {
        $autor = $this->user('autorusunietego');
        $kucharz = $this->user('kucharzusuwajacy');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);

        $this->assertSame(1, $autor->fresh()->unreadNotificationsCount());

        $this->actingAs($kucharz)
            ->delete(route('cooked.destroy', $wykonanie))
            ->assertRedirect();

        $this->assertSame(
            0,
            $autor->fresh()->unreadNotificationsCount(),
            'Licznik dalej liczy powiadomienie o wykonaniu, którego już nie ma.',
        );

        $html = (string) $this->actingAs($autor)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'Jest zdjęcie',
            $html,
            'Lista dalej obiecuje zdjęcie z usuniętego wykonania.',
        );
    }

    /**
     * KONTROLA WYŚCIGU (kryterium odbioru #771): cel znika MIĘDZY
     * wyświetleniem listy a kliknięciem „Zobacz" — filtr listy nic tu nie
     * pomaga, bo powiadomienie było na liście, gdy człowiek na nią wchodził.
     */
    public function test_kliknieciu_zobacz_po_usunieciu_celu_nie_konczy_sie_404(): void
    {
        $autor = $this->user('autorwyscigu');
        $kucharz = $this->user('kucharzwyscigu');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();

        // Cel znika PO tym, jak powiadomienie już istniało — symulacja
        // wyścigu, bez przechodzenia przez pełny ekran listy.
        $this->actingAs($kucharz)->delete(route('cooked.destroy', $wykonanie));

        $odpowiedz = $this->actingAs($autor)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie));

        $odpowiedz->assertRedirect(route('notifications.index'));
        $this->assertNotNull(
            $powiadomienie->refresh()->read_at,
            'Kliknięcie w powiadomienie o zniknięcie celu nie ma prawa zostać bez efektu — nadal oznacza „widziane".',
        );
    }

    /** KONTROLA DODATNIA: istniejące wykonanie nadal daje działające powiadomienie. */
    public function test_istniejace_wykonanie_nadal_daje_dzialajace_powiadomienie(): void
    {
        $autor = $this->user('autoristniejacego');
        $kucharz = $this->user('kucharzistniejacy');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);

        $this->assertSame(1, $autor->fresh()->unreadNotificationsCount());

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();

        $this->actingAs($autor)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('cooked.celebrate', $wykonanie));
    }

    /**
     * KONTROLA DODATNIA: usunięcie JEDNEGO wykonania nie chowa DRUGIEGO
     * powiadomienia tej samej osoby o tym samym przepisie.
     */
    public function test_usuniecie_jednego_wykonania_nie_chowa_drugiego(): void
    {
        $autor = $this->user('autordwoch');
        $kucharz = $this->user('kucharzdwoch');
        $przepis = $this->przepis($autor);

        $pierwsze = $this->ugotowanie($kucharz, $przepis);
        $drugie = $this->ugotowanie($kucharz, $przepis);

        $this->assertSame(2, $autor->fresh()->unreadNotificationsCount());

        $this->actingAs($kucharz)->delete(route('cooked.destroy', $pierwsze));

        $this->assertSame(
            1,
            $autor->fresh()->unreadNotificationsCount(),
            'Usunięcie jednego wykonania schowało też powiadomienie o drugim.',
        );
    }

    /**
     * KONTROLA DODATNIA DLA TYPE_SAVED (ta sama luka, wskazana w komentarzu
     * pod #771): usunięcie przepisu (soft delete) chowa powiadomienie
     * o zapisaniu go do zeszytu.
     */
    public function test_powiadomienie_o_zapisie_usunietego_przepisu_tez_znika(): void
    {
        $autor = $this->user('autorprzepisu');
        $zapisujaca = $this->user('zapisujacaprzepis');
        $przepis = $this->przepis($autor);

        app(SaveRecipeToCollection::class)->handle($zapisujaca, $przepis);

        $this->assertSame(1, $autor->fresh()->unreadNotificationsCount());

        $przepis->delete();

        $this->assertSame(
            0,
            $autor->fresh()->unreadNotificationsCount(),
            'Powiadomienie o zapisie usuniętego przepisu dalej liczy się jako nieprzeczytane.',
        );
    }
}
