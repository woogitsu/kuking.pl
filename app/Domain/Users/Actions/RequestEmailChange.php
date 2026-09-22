<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Notifications\PotwierdzenieNowegoAdresu;
use App\Notifications\ZgloszonaZmianaAdresu;
use App\Support\AdresEmail;
use App\Support\AdresKanoniczny;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Zamówienie zmiany adresu e-mail (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TA AKCJA ROBI, A CZEGO NIE ROBI
 * ────────────────────────────────────────────────────────────────────────
 *
 * NIE DOTYKA `users.email`. To jest cały sens issue #195: dopóki nikt nie
 * kliknął w link wysłany na NOWY adres, obowiązuje stary — logowanie
 * i „nie pamiętam hasła" działają tak jak wczoraj. Ta akcja tylko odkłada
 * żądanie i wysyła dwa listy.
 *
 * DWA LISTY, NIE JEDEN, I OBA SĄ KONIECZNE:
 *
 *  - na NOWY adres — z podpisanym linkiem. To jest dowód, że skrzynka
 *    istnieje i należy do tej osoby;
 *  - na STARY adres — ostrzeżenie „ktoś zażądał zmiany". To jest JEDYNE
 *    ostrzeżenie, jakie dostanie człowiek, któremu ktoś właśnie przejmuje
 *    konto z niezablokowanej przeglądarki. Bez niego cała ta droga
 *    zabezpiecza wyłącznie przed pomyłką, a nie przed drugim człowiekiem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ADRES ZAJĘTY PRZEZ INNE KONTO — SPRAWDZAMY GO PÓŹNIEJ, NIE TUTAJ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Tu NIE MA pytania „czy ktoś już ma ten adres". Gdyby było, formularz
 * odpowiadałby na nie różnie dla adresu zajętego i wolnego — czyli stałby
 * się wyrocznią „kto ma konto w Kuking". Zajętość rozstrzyga
 * `ConfirmEmailChange`, w chwili gdy ktoś kliknie w link — a klika go
 * WYŁĄCZNIE ten, kto czyta pocztę pod tym adresem, czyli ktoś, komu i tak
 * wolno o tym koncie wiedzieć. Pełne rozstrzygnięcie: komentarz tamtej klasy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  JEDNO ŻĄDANIE NA KONTO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `pending_email_changes.user_id` jest unikalne, więc kolejne żądanie
 * ZASTĘPUJE poprzednie i unieważnia poprzedni link. To jest własność
 * bezpieczeństwa: inaczej ktoś, kto raz dostał się do cudzej sesji, mógłby
 * zostawić po sobie kilka ważnych linków i wrócić po nie później.
 */
final class RequestEmailChange
{
    public function handle(User $user, string $nowyAdres, ?string $ip = null): PendingEmailChange
    {
        $nowyAdres = User::normalizeEmail($nowyAdres);

        $godzin = max(1, (int) config('kuking.account.email_change_ttl_hours'));

        // TA SAMA KOLEJNOŚĆ BLOKAD CO PRZY POTWIERDZANIU I ANULOWANIU
        // (AUTH-03 / RACE-06). Stawka jest tu mniejsza niż przy tamtych dwóch,
        // bo ten punkt wymaga zalogowania — ale wzorzec był ten sam:
        // `DELETE` + `INSERT` przy `UNIQUE(user_id)` bez blokady konta. Dwa
        // równoległe zamówienia mogły więc oba dojść do `INSERT` i jedno
        // odbić się o unikalność, dając 500 zamiast przewidywalnego
        // „ostatnie zamówienie wygrywa". Pod blokadą jest jednoznacznie.
        $zmiana = ZamekKonta::zablokuj($user, function (?User $swiezy) use ($user, $nowyAdres, $godzin): PendingEmailChange {
            // Kasujemy i zakładamy od nowa, zamiast aktualizować w miejscu.
            // Nowe żądanie to nowy identyfikator, więc podpisany link
            // z poprzedniego listu przestaje wskazywać cokolwiek — a to jest
            // dokładnie to, co ma się stać.
            PendingEmailChange::query()->where('user_id', $user->getKey())->delete();

            $zmiana = new PendingEmailChange;
            $zmiana->user_id = $user->getKey();
            $zmiana->new_email = $nowyAdres;
            $zmiana->created_at = now();
            $zmiana->expires_at = now()->addHours($godzin);
            $zmiana->save();

            return $zmiana;
        });

        // Wpis w dzienniku audytu PRZED wysyłką listów: zmiana adresu jest
        // zdarzeniem bezpieczeństwa (AGENTS.md §7 — piąte z pięciu pytań),
        // a awaria poczty nie może skasować śladu, że ktoś o nią poprosił.
        //
        // W metadanych stoi adres W SKRÓCIE, nie w całości. Dziennik audytu
        // z założenia notuje FAKT i AKTORA, nie treść (`AuditLogEntry`),
        // a pełny adres jest daną osobową, która po potwierdzeniu i tak
        // znajdzie się w `users.email`. Skrót wystarcza, żeby przy zgłoszeniu
        // („nie zamawiałem tego") powiedzieć, dokąd ta zmiana prowadziła.
        AuditLogEntry::record(
            'account.email_change_requested',
            $user,
            $user,
            metadata: ['nowy_adres_skrot' => AdresEmail::maska($nowyAdres)],
            ip: $ip,
        );

        // Na NOWY adres — konta jeszcze do niego nie ma, więc wysyłamy
        // „na adres", nie „do użytkownika" (`Notification::route`). Gdyby
        // list poszedł przez `$user->notify()`, trafiłby na STARY adres,
        // czyli tam, gdzie nikt niczego nie musi potwierdzać.
        Notification::route('mail', $nowyAdres)->notify(new PotwierdzenieNowegoAdresu(
            $this->linkPotwierdzajacy($zmiana),
            $zmiana->expires_at,
            $user->profile?->display_name,
        ));

        // Na STARY adres — zwykłe `notify()`, bo `users.email` jest wciąż
        // stary i taki ma pozostać do potwierdzenia.
        $user->notify(new ZgloszonaZmianaAdresu(
            AdresEmail::maska($nowyAdres),
            $zmiana->expires_at,
        ));

        return $zmiana;
    }

    /**
     * Podpisany link potwierdzający.
     *
     * PODPIS BEZ WŁASNEGO TERMINU WAŻNOŚCI, A TERMIN W WIERSZU — i to jest
     * świadomy wybór, nie przeoczenie.
     *
     * `URL::temporarySignedRoute()` też by tu zadziałało, ale wygaśnięcie
     * pilnowałby wtedy `middleware('signed')`, który na przeterminowany link
     * odpowiada stroną 403 „Ta strona nie jest dla Ciebie". Dla osoby, która
     * kliknęła wczorajszy list, jest to komunikat NIEPRAWDZIWY (strona jest
     * dla niej) i straszący — a AGENTS.md §5 wymaga błędu po polsku, który
     * mówi, CO ZROBIĆ.
     *
     * Dlatego terminu pilnuje `expires_at` w wierszu, sprawdzane przez
     * `ConfirmEmailChange`, które umie odpowiedzieć „ten link już wygasł,
     * zamów zmianę jeszcze raz". Podpis odpowiada wyłącznie za to, za co
     * odpowiadać powinien: że tego adresu nikt nie ułożył sobie sam.
     *
     * Link NIE ŻYJE przez to dłużej. Wiersz znika przy potwierdzeniu,
     * anulowaniu, zmianie hasła, kolejnym żądaniu i przy sprzątaniu — a bez
     * wiersza podpisany adres nie prowadzi już do niczego.
     */
    private function linkPotwierdzajacy(PendingEmailChange $zmiana): string
    {
        // KANONICZNY KORZEŃ, NIE HOST Z ŻĄDANIA (S2, D-071).
        //
        // To jest jedyny link w liście, który powstaje W ŻĄDANIU HTTP —
        // reszta ważnych listów (reset hasła, potwierdzenie adresu, link
        // do logowania) idzie kolejką i host bierze z `APP_URL`, bo worker
        // żadnego żądania nie ma. Tutaj żądanie jest, więc przed tą zmianą
        // host linku brał się z nagłówków: zmierzone `X-Forwarded-Host:
        // attacker.invalid` dawało podpisany link na cudzej domenie. Dla
        // linku potwierdzającego zmianę adresu e-mail znaczy to oddanie
        // ważnego podpisu osobie, która postawiła sobie stronę pod tym
        // hostem.
        return AdresKanoniczny::zbuduj(
            fn (): string => URL::signedRoute('settings.email.confirm', ['zmiana' => $zmiana->getKey()]),
        );
    }
}
