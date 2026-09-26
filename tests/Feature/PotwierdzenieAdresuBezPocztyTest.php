<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ekran „Potwierdź swój adres e-mail” nie obiecuje listu, którego poczta nie
 * wyśle (issue #1335).
 *
 * CO BYŁO ZEPSUTE
 * Ekran bezwarunkowo mówił „Wysłaliśmy wiadomość na…” i radził zajrzeć do
 * „Spamu”, a „Wyślij wiadomość jeszcze raz” kolejkował powiadomienie
 * i odpowiadał „Wysłaliśmy wiadomość jeszcze raz” — także przy
 * `MAIL_MAILER=log`/`array`, gdy żaden list nie wychodzi.
 *
 * Poczta wyłącznie przez `Notification::fake()`.
 */
class PotwierdzenieAdresuBezPocztyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('niedostarczajace')]
    public function test_ekran_nie_obiecuje_listu_gdy_poczta_nie_wysyla(string $sterownik): void
    {
        config(['mail.default' => $sterownik]);
        $basia = $this->user('basia', ['email_verified_at' => null]);

        $this->actingAs($basia)->get(route('verification.notice'))
            ->assertOk()
            ->assertDontSee('Wysłaliśmy wiadomość', false)
            ->assertDontSee('Spam', false)
            ->assertDontSee('Wyślij wiadomość jeszcze raz', false)
            ->assertSee('Wiadomość z potwierdzeniem nie przyjdzie.', false)
            ->assertSee((string) config('kuking.community.contact_email'), false);
    }

    #[DataProvider('niedostarczajace')]
    public function test_ponowienie_nie_obiecuje_i_nie_zamawia_listu_gdy_poczta_nie_wysyla(string $sterownik): void
    {
        Notification::fake();
        config(['mail.default' => $sterownik]);
        $basia = $this->user('basia', ['email_verified_at' => null]);

        $status = (string) $this->actingAs($basia)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->getSession()->get('status', '');

        $this->assertStringNotContainsString('Wysłaliśmy', $status, 'Odpowiedź obiecuje list, który nie wyjdzie.');
        $this->assertStringNotContainsString('Spam', $status, 'Odpowiedź każe szukać listu, którego nie ma.');
        $this->assertStringContainsString('nie przyjdzie', $status);
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $status,
            'Odpowiedź nie podaje drogi do człowieka.');

        Notification::assertNothingSent();
        $this->assertSame(0, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'List, który nie wyjdzie, zajął miejsce we wspólnej puli.');
    }

    public static function niedostarczajace(): array
    {
        return [['log'], ['array']];
    }

    /** Kontrola dodatnia: przy działającym transporcie droga zostaje jak była. */
    public function test_przy_dzialajacej_poczcie_ekran_i_ponowienie_bez_zmian(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $basia = $this->user('basia', ['email_verified_at' => null]);

        $this->actingAs($basia)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Wysłaliśmy wiadomość na', false)
            ->assertSee('Wyślij wiadomość jeszcze raz', false)
            ->assertSee('Zajrzyj do folderu „Spam”', false)
            ->assertDontSee('Wiadomość z potwierdzeniem nie przyjdzie.', false);

        $status = (string) $this->actingAs($basia)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->getSession()->get('status', '');

        $this->assertStringContainsString('Wysłaliśmy wiadomość jeszcze raz', $status);
        Notification::assertSentToTimes($basia, PotwierdzenieAdresu::class, 1);
    }
}
