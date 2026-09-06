<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Awaryjne wyłączenie 2FA na jednym koncie (issue #12).
 *
 * PO CO TO ISTNIEJE
 * Konto z potwierdzonym 2FA wymaga kodu z telefonu PRZY KAŻDYM logowaniu —
 * to jest cel tej funkcji. Ale to samo oznacza realne ryzyko: ktoś traci
 * telefon I nie ma zapisanych kodów zapasowych (zgubił kartkę, nigdy jej nie
 * zapisał). Serwis nie ma dziś działającego SMTP, więc nie ma „wyślij link
 * odzyskiwania na e-mail" — jedyną drogą powrotu jest ktoś z dostępem do
 * serwera, ręcznie, po weryfikacji tożsamości poza serwisem (telefon, inny
 * ustalony wcześniej kanał).
 *
 * ŚWIADOMIE BEZ ŚCIEŻKI SAMOOBSŁUGOWEJ
 * Samoobsługowe „zresetuj 2FA” zwykłym linkiem w przeglądarce unieważniałoby
 * sens 2FA — każdy, kto ukradnie samo hasło, resetowałby drugi składnik tą
 * samą drogą. Komenda wymaga dostępu do powłoki produkcyjnej, czyli tego
 * samego poziomu zaufania, jaki i tak trzeba mieć, żeby coś w bazie zmienić
 * ręcznie.
 *
 * Komenda used samego `disableTwoFactor()` z modelu — te same reguły co przy
 * zwykłym wyłączeniu z ustawień (sekret, kody zapasowe i licznik powtórzeń
 * czyszczone razem), tylko bez wymogu podania hasła, bo tu decyzję
 * podejmuje operator, nie sam właściciel konta.
 */
class WylaczDwuetapowaWeryfikacje extends Command
{
    protected $signature = 'kuking:2fa-wylacz
                            {login : Adres e-mail albo nazwa użytkownika konta}';

    protected $description = 'Awaryjnie wyłącza 2FA na jednym koncie — do użycia, gdy ktoś stracił telefon i kody zapasowe';

    public function handle(): int
    {
        $user = User::findByLogin((string) $this->argument('login'));

        if ($user === null) {
            $this->error('Nie znaleziono konta o takim loginie.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorConfirmed()) {
            $this->info('To konto nie ma włączonego 2FA — nic do zrobienia.');

            return self::SUCCESS;
        }

        $this->warn('Upewnij się, że tożsamość tej osoby zweryfikowano POZA serwisem (telefon, ustalony wcześniej kanał) — ta komenda nie robi tego za Ciebie.');

        if (! $this->confirm("Wyłączyć 2FA na koncie {$user->email}?")) {
            $this->info('Anulowano.');

            return self::SUCCESS;
        }

        $user->disableTwoFactor();

        $this->info('2FA wyłączone. Osoba może się teraz zalogować samym hasłem — powinna od razu włączyć 2FA ponownie z nowym telefonem.');

        return self::SUCCESS;
    }
}
