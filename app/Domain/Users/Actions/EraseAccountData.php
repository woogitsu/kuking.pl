<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Egzekucja karencji: trwałe usunięcie/anonimizacja danych osobowych konta
 * po upływie 30 dni od zgłoszenia (audyt A8, RODO art. 17).
 *
 * CO KASUJEMY, A CZEGO ŚWIADOMIE NIE
 * `docs/legal/COMPLIANCE.md` §2 wprost dopuszcza (i zaleca) zachowanie treści
 * o realnej wartości społecznej — przepisów, do których inni się odwoływali,
 * komentarzy w wątkach innych osób — w formie zanonimizowanej („autor: konto
 * usunięte"), pod warunkiem że DANE OSOBOWE autora znikają. Dlatego ta klasa:
 *
 *  - anonimizuje `users` (e-mail, hasło, token) i `profiles` (nazwa, bio,
 *    avatar) — to są dane, po których da się rozpoznać konkretnego człowieka;
 *  - NIE kasuje `posts`, `recipes`, `comments`, `cooked_events` ani innych
 *    treści — te zostają, przypisane do już zanonimizowanego konta. Usuwanie
 *    ich osobno byłoby kasowaniem cudzej historii gotowania (komuś innemu
 *    ktoś kiedyś odpowiedział w komentarzu) bez żadnej korzyści prawnej —
 *    RODO chroni DANE OSOBOWE, nie fakt istnienia wpisu.
 *  - odpina zdjęcie profilowe (`avatar_media_id = null`) — samego pliku
 *    w object storage świadomie NIE kasujemy w tym przebiegu: w repozytorium
 *    nie ma dziś żadnego mechanizmu bezpiecznego usuwania wariantów
 *    thumb/feed/large ani sprawdzania, czy to samo zdjęcie nie jest gdzieś
 *    jeszcze referencjonowane. Zgadywanie tego tutaj groziłoby usunięciem
 *    złego pliku. To realna, świadomie zostawiona luka — patrz raport agenta.
 *
 * IDEMPOTENCJA I ODPORNOŚĆ NA PRZERWANIE
 * Cała anonimizacja jednego konta idzie w JEDNEJ transakcji z `lockForUpdate`
 * na świeżo pobranym wierszu. Dwa niezależne uruchomienia egzekutora (albo
 * jedno przerwane w połowie pętli po wielu kontach) nie psują nic: konto już
 * oznaczone `data_erased_at` jest pomijane (metoda zwraca `false` i nic nie
 * zapisuje), a przerwanie między jednym kontem a drugim zostawia już
 * przetworzone konta w pełni anonimowe, a nieprzetworzone — nietknięte
 * i do podjęcia przy następnym uruchomieniu.
 */
final class EraseAccountData
{
    /** @return bool Prawda, jeśli TO wywołanie faktycznie coś usunęło. */
    public function handle(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            // Świeży odczyt pod blokadą, nie ufamy stanowi z argumentu —
            // między zapytaniem, które wybrało konta do egzekucji, a tym
            // wywołaniem ktoś mógł cofnąć usunięcie albo inny proces mógł
            // już to konto obsłużyć.
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->status !== User::STATUS_PENDING_DELETE
                || $fresh->data_erased_at !== null
            ) {
                return false;
            }

            $profile = $fresh->profile;

            if ($profile !== null) {
                $profile->forceFill([
                    'username' => $this->anonimowaNazwa($fresh),
                    'display_name' => 'Użytkownik usunięty',
                    'bio' => null,
                    'avatar_media_id' => null,
                    'region' => null,
                    'speciality' => null,
                ])->save();
            }

            $fresh->forceFill([
                'email' => $this->anonimowyEmail($fresh),
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'email_verified_at' => null,
                'wants_weekly_digest' => false,
                'data_erased_at' => now(),
            ])->save();

            // Defensywnie: `markForDeletion()` kasuje sesje z innych
            // przeglądarek już przy zgłoszeniu, ale między zgłoszeniem
            // a egzekucją minęło 30 dni — gdyby coś tę pierwszą operację
            // ominęło (np. status zmieniony ręcznie podczas incydentu),
            // egzekutor i tak zamyka wszystkie sesje przy wykonaniu.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $fresh->getKey())
                    ->delete();
            }

            return true;
        });
    }

    /**
     * Deterministyczny, unikalny adres — pochodzi z ID konta (UUID, już
     * unikalne), więc nie trzeba sprawdzać kolizji ani losować niczego.
     */
    private function anonimowyEmail(User $user): string
    {
        return 'usuniete+'.$user->getKey().'@konto.kuking.pl';
    }

    /**
     * Nazwa musi przejść CHECK `^[a-zA-Z0-9_]{3,40}$` (migracja
     * `2026_09_05_000200_create_profiles_table`) i pozostać unikalna —
     * fragment ID konta załatwia obie rzeczy bez losowania.
     */
    private function anonimowaNazwa(User $user): string
    {
        return 'usuniety_'.substr(str_replace('-', '', $user->getKey()), 0, 24);
    }
}
