<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Wyślij wiadomość jeszcze raz" nie może wyczerpać puli poczty (D-246).
 *
 * CO BYŁO ZEPSUTE (audyt bezpieczeństwa 23.09, znalezisko 2)
 * Ponowne wysłanie potwierdzenia adresu stało we wspólnej puli w klasie
 * `wejscie` (próg 0), a jedynym jego limitem było `verification_resend` =
 * 6 na minutę, bez sufitu dobowego. Jedno niepotwierdzone konto zużywało więc
 * całą dobową pulę (300 listów) w około 50 minut, a potem aplikacja odmawiała
 * WSZYSTKIM linków logowania i potwierdzeń rejestracji do końca doby.
 *
 * TRZY TESTY, TRZY RZECZY
 *   (a) jedno konto nie przekracza sufitu dobowego na konto;
 *   (b) ponowienia z WIELU kont razem nie zabierają ostatnich listów
 *       logowania linkiem ani pierwszego potwierdzenia rejestracji;
 *   (c) kontrola dodatnia: przy zdrowej puli rejestracja, link logowania
 *       i ponowienie działają normalnie, a list przy rejestracji nie zjada
 *       przydziału ponowień.
 * Bez poprawki (a) i (b) oblewają — dowód w opisie PR-a.
 *
 * Poczta wyłącznie przez `Notification::fake()`.
 */
class SufitPonowieniaPotwierdzeniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `MAIL_MAILER=array` to dla `App\Support\Poczta` „poczta nie
        // działa" — formularz logowania linkiem chowa się wtedy w całości.
        config(['mail.default' => 'smtp']);

        // Środek doby: dwanaście kliknięć co minutę nie przejdzie przez północ.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
    }

    private function ponow(User $kto): TestResponse
    {
        return $this->actingAs($kto)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'));
    }

    private function komunikat(TestResponse $odpowiedz): string
    {
        return (string) $odpowiedz->getSession()->get('status', '');
    }

    /**
     * (a) JEDNO KONTO KLIKAJĄCE W KÓŁKO.
     *
     * Kliknięcie co 61 sekund mieści się w `verification_resend` (6 na
     * minutę) — dokładnie ten ruch, który przed D-246 wypalał pulę.
     */
    public function test_jedno_konto_nie_przekracza_dobowego_sufitu_ponowien(): void
    {
        Notification::fake();
        config(['kuking.poczta.ponowienie_potwierdzenia_na_dobe' => 5]);

        $basia = $this->user('basia', ['email_verified_at' => null]);

        $ostatnia = null;

        for ($klik = 0; $klik < 12; $klik++) {
            $ostatnia = $this->ponow($basia);
            $this->travel(61)->seconds();
        }

        Notification::assertSentToTimes($basia, PotwierdzenieAdresu::class, 5);

        $this->assertSame(
            5,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'Odmowa sufitu konta zajęła miejsce we wspólnej puli, choć list nie wyszedł.',
        );

        $status = $this->komunikat($ostatnia);

        $this->assertStringNotContainsString('Wysłaliśmy wiadomość jeszcze raz', $status,
            'Ekran obiecuje wiadomość, której nie wysłał.');
        $this->assertStringContainsString('5 dodatkowych wiadomości', $status, 'Komunikat nie mówi, ile już wysłaliśmy.');
        $this->assertStringContainsString('jutro, po północy', $status, 'Komunikat nie mówi, kiedy można znowu.');
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $status,
            'Komunikat nie podaje drogi do człowieka.');

        // Nowa doba — nowy przydział.
        $this->travelTo(Carbon::parse('2026-09-24 00:05:00'));

        $this->assertStringContainsString('Wysłaliśmy wiadomość jeszcze raz', $this->komunikat($this->ponow($basia)));
        Notification::assertSentToTimes($basia, PotwierdzenieAdresu::class, 6);
    }

    /**
     * (b) WIELE KONT NARAZ — ostatnie listy doby zostają dla wejścia.
     *
     * Mała pula w proporcjach produkcyjnych (300 / 240 / 100 / 100 / 0).
     * Każde konto klika RAZ, więc sufit na konto nie ma tu nic do rzeczy —
     * mierzymy wyłącznie klasę `ponowienie` we wspólnej puli.
     */
    public function test_masowe_ponowienia_nie_zabieraja_listow_logowania_linkiem(): void
    {
        Notification::fake();
        config([
            'kuking.poczta.limit_dostawcy_dobowy' => 10,
            'kuking.poczta.progi_wygaszania.podsumowanie' => 8,
            'kuking.poczta.progi_wygaszania.zwykla' => 4,
            'kuking.poczta.progi_wygaszania.ponowienie' => 4,
            'kuking.poczta.progi_wygaszania.wejscie' => 0,
            'kuking.poczta.ponowienie_potwierdzenia_na_dobe' => 5,
            'kuking.login_link.dzienny_budzet' => 1000,
        ]);

        $wyslane = 0;
        $ostatnia = null;

        for ($numer = 0; $numer < 12; $numer++) {
            $konto = $this->user('ponawia_'.$numer, ['email_verified_at' => null]);
            $ostatnia = $this->ponow($konto);

            if (str_contains($this->komunikat($ostatnia), 'Wysłaliśmy wiadomość jeszcze raz')) {
                $wyslane++;
            }
        }

        $this->assertSame(6, $wyslane,
            'Ponowienia z wielu kont zeszły poniżej progu klasy `ponowienie` — zjadły listy zostawione dla wejścia.');
        $this->assertStringContainsString('nie czekaj na nią', $this->komunikat($ostatnia));
        $this->assertStringContainsString('ponowne wysyłki', $this->komunikat($ostatnia),
            'Komunikat mówi o pustej puli, choć zostały listy dla rejestracji i logowania linkiem.');

        // Link logowania — MUSI wyjść.
        $this->app['auth']->logout();
        $this->flushSession();

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => 'basia@example.com'])
            ->assertRedirect(route('login.link'));

        Notification::assertSentTo($basia, LinkDoLogowania::class);

        // Pierwsze potwierdzenie przy rejestracji — też MUSI wyjść.
        $this->post(route('register'), [
            'display_name' => 'Halina',
            'username' => 'halina',
            'email' => 'halina@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $halina = User::query()->where('email', 'halina@example.com')->firstOrFail();
        Notification::assertSentTo($halina, PotwierdzenieAdresu::class);

        $this->assertSame(8, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            '6 ponowień + link logowania + potwierdzenie rejestracji.');
    }

    /**
     * (c) KONTROLA DODATNIA — przy zdrowej puli nic się nie zmienia.
     *
     * Wartości produkcyjne z `config/kuking.php`, bez nadpisań. Pierwszy list
     * przy rejestracji nie liczy się do przydziału ponowień: po rejestracji
     * konto może jeszcze pięć razy poprosić o list.
     */
    public function test_rejestracja_link_logowania_i_ponowienie_dzialaja_normalnie(): void
    {
        Notification::fake();

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => 'basia@example.com'])
            ->assertRedirect(route('login.link'));

        Notification::assertSentTo($basia, LinkDoLogowania::class);

        $this->post(route('register'), [
            'display_name' => 'Halina',
            'username' => 'halina',
            'email' => 'halina@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $halina = User::query()->where('email', 'halina@example.com')->firstOrFail();
        Notification::assertSentToTimes($halina, PotwierdzenieAdresu::class, 1);

        // Domyślna 5 tylko po to, żeby ta kontrola chodziła także na kodzie
        // sprzed D-246 (tam klucza nie ma) — i tam ma PRZECHODZIĆ.
        $sufit = (int) config('kuking.poczta.ponowienie_potwierdzenia_na_dobe', 5);

        for ($klik = 0; $klik < $sufit; $klik++) {
            $this->assertStringContainsString('Wysłaliśmy wiadomość jeszcze raz', $this->komunikat($this->ponow($halina)));
            $this->travel(61)->seconds();
        }

        Notification::assertSentToTimes($halina, PotwierdzenieAdresu::class, 1 + $sufit);
    }
}
