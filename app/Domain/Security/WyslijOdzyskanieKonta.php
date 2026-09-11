<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Support\AdresEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Adres z kontem, którego NIKT NIE POTWIERDZIŁ, dostaje list z ustawieniem
 * nowego hasła — nie link wchodzący na konto (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO SIĘ DZIŚ UDAJE NAPASTNIKOWI I DLACZEGO TO JEST POWAŻNE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Rejestracja w Kuking świadomie NIE wymaga potwierdzenia adresu przed
 * pierwszą publikacją, a `LoginController` o potwierdzenie nie pyta w ogóle.
 * Znaczy to, że konto założone na CUDZY adres jest kontem w pełni sprawnym:
 * da się na nie wejść hasłem, publikować i komentować.
 *
 * Napastnik zakłada więc konto na adres ofiary, hasła zna tylko on, adresu
 * nie potwierdza — bo nie ma jak, poczta idzie do ofiary — i czeka. Ofiara
 * przychodzi do Kuking, wybiera „Wyślij mi link do zalogowania", podaje swój
 * własny adres, dostaje list NA SWOJĄ SKRZYNKĘ i wchodzi. Tylko że wchodzi
 * na KONTO NAPASTNIKA: z jego hasłem, którego on dalej zna, i z jego
 * sesjami, które dalej są otwarte. Od tej chwili wszystko, co ofiara doda —
 * zdjęcia, przepisy rodzinne, komentarze — powstaje na koncie, do którego
 * ktoś obcy ma pełny dostęp.
 *
 * Ofiara nie ma tego czym zauważyć. Konto działa, wchodzi się do niego jej
 * własnym adresem i jej własną pocztą, a jedyne, czego nigdy nie widziała,
 * to ekran zakładania tego konta.
 *
 * To jest przejęcie z wyprzedzeniem („pre-account-hijacking"). Dokładnie ten
 * sam atak zamyka po swojej stronie wejście kontem Google — D-069 reguła 2,
 * `GoogleLoginController` — i właśnie przy sprawdzaniu tamtej reguły wyszło,
 * że logowanie linkiem nie zamyka go po swojej.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE ODMOWA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cztery linijki w `wolnoWyslac()` zamknęłyby drogę napastnikowi i przy
 * okazji OFIERZE — bez słowa wyjaśnienia, bo formularz linku musi odpowiadać
 * identycznie dla adresu z kontem i bez konta (D-056). Człowiek zobaczyłby
 * to samo zdanie co zawsze („wysłaliśmy wiadomość") i nie dostałby niczego.
 *
 * To jest dokładnie ten kształt porażki, który D-085 świadomie usunął po
 * tym, jak odbiła się o niego prawdziwa 63-letnia osoba: cicha ściana.
 * Wariant „odmawiamy i milczymy" wprowadzałby ją z powrotem, tylko dla innej
 * grupy — i co gorsza NIE USUWAŁBY NAPASTNIKA, który zna hasło i dalej
 * wchodzi na konto, kiedy chce.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO AKURAT LIST Z USTAWIENIEM HASŁA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo jest jedyną drogą, która jednocześnie wpuszcza właściciela skrzynki
 * i WYRZUCA NAPASTNIKA — a ma to wszystko już napisane i przetestowane:
 *
 *  1. do jej użycia trzeba mieć dostęp do skrzynki, czyli dokładnie ten
 *     dowód, którego w tym koncie brakuje;
 *  2. ustawienie nowego hasła unieważnia hasło napastnika;
 *  3. `PasswordResetController::reset()` woła `invalidateSessions()`, a ta
 *     kasuje jego sesje ORAZ każdy oczekujący link do logowania;
 *  4. anuluje też zamówioną przez niego zmianę adresu (issue #195).
 *
 * Napastnik nie zyskuje na tym nic: list idzie na skrzynkę, której nie ma.
 * Jeśli sam poprosi o link na założony przez siebie adres, dostanie list,
 * którego nie przeczyta.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO NIE JEST WYROCZNIA „KTO MA KONTO W KUKING"
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo na ekranie nie zmienia się NIC. Ta klasa, tak samo jak
 * `WyslijLinkDoLogowania` i `WyslijZaproszenieDoRejestracji`, oddaje `bool`
 * o jednym znaczeniu: „czy zajęłam jeden list z dobowego budżetu". Wychodzi
 * jeden list, tak jak przy koncie potwierdzonym i tak jak przy adresie bez
 * konta (D-085) — więc budżet zajmuje się jednakowo i nie powstaje różnica,
 * z której dałoby się cokolwiek wyczytać.
 *
 * TO JEST WAŻNIEJSZE, NIŻ WYGLĄDA. Gdyby ta droga kończyła się `false`,
 * kontroler zwolniłby zarezerwowane miejsce w budżecie
 * (`LoginLinkController::send()`), a wtedy adres z kontem niepotwierdzonym
 * zachowywałby się inaczej niż adres z kontem potwierdzonym i inaczej niż
 * adres bez konta. Kto ustawi się na ostatniej jednostce budżetu, wyczytałby
 * z tego jeden bit o cudzym koncie — czyli dokładnie ten kanał, który D-085
 * zamknął dla adresu bez konta.
 *
 * Prawda przenosi się więc do LISTU, a ekran milczy identycznie — ten sam
 * wzorzec, który D-048 przyjęło przy zmianie adresu e-mail: „o kolizji
 * dowiaduje się dopiero ten, kto kliknie odnośnik, czyli osoba czytająca
 * pocztę pod tym adresem, której i tak wolno wiedzieć, że ma u nas konto".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA KLASA NIE ROBI I CO ZOSTAJE DO ROZSTRZYGNIĘCIA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie kasuje treści, którą napastnik zdążył dodać. Po ustawieniu hasła
 * ofiara dostaje konto z cudzymi wpisami i musi je usunąć sama. Czy tak ma
 * zostać, czy konto powinno wracać puste — to jest pytanie produktowe,
 * nie techniczne, i czeka na właściciela (`docs/research/`
 * `2026-09-11-pre-hijacking-logowanie-linkiem.md` §3, wariant B).
 */
final class WyslijOdzyskanieKonta
{
    /**
     * @param  User  $user  konto z `email_verified_at === null`
     * @return bool czy wiadomość NAPRAWDĘ poszła (do rozliczenia budżetu
     *              poczty i tylko do tego — patrz komentarz klasy)
     */
    public function handle(User $user, ?string $ip = null): bool
    {
        // DRUGIE SPRAWDZENIE, ŚWIADOMIE — ta sama konstrukcja co
        // w `WyslijZaproszenieDoRejestracji`. Wołający już wie, że adres
        // jest niepotwierdzony, ale ta klasa jest publiczna i wywoła ją
        // kiedyś ktoś inny. Wysłanie tego listu na konto z POTWIERDZONYM
        // adresem byłoby zamianą wygodnej drogi wejścia na niewygodną
        // bez żadnego powodu.
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        // Wpis w dzienniku PRZED wysyłką, z tego samego powodu co przy
        // linku do logowania: prośba o wejście na konto jest zdarzeniem
        // bezpieczeństwa (AGENTS.md §7), a awaria poczty nie może skasować
        // śladu, że ktoś o nie poprosił.
        //
        // OSOBNA NAZWA ZDARZENIA, nie `account.login_link_requested`. Przy
        // zgłoszeniu „ktoś przejął mi konto" to jest jedyne miejsce, z
        // którego da się odczytać, że ta prośba poszła INNĄ drogą niż
        // zwykle — a bez tego rozróżnienia dziennik mówiłby, że wysłaliśmy
        // link, którego nie wysłaliśmy.
        //
        // BEZ PEŁNEGO ADRESU (SECURITY_BASELINE §7), tak jak wszędzie indziej.
        AuditLogEntry::record(
            'account.unverified_recovery_sent',
            $user,
            $user,
            metadata: ['adres_skrot' => AdresEmail::maska($user->email)],
            ip: $ip,
        );

        /*
         * AWARIA WYSYŁKI NIE MA PRAWA DOTRZEĆ NA EKRAN — dosłownie ten sam
         * wymóg i to samo uzasadnienie co w `WyslijLinkDoLogowania`.
         * Wyjątek z transportu poczty przewróciłby żądanie na 500, a 500
         * zdarzałoby się WYŁĄCZNIE tam, gdzie konto istnieje i jest
         * niepotwierdzone — czyli sam kod odpowiedzi stałby się wyrocznią.
         *
         * W DZIENNIKU ZOSTAJE KLASA WYJĄTKU, NIE JEGO KOMUNIKAT (audyt
         * A6-01): komunikat cudzej biblioteki potrafi nieść adres odbiorcy.
         */
        try {
            // `Password::sendResetLink()` ma własny, wbudowany odstęp między
            // listami (`passwords.users.throttle`). Gdy go odbije, list nie
            // wychodzi — a my i tak oddajemy `true`. To jest świadome:
            // budżet dobowy liczy PRÓBY, a zaniżenie licznika w tym jednym
            // przypadku zrobiłoby z niego wyrocznię (patrz komentarz klasy).
            Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            Log::error('Nie udało się wysłać listu z ustawieniem hasła dla konta bez potwierdzonego adresu.', [
                'wyjatek' => $e::class,
                'co_dalej' => 'Powód wysyłki szukaj w `failed_jobs` i w `php artisan kuking:sprawdz-poczte`. '
                    .'Odpowiedź dla człowieka jest z założenia taka sama jak przy sukcesie — inaczej sam '
                    .'kod odpowiedzi zdradzałby, czy konto istnieje.',
            ]);
        }

        return true;
    }
}
