<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #770 — PIERWSZE „ZOBACZ" PRZY UGOTOWANIU NIE MA PRAWA OMINĄĆ
 * CELEBRACJI.
 *
 * DWA KONTROLERY NADAWAŁY TEMU SAMEMU `read_at` DWIE RÓŻNE ROLE.
 * `NotificationController::open()` ustawiał go PRZED przekierowaniem —
 * „to powiadomienie zostało kliknięte". `CookedEventController::celebrate()`
 * czytał go jako „ekran już był pokazany" i na tej podstawie odsyłał
 * dalej, do zwykłego wpisu. Pierwsze kliknięcie „Zobacz" z LISTY
 * powiadomień (w odróżnieniu od bezpośredniego wejścia z e-maila) samo
 * ustawiało ten dowód, zanim ekran zdążył się w ogóle wyświetlić.
 *
 * DRUGI PRZYPADEK Z TEGO SAMEGO ZGŁOSZENIA (dopisek właściciela): odrzucone
 * „Podziękuj" (tekst dłuższy niż 2000 znaków) wraca przez `back()`, czyli
 * GET-em na TĘ SAMĄ trasę `cooked.celebrate` — a ta, widząc już ustawiony
 * `read_at` z PIERWSZEGO wyświetlenia ekranu (nie z tego testu), też
 * odsyłała od razu do `cooked.show`, gubiąc wpisany tekst i komunikat
 * błędu.
 */
