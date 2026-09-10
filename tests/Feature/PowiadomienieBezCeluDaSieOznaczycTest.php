<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #276 — POWIADOMIENIE BEZ „ZOBACZ" NIE DA SIĘ OZNACZYĆ JAKO PRZECZYTANE.
 *
 * ZGŁOSZENIE WŁAŚCICIELA
 * Wszedł w powiadomienia, przeczytał, nie kliknął nic — plakietka dalej
 * pokazywała 3 nieprzeczytane. Zwrócił uwagę, że przy powiadomieniu
 * „Sprawdziliśmy Twoje odwołanie. Cofamy decyzję." **nie ma nawet co
 * kliknąć**. To samo dotyczy „Moderacja Kuking ukryła Twoją treść." — cała
 * informacja stoi już w samej karcie, więc `Notification::adresDocelowy()`
 * dla nich zwraca `null`.
 *
 * DLACZEGO TO NIE JEST TA SAMA DZIURA CO 8 WRZEŚNIA
 * `PowiadomienieZobaczOznaczaPrzeczytaneTest` pilnuje, że „Zobacz" jest
 * formularzem, nie odnośnikiem. Tu chodzi o coś wcześniejszego: dla
 * powiadomień BEZ celu formularz „Zobacz" w ogóle się nie renderował —
 * `@if($link)` w widoku ukrywał całą sekcję. Jedyną drogą do zgaszenia
 * plakietki przy takim powiadomieniu było „oznacz wszystkie", co zabiera
 * też informację o tym, czego człowiek jeszcze nie widział.
 *
 * DLACZEGO KONTROLER SIĘ NIE ZMIENIŁ
 * `NotificationController::open()` od 8 września radzi sobie z brakiem
 * adresu: zapisuje `read_at`, a niżej `return back()` po prostu zostaje na
 * tej samej stronie. Ten kod nigdy nie był zepsuty — nie dało się go
 * wywołać, bo widok nie stawiał do niego żadnego formularza. Naprawa jest
 * więc w widoku: ten sam `<form>` i ten sam POST co „Zobacz", inny napis
 * („Oznacz jako przeczytane" — „Zobacz" kłamałoby, skoro nie ma dokąd
 * zaprowadzić).
 */
class PowiadomienieBezCeluDaSieOznaczycTest extends TestCase
{
    use RefreshDatabase;

