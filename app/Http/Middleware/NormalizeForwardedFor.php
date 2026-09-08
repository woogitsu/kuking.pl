<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Z `X-Forwarded-For` zostaje JEDEN wpis — ten, który dopisała nasza własna
 * infrastruktura. Reszta nagłówka leci do kosza jeszcze przed TrustProxies
 * (ustalenie W7-01, test SEC-01 z audytu).
 *
 * CO BYŁO ZEPSUTE — ZMIERZONE NA ŹRÓDLE FRAMEWORKA, NIE Z PAMIĘCI
 * `bootstrap/app.php` woła `trustProxies(at: '*')`. W Laravelu `'*'` nie
 * znaczy „ufaj wszystkim adresom w łańcuchu": `TrustProxies` tłumaczy to na
 * `setTrustedProxies([REMOTE_ADDR], ...)`, czyli „ufaj tej jednej maszynie,
 * która się właśnie połączyła". Dalej pracuje już Symfony
 * (`Request::normalizeAndFilterClientIps()`): dokleja `REMOTE_ADDR` na koniec
 * łańcucha, wyrzuca z niego adresy zaufane — czyli dokładnie ten jeden —
 * odwraca resztę i oddaje jej PIERWSZY element jako `$request->ip()`.
 *
 * Wychodzi z tego reguła „wygrywa OSTATNI wpis nagłówka". Dla żądania, które
 * naprawdę przeszło przez Cloudflare, ten ostatni wpis jest w porządku — to
 * Cloudflare dopisał tam adres odwiedzającego. Ale dla żądania, przed którym
 * NIC nie stało, ostatnim wpisem jest to, co wpisał sam klient. Wtedy
 * `$request->ip()` jest wartością od klienta i sypią się trzy rzeczy naraz:
 *
 *   1. limity liczone po adresie (`throttle:` z `config/kuking.php` oraz
 *      koszyki A i C z `App\Support\KluczeLimitow`) resetują się przy każdej
 *      próbie — wystarczy zmienić nagłówek;
 *   2. `audit_log.ip_hash` zapisuje skrót adresu wybranego przez napastnika,
 *      więc ślad po incydencie prowadzi tam, gdzie on chce;
 *   3. blokada „po adresie" nie blokuje nic.
 *
 * DLACZEGO LICZBA PRZESKOKÓW, A NIE LISTA ADRESÓW IP
 * Bo listy adresów na tej platformie nie da się napisać. Symfony porównuje
 * listę zaufanych proxy z BEZPOŚREDNIM peerem TCP, a tym peerem jest zawsze
 * brzeg Railway z prywatnej sieci — nigdy adres Cloudflare. Wpisanie zakresów
 * Cloudflare byłoby więc listą, w którą nie trafi żadne żądanie, a adresów
 * brzegu Railway nikt nam nie gwarantuje (`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`
 * §3). Zostaje jedyna własność tego nagłówka, która jest niezależna od adresów:
 *
 *     każde proxy DOPISUJE na końcu, klient może dopisywać tylko na początku.
 *
 * Dlatego liczymy od PRAWEJ. Ile pozycji od prawej — mówi
 * `config('proxy.zaufane_przeskoki')` i to jedyna liczba, którą trzeba znać.
 * Cokolwiek klient dopisze z lewej, przesuwa wyłącznie własne śmieci; wpis
 * dopisany przez naszą infrastrukturę zostaje na swoim miejscu, licząc od końca.
 *
 * CO SIĘ STANIE, GDY RAILWAY ZMIENI TOPOLOGIĘ
 * To jest prawdziwe pytanie, więc odpowiedź jest tu, a nie w raporcie:
 *
 *   - dojdzie proxy dopisujące wpis (np. brzeg Railway zacznie dopisywać
 *     adres Cloudflare) → liczba jest za MAŁA. Aplikacja zacznie widzieć
 *     adres tego proxy: jeden wspólny adres dla wielu osób. Limity zrobią
 *     się za ostre („za dużo prób" u ludzi, którzy nic nie zrobili), ale
 *     PODSZYĆ SIĘ NIE DA. Awaria jest głośna i odwracalna jedną zmienną;
 *   - proxy ubędzie → łańcuch będzie krótszy niż konfiguracja. Wtedy ten
 *     middleware USUWA nagłówek w całości, aplikacja spada na adres
 *     połączenia TCP i zapisuje ostrzeżenie w logu. Znowu: gorsze limity,
 *     zero podszywania się.
 *
 * Obie pomyłki są więc jednostronne: mylą się w stronę zbyt ostrą, nigdy
 * w stronę zaufania klientowi. To był warunek wyboru tego rozwiązania.
 *
 * CZEGO TO NIE ZAŁATWIA — I NIE UDAWAJMY, ŻE ZAŁATWIA
 * Żądanie, które w ogóle nie przeszło przez Cloudflare (bezpośrednie wejście
 * na `*.up.railway.app`), niesie łańcuch złożony wyłącznie z tego, co wpisał
 * klient. Licząc od prawej trafimy wtedy w jego ostatni wpis. Kod tego nie
 * rozstrzygnie, bo nie ma jak odróżnić „przyszło przez nasz brzeg" od
 * „przyszło z pominięciem brzegu" — do tego służy token krawędziowy
 * (`X-Kuking-Edge-Token`, Blok B w `docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`),
 * którego bez panelu Cloudflare nie da się wdrożyć. Ten middleware zamyka
 * połowę aplikacyjną: podrobiony prefiks nagłówka przestaje cokolwiek zmieniać
 * dla ruchu idącego właściwą drogą.
 *
 * DLACZEGO OSOBNY MIDDLEWARE PRZED `TrustProxies`, A NIE PODMIANA TAMTEGO
 * `TrustProxies` robi jeszcze jedną potrzebną rzecz: `X-Forwarded-Proto`.
 * Bez niego `$request->secure()` jest fałszem, `url()` generuje `http://`,
 * a Cloudflare zawraca to na `https` i robi się pętla przekierowań. Chcemy
 * zostawić tamten middleware nietknięty i tylko podać mu nagłówek, w którym
 * nie ma już nic od klienta. Kolejność zapewnia `$middleware->prepend()`
 * w `bootstrap/app.php` — nasz middleware jest PIERWSZY w stosie globalnym.
 */
class NormalizeForwardedFor
{
    private const NAGLOWEK = 'X-Forwarded-For';

    public function handle(Request $request, Closure $next): Response
    {
        $wpisy = $this->wpisyNaglowka($request);
        $przeskoki = max(0, (int) config('proxy.zaufane_przeskoki', 1));

        $adres = $this->adresOdInfrastruktury($wpisy, $przeskoki);

        if ($adres === null) {
            // Bez nagłówka Symfony bierze adres połączenia TCP. To jest adres
            // naszego własnego brzegu — wspólny dla wszystkich, więc limity
            // zrobią się zbyt ostre, ale nikt się pod nikogo nie podszyje.
            $request->headers->remove(self::NAGLOWEK);
            $request->server->remove('HTTP_X_FORWARDED_FOR');

            // Ostrzegamy TYLKO wtedy, gdy nagłówek przyszedł, a mimo to nie
            // dało się z niego nic wziąć — czyli gdy konfiguracja nie pasuje
            // do topologii. Przy `zaufane_przeskoki = 0` (wyłącznik awaryjny)
            // pominięcie nagłówka jest zamierzone i nie jest awarią.
            //
            // BEZ ADRESÓW W LOGU (AGENTS.md §7 — żadnych PII). Do rozpoznania
            // „konfiguracja nie pasuje do topologii" wystarczy sama długość
            // łańcucha.
            if ($przeskoki > 0 && $wpisy !== []) {
                Log::warning('X-Forwarded-For krótszy niż zaufane przeskoki albo nieczytelny', [
                    'wpisow' => count($wpisy),
                    'zaufane_przeskoki' => $przeskoki,
                ]);
            }

            return $next($request);
        }

        $request->headers->set(self::NAGLOWEK, $adres);
        $request->server->set('HTTP_X_FORWARDED_FOR', $adres);

        return $next($request);
    }

    /**
     * Wszystkie wartości nagłówka, w kolejności, w jakiej przyszły.
     *
     * `->all()`, a nie `->get()`, ROZMYŚLNIE. HTTP dopuszcza powtórzony
     * nagłówek i traktuje go tak samo jak jedną linię ze sklejonymi
     * wartościami — ale Symfony w `getTrustedValues()` czyta wyłącznie
     * PIERWSZE wystąpienie. Gdyby proxy dopisało własną linię zamiast
     * doklejać do istniejącej, Symfony patrzyłoby tylko na linię od klienta.
     * Sklejamy więc sami, w kolejności przyjścia, i dopiero to liczymy.
     *
     * @return list<string>
     */
    private function wpisyNaglowka(Request $request): array
    {
        $linie = $request->headers->all('x-forwarded-for');

        $wpisy = [];

        foreach ($linie as $linia) {
            foreach (explode(',', (string) $linia) as $wpis) {
                $wpis = trim($wpis);

                if ($wpis !== '') {
                    $wpisy[] = $wpis;
                }
            }
        }

        return $wpisy;
    }

    /**
     * Wpis dopisany przez najdalsze zaufane proxy — czyli adres klienta.
     *
     * @param  list<string>  $wpisy
     */
    private function adresOdInfrastruktury(array $wpisy, int $przeskoki): ?string
    {
        if ($przeskoki === 0 || $wpisy === []) {
            return null;
        }

        $indeks = count($wpisy) - $przeskoki;

        if ($indeks < 0) {
            return null;
        }

        return $this->adresBezPortu($wpisy[$indeks]);
    }

    /**
     * Sam adres, bez portu — i `null`, jeśli to w ogóle nie jest adres IP.
     *
     * Odcinanie portu robi też Symfony, ale MY musimy zrobić to wcześniej:
     * gdybyśmy oddali dalej wartość, której Symfony nie uzna za adres,
     * odrzuciłoby ją i cofnęło się o jeden wpis W LEWO — czyli dokładnie
     * w obszar, który wypełnia klient. Wpis nie do odczytania traktujemy
     * więc jak brak nagłówka, a nie jak zaproszenie do szukania dalej.
     */
    private function adresBezPortu(string $wpis): ?string
    {
        if (str_starts_with($wpis, '[')) {
            // IPv6 w postaci `[::1]:443`.
            $koniec = strpos($wpis, ']', 1);

            if ($koniec !== false) {
                $wpis = substr($wpis, 1, $koniec - 1);
            }
        } elseif (str_contains($wpis, '.')) {
            // IPv4 w postaci `203.0.113.7:1234`. Gołe IPv6 (bez nawiasów)
            // ma dwukropki, ale nie ma kropek — dlatego warunek pyta
            // o kropkę, tak samo jak Symfony.
            $dwukropek = strpos($wpis, ':');

            if ($dwukropek !== false) {
                $wpis = substr($wpis, 0, $dwukropek);
            }
        }

        return filter_var($wpis, FILTER_VALIDATE_IP) === false ? null : $wpis;
    }
}
