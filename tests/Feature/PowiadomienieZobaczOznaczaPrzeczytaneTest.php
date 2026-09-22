<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „ZOBACZ" OZNACZA POWIADOMIENIE JAKO PRZECZYTANE.
 *
 * ZGŁOSZENIE, Z KTÓREGO TO POWSTAŁO (właściciel, 8 września 2026)
 * „Mam powiadomienie, klikam «zobacz», wchodzę w nie, ale dalej mam jedno
 * nieprzeczytane. Znowu wchodzę, patrzę i nic. Dopiero «oznacz wszystkie
 * jako przeczytane» to załatwia."
 *
 * Opis jest dokładny co do joty i przyczyna była bardziej wstydliwa niż
 * błąd: „Zobacz" był zwykłym `<a href>` prowadzącym do treści. **Nie było
 * w ogóle trasy oznaczającej POJEDYNCZE powiadomienie** — jedyna, jaka
 * istniała, oznaczała wszystkie naraz. Nic się nie psuło; brakowało części.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Plakietka z liczbą nieprzeczytanych jest obietnicą: „tu jest coś, czego
 * jeszcze nie widziałeś". Licznik, który nie schodzi po przeczytaniu, uczy
 * człowieka, że plakietka kłamie — a wtedy przestaje na nią patrzeć także
 * wtedy, gdy mówi prawdę. Dla grupy 50+ (`docs/UX_50_PLUS.md`) to jest
 * dokładnie ten rodzaj zachowania, po którym człowiek uznaje, że „coś
 * zrobił źle", i przestaje klikać.
 *
 * CZEGO TEN PLIK PILNUJE, POZA SAMĄ POPRAWKĄ
 * Że przycisk zostaje FORMULARZEM. Powrót do `<a href>` byłby najbardziej
 * naturalną „drobną poprawką porządkową", jaką ktoś kiedyś zrobi — i cofnąłby
 * całą tę zmianę, bo GET nie ma prawa zmieniać stanu.
 */
class PowiadomienieZobaczOznaczaPrzeczytaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_zobacz_oznacza_to_jedno_powiadomienie_i_odsyla_do_tresci(): void
    {
        $odbiorca = $this->user('basia');

        $pierwsze = $this->powiadomienieOObserwowaniu($odbiorca, 'kasia');
        $drugie = $this->powiadomienieOObserwowaniu($odbiorca, 'zofia');

        $odpowiedz = $this->actingAs($odbiorca)
            ->post(route('notifications.open', $pierwsze));

        $odpowiedz->assertRedirect(route('profile.show', 'kasia'));

        $this->assertNotNull(
            $pierwsze->refresh()->read_at,
            'Kliknięcie „Zobacz" nie oznaczyło powiadomienia jako przeczytanego — '
            .'czyli licznik nieprzeczytanych dalej pokazuje coś, co człowiek właśnie obejrzał.',
        );

        // KONTROLA, BEZ KTÓREJ TEN TEST BYŁBY ZIELONY TAKŻE PO OZNACZENIU
        // WSZYSTKIEGO NARAZ — a to jest inne zachowanie i inna decyzja.
        $this->assertNull(
            $drugie->refresh()->read_at,
            'Kliknięcie w jedno powiadomienie oznaczyło też pozostałe. „Zobacz" ma '
            .'dotyczyć tego jednego; od oznaczania wszystkiego jest osobny przycisk.',
        );
    }

    public function test_cudzego_powiadomienia_nie_da_sie_oznaczyc(): void
    {
        $odbiorca = $this->user('basia');
        $obcy = $this->user('marek');

        $powiadomienie = $this->powiadomienieOObserwowaniu($odbiorca, 'kasia');

        $this->actingAs($obcy)
            ->post(route('notifications.open', $powiadomienie))
            ->assertNotFound();

        $this->assertNull(
            $powiadomienie->refresh()->read_at,
            'Cudzy identyfikator oznaczył powiadomienie jako przeczytane. UUID w adresie '
            .'to nie autoryzacja (AGENTS.md §7).',
        );
    }

    /**
     * POWTÓRNE KLIKNIĘCIE NIE PRZESUWA ZNACZNIKA — I TO NIE JEST KOSMETYKA.
     *
     * Od `read_at` liczy się retencja powiadomień (`PrzedawnionePowiadomienia`,
     * 3 miesiące). Gdyby każde zajrzenie odświeżało znacznik, powiadomienie
     * oglądane raz na miesiąc żyłoby w bazie bez końca, a polityka
     * prywatności obiecuje ludziom konkretny okres.
     */
    public function test_powtorne_klikniecie_nie_przesuwa_znacznika_przeczytania(): void
    {
        $odbiorca = $this->user('basia');
        $powiadomienie = $this->powiadomienieOObserwowaniu($odbiorca, 'kasia');

        $dawno = now()->subDays(40);
        $powiadomienie->forceFill(['read_at' => $dawno])->save();

        $this->actingAs($odbiorca)->post(route('notifications.open', $powiadomienie));

        $this->assertSame(
            $dawno->format('Y-m-d H:i:s'),
            $powiadomienie->refresh()->read_at?->format('Y-m-d H:i:s'),
            'Powtórne kliknięcie przesunęło znacznik przeczytania w przód, a od niego '
            .'zależy, kiedy powiadomienie zniknie z bazy.',
        );
    }

    public function test_na_liscie_stoi_formularz_a_nie_zwykly_odnosnik(): void
    {
        $odbiorca = $this->user('basia');
        $powiadomienie = $this->powiadomienieOObserwowaniu($odbiorca, 'kasia');

        $html = (string) $this->actingAs($odbiorca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('notifications.open', $powiadomienie),
            $html,
            'Na liście nie ma formularza „Zobacz" dla tego powiadomienia.',
        );

        $this->assertStringNotContainsString(
            '>Zobacz</a>',
            $html,
            'Przycisk „Zobacz" wrócił do postaci odnośnika. GET nie może zmieniać stanu, '
            .'więc taki przycisk znowu przestałby oznaczać powiadomienie jako przeczytane.',
        );
    }

    /** Powiadomienie „ktoś Cię obserwuje" — najprostsze z adresem docelowym. */
    private function powiadomienieOObserwowaniu(User $odbiorca, string $kto): Notification
    {
        $powiadomienie = app(NotifyUser::class)->handle(
            $odbiorca,
            Notification::TYPE_FOLLOW,
            $this->user($kto),
            ['username' => $kto],
        );

        $this->assertNotNull(
            $powiadomienie,
            "Kontrola: powiadomienie od „{$kto}” w ogóle nie powstało, więc reszta testu nic nie mierzy.",
        );

        return $powiadomienie;
    }
}
