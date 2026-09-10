<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Support\AdresEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wystawienie i wysłanie jednorazowego linku do zalogowania (issue #25, D-056).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KLASA NIE ODPOWIADA CZŁOWIEKOWI — I TO JEST JEJ NAJWAŻNIEJSZA CECHA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `handle()` oddaje `true` albo `false` WYŁĄCZNIE po to, żeby kontroler
 * wiedział, czy zajął jeden list z dobowego budżetu. Ta wartość NIE MA PRAWA
 * dotrzeć na ekran ani wpłynąć na treść odpowiedzi: komunikat po wysłaniu
 * formularza musi być identyczny dla adresu, na którym jest konto, i dla
 * adresu, na którym go nie ma. Inaczej formularz staje się wyrocznią „kto ma
 * konto w Kuking" — a serwis, w którym da się sprawdzić, czy sąsiadka tu
 * gotuje, nie jest bezpieczną izbą (`docs/product/SOUL.md`, filar czwarty;
 * ta sama zasada co w `PasswordResetController` i przy zmianie adresu, D-048).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KOMU LINKU NIE WYSYŁAMY (i nie mówimy o tym pytającemu)
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. NA ADRES BEZ KONTA — nie ma dokąd.
 *  2. NA KONTO ZAMKNIĘTE (`STATUSY_ZAMKNIETEGO_KONTA`: zablokowane,
 *     zgłoszone do usunięcia, wymazane). Tam nie wpuszcza także hasło
 *     (`LoginController`), a link, który wchodzi tam, gdzie hasło nie wchodzi,
 *     byłby obejściem blokady moderacyjnej. Osoba zablokowana ma na ekranie
 *     logowania powód i drogę odwoławczą (#10) — i to jest jej droga.
 *  3. NA KONTO MODERATORA ALBO ADMINISTRATORA — wprost z zakresu issue #25:
 *     „konta moderatorów i administratorów wykluczone, tam obowiązuje hasło
 *     + 2FA". To są konta z władzą nad cudzą treścią i cudzymi danymi;
 *     przeniesienie ich bezpieczeństwa na skrzynkę pocztową byłoby
 *     rozluźnieniem, którego `EnsureModeratorHasTwoFactor` nie widzi, bo
 *     tamten middleware pilnuje panelu, a nie wejścia do serwisu.
 *
 * Cisza wobec pytającego jest w każdym z tych trzech przypadków konieczna
 * — inaczej formularz odpowiadałby na pytania „czy tu jest konto",
 * „czy zostało zablokowane" i „czy ta osoba jest moderatorem". Żeby cisza
 * nie zamieniła się w pułapkę, ekran po wysłaniu MÓWI WPROST (dla wszystkich
 * jednakowo), że kont obsługi serwisu ta droga nie obejmuje — czyli moderator
 * czyta wyjaśnienie, nie czekając na list, który nie przyjdzie.
 */
final class WyslijLinkDoLogowania
{
    /**
     * @param  string  $adres  adres e-mail wpisany w formularz, jeszcze
     *                         nieznormalizowany
     * @return bool czy list NAPRAWDĘ poszedł (do rozliczenia budżetu poczty
     *              i tylko do tego — patrz komentarz klasy)
     */
    public function handle(string $adres, ?string $ip = null): bool
    {
        // Higiena danych przy okazji, nie bramka bezpieczeństwa: wygasły
        // token i tak nie działa (`LoginLinkToken::jestWazny()`). Robimy to
        // tutaj, a nie osobną komendą w harmonogramie, bo tabela mieści
        // najwyżej jeden wiersz na konto i żyje minutami — nocne sprzątanie
        // byłoby dla niej narzędziem cięższym niż sprzątany śmieć.
        $this->posprzatajPrzedawnione();

        $user = User::query()
            ->where('email', User::normalizeEmail($adres))
            ->first();

        if (! $this->wolnoWyslac($user)) {
            return false;
        }

        $token = LoginLinkToken::nowyToken();
        $minut = max(1, (int) config('kuking.login_link.waznosc_minut'));

        $wiersz = DB::transaction(function () use ($user, $token, $minut): LoginLinkToken {
            // Kasujemy i zakładamy od nowa, zamiast aktualizować w miejscu.
            // Nowa prośba to nowy token, więc link z poprzedniego listu
            // przestaje działać w tej samej chwili — i o to chodzi
            // (`user_id` jest unikalne, więc bez tego zapis by się odbił).
            LoginLinkToken::query()->where('user_id', $user->getKey())->delete();

            $wiersz = new LoginLinkToken;
            $wiersz->user_id = $user->getKey();
            // W BAZIE LĄDUJE SKRÓT. Token jawny żyje w zmiennej lokalnej
            // i wychodzi wyłącznie do listu.
            $wiersz->token_hash = LoginLinkToken::skrot($token);
            $wiersz->created_at = now();
            $wiersz->expires_at = now()->addMinutes($minut);
            $wiersz->save();

            return $wiersz;
        });

        // Wpis w dzienniku PRZED wysyłką: prośba o wejście na konto jest
        // zdarzeniem bezpieczeństwa (AGENTS.md §7 — piąte z pięciu pytań),
        // a awaria poczty nie może skasować śladu, że ktoś o nie poprosił.
        //
        // BEZ TOKENU I BEZ PEŁNEGO ADRESU (SECURITY_BASELINE §7: dziennik
        // notuje fakt i aktora, nigdy treści ani tokenów). Adres w skrócie
        // wystarcza, żeby przy zgłoszeniu „dostaję listy, o które nie
        // prosiłam" powiedzieć, o którą skrzynkę chodzi.
        AuditLogEntry::record(
            'account.login_link_requested',
            $user,
            $user,
            metadata: ['adres_skrot' => AdresEmail::maska($user->email)],
            ip: $ip,
        );

        /*
         * AWARIA WYSYŁKI NIE MA PRAWA DOTRZEĆ NA EKRAN — I TO JEST WYMÓG
         * BEZPIECZEŃSTWA, NIE UPRZEJMOŚCI.
         *
         * Wyjątek z transportu poczty przewróciłby to żądanie na 500. Ale
         * przewróciłby je WYŁĄCZNIE wtedy, gdy konto istnieje — bo tylko
         * wtedy w ogóle jest co wysyłać. Sam kod odpowiedzi stałby się więc
         * wyrocznią „kto ma konto w Kuking", i to wyrocznią, której nie
         * zasłania żaden wspólny komunikat. Zmierzone w tym repozytorium:
         * przy kolejce `sync` i niedostępnym SMTP adres z kontem oddawał 500,
         * a adres bez konta 302.
         *
         * W produkcji (`QUEUE_CONNECTION=database`) `notify()` tylko odkłada
         * zadanie, więc ten `catch` prawie nigdy nie ma co łapać — nieudana
         * wysyłka ląduje w `failed_jobs` z pełnym powodem (D-047). Ten blok
         * jest zabezpieczeniem na wypadek konfiguracji, w której wysyłka
         * idzie w żądaniu.
         *
         * W DZIENNIKU ZOSTAJE KLASA WYJĄTKU, NIE JEGO KOMUNIKAT (audyt A6-01,
         * `App\Logging\WebhookBleduHandler`): komunikat cudzej biblioteki
         * potrafi nieść adres odbiorcy, a ten dziennik nie jest miejscem na
         * dane osobowe. Pełny powód jest w `failed_jobs` i w
         * `kuking:sprawdz-poczte`.
         */
        try {
            $user->notify(new LinkDoLogowania($token, $wiersz->expires_at));
        } catch (Throwable $e) {
            Log::error('Nie udało się wysłać listu z linkiem do logowania.', [
                'wyjatek' => $e::class,
                'co_dalej' => 'Powód wysyłki szukaj w `failed_jobs` i w `php artisan kuking:sprawdz-poczte`. '
                    .'Odpowiedź dla człowieka jest z założenia taka sama jak przy sukcesie — inaczej sam '
                    .'kod odpowiedzi zdradzałby, czy konto istnieje.',
            ]);
        }

        // `true` także wtedy, gdy wysyłka padła: budżet dobowy liczy PRÓBY
        // opłacone po stronie dostawcy, a odrzucony list też zwykle zajmuje
        // miejsce w puli. Zaniżanie licznika po nieudanej wysyłce kazałoby
        // nam wysłać więcej listów, niż mamy.
        return true;
    }

    /**
     * Wygasłe tokeny znikają z bazy. Publiczne, żeby dało się to wywołać
     * także spoza tej akcji (dziś nikt tego nie robi, ale komenda sprzątająca
     * jest naturalnym kolejnym miejscem, gdyby tabela kiedyś urosła).
     */
    public function posprzatajPrzedawnione(): int
    {
        return LoginLinkToken::query()->where('expires_at', '<', now())->delete();
    }

    private function wolnoWyslac(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            return false;
        }

        // `isModerator()` jest prawdziwe także dla roli `admin` — jedno
        // pytanie zamyka obie role naraz.
        return ! $user->isModerator();
    }
}
