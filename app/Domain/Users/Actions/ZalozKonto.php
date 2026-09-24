<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Community\HostUserResolver;
use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
 *     adresu (po zatwierdzeniu konta — jej awaria wraca w `ZalozoneKonto`,
 *     a nie jako błąd, #1373),
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
 *  - **adres jest od razu potwierdzony WYŁĄCZNIE przy Google**, bo Google
 *    mówi wprost `email_verified: true`. Przy Facebooku takiego pola nie ma
 *    (D-098), więc konto z Facebooka powstaje z adresem NIEPOTWIERDZONYM
 *    i dostaje naszą wiadomość „potwierdź adres" jak każde inne.
 *  - **adres jest od razu potwierdzony**, gdy Google potwierdziło, że
 *    należy do tego człowieka. Wysłanie mu jeszcze naszej wiadomości
 *    „potwierdź adres" byłoby proszeniem o to samo dwa razy — dokładnie ten
 *    sam wywód, który stoi w `User::assignEmail()` przy kliknięciu w link.
 *    Zysk uboczny: ani jeden list z dobowej puli 300 (D-047).
 */
final class ZalozKonto
{
    public function __construct(
        private readonly FollowUser $followUser,
        private readonly HostUserResolver $hostUser,
    ) {}

    /**
     * @param  string|null  $haslo  hasło jawne, albo `null` przy drodze bez hasła
     * @param  string|null  $googleSub  identyfikator konta Google, gdy konto powstaje tą drogą
     * @param  string|null  $facebookId  identyfikator konta Facebooka, gdy konto powstaje tą drogą
     * @param  array<string, mixed>  $dziennik  dodatkowe pola do wpisu w dzienniku audytu
     * @param  (Closure(): string)|null  $dowodAdresu  dowód posiadania skrzynki zużywany W TEJ SAMEJ
     *                                                 transakcji, w której powstaje konto — patrz niżej
     */
    public function handle(
        string $email,
        string $displayName,
        string $username,
        ?string $haslo = null,
        bool $emailPotwierdzony = false,
        ?string $googleSub = null,
        ?string $facebookId = null,
        ?string $ip = null,
        array $dziennik = [],
        ?Closure $dowodAdresu = null,
    ): ZalozoneKonto {
        $user = DB::transaction(function () use (
            $email, $displayName, $username, $haslo, $emailPotwierdzony, $googleSub, $facebookId, $dowodAdresu
        ): User {
            /*
             * DOWÓD POSIADANIA SKRZYNKI ZUŻYWA SIĘ TUTAJ, W TEJ SAMEJ
             * TRANSAKCJI, W KTÓREJ POWSTAJE KONTO (D-085).
             *
             * Dziś ten dowód ma jedną postać: zaproszenie do rejestracji
             * wysłane na adres, na którym nie było konta. Zużywa je
             * `ZaproszenieWSesji::zuzyj()` pod `lockForUpdate()` i oddaje
             * adres Z WIERSZA W BAZIE — nie z pola w formularzu. Gdyby adres
             * brał się z żądania, powstałoby konto z `email_verified_at`
             * ustawionym na adres, którego nikt nigdy nie potwierdził.
             *
             * DLACZEGO DOMKNIĘCIE, A NIE DWA PARAMETRY: zużycie musi być
             * wewnątrz TEJ transakcji, żeby nieważne zaproszenie wycofało
             * całe założenie konta. Wołający nie ma jak tego zagwarantować
             * z zewnątrz — a `zuzyj()` poza transakcją wygląda identycznie
             * i nie blokuje niczego (dlatego samo pyta `wTransakcji()`).
             */
            if ($dowodAdresu !== null) {
                $email = $dowodAdresu();
                $emailPotwierdzony = true;
            }

            // `email` NIE JEST w `$fillable` (issue #195, ten sam powód co
            // `status` i `role`), więc adres wchodzi jawnie, przez
            // `assignEmail()`.
            // `password` NIE JEST w `$fillable` (ta sama zasada co `email`,
            // `status` i `role`), więc hasło wchodzi jawnie, przez
            // `assignPassword()` — które samo liczy skrót.
            $user = (new User([
                'locale' => 'pl',
                'text_scale' => config('kuking.text.default_scale'),
                'age_confirmed_at' => now(),
            ]))
                ->assignEmail($email, $emailPotwierdzony)
                // BRAK HASŁA TO NIE PUSTE HASŁO — patrz komentarz klasy.
                ->assignPassword($haslo ?? Str::random(64));

            $user->save();

            // Po `save()`, bo `connectGoogle()` dokłada wiersz w tabeli
            // `tozsamosci_zewnetrzne` z kluczem obcym na to konto — musi
            // więc już istnieć. `TozsamoscZewnetrzna` ma puste `$fillable`
            // i powiązanie wchodzi wyłącznie tą jawną, nazwaną drogą
            // (AGENTS.md §7, D-098).
            if ($googleSub !== null) {
                $user->connectGoogle($googleSub);
            }

            /*
             * DROGA PRZEZ FACEBOOKA WYGLĄDA TAK SAMO, ALE `$emailPotwierdzony`
             * JEST PRZY NIEJ ZAWSZE `false` — i to jest jedyna, ale
             * fundamentalna różnica (issue #259, D-098).
             *
             * Facebook nie oddaje `email_verified`, więc nie ma czego
             * przeczytać: konto powstaje z adresem niepotwierdzonym
             * i przechodzi naszą zwykłą ścieżkę potwierdzenia, dokładnie jak
             * przy rejestracji hasłem. Pilnuje tego wywołanie w
             * `FacebookLoginController::finish()`, a nie warunek tutaj —
             * bo warunek tutaj byłby drugą kopią tej samej reguły i pierwsza
             * osoba, która doda trzeciego dostawcę, musiałaby ją znaleźć.
             */
            if ($facebookId !== null) {
                $user->connectFacebook($facebookId);
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
         * ────────────────────────────────────────────────────────────────
         *  OD TEGO MIEJSCA KONTO ISTNIEJE — I NIC GO NIE COFNIE (#1373)
         * ────────────────────────────────────────────────────────────────
         *
         * Trzy kroki niżej dzieją się PO `COMMIT`. Wyjątek z któregokolwiek
         * dawał 500 przy koncie, które już jest: człowiek słyszał „nie
         * udało się", a ponowienie odbijało się od zajętego adresu i nazwy.
         * Dlatego każdy z nich ma jawną zasadę: awaria idzie do `report()`
         * z nazwą konta i tego, czego brakuje, a odpowiedź zostaje
         * odpowiedzią udanej rejestracji (D-249). Każdy też stoi w punkcie
         * zapisu — wołana w cudzej transakcji awaria bazy nie zerwie jej
         * (PostgreSQL 25P02).
         *
         * Wpis `account.registered` jest POMOCNICZY, nie część decyzji:
         * autorytatywny ślad założenia konta to wiersz `users` z
         * `created_at` i `age_confirmed_at` (D-249).
         */
        $listNieWyszedl = ! $this->wyslijPotwierdzenie($user);

        AuditLogEntry::recordBezWywracania(
            action: 'account.registered',
            actor: $user,
            subject: $user,
            metadata: $dziennik,
            ip: $ip,
        );

        $this->zaobserwujGospodarza($user);

        return new ZalozoneKonto($user, $listNieWyszedl);
    }

    /**
     * Z `Registered` wychodzi wiadomość z potwierdzeniem adresu.
     *
     * Konto z adresem JUŻ potwierdzonym (droga Google) nie dostanie jej
     * wcale — laravelowy `SendEmailVerificationNotification` pyta
     * `hasVerifiedEmail()` i milczy. Nie ma tu więc warunku do napisania
     * i nie dokładaj go: byłby drugą kopią tej samej reguły.
     *
     * ZASADA PONOWIENIA: nie ponawiamy sami. List zlecony do kolejki pół
     * raza (rezerwacja w puli bez zadania) byłby gorszy niż brak listu,
     * więc punkt zapisu cofa oba naraz, a ponowienie należy do człowieka —
     * „Wyślij potwierdzenie jeszcze raz" w Ustawieniach, które idzie tą samą
     * drogą (`WyslijPotwierdzenieAdresu`). Kontroler mówi mu o tym wprost
     * (`ZalozoneKonto::KOMUNIKAT_BEZ_LISTU`).
     *
     * @return bool `false` = list, który miał wyjść, nie wyszedł
     */
    private function wyslijPotwierdzenie(User $user): bool
    {
        try {
            DB::transaction(fn () => event(new Registered($user)));

            return true;
        } catch (Throwable $awaria) {
            report(new RuntimeException(
                'Konto '.$user->getKey().' jest założone, ale nie wyszło zdarzenie Registered '
                .'(wiadomość z potwierdzeniem adresu) — człowiek dostał polecenie, żeby wysłał ją jeszcze raz.',
                previous: $awaria,
            ));

            // Przy potwierdzonym adresie listu i tak nie miało być — nie
            // mówimy człowiekowi o wiadomości, na którą nie czekał.
            return $user->hasVerifiedEmail();
        }
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
     * DWA RODZAJE AWARII, DWIE DROGI
     * `BladDlaCzlowieka` rzuca `FollowUser` świadomie (konto niedostępne,
     * blokada, próba obserwowania samego siebie) — to stan konfiguracji,
     * nie usterka, i przechodzi po cichu jak dotąd.
     *
     * Każdy inny wyjątek (baza, błąd programisty) NIE jest „gospodarzem źle
     * wpisanym": przez pewien czas był od niego nieodróżnialny, bo stało tu
     * `RuntimeException`, po którym dziedziczy `PDOException`. Dziś idzie do
     * `report()` z nazwą konta — głośno dla operatora — ale nie do
     * człowieka, bo konto jest już zatwierdzone i 500 byłoby kłamstwem
     * (#1373, D-249).
     *
     * ZASADA NAPRAWY: nie ponawiamy sami. Obserwowanie jest idempotentne,
     * więc naprawa to jedno `FollowUser` dla konta z raportu; do tego czasu
     * feed ratują tematy z onboardingu (#31), a gospodarza da się
     * zaobserwować z jego profilu.
     */
    private function zaobserwujGospodarza(User $user): void
    {
        try {
            DB::transaction(function () use ($user): void {
                // Gospodarz rozpoznawany po UUID konta, nie po edytowalnej
                // nazwie profilu (#1089) — jedno źródło: HostUserResolver.
                $gospodarz = $this->hostUser->resolve();

                if ($gospodarz === null || $gospodarz->getKey() === $user->getKey()) {
                    return;
                }

                $this->followUser->handle($user, $gospodarz);
            });
        } catch (BladDlaCzlowieka) {
            // Gospodarz zawieszony albo źle wpisany w konfiguracji. Rejestracja
            // idzie dalej; feed ratują tematy z onboardingu (#31).
        } catch (Throwable $awaria) {
            report(new RuntimeException(
                'Konto '.$user->getKey().' jest założone, ale nie zaczęło obserwować gospodarza '
                .'— to nie jest błąd konfiguracji, tylko awaria; naprawa: FollowUser dla tego konta.',
                previous: $awaria,
            ));
        }
    }
}
