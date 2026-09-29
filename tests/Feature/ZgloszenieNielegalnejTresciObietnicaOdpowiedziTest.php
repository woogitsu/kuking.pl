<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Formularz DSA nie obiecuje odpowiedzi tam, gdzie nie ma dokąd jej wysłać (#2222).
 *
 * Wstęp mówił bezwarunkowo „odpiszemy Ci z decyzją”, choć adres e-mail jest
 * dobrowolny, a bez niego nic nie wysyłamy. Teksty są teraz warunkowe:
 * odpowiedź idzie tylko na podany adres, a bez adresu jedynym śladem jest
 * numer sprawy z ekranu potwierdzenia. Zgłoszenie anonimowe nadal przechodzi.
 */
class ZgloszenieNielegalnejTresciObietnicaOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_warunkuje_odpowiedz_adresem_e_mail(): void
    {
        $html = (string) $this->get(route('zglos.nielegalna'))->assertOk()->getContent();

        $this->assertStringContainsString('Jeśli podasz adres e-mail, wyślemy potwierdzenie i decyzję.', $html);
        $this->assertStringContainsString('Bez adresu też sprawdzimy zgłoszenie, ale nie wyślemy żadnej odpowiedzi', $html);
        $this->assertStringContainsString('Bez adresu nie wyślemy odpowiedzi.', $html);

        // Żadnego bezwarunkowego zdania.
        $this->assertStringNotContainsString('odpiszemy Ci z decyzją', $html);
        $this->assertStringNotContainsString('Napiszemy Ci, co postanowiliśmy', $html);
        $this->assertStringNotContainsString('nie damy znać', $html);

        // E-mail nadal jest dobrowolny — pole nie jest wymagane.
        $this->assertDoesNotMatchRegularExpression('/name="notifier_email"[^>]*\brequired\b/', $html);
    }

    public function test_potwierdzenie_zgloszenia_anonimowego_mowi_ze_odpowiedzi_nie_bedzie(): void
    {
        Notification::fake();

        $html = (string) $this->followingRedirects()
            ->post(route('zglos.nielegalna.store'), $this->zgloszenie(['notifier_name' => '', 'notifier_email' => '']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, Report::where('source', Report::SOURCE_LEGAL_NOTICE)->count());
        $numer = (string) Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail()->numer_sprawy;

        $this->assertStringContainsString($numer, $html);
        $this->assertStringContainsString('Jeśli adresu nie podano, nie wyślemy żadnej odpowiedzi', $html);
        $this->assertStringContainsString('jedynym śladem Twojego zgłoszenia', $html);
        $this->assertStringNotContainsString('Napiszemy Ci, co postanowiliśmy', $html);
        Notification::assertNothingSent();
    }

    public function test_potwierdzenie_zgloszenia_z_adresem_dalej_obiecuje_decyzje_na_ten_adres(): void
    {
        Notification::fake();

        $html = (string) $this->followingRedirects()
            ->post(route('zglos.nielegalna.store'), $this->zgloszenie(['notifier_email' => 'anna@kancelaria.example']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Jeśli w zgłoszeniu był adres e-mail, napiszemy tam, co postanowiliśmy', $html);
        $this->assertStringContainsString('wysłaliśmy', $html);
        // Ekran nie powtarza adresu podanego w formularzu.
        $this->assertStringNotContainsString('anna@kancelaria.example', $html);
    }

    /** @return array<string, mixed> */
    private function zgloszenie(array $zmiany): array
    {
        return array_merge([
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ], $zmiany);
    }
}
