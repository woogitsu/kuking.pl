<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\AuditLogEntry;
use App\Models\RegistrationInvite;
use App\Models\User;
use App\Notifications\ZaproszenieDoZalozeniaKonta;
use App\Support\AdresEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Zaproszenie do założenia konta dla adresu, na którym konta NIE MA (D-067).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO — PRAWDZIWE ZDARZENIE, NIE HIPOTEZA
 * ────────────────────────────────────────────────────────────────────────
 *
 * 63-letnia osoba chciała założyć konto, odbiła się o walidację nazwy
 * użytkownika, przeszła na ekran „Wyślij mi link do zalogowania", wpisała swój
 *
 * adres i zobaczyła zielone „Wysłaliśmy wiadomość na e***@gmail.com".
 * Nie wyszło nic — bo pod tym adresem nie było konta, a ten ekran świadomie
 * odpowiada identycznie dla adresu z kontem i bez konta (D-056).
 *
 * Od teraz taki adres dostaje wiadomość z linkiem prowadzącym na dokończenie
 * ZAKŁADANIA KONTA, z adresem wpisanym i już potwierdzonym.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KLASA NIE ODPOWIADA CZŁOWIEKOWI — TAK SAMO JAK WyslijLinkDoLogowania
 * ────────────────────────────────────────────────────────────────────────
 *
 * `handle()` oddaje `true` albo `false` WYŁĄCZNIE po to, żeby wołający
 * wiedział, czy zajął jeden list z dobowego budżetu poczty. Ta wartość NIE MA
 * PRAWA dotrzeć na ekran: komunikat po wysłaniu formularza musi być identyczny
 * dla adresu z kontem i bez konta, i identyczny także wtedy, gdy zaproszenia
 * są dziś wyczerpane albo wyłączone. Inaczej formularz staje się wyrocznią
 * „kto ma konto w Kuking" — a wyrocznią, którą napastnik umie WYWOŁAĆ SAM,
 * wysyłając tyle zaproszeń, ile wchodzi w dobowy sufit.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PRYWATNOŚĆ Z D-056 JEST PO TEJ ZMIANIE MOCNIEJSZA, NIE SŁABSZA
 * ────────────────────────────────────────────────────────────────────────
 *
 * D-056 zapisało znane, przyjęte ryzyko: dobowy budżet zajmował się tylko przy
 * REALNIE wysłanym liście, więc adres bez konta go nie ruszał — a kto ustawił
 * się dokładnie na ostatniej jednostce budżetu, mógł z zachowania formularza
 * wyczytać jeden bit („czy tamten adres ma konto").
 *
 * Po tej zmianie oba przypadki wysyłają wiadomość i oba zajmują ten sam
 * budżet, więc TEJ różnicy nie ma już wcale. Zostaje różnica słabsza i o piętro
 * niżej: sufit zaproszeń jest osobny i niższy, więc na jego granicy adres bez
 * konta przestaje generować wysyłkę wcześniej niż adres z kontem. Ekran jednak
 * milczy o tym identycznie w obu przypadkach, a napastnik nie widzi cudzej
 * skrzynki — całe rozstrzygnięcie jest w D-067.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KIEDY ZAPROSZENIA NIE WYSYŁAMY (i nie mówimy o tym pytającemu)
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. GDY NA ADRESIE JEST KONTO — wtedy idzie link do logowania, nie
 *     zaproszenie. Rozstrzyga to `WyslijLinkDoLogowania`, a ta klasa pyta
 *     jeszcze raz sama, bo jest publiczna i nie wolno jej dać wysłać
 *     zaproszenia na adres z kontem (byłaby to gotowa droga do drugiego konta
 *     na cudzym adresie).
 *  2. GDY REJESTRACJA JEST ZAMKNIĘTA (`account.registration_open`) —
 *     zaproszenie prowadziłoby na ekran 503. Zaproszenie do drzwi, które są
 *     zamknięte, jest gorsze niż jego brak.
 *  3. GDY TA POŁOWA DROGI JEST WYŁĄCZONA
 *     (`login_link.zaproszenia.wlaczone` = false) — powrót do zachowania
 *     z D-056 jedną zmienną środowiskową, bez wycofywania migracji.
 *  4. GDY DOBOWY SUFIT ZAPROSZEŃ JEST WYCZERPANY — patrz
 *     `DziennyBudzetListow::dlaZaproszenDoRejestracji()`.
 */
final class WyslijZaproszenieDoRejestracji
{
    /**
     * @param  string  $adres  adres e-mail wpisany w formularz, jeszcze
     *                         nieznormalizowany
     * @return bool czy wiadomość NAPRAWDĘ poszła (do rozliczenia budżetu
     *              poczty i tylko do tego — patrz komentarz klasy)
     */
    public function handle(string $adres, ?string $ip = null): bool
    {
        if (! self::wlaczone()) {
            return false;
        }

        if (! (bool) config('kuking.account.registration_open')) {
            return false;
        }

        $adres = User::normalizeEmail($adres);

        // DRUGIE SPRAWDZENIE, ŚWIADOMIE. Wołający już wie, że konta nie ma
        // (`WyslijLinkDoLogowania` znalazło `null`), ale ta klasa jest
        // publiczna i wywoła ją kiedyś ktoś inny. Zaproszenie wysłane na adres
        // Z KONTEM byłoby drogą do założenia drugiego konta na cudzym adresie
        // — a to jest dokładnie ta pomyłka, której nie wolno zostawić na
        // umowie między dwiema klasami.
        if (User::query()->where('email', $adres)->exists()) {
            return false;
        }

        $sufit = DziennyBudzetListow::dlaZaproszenDoRejestracji();

        if (! $sufit->jestMiejsce()) {
            return false;
        }

        // Higiena danych przy okazji, nie bramka bezpieczeństwa (wygasłe
        // zaproszenie i tak nie działa — `RegistrationInvite::jestWazne()`).
        // Tabela trzyma adresy e-mail osób BEZ konta, więc kasujemy je tak
        // szybko, jak się da; dobowa komenda `kuking:sprzataj-zaproszenia`
        // jest siatką bezpieczeństwa na dni, w których nikt o nic nie prosi.
        $this->posprzatajPrzedawnione();

        $token = RegistrationInvite::nowyToken();
        $godzin = max(1, (int) config('kuking.login_link.zaproszenia.waznosc_godzin'));

        $zaproszenie = DB::transaction(function () use ($adres, $token, $godzin): RegistrationInvite {
            // Kasujemy i zakładamy od nowa, zamiast aktualizować w miejscu.
            // Nowa prośba to nowe zaproszenie, więc link z poprzedniej
            // wiadomości przestaje działać w tej samej chwili — i o to chodzi
            // (`email` jest unikalne, więc bez tego zapis by się odbił).
            RegistrationInvite::query()->where('email', $adres)->delete();

            $wiersz = new RegistrationInvite;
            $wiersz->email = $adres;
            // W BAZIE LĄDUJE SKRÓT. Token jawny żyje w zmiennej lokalnej
            // i wychodzi wyłącznie do wiadomości.
            $wiersz->token_hash = RegistrationInvite::skrot($token);
            $wiersz->created_at = now();
            $wiersz->expires_at = now()->addHours($godzin);
            $wiersz->save();

            return $wiersz;
        });

        /*
         * WPIS W DZIENNIKU PRZED WYSYŁKĄ — i to jest jedyne miejsce, w którym
         * notujemy adres IP tej prośby.
         *
         * To jest jedyna droga w serwisie, na której NIEZNAJOMY każe nam
         * wysłać wiadomość na dowolny adres. Gdy przyjdzie zgłoszenie
         * „dostaję wiadomości, o które nie prosiłam", musimy umieć powiedzieć,
         * ile ich było i czy szły z jednego miejsca — bez tego wpisu nie
         * umielibyśmy nic.
         *
         * BEZ AKTORA, bo konta nie ma (`actor_id` jest w `audit_log`
         * nullowalny — patrz migracja anonimowych zgłoszeń). BEZ TOKENU
         * i BEZ PEŁNEGO ADRESU (SECURITY_BASELINE §7: dziennik notuje fakt
         * i aktora, nigdy treści ani tokenów); adres w skrócie wystarcza, żeby
         * rozpoznać, o którą skrzynkę chodzi. Adres IP zapisuje się w skrócie
         * sam, w `AuditLogEntry::record()`.
         */
        AuditLogEntry::record(
            'account.registration_invite_sent',
            metadata: ['adres_skrot' => AdresEmail::maska($adres)],
            ip: $ip,
        );

        /*
         * AWARIA WYSYŁKI NIE MA PRAWA DOTRZEĆ NA EKRAN — dokładnie ten sam
         * wymóg i to samo uzasadnienie co w `WyslijLinkDoLogowania`. Wyjątek
         * z transportu poczty przewróciłby żądanie na 500, ale przewróciłby je
         * tylko na JEDNEJ z dwóch dróg — a wtedy sam kod odpowiedzi mówi, czy
         * na tym adresie jest konto.
         *
         * W DZIENNIKU ZOSTAJE KLASA WYJĄTKU, NIE JEGO KOMUNIKAT (audyt A6-01):
         * komunikat cudzej biblioteki potrafi nieść adres odbiorcy, a ten
         * dziennik nie jest miejscem na dane osobowe. ANI SŁOWA O ADRESIE —
         * także w skrócie, bo dziennik serwera czyta się inaczej niż
         * `audit_log`.
         */
        try {
            // `Notification::route('mail', …)`, a nie `$user->notify()` — nie
            // ma użytkownika, do którego dałoby się to wysłać. Ta sama droga
            // co przy potwierdzeniu NOWEGO adresu e-mail (`RequestEmailChange`).
            Notification::route('mail', $adres)
                ->notify(new ZaproszenieDoZalozeniaKonta($token, $zaproszenie->expires_at));
        } catch (Throwable $e) {
            Log::error('Nie udało się wysłać zaproszenia do założenia konta.', [
                'wyjatek' => $e::class,
                'co_dalej' => 'Powód wysyłki szukaj w `failed_jobs` i w `php artisan kuking:sprawdz-poczte`. '
                    .'Odpowiedź dla człowieka jest z założenia taka sama jak przy sukcesie — inaczej sam '
                    .'kod odpowiedzi zdradzałby, czy konto istnieje.',
            ]);
        }

        // Sufit zajmujemy PO wysyłce, tak samo jak budżet dobowy w
        // `LoginLinkController`: gdyby licznik ruszał przy każdym wysłaniu
        // formularza, automat wpisujący adresy wyczerpałby go w kilka minut,
        // nie wysławszy ani jednej wiadomości.
        //
        // `true` także wtedy, gdy wysyłka padła — budżet liczy próby opłacone
        // po stronie dostawcy, a odrzucona wiadomość też zwykle zajmuje
        // miejsce w puli (ta sama zasada co w `WyslijLinkDoLogowania`).
        $sufit->zajmij();

        return true;
    }

    /**
     * Wygasłe zaproszenia znikają z bazy.
     *
     * Publiczne, bo wołają to dwa miejsca: ta akcja (przy okazji, żeby adres
     * nie leżał ani chwili dłużej, niż musi) i dobowa komenda
     * `kuking:sprzataj-zaproszenia` przez `PrzedawnioneZaproszenia` — ta druga
     * jest siatką bezpieczeństwa na dni, w których nikt o nic nie prosi,
     * i tym, co daje polityce prywatności prawo napisać „24 godziny".
     */
    public function posprzatajPrzedawnione(): int
    {
        return RegistrationInvite::query()->where('expires_at', '<', now())->delete();
    }

    private static function wlaczone(): bool
    {
        return (bool) config('kuking.login_link.zaproszenia.wlaczone', false);
    }
}
