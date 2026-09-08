<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Nadanie roli `moderator` albo `admin` jednemu kontu (D-039).
 *
 * PO CO TO ISTNIEJE — I DLACZEGO DOPIERO TERAZ
 * `User::promoteTo()` i `User::isAdmin()` były w repozytorium od początku
 * i nie miały ANI JEDNEGO wywołania. Żaden seeder nie nadawał roli `admin`,
 * żaden ekran jej nie zmieniał. Rola istniała w kolumnie, w stałej i w dwóch
 * metodach — i nigdzie indziej. Do 8 września nie miało to skutku, bo nic
 * o administratora nie pytało.
 *
 * Zmieniło to D-039: odwołania od decyzji moderacyjnych rozstrzyga wyłącznie
 * administrator (`UserPolicy::resolveAppeals()`). Bez drogi nadania tej roli
 * to zawężenie zamknęłoby odwołania NA GŁUCHO — nie byłoby kto ich zamknąć,
 * a termin z DSA art. 20 biegłby dalej. Ta komenda jest warunkiem tamtej
 * zmiany, nie dodatkiem do niej.
 *
 * ŚWIADOMIE BEZ EKRANU W PRODUKCIE
 * Nadawanie ról przez przeglądarkę znaczyłoby, że przejęcie jednego konta
 * administratora wystarcza, żeby zrobić administratorów z kolejnych. Komenda
 * wymaga dostępu do powłoki produkcyjnej — czyli tego samego poziomu
 * zaufania, jaki i tak trzeba mieć, żeby cokolwiek zmienić w bazie ręcznie.
 * Ten sam wybór co przy `kuking:2fa-wylacz`.
 *
 * `role` NIE JEST w `$fillable` (AGENTS.md §7) i ta komenda tego nie omija:
 * idzie przez `promoteTo()`, czyli przez jawną, nazwaną operację na modelu,
 * która sama sprawdza, czy rola w ogóle istnieje.
 */
class NadajRole extends Command
{
    protected $signature = 'kuking:nadaj-role
                            {login : Adres e-mail albo nazwa użytkownika konta}
                            {rola : user, moderator albo admin}
                            {--tak : Nie pytaj o potwierdzenie}';

    protected $description = 'Nadaje kontu rolę user/moderator/admin — jedyna droga do roli administratora';

    /** @var list<string> */
    private const ROLE = [User::ROLE_USER, User::ROLE_MODERATOR, User::ROLE_ADMIN];

    public function handle(): int
    {
        $rola = (string) $this->argument('rola');

        if (! in_array($rola, self::ROLE, true)) {
            $this->error('Nieznana rola: '.$rola.'. Dozwolone: '.implode(', ', self::ROLE).'.');

            return self::FAILURE;
        }

        $user = User::findByLogin((string) $this->argument('login'));

        if ($user === null) {
            $this->error('Nie znaleziono konta o takim loginie.');

            return self::FAILURE;
        }

        // KONTO MUSI BYĆ CZYNNE. Rola na koncie zablokowanym, skasowanym albo
        // czekającym na usunięcie nie jest uprawnieniem, tylko pułapką: konto
        // i tak nie wejdzie do panelu, a `SELECT` po roli pokaże
        // administratora, którego nie ma.
        if (! $user->isActive()) {
            $this->error("Konto ma status „{$user->status}\", nie „active\" — najpierw przywróć konto, potem nadaj rolę.");

            return self::FAILURE;
        }

        $poprzednia = (string) $user->role;

        if ($poprzednia === $rola) {
            $this->info("To konto już ma rolę „{$rola}\" — nic do zrobienia.");

            return self::SUCCESS;
        }

        if (! $this->wolnoOdebracAdmina($user, $rola)) {
            return self::FAILURE;
        }

        if ($rola === User::ROLE_ADMIN) {
            $this->warn('Administrator rozstrzyga odwołania od decyzji moderacyjnych (D-039) i widzi cały panel moderacji. Nadawaj to świadomie.');
        }

        if (! $this->option('tak') && ! $this->confirm("Zmienić rolę konta {$user->email} z „{$poprzednia}\" na „{$rola}\"?")) {
            $this->info('Anulowano.');

            return self::SUCCESS;
        }

        $user->promoteTo($rola);

        // DZIENNIK ZDARZEŃ, NIE SAM NAPIS NA EKRANIE. Zmiana roli jest
        // „zmianą wysokiego znaczenia" w rozumieniu `docs/DATABASE.md`:
        // decyduje, kto może zamknąć czyjeś odwołanie. `actor` jest pusty,
        // bo komendę uruchamia powłoka, a nie zalogowany człowiek — dlatego
        // źródło stoi wprost w metadanych, żeby wpis nie wyglądał na zmianę
        // znikąd.
        AuditLogEntry::record(
            action: 'user.role_changed',
            subject: $user,
            metadata: [
                'from' => $poprzednia,
                'to' => $rola,
                'source' => 'console:kuking:nadaj-role',
            ],
        );

        $this->info("Rola konta {$user->email} zmieniona z „{$poprzednia}\" na „{$rola}\".");

        if (in_array($rola, [User::ROLE_MODERATOR, User::ROLE_ADMIN], true) && ! $user->hasTwoFactorConfirmed()) {
            $this->warn('To konto NIE MA jeszcze potwierdzonego 2FA, a bez niego panel moderacji go nie wpuści (EnsureModeratorHasTwoFactor). Niech włączy je w ustawieniach.');
        }

        return self::SUCCESS;
    }

    /**
     * Nie zostawiaj serwisu bez administratora.
     *
     * Odebranie roli OSTATNIEMU administratorowi zamyka odwołania tak samo
     * skutecznie, jak zawężenie Policy bez żadnego administratora — z tą
     * różnicą, że tutaj widać to dopiero wtedy, gdy ktoś się odwoła. Komenda
     * odmawia; `--tak` tego NIE omija, bo to nie jest pytanie o wygodę.
     */
    private function wolnoOdebracAdmina(User $user, string $nowaRola): bool
    {
        if ($user->role !== User::ROLE_ADMIN || $nowaRola === User::ROLE_ADMIN) {
            return true;
        }

        $innych = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereKeyNot($user->getKey())
            ->where('status', User::STATUS_ACTIVE)
            ->count();

        if ($innych > 0) {
            return true;
        }

        $this->error('To jest ostatnie czynne konto administratora. Odebranie mu roli zostawi serwis bez nikogo, kto może rozstrzygnąć odwołanie (DSA art. 20). Najpierw nadaj rolę komuś innemu.');

        return false;
    }
}
