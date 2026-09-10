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
}
