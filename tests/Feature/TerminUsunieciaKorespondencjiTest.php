<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ekran „Odpowiedz tej osobie" przy sprawie ZAMKNIĘTEJ (issue #847).
 *
 * STAN ZASTANY (pomiar stanowiska `gpt/pytania-widoki`, #847): formularz
 * odpowiedzi jest dostępny także przy sprawie zamkniętej, a ekran nie mówi
 * przy nim, kiedy retencja skasuje sprawę razem z każdą świeżo dopisaną
 * odpowiedzią (kaskada `contact_message_replies` → `contact_messages`).
 * Operator dopisuje wyjaśnienie do starej zamkniętej sprawy, a następnego
 * dnia sprzątanie (`kuking:sprzataj-wiadomosci`) kasuje całą sprawę razem ze
 * świeżą odpowiedzią.
 *
 * DECYZJA WŁAŚCICIELA (20.09.2026): pokazać konkretny termin usunięcia
 * (datę, nie „wkrótce") i drogę ponownego otwarcia sprawy. WPROST ODRZUCONE:
 * przesuwanie retencji od ostatniej odpowiedzi.
 *
 * Ten plik pilnuje CZTERECH rzeczy:
 *  1. sprawa zamknięta blisko terminu pokazuje konkretną datę usunięcia;
 *  2. sprawa zamknięta dawno temu też ją pokazuje (nie różni się formą);
 *  3. sprawa otwarta ponownie NIE pokazuje terminu — nie ma czym straszyć,
 *     skoro sprawa otwarta nigdy nie jest kandydatem do skasowania;
 *  4. data pokazana na ekranie ZGADZA SIĘ z tym, co naprawdę zrobi
 *     `PrzedawnioneWiadomosciDoOperatora::posprzataj()` — sprawdzone
 *     URUCHOMIENIEM sprzątania dzień przed terminem (0 kandydatów) i w dniu
 *     terminu (sprawa naprawdę znika), a nie samym odczytaniem daty z ekranu.
 *
 * Ekran pokazuje też drogę ponownego otwarcia sprawy — pole „Stan
 * wiadomości" niżej na tym samym ekranie, z wyraźnym odnośnikiem od strony
 * formularza odpowiedzi.
 */
class TerminUsunieciaKorespondencjiTest extends TestCase
{
    use RefreshDatabase;

    private function sprzataj(int $miesiecy, bool $naSucho = false): int
    {
        return app(PrzedawnioneWiadomosciDoOperatora::class)->posprzataj($miesiecy, $naSucho);
    }

    public function test_zamknieta_sprawa_blisko_terminu_pokazuje_konkretna_date(): void
    {
        config(['kuking.kontakt.retention_months' => 12]);
        $operator = $this->moderator();

        // Zamknięta 11 miesięcy i 3 tygodnie temu — termin usunięcia jest
        // tuż-tuż, dokładnie ten przypadek, w którym dopisek zniknąłby
        // najszybciej.
        $wiadomosc = ContactMessage::factory()
            ->zalatwiona($operator, now()->subMonths(11)->subWeeks(3))
            ->create();

        $html = $this->actingAs($operator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->getContent();

        $termin = app(PrzedawnioneWiadomosciDoOperatora::class)->terminUsuniecia($wiadomosc->fresh());
        $this->assertNotNull($termin, 'Kontrola testu: sprawa zamknięta musi mieć termin.');

        $this->assertStringContainsString(
            Czas::data($termin, 'j F Y'),
            $html,
            'Ekran ma pokazać KONKRETNĄ datę usunięcia, nie samo słowo "wkrótce".',
        );
        $this->assertStringNotContainsString('wkrótce', mb_strtolower($html));
    }

    public function test_zamknieta_dawno_sprawa_tez_pokazuje_termin(): void
    {
        $operator = $this->moderator();

        // Zamknięta pięć lat temu — dawno po terminie retencji. Mimo że
        // fizycznie powinna już zniknąć, test buduje wiersz wprost (bez
        // przelotu przez sprzątanie), żeby sprawdzić samo wyliczanie i
        // wygląd ekranu niezależnie od harmonogramu.
        $wiadomosc = ContactMessage::factory()
            ->zalatwiona($operator, now()->subYears(5))
            ->create();

        $html = $this->actingAs($operator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->getContent();

        $termin = app(PrzedawnioneWiadomosciDoOperatora::class)->terminUsuniecia($wiadomosc->fresh());

        $this->assertStringContainsString(Czas::data($termin, 'j F Y'), $html);
        $this->assertStringContainsString('otwórz sprawę ponownie', mb_strtolower($html));
    }

    public function test_otwarta_ponownie_sprawa_nie_straszy_terminem(): void
    {
        $operator = $this->moderator();

        $wiadomosc = ContactMessage::factory()
            ->zalatwiona($operator, now()->subMonths(11)->subWeeks(3))
            ->create();

        // Świadomie otwarta ponownie — sprawa otwarta nigdy nie jest
        // kandydatem do skasowania (ta sama zasada co przy sprawach
        // moderacyjnych), więc ekran nie ma czym straszyć.
        $wiadomosc->oznaczJako(ContactMessage::STATUS_W_TOKU, $operator);

        $this->assertNull(
            app(PrzedawnioneWiadomosciDoOperatora::class)->terminUsuniecia($wiadomosc->fresh()),
            'Kontrola testu: sprawa otwarta nie ma terminu usunięcia.',
        );

        $html = $this->actingAs($operator)
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('zostanie skasowana', mb_strtolower($html));
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Termin pokazany na ekranie musi się zgadzać z tym, co naprawdę zrobi
     * sprzątanie — sprawdzone przez URUCHOMIENIE `posprzataj()`, nie przez
     * porównanie dwóch dat wyliczonych osobno. Dzień przed terminem: sprawa
     * NIE jest kandydatem. W dniu terminu: sprawa naprawdę znika.
     */
    public function test_pokazany_termin_zgadza_sie_z_tym_co_zrobi_sprzatanie(): void
    {
        config(['kuking.kontakt.retention_months' => 12]);
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));

        $operator = $this->moderator();
        $wiadomosc = ContactMessage::factory()
            ->zalatwiona($operator, Carbon::parse('2025-09-20 13:00:00', 'UTC'))
            ->create();

        $termin = app(PrzedawnioneWiadomosciDoOperatora::class)->terminUsuniecia($wiadomosc->fresh());
        $this->assertNotNull($termin);

        // Chwilę przed pokazanym terminem: sprzątanie jeszcze nie rusza tej
        // sprawy.
        Carbon::setTestNow($termin->copy()->subMinute());
        $this->assertSame(0, $this->sprzataj(12, naSucho: true));
        $this->assertDatabaseHas('contact_messages', ['id' => $wiadomosc->getKey()]);

        // W chwilę po pokazanym terminie: sprzątanie naprawdę kasuje tę
        // sprawę — dokładnie to, co ekran obiecał.
        Carbon::setTestNow($termin->copy()->addMinute());
        $this->assertSame(1, $this->sprzataj(12));
        $this->assertDatabaseMissing('contact_messages', ['id' => $wiadomosc->getKey()]);

        Carbon::setTestNow();
    }
}