class PowiadomienieZobaczOtwieraCelebracjeTest extends TestCase
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
        return app(RecordCookedEvent::class)->handle($kucharz, $przepis, 'Wyszło super.');
    }

    private function powiadomienie(User $autor): Notification
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();
    }

    public function test_pierwsze_zobacz_z_listy_pokazuje_celebracje_a_nie_zwykly_wpis(): void
    {
        $autor = $this->user('autorcelebracji');
        $kucharz = $this->user('kucharzcelebracji');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);
        $powiadomienie = $this->powiadomienie($autor);

        $this->assertTrue($powiadomienie->isUnread(), 'Kontrola: powiadomienie ma zaczynać jako nieprzeczytane.');

        $odpowiedz = $this->actingAs($autor)
            ->post(route('notifications.open', $powiadomienie));

        // Kryterium odbioru #770: liczy się KOŃCOWY widok po przekierowaniach,
        // nie sam pośredni adres.
        $finalny = $this->followRedirects($odpowiedz)->assertOk();
        $finalny->assertSee('Podziękuj', false);
        $finalny->assertSee($przepis->title, false);

        $this->assertFalse(
            $powiadomienie->fresh()->isUnread(),
            'Ekran się pokazał, więc powiadomienie ma być teraz oznaczone jako przeczytane.',
        );
    }

    /** KONTROLA WŁASNOŚCI: cudzy identyfikator nie otwiera ekranu celebracji. */
    public function test_cudzy_identyfikator_wykonania_nie_otwiera_celebracji(): void
    {
        $autor = $this->user('autorwlasnosci');
        $kucharz = $this->user('kucharzwlasnosci');
        $obcy = $this->user('obcywlasnosci');
        $przepis = $this->przepis($autor);
        $this->ugotowanie($kucharz, $przepis);

        $powiadomienieObcego = $this->powiadomienie($autor);

        $this->actingAs($obcy)
            ->post(route('notifications.open', $powiadomienieObcego))
            ->assertNotFound();
    }

    /** KONTROLA DODATNIA: „oznacz wszystkie jako przeczytane" nadal działa i nie zaburza tej naprawy. */
    public function test_oznacz_wszystkie_jako_przeczytane_nadal_dziala(): void
    {
        $autor = $this->user('autoroznaczwszystkie');
        $kucharz = $this->user('kucharzoznaczwszystkie');
        $przepis = $this->przepis($autor);
        $this->ugotowanie($kucharz, $przepis);

        $this->actingAs($autor)->post(route('notifications.read'));

        $this->assertFalse($this->powiadomienie($autor)->fresh()->isUnread());
    }

    /**
     * PONOWNE WEJŚCIE na już obejrzaną celebrację nadal ląduje na zwykłym
     * wpisie — to zachowanie z issue #17 i `KomusWyszloTest`, którego ta
     * naprawa nie ma prawa cofnąć.
     */
    public function test_ponowne_zobacz_po_obejrzeniu_prowadzi_juz_do_zwyklego_wpisu(): void
    {
        $autor = $this->user('autorponownie');
        $kucharz = $this->user('kucharzponownie');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);
        $powiadomienie = $this->powiadomienie($autor);

        // Pierwsze wejście — bezpośrednim GET-em, jak w KomusWyszloTest.
        $this->actingAs($autor)->get(route('cooked.celebrate', $wykonanie))->assertOk();

        // „Zobacz" liczy cel jako `cooked.celebrate` zawsze — to DOPIERO
        // `celebrate()`, odwiedzone drugi raz, odsyła dalej. Liczy się więc
        // KOŃCOWY widok po przekierowaniach, nie sam pierwszy `Location`.
        $this->actingAs($autor)
            ->followingRedirects()
            ->post(route('notifications.open', $powiadomienie))
            ->assertOk()
            ->assertDontSee('Podziękuj', false);
    }

    /**
     * DRUGI PRZYPADEK Z TEGO SAMEGO ZGŁOSZENIA: odrzucone „Podziękuj" wraca
     * na ekran celebracji z zachowanym tekstem i błędem, nie na
     * `cooked.show`.
     */
    public function test_odrzucone_podziekowanie_wraca_na_celebracje_z_bledem_i_tekstem(): void
    {
        $autor = $this->user('autorodrzucenia');
        $kucharz = $this->user('kucharzodrzucenia');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->ugotowanie($kucharz, $przepis);
        $powiadomienie = $this->powiadomienie($autor);

        // Pierwsze wejście przez „Zobacz" — dokładnie ten scenariusz z #770.
        // Trzeba PODĄŻYĆ za przekierowaniem, żeby faktycznie odwiedzić
        // `celebrate()` — to ono, nie `notifications.open`, oznacza teraz
        // powiadomienie jako przeczytane (patrz naprawa #770 wyżej).
        $this->actingAs($autor)->followingRedirects()->post(route('notifications.open', $powiadomienie))->assertOk();
        $this->assertFalse($powiadomienie->fresh()->isUnread());

        $zaDlugiTekst = str_repeat('a', 2001);

        $odpowiedzThank = $this->actingAs($autor)
            ->from(route('cooked.celebrate', $wykonanie))
            ->post(route('cooked.thank', $wykonanie), ['body' => $zaDlugiTekst]);

        $odpowiedzThank->assertRedirect(route('cooked.celebrate', $wykonanie));
        $odpowiedzThank->assertSessionHasErrors('body');

        $this->assertNull(
            Comment::where('cooked_event_id', $wykonanie->getKey())->first(),
            'Odrzucony tekst mimo to utworzył komentarz.',
        );

        // Przeglądarka po `back()` odpytuje tę samą trasę GET-em — z sesją
        // niosącą stary tekst i błąd.
        $ekran = $this->followingRedirects()
            ->from(route('cooked.celebrate', $wykonanie))
            ->post(route('cooked.thank', $wykonanie), ['body' => $zaDlugiTekst]);

        $ekran->assertOk();
        $ekran->assertSee('Podziękuj', false);
        $ekran->assertSee($zaDlugiTekst);

        // Poprawiony, mieszczący się w limicie tekst nadal daje dokładnie
        // jedno podziękowanie — poprawka nie ma prawa zdublować komentarza
        // ani zablokować poprawionego wysłania.
        $this->actingAs($autor)
            ->post(route('cooked.thank', $wykonanie), ['body' => 'W sam raz.'])
            ->assertRedirect(route('cooked.show', $wykonanie));

        $this->assertSame(1, Comment::where('cooked_event_id', $wykonanie->getKey())->count());
    }
}
