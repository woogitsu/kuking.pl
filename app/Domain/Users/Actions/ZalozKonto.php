<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ZAŁOŻENIE KONTA — jedno miejsce dla WSZYSTKICH dróg wejścia.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA KLASA POWSTAŁA (issue #258, D-069)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 10 września zakładanie konta żyło w całości w `RegisterController`.
 * Było tam poprawne — i było tam JEDNO. Wejście kontem Google jest DRUGĄ
 * drogą do tego samego skutku, a dwie niezależne kopie tej samej listy
 * czynności to dokładnie ten kształt błędu, przed którym ostrzega
 * AGENTS.md §4: reguła domenowa mieszka w `app/Domain`, żeby nie dało się
 * jej obejść, dodając drugi endpoint.
 *
 * Rzeczy, o których wolno zapomnieć w drugiej kopii, są tu wyliczone i każda
 * z nich jest widoczna gołym okiem, gdy jej zabraknie:
 *
 *  1. profil z nazwą w adresie (bez niego konto nie ma gdzie mieszkać),
 *  2. powitanie w powiadomieniach (pierwsza rzecz, jaką człowiek widzi),
 *  3. `age_confirmed_at` — oświadczenie o wieku, wymóg prawny,
 *  4. `event(new Registered)` — z niego wychodzi wiadomość z potwierdzeniem
 *     adresu,
 *  5. wpis w dzienniku audytu,
 *  6. obserwowanie gospodarza (`docs/product/COLD_START.md`) — bez niego
 *     nowe konto widzi pusty feed, czyli koniec korzystania z serwisu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST RÓŻNE NA DRODZE PRZEZ GOOGLE
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - **hasła nie ma.** `password` jest w bazie `NOT NULL`, więc wpisujemy
 *    tam skrót wartości losowej, której NIKT nie zna — także my. Konto ma
 *    wtedy dwie drogi wejścia: Google i (bo adres jest potwierdzony) link
 *    e-mail. Hasło ustawi sobie przez „Nie pamiętam hasła", jeśli zechce.
 *    Wpisanie tam czegokolwiek przewidywalnego („google", pustego napisu,
 *    stałej) byłoby hasłem wspólnym dla wszystkich takich kont.
 *  - **adres jest od razu potwierdzony**, gdy Google potwierdziło, że
 *    należy do tego człowieka. Wysłanie mu jeszcze naszej wiadomości
 *    „potwierdź adres" byłoby proszeniem o to samo dwa razy — dokładnie ten
 *    sam wywód, który stoi w `User::assignEmail()` przy kliknięciu w link.
 *    Zysk uboczny: ani jeden list z dobowej puli 300 (D-047).
 */
final class ZalozKonto
{
    public function __construct(private readonly FollowUser $followUser) {}

    /**
     * @param  string|null  $haslo  hasło jawne, albo `null` przy drodze bez hasła
     * @param  string|null  $googleSub  identyfikator konta Google, gdy konto powstaje tą drogą
     * @param  array<string, mixed>  $dziennik  dodatkowe pola do wpisu w dzienniku audytu
     */
    public function handle(
        string $email,
        string $displayName,
        string $username,
        ?string $haslo = null,
        bool $emailPotwierdzony = false,
        ?string $googleSub = null,
        ?string $ip = null,
        array $dziennik = [],
    ): User {
        $user = DB::transaction(function () use (
            $email, $displayName, $username, $haslo, $emailPotwierdzony, $googleSub
        ): User {
            // `email` NIE JEST w `$fillable` (issue #195, ten sam powód co
            // `status` i `role`), więc adres wchodzi jawnie, przez
            // `assignEmail()`.
            $user = (new User([
                // BRAK HASŁA TO NIE PUSTE HASŁO — patrz komentarz klasy.
                'password' => Hash::make($haslo ?? Str::random(64)),
                'locale' => 'pl',
                'text_scale' => config('kuking.text.default_scale'),
                'age_confirmed_at' => now(),
            ]))->assignEmail($email, $emailPotwierdzony);

            $user->save();

            // Po `save()`, bo `connectGoogle()` zapisuje wiersz, który musi
            // już istnieć. `google_sub` jest poza `$fillable` i wchodzi
            // wyłącznie tą jawną, nazwaną drogą (AGENTS.md §7).
            if ($googleSub !== null) {
                $user->connectGoogle($googleSub);
            }

            Profile::create([
                'user_id' => $user->getKey(),
                'username' => $username,
                'display_name' => $displayName,
            ]);

            Notification::create([
                'user_id' => $user->getKey(),
                'type' => Notification::TYPE_WELCOME,
                'data' => ['display_name' => $displayName],
            ]);

            return $user;
        });

        /*
         * Z tego zdarzenia wychodzi wiadomość z potwierdzeniem adresu.
         * Konto z adresem JUŻ potwierdzonym (droga Google) nie dostanie jej
         * wcale — laravelowy `SendEmailVerificationNotification` pyta
         * `hasVerifiedEmail()` i milczy. Nie ma tu więc warunku do napisania
         * i nie dokładaj go: byłby drugą kopią tej samej reguły.
         */
        event(new Registered($user));

        AuditLogEntry::record(
            action: 'account.registered',
            actor: $user,
            subject: $user,
            metadata: $dziennik,
            ip: $ip,
        );

        $this->zaobserwujGospodarza($user);

        return $user;
    }

    /**
     * Nowe konto zaczyna obserwować gospodarza (docs/product/COLD_START.md).
     *
     * DLACZEGO POZA TRANSAKCJĄ
     * Rejestracja MUSI się udać. Gdyby konto gospodarza było źle wpisane
     * w konfiguracji, zawieszone albo skasowane, wyjątek wewnątrz transakcji
     * wycofałby całe założenie konta — i człowiek nie miałby gdzie wrócić.
     * Brak jednego obserwowania jest problemem mniejszym o kilka rzędów
     * wielkości niż rejestracja, która się nie udała.
     *
     * DLACZEGO NIE CICHY `catch` NA WSZYSTKO
     * Łapiemy tylko `BladDlaCzlowieka`, który `FollowUser` rzuca świadomie
     * (konto niedostępne, blokada, próba obserwowania samego siebie).
     * Błąd programisty ma dalej wybuchać głośno — a przez pewien czas nie
     * wybuchał: stało tu `RuntimeException`, po którym dziedziczy
     * `PDOException`, więc awaria bazy w tym miejscu była nieodróżnialna
     * od „gospodarz źle wpisany w konfiguracji".
     */
    private function zaobserwujGospodarza(User $user): void
    {
        $nazwa = (string) config('kuking.community.host_username');

        if ($nazwa === '') {
            return;
        }

        $gospodarz = Profile::where('username', $nazwa)->first()?->user;

        if ($gospodarz === null || $gospodarz->getKey() === $user->getKey()) {
            return;
        }

        try {
            $this->followUser->handle($user, $gospodarz);
        } catch (BladDlaCzlowieka) {
            // Gospodarz zawieszony albo źle wpisany w konfiguracji. Rejestracja
            // idzie dalej; feed ratują tematy z onboardingu (#31).
        }
    }
}
