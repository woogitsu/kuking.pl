<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Analityka odwiedzin przez Cloudflare Web Analytics — czy działa, pod jakim
 * tokenem i pod jakimi DWOMA adresami (D-092).
 *
 * PO CO TA KLASA ISTNIEJE
 * Z tego samego powodu, dla którego istnieje `App\Support\Turnstile`: jedno
 * pytanie („czy analityka jest włączona") ma mieć jedną odpowiedź, z której
 * korzystają wszyscy — widok wstawiający znacznik `<script>` i polityka
 * bezpieczeństwa, która te same hosty musi dopuścić w nagłówku CSP.
 *
 * Gdyby te dwa miejsca pytały osobno, dałoby się dojść do stanu, w którym
 * skrypt JEST w HTML-u, a CSP go nie przepuszcza. To jest najgorszy możliwy
 * kształt tej awarii: strona wygląda normalnie, w dzienniku serwera nie ma
 * nic, przeglądarka odmawia po cichu w konsoli, a właściciel widzi w panelu
 * Cloudflare zero odwiedzin i nie ma jak zgadnąć dlaczego. Ten projekt złapał
 * już kilka wariantów „narzędzie melduje sukces, nie robiąc nic"
 * (`MAIL_MAILER=log`, martwy `kuking.media_disk`) — ten byłby kolejnym.
 *
 * DLACZEGO NIE NAZYWA SIĘ `Analityka`
 * Bo `App\Domain\Analytics\*` to NASZA własna, serwerowa analityka (jedenaście
 * klas liczących „ile osób ugotowało w tym tygodniu") i dwie rzeczy o tej
 * samej nazwie w dwóch miejscach to gwarancja pomyłki. Ta klasa dotyczy
 * wyłącznie beacona firmy Cloudflare i ma to w nazwie.
 *
 * DLACZEGO DWA HOSTY, A NIE JEDEN — I DLACZEGO TO JEST TU NAJWAŻNIEJSZE
 * Zmierzone przez pobranie i odczytanie `beacon.min.js` (D-092):
 *
 *   - plik pobiera się z `static.cloudflareinsights.com`,
 *   - zdarzenia lecą na `cloudflareinsights.com/cdn-cgi/rum`, czyli na INNY
 *     host (bez `static.`).
 *
 * Przy Plausible, którego ta klasa zastąpiła, oba adresy były tym samym
 * hostem, więc jedna wartość w konfiguracji wystarczała na obie dyrektywy
 * CSP. Tutaj NIE wystarcza i to jest cała pułapka tego wpięcia: dopisanie
 * jednego hosta do `script-src` daje stronę bez usterki, pusty panel
 * i zero śladu w dzienniku.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie sprawdza, czy token jest PRAWDZIWY. Tego z naszej strony zmierzyć się
 * nie da: Cloudflare przyjmuje żądanie i po cichu odrzuca zdarzenie z nieznanym
 * tokenem. Literówkę w `CLOUDFLARE_ANALYTICS_TOKEN` widać wyłącznie po tym,
 * że w panelu nic nie przybywa.
 */
final class AnalitykaCloudflare
{
    /**
     * Czy w HTML-u ma się w ogóle pojawić znacznik analityki.
     *
     * Pusty token znaczy „nie ma analityki" — i to jest stan domyślny
     * lokalnie, w testach i w CI (patrz komentarz przy
     * `kuking.analytics.cloudflare` w `config/kuking.php`).
     */
    public static function wlaczona(): bool
    {
        return self::token() !== '';
    }

    /**
     * Token serwisu z panelu Cloudflare (Web Analytics → Add a site).
     *
     * To NIE jest sekret: stoi w HTML-u każdej strony i tak ma być. Służy
     * wyłącznie do tego, żeby Cloudflare wiedział, czyje to zdarzenie —
     * nie daje dostępu do panelu ani do danych.
     */
    public static function token(): string
    {
        return trim((string) config('kuking.analytics.cloudflare.token'));
    }

    /** Host, z którego POBIERA SIĘ plik beacona — dyrektywa `script-src`. */
    public static function hostSkryptu(): string
    {
        return rtrim(trim((string) config('kuking.analytics.cloudflare.host_skryptu')), '/');
    }

    /**
     * Host, na który beacon WYSYŁA zdarzenia — dyrektywa `connect-src`.
     *
     * Inny niż host skryptu i to jest zmierzone, nie przepisane z pamięci:
     * w `beacon.min.js` stoi `"https://cloudflareinsights.com/cdn-cgi/rum"`
     * jako adres docelowy, a wysyłka idzie przez `navigator.sendBeacon`
     * (z `XMLHttpRequest` jako wariantem zapasowym) — czyli podlega
     * `connect-src`, która w naszej polityce jest wypisana osobno i NIE
     * dziedziczy nic z `default-src 'self'`.
     */
    public static function hostZdarzen(): string
    {
        return rtrim(trim((string) config('kuking.analytics.cloudflare.host_zdarzen')), '/');
    }

    /** Pełny adres pliku z beaconem. */
    public static function adresSkryptu(): string
    {
        return self::hostSkryptu().'/beacon.min.js';
    }

    /**
     * Zawartość atrybutu `data-cf-beacon` — czyli konfiguracja, którą beacon
     * czyta z własnego znacznika.
     *
     * Podajemy WYŁĄCZNIE token. Świadomie nie podajemy pola `version`:
     * zmierzone w skrypcie, obecność `version` przełącza adres zdarzeń na
     * ścieżkę WZGLĘDNĄ (`/cdn-cgi/rum` na naszej własnej domenie, którą
     * przechwytuje proxy Cloudflare przy automatycznym wstrzyknięciu
     * beacona). My znacznik stawiamy sami, więc chcemy adresu bezwzględnego —
     * tego samego, który dopuszczamy w `connect-src`. Gdyby te dwie rzeczy
     * się rozjechały, testy CSP opisywałyby nieprawdę.
     */
    public static function konfiguracjaBeacona(): string
    {
        return (string) json_encode(
            ['token' => self::token()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Czy DOKUMENT PRAWNY nadal obiecuje czytelnikowi tę analitykę.
     *
     * PO CO W OGÓLE PYTAĆ O TO KOD, SKORO TO TYLKO TEKST
     * Bo to jest jedyne pytanie, które odróżnia „właściciel nie chce
     * analityki" od „analityki nie ma, a polityka prywatności mówi, że jest".
     * Pierwsze jest poprawnym stanem serwisu. Drugie jest nieprawdą w
     * dokumencie, na który człowiek nie ma jak spojrzeć od środka: przycisku
     * Google widać brak na ekranie logowania, a braku beacona nie widać
     * NIGDZIE — ani odwiedzającemu, ani właścicielowi, który przecież
     * przeczytał w panelu Cloudflare, że serwis istnieje.
     *
     * Odpowiedzi używa `HealthController::sprawdzAnalityke()`. Zapala sygnał
     * TYLKO przy rozjeździe: dokument obiecuje, a tokenu nie ma.
     *
     * DLACZEGO ZWYKŁY `str_contains`, A NIE PARSOWANIE DOKUMENTU
     * Bo pytanie brzmi „czy nazwa usługi pada w tym dokumencie", a nie „czy
     * zdanie jest twierdzące". Sprytniejszy test treści prawnej oblewałby
     * przy każdej poprawce stylistycznej i skończyłby wyciszony
     * (`docs/PULAPKI_TESTOW.md`). Od strony gramatyki pilnuje tego dokumentu
     * `DokumentyPrawneNieKlamiaTest`, który chodzi po każdym wystąpieniu
     * nazwy w jego własnym zdaniu (D-092).
     *
     * BRAK PLIKU ODDAJE `false`, CZYLI CISZĘ — świadomie. Nieczytelny albo
     * nieistniejący dokument prawny to awaria o własnej, znacznie głośniejszej
     * sygnalizacji: `StaticPageController::markdown()` rzuca wtedy wyjątkiem
     * na trasie `/prywatnosc`, czyli 500 na żywej stronie i dzwonek na
     * `blad_webhook`. Udawanie tutaj, że obietnica stoi, dołożyłoby do tego
     * mylący powód w `/health` („brak tokenu") do sprawy, która z tokenem nie
     * ma nic wspólnego.
     *
     * CZYTAMY PLIK PRZY KAŻDYM WYWOŁANIU, BEZ BUFOROWANIA. To jest kilkadziesiąt
     * kilobajtów z dysku raz na odpytanie `/health` — mniej niż robi sonda
     * zdjęć w tym samym żądaniu (ta ZAPISUJE plik próbny i czyta go z powrotem).
     * Bufor w statycznym polu przeżyłby podmianę konfiguracji w teście i dałby
     * strażnika, który mierzy stan sprzed poprzedniej asercji.
     */
    public static function obiecanaWDokumencie(): bool
    {
        $sciezka = resource_path(self::dokumentObietnicy());

        if (! is_file($sciezka)) {
            return false;
        }

        $tresc = @file_get_contents($sciezka);

        if ($tresc === false) {
            return false;
        }

        return str_contains($tresc, self::frazaObietnicy());
    }

    /** Dokument prawny, w którym stoi obietnica — ścieżka względem `resource_path()`. */
    public static function dokumentObietnicy(): string
    {
        return trim((string) config('kuking.analytics.cloudflare.obietnica.dokument'));
    }

    /** Fraza, której obecność w tym dokumencie czytamy jako obietnicę. */
    public static function frazaObietnicy(): string
    {
        return trim((string) config('kuking.analytics.cloudflare.obietnica.fraza'));
    }

    /**
     * Zdanie DLA WŁAŚCICIELA o tym, czego brakuje — do serwerowego logu,
     * nigdy do publicznej odpowiedzi `/health` (tam idzie sam kod
     * `analityka_bez_tokenu`).
     *
     * Mówi o DWÓCH czynnościach, nie o jednej, i to jest tu najważniejsze.
     * Token bez przestawienia wariantu zbierania danych w panelu Cloudflare
     * daje stan najgorszy z możliwych: skrypt na stronie jest, CSP go
     * przepuszcza, `/health` milczy, polityka prywatności mówi prawdę —
     * a panel dalej świeci zerami, bo wariant „excluding visitor data in the
     * EU" odrzuca ruch z Unii Europejskiej, czyli praktycznie cały nasz.
     * Zmierzyć tego z naszej strony nie da się wcale (Cloudflare przyjmuje
     * zdarzenie i odrzuca je u siebie), więc jedyne, co możemy zrobić, to
     * powiedzieć o tym w tym samym zdaniu, w którym mówimy o tokenie.
     */
    public static function komunikatBrakuTokenu(): string
    {
        return 'Polityka prywatności ('.self::dokumentObietnicy().') obiecuje analitykę '
            .'„'.self::frazaObietnicy().'", ale nie ma tokenu: ustaw CLOUDFLARE_ANALYTICS_TOKEN '
            .'(Cloudflare → Web Analytics → serwis kuking.pl → wartość pola `token` ze znacznika). '
            .'Do tego czasu beacona nie ma w HTML-u, panel jest pusty, a dokument prawny opisuje '
            .'przetwarzanie, którego nie ma. W panelu Cloudflare sprawdź przy okazji DWIE rzeczy, '
            .'bez których sam token nic nie da: wariant zbierania danych musi obejmować Unię '
            .'Europejską, a automatyczne wstrzykiwanie beacona ma być wyłączone. '
            .'Krok po kroku: docs/infra/DEPLOYMENT_RUNBOOK.md, KROK 8F.';
    }
}
