<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * Wygasłe żetony resetu hasła (audyt B5, znalezisko 6).
 *
 * `password_reset_tokens` jest kluczowana adresem e-mail zapisanym jawnie.
 * Żeton przestaje działać po `auth.passwords.users.expire` minutach sam,
 * ale wiersz prośby, z której nikt nie skorzystał, zostawał w bazie i w kopiach
 * bez terminu. To samo, co robi `auth:clear-resets` z Laravela — pod własną
 * nazwą, bo harmonogram przyjmuje wyłącznie komendy `kuking:*` przez
 * `Harmonogram::artisan()` (`HarmonogramSprawdzaKodWyjsciaTest`).
 */
class SprzatajResetyHasel extends Command
{
    protected $signature = 'kuking:sprzataj-resety-hasel';

    protected $description = 'Kasuje wygasłe żetony resetu hasła (wiersze z adresem e-mail) — audyt B5.';

    public function handle(): int
    {
        Password::broker((string) config('auth.defaults.passwords'))->getRepository()->deleteExpired();

        $this->info('Wygasłe żetony resetu hasła skasowane.');

        return self::SUCCESS;
    }
}
