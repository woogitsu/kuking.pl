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
     * Zdanie dla człowieka, gdy token PRZYSZEDŁ i został odrzucony —
     * podrobiony, zużyty albo starszy niż pięć minut.
     *
     * TO NIE JEST TEN SAM PRZYPADEK CO BRAK TOKENU i nie wolno im dać
     * wspólnego tekstu (D-050). Tu sprawdzenie zadziałało i powiedziało
     * „nie"; tam sprawdzenie w ogóle się nie wczytało. Człowiek ma zrobić
     * dwie różne rzeczy, więc musi przeczytać dwa różne zdania — wspólny
     * komunikat kazałby połowie osób szukać usterki, której u nich nie ma.
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
        return 'Nie udało się potwierdzić, że formularza nie wypełnia automat — to sprawdzenie mogło wygasnąć, '
            .'jeśli formularz był otwarty dłuższą chwilę. Twoje dane nie zniknęły: wyślij formularz '
            .'jeszcze raz. Jeśli znowu się nie uda, napisz do nas na '
            .self::adresKontaktowy().' — odpisuje człowiek.';
    }

    /**
     * Zdanie dla człowieka, gdy token W OGÓLE NIE PRZYSZEDŁ, a Turnstile na
     * tym formularzu obowiązuje.
     *
     * KOGO TO DOTYCZY I DLACZEGO TEN TEKST W OGÓLE ISTNIEJE
     * Od zmiany w D-050 brak tokenu ODRZUCA wysłanie. Osoba z wyłączonym
     * JavaScriptem zobaczy wcześniej `<noscript>` w widgecie i będzie
     * wiedziała, co się dzieje. Ale jest drugi, znacznie gorszy przypadek:
     * JavaScript JEST włączony, tylko skrypt widgetu się nie dociągnął —
     * słabe łącze, blokada reklam, Cloudflare niedostępny z tej sieci.
     * Wtedy na ekranie NIE MA NIC, czego brakuje, a bez tego zdania człowiek
     * dostaje komunikat o polu, którego nie widzi, i nie ma pojęcia,
     * co zrobić. To jest dokładnie ta cicha utrata użytkownika, przed którą
     * broni cała reszta tej decyzji.
     *
     * KOLEJNOŚĆ RAD JEST CELOWA:
     *  1. „Wyślij jeszcze raz" — bo nieudana walidacja i tak przerysowuje
     *     stronę (z `old()`), więc przy okazji DRUGI RAZ próbuje pobrać
     *     skrypt. Przy chwilowym problemie z siecią to wystarcza i nie
     *     kosztuje ani jednego wpisanego znaku.
     *  2. Dopiero potem JavaScript i blokada reklam — to wymaga grzebania
     *     w ustawieniach i dla części osób jest nie do zrobienia.
     *  3. Na końcu adres e-mail, bo dla kogoś, komu nic nie pomogło, jest
     *     to JEDYNA droga dalej. `/napisz-do-nas` nią nie jest: ten formularz
     *     ma Turnstile tak samo jak ten, na którym człowiek właśnie utknął.
     */
    public static function komunikatBrakuTokenu(): string
    {
        return 'Nie udało się wczytać sprawdzenia „czy to na pewno człowiek" i dlatego nie możemy '
            .'przyjąć tego formularza. Twoje dane nie zniknęły: wyślij go jeszcze raz — zwykle '
            .'za drugim razem sprawdzenie się wczytuje. Jeśli znowu się nie uda, włącz w przeglądarce '
            .'JavaScript i wyłącz na tej stronie blokadę reklam. Gdy nic nie pomaga, napisz do nas na '
            .self::adresKontaktowy().' — odpisuje człowiek i załatwimy to razem.';
    }

    /**
     * Zdanie do `<noscript>` — jedyne, co zobaczy osoba z wyłączonym
     * JavaScriptem, bo widget się u niej nie narysuje.
     *
     * DLACZEGO OSOBNO DLA KAŻDEGO FORMULARZA, A NIE JEDNO „WYMAGANY
     * JAVASCRIPT" NA WSZYSTKIE SZEŚĆ
     * Bo człowiek stoi wtedy przed formularzem, który wygląda na sprawny,
     * i musi się dowiedzieć nie tego, jaka technologia jest wymagana, tylko
     * CZEGO KONKRETNIE NIE DA SIĘ TERAZ ZROBIĆ. „Wymagany JavaScript" nad
     * formularzem odzyskiwania hasła nie mówi mu, że właśnie nie odzyska
     * hasła. Nazwa czynności bierze się z `czynnosc()` — z tego samego
     * miejsca co reszta tekstów, żeby sześć zdań nie zaczęło się rozjeżdżać
     * osobno.
     */
    public static function zdanieBezJavaScriptu(string $miejsce): string
    {
        return 'Do '.self::czynnosc($miejsce).' potrzebny jest włączony JavaScript — bez niego '
            .'nie umiemy sprawdzić, że formularza nie wypełnia automat. Włącz JavaScript '
            .'w ustawieniach przeglądarki i odśwież tę stronę.';
    }

    /**
     * Adres, pod którym siedzi człowiek — droga wyjścia dla kogoś, kto
     * utknął przed formularzem.
     *
     * Ten sam adres w komunikacie odrzucenia, w `<noscript>` i na
     * `/napisz-do-nas`. Jedno źródło, bo trzy kopie adresu e-mail w trzech
     * plikach rozjeżdżają się przy pierwszej zmianie skrzynki.
     */
    public static function adresKontaktowy(): string
    {
        return (string) config('kuking.community.contact_email');
    }

    /**
     * Nazwa czynności, której nie da się wykonać bez sprawdzenia — w dopełniaczu,
     * do wstawienia po „Do ".
     *
     * Nieznane miejsce dostaje zdanie ogólne zamiast wyjątku: brak tekstu
     * dopasowanego do formularza jest usterką redakcyjną, ale pusty ekran
     * albo błąd 500 na formularzu publicznym byłby usterką znacznie gorszą.
     */
    private static function czynnosc(string $miejsce): string
    {
        return match ($miejsce) {
            'rejestracja' => 'założenia konta',
            'logowanie' => 'zalogowania się',
            'odzyskanie_hasla' => 'wysłania linku do nowego hasła',
            'cofniecie_usuniecia' => 'cofnięcia usunięcia konta',
            'kontakt' => 'wysłania do nas wiadomości',
            'zgloszenie_nielegalnej_tresci' => 'wysłania zgłoszenia',
            default => 'wysłania tego formularza',
        };
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
