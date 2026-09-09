<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy Cloudflare Turnstile NAPRAWDĘ działa — i na którym formularzu.
 *
 * PO CO TA KLASA ISTNIEJE
 * Bez kluczy Turnstile jest wyłączony i tak ma być: widget się nie renderuje,
 * reguła walidacji nie odpytuje nikogo, żaden formularz się nie psuje. To jest
 * dobre zachowanie domyślne — i jednocześnie dokładnie ten kształt awarii,
 * na który ten projekt nadział się już kilka razy: narzędzie melduje sukces,
 * nie robiąc nic (`MAIL_MAILER=log`, martwy `kuking.media_disk`, limit
 * `upload` niepodpięty do żadnej trasy).
 *
 * Dlatego jedno pytanie („czy Turnstile jest skonfigurowany") ma tu jedną
 * odpowiedź, z której korzystają wszyscy: widget, reguła walidacji
 * i `/health`. Nie ma drugiej flagi „czy captcha działa", bo rozjechałaby się
 * z rzeczywistością pierwszego dnia, w którym ktoś wyczyści klucz w Railway
 * i zapomni o niej — to ta sama zasada, dla której `Support\Poczta` pyta
 * wprost o sterownik poczty, a `Support\Wersja` czyta skrót commita
 * z Railwaya.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie sprawdza, czy klucze są PRAWIDŁOWE — tego z naszej strony nie da się
 * zmierzyć bez odpytania Cloudflare tokenem od prawdziwej osoby. Zły sekret
 * poznajemy dopiero z odpowiedzi `siteverify` (`invalid-input-secret`)
 * i wtedy — świadomie — PRZEPUSZCZAMY formularz i krzyczymy w dzienniku,
 * zamiast zamykać rejestrację przez własny błąd konfiguracji. Patrz
 * `App\Turnstile\KlientTurnstile`.
 */
final class Turnstile
{
    /**
     * Nazwa pola, w którym widget Cloudflare odkłada token. Narzuca ją
     * Turnstile, nie my — dlatego z myślnikami, wbrew resztą pól w tym
     * repozytorium.
     */
    public const POLE = 'cf-turnstile-response';

    /**
     * Górna granica długości tokenu, powyżej której nie pytamy już Cloudflare.
     *
     * Dokumentacja Turnstile mówi o tokenach do 2048 znaków. Zapas jest po to,
     * żeby dłuższy token z przyszłej wersji widgetu nie zaczął nagle odpadać;
     * ograniczenie w ogóle istnieje po to, żeby ktoś nie wysyłał nam megabajta
     * w polu formularza i nie kazał tego przepychać do cudzego API.
     */
    public const MAKSYMALNA_DLUGOSC_TOKENU = 4096;

    /** Czy w ogóle mamy czym sprawdzać: OBA klucze muszą być ustawione. */
    public static function skonfigurowany(): bool
    {
        return self::kluczPubliczny() !== '' && self::sekret() !== '';
    }

    /**
     * Czy Turnstile obowiązuje na tym formularzu — czyli czy mamy klucze
     * ORAZ czy to miejsce jest włączone w `config/kuking.php`.
     *
     * Jedna odpowiedź dla widgetu i dla walidacji. Gdyby te dwie rzeczy
     * pytały osobno, dałoby się dojść do stanu „widget jest, walidacja nie
     * działa" — czyli do ozdoby udającej zabezpieczenie.
     */
    public static function dziala(string $miejsce): bool
    {
        return self::skonfigurowany() && self::miejsceWlaczone($miejsce);
    }

    /**
     * Czy KTÓREKOLWIEK miejsce jest włączone w konfiguracji.
     *
     * Czyta to `/health`: brak kluczy jest błędem konfiguracji tylko wtedy,
     * gdy ktoś o Turnstile poprosił. Świadome wyłączenie wszystkich miejsc
     * jest poprawnym stanem i nie ma o czym krzyczeć.
     */
    public static function ktoresMiejsceWlaczone(): bool
    {
        return in_array(true, array_map(
            static fn (mixed $wlaczone): bool => (bool) $wlaczone,
            self::miejsca(),
        ), true);
    }

    public static function kluczPubliczny(): string
    {
        return trim((string) config('kuking.turnstile.klucz_publiczny'));
    }

    /**
     * NIE POKAZUJ TEGO NIGDZIE. Sekret wychodzi wyłącznie w ciele żądania
     * do `siteverify` — nie do widoku, nie do logu, nie do komunikatu błędu
     * (AGENTS.md §7).
     */
    public static function sekret(): string
    {
        return trim((string) config('kuking.turnstile.sekret'));
    }

    public static function limitCzasu(): int
    {
        return max(1, (int) config('kuking.turnstile.limit_czasu', 4));
    }

    /**
     * Zdanie dla człowieka, gdy token przyszedł i został odrzucony.
     *
     * TRZY RZECZY, KTÓRE TEN KOMUNIKAT MUSI ZROBIĆ (docs/UX_50_PLUS.md):
     *
     *  1. Powiedzieć, CO ZROBIĆ — „wyślij jeszcze raz", nie „captcha failed".
     *  2. NIE kazać odświeżać strony. Najczęstszy powód odrzucenia to
     *     wygaśnięcie sprawdzenia (token Turnstile żyje 5 minut), a to zdarza
     *     się właśnie osobie, która pisała długo. Odświeżenie skasowałoby jej
     *     tekst; ponowne wysłanie tego samego formularza — nie, bo wszystkie
     *     pola wracają przez `old()`, a widget wystawia świeży token.
     *  3. Dać drogę wyjścia, gdy nie pomoże, bo dla tej osoby to jest ślepa
     *     ściana: nie ma pojęcia, co to Turnstile, i nie zgadnie.
     */
    public static function komunikatOdrzucenia(): string
    {
        return 'Nie udało się potwierdzić, że nie jesteś robotem — to sprawdzenie mogło wygasnąć, '
            .'jeśli formularz był otwarty dłuższą chwilę. Twoje dane nie zniknęły: wyślij formularz '
            .'jeszcze raz. Jeśli znowu się nie uda, napisz do nas na '
            .(string) config('kuking.community.contact_email').' — odpisuje człowiek.';
    }

    /**
     * Zdanie DLA WŁAŚCICIELA (dziennik, `/health`, runbook) o tym, czego
     * brakuje. Nie pokazujemy tego użytkownikowi — jego to nie dotyczy.
     */
    public static function komunikatBrakuKluczy(): string
    {
        return 'Turnstile jest włączony w `config/kuking.php`, ale nie ma kluczy: ustaw '
            .'TURNSTILE_SITE_KEY i TURNSTILE_SECRET_KEY (Cloudflare → Turnstile → widget). '
            .'Do tego czasu formularze publiczne chodzą bez tej ochrony — zostają im limity '
            .'zapytań. Instrukcja: docs/infra/DEPLOYMENT_RUNBOOK.md, krok 8A.';
    }

    /** @return array<string, bool> */
    public static function miejsca(): array
    {
        /** @var array<string, mixed> $miejsca */
        $miejsca = (array) config('kuking.turnstile.miejsca', []);

        return array_map(static fn (mixed $wlaczone): bool => (bool) $wlaczone, $miejsca);
    }

    /**
     * Nieznana nazwa miejsca znaczy „wyłączone", a nie „włączone".
     *
     * Literówka w nazwie ma skutkować BRAKIEM widgetu (widać od razu na
     * ekranie), a nie milczącym przepuszczaniem walidacji przy widocznym
     * widgecie — to drugie wyglądałoby na działające.
     */
    private static function miejsceWlaczone(string $miejsce): bool
    {
        return self::miejsca()[$miejsce] ?? false;
    }
}
