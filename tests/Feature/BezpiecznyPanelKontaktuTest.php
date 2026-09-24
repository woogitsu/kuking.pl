<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Tests\TestCase;

/** Testy HTTP na PostgreSQL; kolejne żądania nie są pomiarem dwóch połączeń. */
class BezpiecznyPanelKontaktuTest extends TestCase
{
    use RefreshDatabase;

    public function test_stara_karta_nie_nadpisuje_nowszego_stanu_i_zachowuje_tekst(): void
    {
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create();
        $url = route('admin.contact.show', $message);
        $this->from($url)->post(route('admin.contact.update', $message), [
            'version' => 0, 'status' => 'done', 'handler_note' => 'Nowsze ustalenia.',
        ])->assertSessionHasNoErrors();
        $this->get($url)->assertOk();
        $page = $this->followingRedirects()->from($url)->post(route('admin.contact.update', $message), [
            'version' => 0, 'status' => 'new', 'handler_note' => 'Tekst ze starej karty.',
        ])->assertOk();
        $this->assertSame('done', $message->refresh()->status);
        $this->assertSame('Nowsze ustalenia.', $message->handler_note);
        $page->assertSee('Wiadomość zmieniła się od otwarcia tej karty.')
            ->assertSee('Nowsze ustalenia.')->assertSee('Tekst ze starej karty.');
        $this->post(route('admin.contact.update', $message), [
            'version' => 1, 'status' => 'done', 'handler_note' => 'Świadome uzgodnienie.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Świadome uzgodnienie.', $message->refresh()->handler_note);
    }

    /**
     * Issue #845. Żądanie składane z WYRENDEROWANEJ karty dokładnie tak, jak
     * składa je przeglądarka: tylko pola zatwierdzonego formularza. Wcześniej
     * test dosyłał ręcznie pola sąsiedniego formularza, których przeglądarka
     * nigdy nie wysłała — i świecił na zielono przy zgubionym szkicu.
     */
    public function test_zapis_stanu_zachowuje_odpowiedz_ale_nie_wysyla_listu(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $url = route('admin.contact.show', $message);
        $karta = $this->karta($url);
        $klucz = $this->pola($karta, 'odpowiedz-formularz', skrypt: false)['reply_key'];
        $this->wpisz($karta, 'odpowiedz-formularz', 'odpowiedz', 'Robocza odpowiedź 845.');
        $this->wpisz($karta, 'stan-wiadomosci', 'status', 'in_progress');

        $this->from($url)->post(route('admin.contact.update', $message), $this->pola($karta, 'stan-wiadomosci'))
            ->assertSessionHasNoErrors()->assertSessionHasInput('odpowiedz', 'Robocza odpowiedź 845.')
            ->assertSessionHasInput('reply_key', $klucz);

        $this->assertSame('in_progress', $message->refresh()->status);
        $this->assertSame(0, $message->odpowiedzi()->count());
        Mail::assertNothingSent();
        $po = $this->karta($url);
        $this->assertSame('Robocza odpowiedź 845.', $this->pola($po, 'odpowiedz-formularz', skrypt: false)['odpowiedz']);
        $this->assertSame($klucz, $this->pola($po, 'odpowiedz-formularz', skrypt: false)['reply_key']);
    }

    /** Bez skryptu kopie nie lecą wcale — pusty `reply_key` nie nadpisze klucza. */
    public function test_zapis_stanu_bez_skryptu_nie_wysyla_pustych_kopii(): void
    {
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $karta = $this->karta(route('admin.contact.show', $message));
        $this->wpisz($karta, 'odpowiedz-formularz', 'odpowiedz', 'Szkic bez skryptu.');

        $pola = $this->pola($karta, 'stan-wiadomosci', skrypt: false);

        $this->assertSame(['_token', 'version', 'status', 'handler_note'], array_keys($pola));
    }

    public function test_wyslanie_odpowiedzi_zachowuje_notatke_ale_jej_nie_zapisuje(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $url = route('admin.contact.show', $message);
        $karta = $this->karta($url);
        $this->wpisz($karta, 'stan-wiadomosci', 'handler_note', 'Robocza notatka 845.');
        $this->wpisz($karta, 'stan-wiadomosci', 'status', 'done');
        $this->wpisz($karta, 'odpowiedz-formularz', 'odpowiedz', 'Odpowiedź.');

        $this->from($url)->post(route('admin.contact.reply', $message), $this->pola($karta, 'odpowiedz-formularz'))
            ->assertSessionHasNoErrors()->assertSessionHasInput('handler_note', 'Robocza notatka 845.')
            ->assertSessionHasInput('status', 'done');

        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
        $this->assertNull($message->refresh()->handler_note);
        $this->assertSame('new', $message->status);
        $po = $this->karta($url);
        $this->assertSame('Robocza notatka 845.', $this->pola($po, 'stan-wiadomosci', skrypt: false)['handler_note']);
        $this->assertSame('done', $this->pola($po, 'stan-wiadomosci', skrypt: false)['status']);
    }

    public function test_ponowiony_post_to_jedna_odpowiedz_i_jeden_list(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $data = ['reply_key' => (string) Str::uuid(), 'odpowiedz' => 'Odpowiedź.'];
        $this->post(route('admin.contact.reply', $message), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.contact.reply', $message), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, $message->odpowiedzi()->count(), 'Ponowienie utworzyło drugi list.');
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
        $data['reply_key'] = (string) Str::uuid();
        $this->post(route('admin.contact.reply', $message), $data)->assertSessionHasNoErrors();
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 2);
    }

    public function test_zerwane_polaczenie_nie_udaje_pewnej_odmowy(): void
    {
        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => 'test-key-12345',
            'services.emaillabs.secret' => 'test-secret-12345',
            'services.emaillabs.smtp_account' => 'test.smtp',
            'services.emaillabs.endpoint' => 'https://example.invalid/mail',
        ]);
        Mail::purge('emaillabs');
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Przerwane połączenie.');
        });
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $url = route('admin.contact.show', $message);
        $data = ['reply_key' => (string) Str::uuid(), 'odpowiedz' => 'Tekst zachowany.'];
        $this->from($url)->post(route('admin.contact.reply', $message), $data)
            ->assertSessionHasInput('odpowiedz', 'Tekst zachowany.')->assertSessionHasErrors('odpowiedz');
        $this->assertSame(ContactMessageReply::STATUS_W_TOKU, $message->odpowiedzi()->sole()->status);
        $this->get($url)->assertSee('Nie wiadomo, czy')->assertDontSee('Ta wiadomość NIE wyszła')
            ->assertDontSee('poczta serwisu odmówiła');
        $this->from($url)->post(route('admin.contact.reply', $message), $data);
        $this->assertSame(1, $calls);
    }

    public function test_zmieniona_odpowiedz_ze_starym_kluczem_ma_droge_do_nowego_listu(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $key = (string) Str::uuid();
        $url = route('admin.contact.show', $message);
        $this->post(route('admin.contact.reply', $message), ['reply_key' => $key, 'odpowiedz' => 'Pierwszy list.'])
            ->assertSessionHasNoErrors();
        $this->get($url)->assertOk();
        $page = $this->followingRedirects()->from($url)->post(route('admin.contact.reply', $message), [
            'reply_key' => $key, 'odpowiedz' => 'Drugi świadomy list.',
        ])->assertOk();
        Mail::assertSent(OdpowiedzNaWiadomosc::class, 1);
        $this->assertSame(1, $message->odpowiedzi()->count());
        $page->assertSee('Ten formularz był już użyty do innej odpowiedzi.')
            ->assertSee('Drugi świadomy list.')->assertSee('Wyślij jako nową odpowiedź');
    }

    public function test_brak_klucza_nie_tworzy_odpowiedzi(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $this->post(route('admin.contact.reply', $message), ['odpowiedz' => 'Nie zgub tekstu.'])
            ->assertSessionHasErrors('reply_key')->assertSessionHasInput('odpowiedz', 'Nie zgub tekstu.');
        $this->assertSame(0, $message->odpowiedzi()->count());
        Mail::assertNothingSent();
    }

    private function karta(string $url): \DOMDocument
    {
        $html = $this->get($url)->assertOk()->getContent();
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return $dom;
    }

    /** Pola, których właścicielem jest formularz `#$id` (potomek albo `form="$id"`). */
    private function wlasne(\DOMDocument $dom, string $id): \DOMNodeList
    {
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query("//form[@id='$id']")->length, "Brak formularza #$id.");

        return $xpath->query("//form[@id='$id']//*[(self::input or self::textarea or self::select) and not(@form)]"
            ."|//*[(self::input or self::textarea or self::select) and @form='$id']");
    }

    /** Wpisanie wartości tak, jak robi to człowiek w polu. */
    private function wpisz(\DOMDocument $dom, string $formularz, string $nazwa, string $wartosc): void
    {
        $trafione = 0;
        foreach ($this->wlasne($dom, $formularz) as $pole) {
            if ($pole->getAttribute('name') !== $nazwa || $pole->hasAttribute('disabled')) {
                continue;
            }
            if ($pole->getAttribute('type') === 'radio') {
                $pole->removeAttribute('checked');
                if ($pole->getAttribute('value') === $wartosc) {
                    $pole->setAttribute('checked', 'checked');
                    $trafione++;
                }
            } elseif ($pole->nodeName === 'textarea') {
                $pole->textContent = $wartosc;
                $trafione++;
            } else {
                $pole->setAttribute('value', $wartosc);
                $trafione++;
            }
        }
        $this->assertSame(1, $trafione, "Nie ma pola $nazwa w #$formularz.");
    }

    /**
     * Lista pól wysłanych przez przeglądarkę (HTML: „constructing the entry
     * list"). Przy `skrypt: true` najpierw to, co robi
     * `resources/js/kopia-sasiedniego-pola.js` na zdarzeniu `submit`:
     * kopia `data-kopia-z` dostaje wartość źródła i traci `disabled`.
     * Logika kopii NIE jest tu wymyślona — test bierze selektory ze znacznika,
     * a sam skrypt ma własny test (`kopia-sasiedniego-pola.test.mjs`).
     */
    private function pola(\DOMDocument $dom, string $id, bool $skrypt = true): array
    {
        $xpath = new \DOMXPath($dom);
        $css = new CssSelectorConverter(true);
        $pola = [];
        foreach ($this->wlasne($dom, $id) as $pole) {
            $wartosc = $pole->nodeName === 'textarea' ? $pole->textContent : $pole->getAttribute('value');
            if ($skrypt && $pole->hasAttribute('data-kopia-z')) {
                $zrodlo = $xpath->query($css->toXPath($pole->getAttribute('data-kopia-z')))->item(0);
                if ($zrodlo !== null) {
                    $pole->removeAttribute('disabled');
                    $wartosc = $zrodlo->nodeName === 'textarea' ? $zrodlo->textContent : $zrodlo->getAttribute('value');
                }
            }
            $typ = $pole->getAttribute('type');
            if ($pole->hasAttribute('disabled') || in_array($typ, ['submit', 'button'], true)
                || (in_array($typ, ['radio', 'checkbox'], true) && ! $pole->hasAttribute('checked'))) {
                continue;
            }
            $pola[$pole->getAttribute('name')] = $wartosc;
        }

        return $pola;
    }
}
