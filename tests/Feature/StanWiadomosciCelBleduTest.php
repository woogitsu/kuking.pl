<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StanWiadomosciCelBleduTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    public static function invalidStatuses(): array
    {
        return [['nieznany'], [null]];
    }

    #[DataProvider('invalidStatuses')]
    public function test_summary_target_exists(?string $status): void
    {
        Mail::fake();
        $m = ContactMessage::factory()->create();
        $before = $m->refresh()->getRawOriginal();
        $url = route('admin.contact.show', $m);
        $html = $this->actingAs($this->moderator())->followingRedirects()->from($url)->post(route('admin.contact.update', $m), ['status' => $status, 'handler_note' => 'Lokalna notatka'])->assertOk()->assertSee('href="#f-status"', false)->getContent();
        $this->assertSame($before, $m->refresh()->getRawOriginal());
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//input[@type="radio" and @name="status" and @id="f-status"]')->length);
        $this->assertSame(1, $xp->query('//*[@id="f-status-error"]')->length);
        $error = $xp->query('//*[@id="f-status-error"]')->item(0);
        $this->assertStringContainsString('Wybierz stan wiadomości.', $error->textContent);
        $radios = $xp->query('//input[@type="radio" and @name="status"]');
        $this->assertCount(count(ContactMessage::STATUSY), $radios);
        foreach ($radios as $radio) {
            $this->assertSame('true', $radio->getAttribute('aria-invalid'));
            $this->assertContains('f-status-error', explode(' ', $radio->getAttribute('aria-describedby')));
            $this->assertSame(1, $xp->query('ancestor::fieldset//*[@id="f-status-error"]', $radio)->length);
        }
        $this->assertSame('Lokalna notatka', $xp->query('//textarea[@name="handler_note"]')->item(0)->textContent);
        Mail::assertNothingOutgoing();
    }

    public function test_valid_state_and_save_do_not_send_mail(): void
    {
        Mail::fake();
        $m = ContactMessage::factory()->create();
        $operator = $this->moderator();
        $url = route('admin.contact.show', $m);
        $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(0, $xp->query('//input[@name="status"][@aria-invalid="true" or @aria-describedby]')->length);
        $this->assertSame(0, $xp->query('//*[@id="f-status-error"]')->length);
        $this->post(route('admin.contact.update', $m), ['status' => ContactMessage::STATUS_W_TOKU, 'handler_note' => 'Sprawdzam'])->assertRedirect($url)->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame(ContactMessage::STATUS_W_TOKU, $m->status);
        $this->assertSame('Sprawdzam', $m->handler_note);
        $this->assertSame($operator->id,$m->handled_by);
        Mail::assertNothingOutgoing();
    }
}
