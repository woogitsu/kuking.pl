<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Providers\PocztaServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    public function test_zapis_stanu_zachowuje_odpowiedz_ale_nie_wysyla_listu(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create();
        $this->post(route('admin.contact.update', $message), [
            'version' => 0, 'status' => 'in_progress', 'handler_note' => 'Sprawdzam.',
            'odpowiedz' => 'Robocza odpowiedź 845.', 'reply_key' => $key = (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertSessionHasInput('odpowiedz', 'Robocza odpowiedź 845.')
            ->assertSessionHasInput('reply_key', $key);
        Mail::assertNothingSent();
    }

    public function test_wyslanie_odpowiedzi_zachowuje_notatke_ale_jej_nie_zapisuje(): void
    {
        Mail::fake();
        $this->actingAs($this->moderator());
        $message = ContactMessage::factory()->create(['contact_email' => 'test@example.test']);
        $this->post(route('admin.contact.reply', $message), [
            'reply_key' => (string) Str::uuid(), 'odpowiedz' => 'Odpowiedź.',
            'version' => 0, 'status' => 'done', 'handler_note' => 'Robocza notatka 845.',
        ])->assertSessionHasNoErrors()->assertSessionHasInput('handler_note', 'Robocza notatka 845.')
            ->assertSessionHasInput('status', 'done');
        $this->assertNull($message->refresh()->handler_note);
        $this->assertSame('new', $message->status);
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
            // Jedyny adres, któremu transport da klucze (#991, D-250); obcy
            // host zatrzymałby wysyłkę przed siecią i test nie mierzyłby
            // zerwanego połączenia. Żądanie i tak przechwytuje `Http::fake`.
            'services.emaillabs.endpoint' => PocztaServiceProvider::ADRES_API,
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
}
