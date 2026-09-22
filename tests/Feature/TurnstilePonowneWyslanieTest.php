<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TurnstilePonowneWyslanieTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): iterable
    {
        $forms = [
            'rejestracja' => ['/register', ['display_name' => 'Basia', 'username' => 'basia_testowa', 'email' => 'basia@example.com', 'password' => 'zielonapietruszkarano', 'age_confirmed' => '1', 'terms_accepted' => '1']],
            'logowanie' => ['/login', ['login' => 'basia@example.com', 'password' => 'zielonapietruszkarano']],
            'cofniecie_usuniecia' => ['/cofnij-usuniecie-konta', ['login' => 'basia@example.com', 'password' => 'zielonapietruszkarano']],
            'odzyskanie_hasla' => ['/nie-pamietam-hasla', ['email' => 'basia@example.com']],
            'logowanie_linkiem' => ['/logowanie/link', ['email' => 'basia@example.com']],
            'kontakt' => ['/napisz-do-nas', ['kind' => 'blad', 'message' => 'Nie mogę dodać zdjęcia mojego obiadu.', 'contact_email' => 'basia@example.com']],
            'zgloszenie_nielegalnej_tresci' => ['/zglos-nielegalna-tresc', ['target_url' => 'https://kuking.pl/przepis/rosol', 'reason' => 'copyright', 'illegality_explanation' => 'To mój tekst przepisany bez mojej zgody.', 'notifier_name' => 'Anna Kowalska', 'notifier_email' => 'anna@example.com', 'good_faith' => '1']],
        ];
        foreach ($forms as $place => [$url, $data]) {
            foreach (['brak', 'odmowa', 'tablica', 'za_dlugi'] as $failure) {
                yield "$place/$failure" => [$place, $url, $data, $failure];
            }
        }
    }

    #[DataProvider('forms')]
    public function test_instrukcja_pasuje_do_pol_po_odrzuceniu(string $place, string $url, array $data, string $failure): void
    {
        config(['kuking.turnstile.klucz_publiczny' => 'test-publiczny', 'kuking.turnstile.sekret' => 'test-sekret', 'mail.default' => 'smtp']);
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake([
            KlientTurnstile::ADRES => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
            'https://api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
        $this->assertTrue(Turnstile::dziala($place));
        $payload = $data;
        if ($failure !== 'brak') {
            $payload[Turnstile::POLE] = match ($failure) {
                'tablica' => ['nie-token'],
                'za_dlugi' => str_repeat('x', 4097),
                default => 'odrzucony-token',
            };
        }
        $response = $this->from($url)->post($url, $payload);
        $response->assertRedirect($url);
        $old = session('_old_input');
        $this->assertArrayNotHasKey('password', $old);
        // Pomocniki assertSession* uruchamiają sesję ponownie i zużywają flash.
        $page = $this->followRedirects($response)->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $xpath = new DOMXPath($dom);
        $message = trim($xpath->query('//a[@href="#f-cf-turnstile-response"]')->item(0)?->textContent ?? '');
        $this->assertNotSame('', $message);
        $this->assertStringContainsString(Turnstile::adresKontaktowy(), $message);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"error-summary")]//li')->length);
        $this->assertSame($message, trim($xpath->query('//*[@id="f-cf-turnstile-response"]//span[@class="field-error"]')->item(0)?->textContent ?? ''));
        foreach ($data as $name => $value) {
            $fields = $xpath->query('//*[@name="'.$name.'"]');
            $this->assertGreaterThan(0, $fields->length, $name);
            if ($name === 'password') {
                $this->assertSame('', $fields->item(0)->getAttribute('value'));
                $page->assertDontSee($value);

                continue;
            }
            $this->assertSame($value, $old[$name] ?? null);
            $field = $fields->item(0);
            if (in_array($field->getAttribute('type'), ['radio', 'checkbox'], true)) {
                $checked = $xpath->query('//*[@name="'.$name.'" and @checked]');
                $this->assertSame(1, $checked->length, $name);
                $this->assertSame($value, $checked->item(0)->getAttribute('value'));
            } else {
                $this->assertSame($value, $field->nodeName === 'textarea' ? trim($field->textContent) : $field->getAttribute('value'), $name);
            }
        }
        $this->assertStringContainsString($failure === 'brak' ? 'wczytać sprawdzenia' : 'sprawdzenie mogło wygasnąć', $message);
        $this->assertStringContainsString('wyślij', $message);
        if (isset($data['password'])) {
            $this->assertStringContainsString('wpisz hasło ponownie', $message);
            $this->assertStringContainsString('Pozostałe dane', $message);
            $this->assertLessThan(strpos($message, 'wyślij'), strpos($message, 'wpisz hasło ponownie'));
        } else {
            $this->assertStringNotContainsString('hasło', $message);
        }
        Notification::assertNothingSent();
    }

    public function test_pomiar_obejmuje_wszystkie_siedem_miejsc(): void
    {
        $places = array_unique(array_column(iterator_to_array(self::forms()), 0));
        $this->assertCount(7, $places);
        $this->assertEqualsCanonicalizing(array_keys(Turnstile::miejsca()), array_values($places));
    }
}
