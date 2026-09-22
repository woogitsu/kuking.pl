<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Zgrubny adres IP — to, co wolno zapisać do tabeli `sessions` (RZ-01).
 *
 * DLACZEGO TA KLASA ISTNIEJE
 * `sessions.ip_address` trzymał adres JAWNIE, w pełnej postaci, bo tak
 * wygląda domyślny `DatabaseSessionHandler` Laravela. Badanie RZ-01
 * (21.09.2026) ustaliło dwie rzeczy naraz:
 *
 *  1. adres jest tam prawdziwym adresem człowieka, nie adresem infrastruktury
 *     — `App\Http\Middleware\NormalizeForwardedFor` istnieje właśnie po to,
 *     żeby `$request->ip()` przebijał łańcuch Cloudflare → Railway → kontener;
 *  2. **nic tego adresu nie czyta.** Trzy jedyne odwołania do tabeli to dwa
 *     `->delete()` po `user_id` i deklaracja nazwy tabeli w `config/session.php`.
 *     Nie ma wykrywania przejęcia sesji ani ekranu „aktywne urządzenia".
 *
 * Czyli: pełna precyzja nie ma dziś ODBIORCY, a ma koszt — kto weźmie zrzut
 * tabeli, dostaje parę (user_id, dokładny adres domowy). `SESSION_ENCRYPT`
 * tego nie zasłania: szyfruje wyłącznie kolumnę `payload`, a `ip_address`
 * i `user_agent` stoją obok jako osobne kolumny.
 *
 * ZGRUBNY ADRES, A NIE `null`
 * Zostaje tyle, ile wystarcza przy incydencie na ręczne pytanie „czy te sesje
 * szły z jednego miejsca, czy z pół świata" — a znika to, co wskazuje na
 * jedno łącze. Zerowanie kolumny zamknęłoby tę drogę bez powrotu i jest
 * osobną decyzją (opcja C raportu RZ-01), nie porządkiem przy okazji.
 *
 * ================= CZEGO TA KLASA NIE WOLNO UŻYĆ =================
 * **NIE do kluczy limitera logowania (`App\Support\KluczeLimitow`).**
 * Tam adres wchodzi do HMAC-a jako TOŻSAMOŚĆ miejsca, z którego idą próby.
 * Maskowanie przed HMAC-em zlałoby całą podsieć /24 w jeden koszyk: jedna
 * osoba atakująca wyczerpałaby limit za maksymalnie 254 sąsiadów, a przy
 * CGNAT operatora komórkowego — za znacznie więcej. To nie jest to samo
 * zastosowanie i nie wolno tych dwóch miejsc „ujednolicać".
 * Pilnuje tego `tests/Feature/SesjaZapisujeTylkoZgrubnyAdresTest.php`.
 * =================================================================
 */
final class MaskaAdresuIp
{
    /**
     * Ile najstarszych BAJTÓW adresu IPv6 zostawiamy: 6 bajtów = 48 bitów.
     *
     * DLACZEGO /48, A NIE /64
     * `/64` to w IPv6 z definicji JEDNA sieć lokalna — czyli dokładnie jedno
     * mieszkanie. Obcięcie do `/64` wygląda na maskowanie, a nie maskuje
     * niczego: identyfikuje gospodarstwo domowe równie dobrze, co pełny
     * adres IPv4 przed maskowaniem. To jest cała różnica między „zgrubnie"
     * a „precyzyjnie, tylko krócej zapisane".
     *
     * `/48` to granica, na której operatorzy delegują prefiks do abonenta
     * (RIPE-690: `/48` albo `/56`), więc przy typowym `/56` jeden `/48`
     * obejmuje do 256 abonentów — rząd wielkości ten sam, co IPv4 `/24`
     * z maską ostatniego oktetu. To także maska, którą do anonimizacji IP
     * wybrały narzędzia analityczne (Google Analytics `anonymizeIp`, Matomo),
     * więc nie wymyślamy własnej liczby.
     *
     * UCZCIWA GRANICA: u operatora, który deleguje abonentowi całe `/48`,
     * ta maska nie zabiera nic. Dlatego zamaskowany adres NADAL jest daną
     * osobową, gdy leży obok `user_id` — ta zmiana zmniejsza szkodę przy
     * wycieku, nie znosi obowiązku opisania tego przetwarzania w polityce
     * prywatności.
     */
    private const BAJTOW_IPV6 = 6;

    /**
     * Zgrubny adres albo `null`, gdy na wejściu nie ma adresu.
     *
     * `null` także dla śmiecia, którego nie da się rozpoznać jako adresu:
     * kolumna `varchar(45)` nie jest miejscem na nierozpoznany łańcuch
     * z nagłówka.
     */
    public static function zgrubny(?string $adres): ?string
    {
        if ($adres === null || trim($adres) === '') {
            return null;
        }

        $adres = trim($adres);

        if (filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::bezOstatniegoOktetu($adres);
        }

        if (filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $bajty = inet_pton($adres);

        if ($bajty === false) {
            return null;
        }

        // Adres IPv4 zapisany jako IPv6 (`::ffff:203.0.113.7`) to nadal adres
        // IPv4 i ma dostać maskę IPv4. Bez tego wyjątku obcięcie do 48 bitów
        // zrobiłoby z niego `::`, czyli kolumnę bez żadnej informacji —
        // maskowanie SILNIEJSZE, niż ktokolwiek wybrał, i nie do odróżnienia
        // w zrzucie od błędu zapisu.
        if (str_starts_with($bajty, str_repeat("\x00", 10)."\xff\xff")) {
            $czworka = inet_ntop(substr($bajty, 12));

            return is_string($czworka) ? self::bezOstatniegoOktetu($czworka) : null;
        }

        $obciety = inet_ntop(substr($bajty, 0, self::BAJTOW_IPV6).str_repeat("\x00", 16 - self::BAJTOW_IPV6));

        return is_string($obciety) ? $obciety : null;
    }

    /** IPv4 → `/24`: ostatni oktet na zero, reszta bez zmian. */
    private static function bezOstatniegoOktetu(string $adres): string
    {
        $oktety = explode('.', $adres);
        $oktety[3] = '0';

        return implode('.', $oktety);
    }
}
