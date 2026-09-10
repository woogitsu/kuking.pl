<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Skrot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Zaproszenie do założenia konta dla adresu, na którym konta NIE MA (D-067).
 *
 * Powstaje wtedy, gdy ktoś poprosi o „link do zalogowania" dla adresu bez
 * konta — i jest odpowiedzią na prawdziwe zdarzenie: człowiek dostawał zielone
 * „wysłaliśmy wiadomość" i nie dostawał niczego, bo pod tym adresem konta nie
 * było, a ekran świadomie odpowiada identycznie w obu przypadkach (D-056).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZYM TEN WIERSZ JEST, A CZYM NIE JEST
 * ────────────────────────────────────────────────────────────────────────
 *
 * NIE JEST hasłem jednorazowym — nie wpuszcza na żadne konto, bo konta nie
 * ma. Jest DOWODEM DOSTĘPU DO SKRZYNKI: kto kliknął link z tej skrzynki,
 * udowodnił, że jest jej właścicielem, więc adres wchodzi do rejestracji jako
 * już potwierdzony i drugiej wiadomości weryfikacyjnej nie wysyłamy.
 *
 * Stąd termin dłuższy niż przy logowaniu linkiem (24 h kontra 30 min)
 * i uzasadnienie w `config/kuking.php` — poświadczenie jest słabsze, a droga
 * po jego kliknięciu DŁUŻSZA: trzeba jeszcze wymyślić nazwę użytkownika
 * i hasło, i niejeden raz odbić się o walidację.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TOKEN LEŻY WYŁĄCZNIE JAKO SKRÓT
 * ────────────────────────────────────────────────────────────────────────
 *
 * W bazie jest `token_hash` (HMAC-SHA256, `App\Support\Skrot`), a wartość
 * jawna istnieje przez jedno wywołanie `WyslijZaproszenieDoRejestracji`,
 * w którym idzie prosto do wiadomości. CHECK w bazie wymusza kształt skrótu,
 * więc zapisanie tokenu wprost baza odrzuci.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  WIERSZ ZNIKA NA TRZY SPOSOBY I TO JEST PEŁNA LISTA
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. założenie konta — `RegisterController::store()` kasuje wiersz w tej
 *     samej transakcji, w której powstaje konto (jednorazowość);
 *  2. kolejna prośba z tego samego adresu — `email` jest unikalne, więc nowe
 *     zaproszenie unieważnia poprzednie
 *     (`WyslijZaproszenieDoRejestracji`);
 *  3. wygaśnięcie — `kuking:sprzataj-zaproszenia` raz na dobę
 *     (`App\Domain\Compliance\PrzedawnioneZaproszenia`).
 *
 * Sprzątanie NIE JEST bramką bezpieczeństwa: link przestaje działać co do
 * minuty dzięki `jestWazne()`, a nie dzięki `DELETE`. Jest higieną danych —
 * i to ważniejszą niż przy `login_link_tokens`, bo tutaj w wierszu leży adres
 * e-mail osoby, która NIE MA u nas konta i nigdy nie musi mieć.
 *
 * $fillable NIE ISTNIEJE i to jest celowe: wiersz zapisuje wyłącznie
 * `WyslijZaproszenieDoRejestracji`, jawnie, pole po polu. Domyślny
 * `$guarded = ['*']` Eloquenta odrzuca tu każde masowe przypisanie — ta sama
 * zasada co w `LoginLinkToken`, `PendingEmailChange` i co przy
 * `status`/`role` w `User` (AGENTS.md §7).
 *
 * @property string $email
 */
class RegistrationInvite extends Model
{
    use HasUuids;

    protected $table = 'registration_invites';

    /**
     * Tabela ma `created_at`, ale NIE ma `updated_at` — wiersza się nie
     * edytuje. Nowa prośba zastępuje stary wiersz w całości, użycie go kasuje
     * (ten sam wybór co w `LoginLinkToken` i `PendingEmailChange`).
     */
    public const UPDATED_AT = null;

    /**
     * Klucz w sesji, pod którym leży IDENTYFIKATOR przyjętego zaproszenia.
     *
     * DLACZEGO SESJA, A NIE TOKEN W ADRESIE `/register`
     *
     * Bo formularz rejestracji wraca do człowieka wiele razy: pomylona nazwa
     * użytkownika, za krótkie hasło, niezaznaczony haczyk. Token w adresie
     * przeżyłby te powroty tylko wtedy, gdyby siedział w ukrytym polu formularza
     * — czyli byłby przepisywany z żądania do żądania, wyciekał w nagłówku
     * `Referer` i lądował w historii przeglądarki.
     *
     * Sesja daje przy okazji rzecz najważniejszą dla tej funkcji: adres do
     * rejestracji bierze się z WIERSZA W BAZIE wskazanego przez sesję, a nie
     * z pola, które przychodzi z przeglądarki. Podmiana adresu w formularzu
     * jest wtedy niewykonalna, a nie „zablokowana" (`readonly` w HTML jest
     * podpowiedzią dla człowieka, nie zabezpieczeniem).
     *
     * Ta sama sesyjna ścieżka co `logowanie.2fa.user_id` przy drugim składniku
     * logowania.
     */
    public const KLUCZ_SESJI = 'rejestracja.zaproszenie_id';

    /**
     * Długość tokenu w postaci jawnej — tyle samo co przy logowaniu linkiem.
     *
     * 64 znaki z alfabetu `Str::random()` ([A-Za-z0-9]) to około 380 bitów.
     * Wysoka entropia jest warunkiem, pod którym wolno trzymać SZYBKI skrót
     * zamiast bcrypta (patrz migracja).
     */
    public const DLUGOSC_TOKENU = 64;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Nowy token w postaci jawnej — do wstawienia w link i do niczego innego. */
    public static function nowyToken(): string
    {
        return Str::random(self::DLUGOSC_TOKENU);
    }

    /**
     * Skrót tokenu — jedyna postać, w jakiej token wolno gdziekolwiek zapisać.
     *
     * `Skrot::hmac()`, czyli ta sama konstrukcja co przy `login_link_tokens`,
     * `audit_log.ip_hash` i kluczach limitera. Jedno miejsce, bo dwa pomysły
     * na „ten sam skrót" to brak skrótu.
     */
    public static function skrot(string $token): string
    {
        return Skrot::hmac($token);
    }

    /**
     * Wiersz pasujący do tokenu z adresu — albo `null`.
     *
     * Kształt wartości sprawdzamy PRZED pytaniem bazy: link ucięty przez
     * klienta pocztowego albo przepisany z błędem nie ma po co jechać do
     * Postgresa, a i tak ma skończyć się tym samym ekranem co token wygasły.
     */
    public static function znajdzPoTokenie(string $token): ?self
    {
        if (! self::wygladaJakToken($token)) {
            return null;
        }

        return self::query()->where('token_hash', self::skrot($token))->first();
    }

    public static function wygladaJakToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{'.self::DLUGOSC_TOKENU.'}$/', $token) === 1;
    }

    /**
     * Czy to zaproszenie jest jeszcze ważne.
     *
     * Pytamy o to W KODZIE, mimo że przedawnione wiersze i tak sprząta komenda
     * dobowa: sprzątanie jest higieną danych, nie bramką bezpieczeństwa — link
     * ma przestać działać co do minuty. Ten sam podział ról co przy
     * `LoginLinkToken::jestWazny()` i `PendingEmailChange::jestWazne()`.
     */
    public function jestWazne(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