    public function test_powiadomienie_bez_celu_ma_przycisk_oznacz_jako_przeczytane_zamiast_zobacz(): void
    {
        $odbiorca = $this->user('basia');

        $bezCelu = $this->powiadomienieBezCelu($odbiorca);

        // KONTROLA DODATNIA na tej samej stronie (docs/PULAPKI_TESTOW.md #4):
        // powiadomienie Z celem musi dalej pokazywać „Zobacz", a NIE „Oznacz
        // jako przeczytane". Bez tej pary test przechodziłby także wtedy,
        // gdyby oba napisy pokazywały się wszędzie jednakowo.
        $zCelem = $this->powiadomienieOObserwowaniu($odbiorca, 'kasia');

        $html = (string) $this->actingAs($odbiorca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        $kartaBezCelu = $this->wycinek($html, $bezCelu->data['title'], '</li>');

        $this->assertStringContainsString(
            route('notifications.open', $bezCelu),
            $kartaBezCelu,
            'Karta powiadomienia bez celu nie ma formularza wcale — dokładnie ta dziura z issue #276.',
        );
        $this->assertStringContainsString(
            'Oznacz jako przeczytane',
            $kartaBezCelu,
            'Powiadomienie bez celu nie ma przycisku z tekstem — a ikona/przycisk bez podpisu to i tak zakaz UX 50+.',
        );
        $this->assertStringNotContainsString(
            '>Zobacz<',
            $kartaBezCelu,
            '„Zobacz" kłamie na powiadomieniu, które nigdzie nie prowadzi.',
        );

        $kartaZCelem = $this->wycinek($html, 'zaczyna Cię obserwować', '</li>');

        $this->assertStringContainsString('>Zobacz<', $kartaZCelem);
        $this->assertStringNotContainsString('Oznacz jako przeczytane', $kartaZCelem);
    }

    public function test_klikniecie_oznacz_jako_przeczytane_zapisuje_read_at_i_zostaje_na_liscie(): void
    {
        $odbiorca = $this->user('basia');
        $powiadomienie = $this->powiadomienieBezCelu($odbiorca);

        // `from()` ustawia referer, dokładnie tak jak realna przeglądarka po
        // kliknięciu formularza na `/powiadomienia` — bez tego `back()` nie
        // ma dokąd wrócić i test niczego by nie mówił o prawdziwym zachowaniu.
        $this->actingAs($odbiorca)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull(
            $powiadomienie->refresh()->read_at,
            'Powiadomienie bez celu dalej jest nieprzeczytane mimo kliknięcia — plakietka przy nim nigdy nie zgaśnie.',
        );
    }

    /**
     * Przycisk znika, gdy już nic by nie zrobił.
     *
     * `open()` zapisuje `read_at` tylko przy nieprzeczytanym, więc drugie
     * kliknięcie tego samego formularza jest wizualnie identyczne, a nic
     * nie zmienia. Widoczna akcja, która nie robi nic nowego, jest martwym
     * przyciskiem — AGENTS.md zabrania martwych przycisków wprost.
     */
    public function test_przycisk_znika_po_przeczytaniu_zeby_nie_byc_martwym(): void
    {
        $odbiorca = $this->user('basia');
        $powiadomienie = $this->powiadomienieBezCelu($odbiorca);
        $powiadomienie->forceFill(['read_at' => now()])->save();

        $html = (string) $this->actingAs($odbiorca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        $karta = $this->wycinek($html, $powiadomienie->data['title'], '</li>');

        $this->assertStringNotContainsString(
            route('notifications.open', $powiadomienie),
            $karta,
            'Formularz zostaje na karcie mimo że powiadomienie jest już przeczytane — to martwy przycisk.',
        );
    }

    /**
     * UUID W ADRESIE TO NIE AUTORYZACJA (AGENTS.md §7).
     *
     * Osobny test od `PowiadomienieZobaczOznaczaPrzeczytaneTest::test_cudzego_powiadomienia_nie_da_sie_oznaczyc`
     * — tamten dowodzi tego dla powiadomienia Z celem, ten dla powiadomienia
     * BEZ celu, czyli dokładnie tej nowej gałęzi widoku z tego PR-a.
     */
    public function test_cudzego_powiadomienia_bez_celu_nie_da_sie_oznaczyc(): void
    {
        $odbiorca = $this->user('basia');
        $obcy = $this->user('marek');
        $powiadomienie = $this->powiadomienieBezCelu($odbiorca);

        $this->actingAs($obcy)
            ->post(route('notifications.open', $powiadomienie))
            ->assertNotFound();

        $this->assertNull(
            $powiadomienie->refresh()->read_at,
            'Cudzy identyfikator oznaczył powiadomienie jako przeczytane. UUID w adresie to nie autoryzacja.',
        );
    }

    /**
     * DRUGI PRZYCISK „OZNACZ WSZYSTKIE" — TAKŻE POD LISTĄ (issue #276, druga
     * poprawka poza samą dziurą).
     *
     * Przy trzech powiadomieniach różnej długości — jedno z nich z kilkoma
     * akapitami uzasadnienia decyzji moderacyjnej — przycisk sprzed listy
     * wychodzi z ekranu, zanim człowiek skończy czytać. Właściciel na
     * zrzucie widział go uciętym u góry i wcale go nie zarejestrował.
     */
    public function test_oznacz_wszystkie_jest_dostepne_takze_pod_lista_nie_tylko_nad_nia(): void
    {
        $odbiorca = $this->user('basia');
        $this->powiadomienieBezCelu($odbiorca);

        $html = (string) $this->actingAs($odbiorca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->getContent();

        $wystapien = substr_count($html, 'action="'.route('notifications.read').'"');

        $this->assertGreaterThanOrEqual(
            2,
            $wystapien,
            'Przycisk „oznacz wszystkie" stoi tylko raz — przy długiej liście jest poza ekranem, zanim ktoś skończy czytać.',
        );
    }

    /** Powiadomienie moderacyjne bez `data['url']` — dokładnie przykład z issue #276. */
    private function powiadomienieBezCelu(User $odbiorca): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => [
                'title' => 'Sprawdziliśmy Twoje odwołanie. Cofamy decyzję.',
                'message' => 'Uznaliśmy Twoje odwołanie za zasadne i cofamy poprzednią decyzję.',
            ],
        ]);
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

    /**
     * Wycina fragment HTML-a między dwoma znacznikami — zamiast szukać
     * w całej stronie. Nawigacja, prawa szyna i stopka mogą nieść te same
     * słowa i liczby co karta powiadomienia (docs/PULAPKI_TESTOW.md #1).
     */
    private function wycinek(string $html, string $od, string $do): string
    {
        $start = mb_strpos($html, $od);
        $this->assertNotFalse($start, "Nie znalazłem znacznika początku „{$od}” w HTML-u.");

        $koniec = mb_strpos($html, $do, $start);
        $koniec = $koniec === false ? mb_strlen($html) : $koniec;

        return mb_substr($html, $start, $koniec - $start);
    }
}
