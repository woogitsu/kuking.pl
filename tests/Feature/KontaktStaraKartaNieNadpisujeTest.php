<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #846: dwie karty tej samej wiadomości otwarte na tej samej wersji.
 * Zapis z pierwszej przechodzi; zapis ze starszej drugiej NIE nadpisuje
 * notatki ani nie cofa stanu, a jej tekst zostaje w formularzu.
 *
 * Żądania idą KOLEJNO, nie równocześnie — sama blokada wiersza bez
 * porównania wersji tego scenariusza nie łapie.
 */
class KontaktStaraKartaNieNadpisujeTest extends TestCase
{
    use RefreshDatabase;

    /** Wersja z ukrytego pola formularza — tak, jak widzi ją przeglądarka. */
    private function wersjaZFormularza(ContactMessage $wiadomosc): string
    {
        $html = $this->get(route('admin.contact.show', $wiadomosc))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<input type="hidden" name="wersja" value="([0-9a-f]{64})">/', $html, $m));

        return $m[1];
    }

    public function test_starsza_karta_nie_nadpisuje_nowszej_notatki_i_stanu(): void
    {
        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create();
        $this->actingAs($moderator);

        $karta1 = $this->wersjaZFormularza($wiadomosc);
        $karta2 = $this->wersjaZFormularza($wiadomosc);
        $this->assertSame($karta1, $karta2);

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'handler_note' => 'Ustalone: poprawka w issue #200.',
            'wersja' => $karta1,
        ])->assertRedirect(route('admin.contact.show', $wiadomosc))->assertSessionHasNoErrors();

        $poPierwszym = $wiadomosc->refresh()->getRawOriginal();

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_NOWA,
            'handler_note' => 'Inna notatka ze starej karty.',
            'wersja' => $karta2,
        ])->assertRedirect(route('admin.contact.show', $wiadomosc))
            ->assertSessionHasErrors('wersja')
            ->assertSessionHasInput('handler_note', 'Inna notatka ze starej karty.');

        // Wiersz dokładnie taki, jak po pierwszym zapisie.
        $this->assertSame($poPierwszym, $wiadomosc->refresh()->getRawOriginal());
        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);
        $this->assertSame('Ustalone: poprawka w issue #200.', $wiadomosc->handler_note);
        $this->assertSame($moderator->id, $wiadomosc->handled_by);

        // Ekran po konflikcie: zapisany stan nad formularzem, tekst drugiej
        // karty w polu, radio na stanie z bazy, wersja już bieżąca.
        $ekran = $this->followingRedirects()->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_NOWA,
            'handler_note' => 'Inna notatka ze starej karty.',
            'wersja' => $karta2,
        ])->assertOk();
        $this->assertSame($poPierwszym, $wiadomosc->refresh()->getRawOriginal());
        $ekran->assertSee('Tak ta wiadomość jest zapisana teraz')
            ->assertSee('href="#stan-zapisany"', false)
            ->assertSee('id="stan-zapisany"', false)
            ->assertSee('Ustalone: poprawka w issue #200.')
            ->assertSee('Inna notatka ze starej karty.')
            ->assertSee('naciśnij „Zapisz” jeszcze raz', false)
            ->assertSee('name="wersja" value="'.$wiadomosc->wersjaObslugi().'"', false);
        $this->assertMatchesRegularExpression('/value="done"\s+checked/', $ekran->getContent());
        $this->assertDoesNotMatchRegularExpression('/aria-invalid="true"[^>]*handler_note|handler_note[^>]*aria-invalid="true"/', $ekran->getContent());
    }

    public function test_swiadomy_zapis_po_ponownym_otwarciu_przechodzi(): void
    {
        $this->actingAs($this->moderator());
        $wiadomosc = ContactMessage::factory()->create();
        $stara = $this->wersjaZFormularza($wiadomosc);

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'handler_note' => 'Pierwsza.',
            'wersja' => $stara,
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_W_TOKU,
            'handler_note' => 'Druga.',
            'wersja' => $stara,
        ])->assertSessionHasErrors('wersja');

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_W_TOKU,
            'handler_note' => 'Druga.',
            'wersja' => $this->wersjaZFormularza($wiadomosc),
        ])->assertSessionHasNoErrors();

        $wiadomosc->refresh();
        $this->assertSame(ContactMessage::STATUS_W_TOKU, $wiadomosc->status);
        $this->assertSame('Druga.', $wiadomosc->handler_note);
    }

    public function test_podwojne_klikniecie_tym_samym_nie_jest_konfliktem(): void
    {
        $this->actingAs($this->moderator());
        $wiadomosc = ContactMessage::factory()->create();
        $wersja = $this->wersjaZFormularza($wiadomosc);
        $dane = ['status' => ContactMessage::STATUS_ZALATWIONA, 'handler_note' => 'Raz.', 'wersja' => $wersja];

        $this->post(route('admin.contact.update', $wiadomosc), $dane)->assertSessionHasNoErrors();
        $this->post(route('admin.contact.update', $wiadomosc), $dane)
            ->assertRedirect(route('admin.contact.show', $wiadomosc))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Zapisano: Załatwiona.');
    }

    public function test_niezmieniony_rekord_zapisuje_sie_normalnie(): void
    {
        $this->actingAs($this->moderator());
        $wiadomosc = ContactMessage::factory()->create();

        $this->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_W_TOKU,
            'handler_note' => 'Sprawdzam.',
            'wersja' => $this->wersjaZFormularza($wiadomosc),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Sprawdzam.', $wiadomosc->refresh()->handler_note);
    }

    public function test_zmiana_innej_wiadomosci_nie_blokuje_zapisu(): void
    {
        $this->actingAs($this->moderator());
        $a = ContactMessage::factory()->create();
        $b = ContactMessage::factory()->create();
        $wersjaA = $this->wersjaZFormularza($a);
        $wersjaB = $this->wersjaZFormularza($b);

        $this->post(route('admin.contact.update', $a), [
            'status' => ContactMessage::STATUS_ZALATWIONA, 'handler_note' => 'A.', 'wersja' => $wersjaA,
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.contact.update', $b), [
            'status' => ContactMessage::STATUS_W_TOKU, 'handler_note' => 'B.', 'wersja' => $wersjaB,
        ])->assertSessionHasNoErrors();

        $this->assertSame('A.', $a->refresh()->handler_note);
        $this->assertSame('B.', $b->refresh()->handler_note);
    }

    public function test_bez_uprawnien_404_i_nic_sie_nie_zmienia(): void
    {
        $wiadomosc = ContactMessage::factory()->create();
        $przed = $wiadomosc->refresh()->getRawOriginal();

        $this->actingAs($this->user('basia'))
            ->post(route('admin.contact.update', $wiadomosc), [
                'status' => ContactMessage::STATUS_ZALATWIONA,
                'handler_note' => 'Obca.',
                'wersja' => $wiadomosc->wersjaObslugi(),
            ])->assertNotFound();

        $this->assertSame($przed, $wiadomosc->refresh()->getRawOriginal());
    }
}
