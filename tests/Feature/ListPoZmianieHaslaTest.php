<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\CancelEmailChange;
use App\Domain\Users\Actions\UstawNoweHaslo;
use App\Models\User;
use App\Notifications\PotwierdzenieZmianyHasla;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * List „Hasło do Twojego konta zostało zmienione" (#2565).
 *
 * Jeden list po skutecznej zmianie w ustawieniach i jeden po resecie
 * linkiem; żaden po błędnym formularzu, rollbacku ani dla konta wymazanego.
 * Treść bez tokenu, bez linku logującego, bez IP.
 */
class ListPoZmianieHaslaTest extends TestCase
{
    use RefreshDatabase;

    private const NOWE_HASLO = 'zupelnienowehaslo789';

    private function zmien(User $user): void
    {
        $this->actingAs($user)->put(route('settings.security.password'), [
            'current_password' => 'haslo-testowe-123',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect();
    }

    private function zmienAkcja(User $user): void
    {
        app(UstawNoweHaslo::class)->handle(
            $user,
            self::NOWE_HASLO,
            CancelEmailChange::POWOD_ZMIANA_HASLA,
            obecneHaslo: 'haslo-testowe-123',
            generacjaSesji: (int) $user->session_generation,
        );
    }

    private function listyDo(string $adres): int
    {
        $n = 0;
        Notification::assertSentOnDemand(
            PotwierdzenieZmianyHasla::class,
            function (object $powiadomienie, array $kanaly, AnonymousNotifiable $odbiorca) use ($adres, &$n): bool {
                if (($odbiorca->routes['mail'] ?? null) === $adres) {
                    $n++;
                }

                return true;
            },
        );

        return $n;
    }

    public function test_zmiana_hasla_w_ustawieniach_wysyla_jeden_list_do_wlasciciela(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->zmien($basia);

        Notification::assertSentOnDemandTimes(PotwierdzenieZmianyHasla::class, 1);
        $this->assertSame(1, $this->listyDo('basia@example.test'));
    }

    public function test_reset_linkiem_wysyla_jeden_list_do_wlasciciela(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $token = Password::createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'basia@example.test',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect(route('login'));

        Notification::assertSentOnDemandTimes(PotwierdzenieZmianyHasla::class, 1);
        $this->assertSame(1, $this->listyDo('basia@example.test'));
    }

    public function test_reset_niepotwierdzonego_adresu_tez_wysyla_list(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => null]);
        $token = Password::createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'basia@example.test',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect(route('login'));

        Notification::assertSentOnDemandTimes(PotwierdzenieZmianyHasla::class, 1);
    }

    public function test_zle_obecne_haslo_nie_wysyla_listu(): void
    {
        Notification::fake();
        $basia = $this->user('basia');

        $this->actingAs($basia)->put(route('settings.security.password'), [
            'current_password' => 'zle-haslo-zupelnie',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertSessionHasErrors('current_password');

        Notification::assertNothingSent();
    }

    public function test_zuzyty_token_nie_wysyla_listu(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->post(route('password.update'), [
            'token' => 'nieistniejacy-token',
            'email' => 'basia@example.test',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_rollback_transakcji_nie_wysyla_listu(): void
    {
        Notification::fake();
        $basia = $this->user('basia');

        DB::listen(static function (QueryExecuted $zapytanie): void {
            if (str_starts_with($zapytanie->sql, 'insert into "audit_log"')) {
                throw new RuntimeException('Awaria wstrzyknięta przez test #2565.');
            }
        });

        try {
            $this->zmienAkcja($basia);
            $this->fail('Wstrzyknięta awaria nie dotarła do akcji — test niczego by nie mierzył.');
        } catch (RuntimeException $e) {
            $this->assertSame('Awaria wstrzyknięta przez test #2565.', $e->getMessage());
        }

        Notification::assertNothingSent();
    }

    public function test_wycofanie_transakcji_po_zapisie_hasla_nie_wysyla_listu(): void
    {
        // Akcja kończy się sukcesem, ale otaczająca ją transakcja jest
        // wycofywana — list zamówiony „po zatwierdzeniu" nie może wyjść.
        Notification::fake();
        $basia = $this->user('basia');

        try {
            DB::transaction(function () use ($basia): void {
                $this->zmienAkcja($basia);

                throw new RuntimeException('Wycofanie wywołane przez test #2565.');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Wycofanie wywołane przez test #2565.', $e->getMessage());
        }

        Notification::assertNothingSent();
    }

    public function test_list_idzie_kolejka_i_trafia_na_adres_z_chwili_zlecenia(): void
    {
        config(['mail.default' => 'array']);
        $kolejka = Queue::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->zmien($basia);
        $zadania = $kolejka->pushed(SendQueuedNotifications::class);
        $this->assertCount(1, $zadania);

        // Adres konta zmienia się, zanim worker ruszy — list i tak idzie na utrwalony.
        User::query()->whereKey($basia->getKey())->update(['email' => 'inny@example.test']);
        unserialize(serialize($zadania->first()))->handle(app(ChannelManager::class));

        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $list = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame('basia@example.test', $list->getTo()[0]->getAddress());
        $this->assertSame('Hasło do Twojego konta w Kuking zostało zmienione', $list->getSubject());
    }

    public function test_konto_wymazane_nie_dostaje_listu(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->forceFill(['status' => User::STATUS_ERASED, 'data_erased_at' => now()])->save();

        $this->zmienAkcja($basia);

        Notification::assertNothingSent();
    }

    public function test_konto_zbanowane_dostaje_list_bezpieczenstwa(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->zmienAkcja($basia);

        Notification::assertSentOnDemandTimes(PotwierdzenieZmianyHasla::class, 1);
    }

    public function test_zmiana_w_ustawieniach_z_niepotwierdzonym_adresem_nie_wysyla_listu(): void
    {
        Notification::fake();
        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => null]);

        $this->zmienAkcja($basia);

        Notification::assertNothingSent();
    }

    public function test_tresc_ma_date_instrukcje_i_nie_ma_tokenu_ani_linku_logujacego(): void
    {
        config(['kuking.strefa' => 'Europe/Warsaw']);
        $kiedy = now()->setTimezone('UTC')->setDate(2026, 7, 1)->setTime(10, 30);

        foreach ([PotwierdzenieZmianyHasla::ZMIANA_W_USTAWIENIACH, PotwierdzenieZmianyHasla::RESET_LINKIEM] as $sposob) {
            $html = (string) (new PotwierdzenieZmianyHasla($kiedy, $sposob, 'Basia', null))
                ->toMail(new AnonymousNotifiable)->render();

            $this->assertStringContainsString('1 lipca 2026, 12:30', $html, 'Godzina ma być w strefie kuking.strefa.');
            $this->assertStringContainsString(route('password.request'), $html);
            $this->assertStringContainsString(route('kontakt'), $html);
            $this->assertStringContainsString('Jeśli to nie Twoja decyzja', $html);
            $this->assertStringNotContainsString('/nowe-haslo/', $html, 'W liście nie ma tokenu resetu.');
            $this->assertStringNotContainsString('token', strtolower($html));
            $this->assertStringNotContainsString('signature=', $html, 'W liście nie ma podpisanych linków.');
            $this->assertStringNotContainsString('/logowanie/', $html);
            $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $html, 'Bez adresu IP.');
        }
    }
}
