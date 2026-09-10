<?php

declare(strict_types=1);

namespace App\Google;

use App\Models\User;
use App\Support\Google;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wejście kontem Google — cienki klient OAuth 2.0 / OpenID Connect
 * na `Illuminate\Support\Facades\Http` (issue #258, D-069).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO BEZ `laravel/socialite`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Kryterium jest to samo, którym D-065 odesłał trzy pakiety: pakiet wchodzi
 * wtedy, gdy usuwa nazwany, DZIŚ istniejący problem. Tutaj cała rozmowa
 * z Google to jedno przekierowanie i JEDNO żądanie `POST` z sześcioma
 * polami — a Laravel ma klient HTTP, sesję, podpisywanie i walidację
 * w standardzie (AGENTS.md §3 wymienia „kolejną bibliotekę, gdy Laravel ma
 * to w standardzie" wśród zakazów). Tą samą drogą poszedł transport poczty
 * (D-047) i weryfikacja Turnstile (D-050).
 *
 * Do tego trzy rzeczy, które akurat u nas przechylają wagę:
 *
 *  1. **`email_verified` jest u nas warunkiem wejścia, nie ciekawostką.**
 *     Socialite oddaje je jako surowy element tablicy `$user->user`, obok
 *     API, które zachęca do czytania `$user->getEmail()` — czyli robi łatwym
 *     dokładnie to, co przy tej funkcji jest luką na przejęcie konta.
 *     Kod, który MUSI o to zapytać, jest bezpieczniejszy niż kod, który MOŻE.
 *  2. **PKCE.** Wymagamy go od pierwszego dnia (issue #258). Włączenie go
 *     w Socialite dla Google to i tak własny kod na jego wnętrznościach.
 *  3. **Nie chcemy zdjęcia ani tokenu odświeżania.** Socialite pobiera
 *     awatar domyślnie; u nas zdjęcie profilowe przechodzi przez moderację
 *     modelem i własny pipeline (D-061), więc zdjęcie z zewnątrz weszłoby
 *     POZA tę drogę. Łatwiej nie napisać takiego żądania, niż pamiętać
 *     o wyłączaniu go w cudzym.
 *
 * Cena tej decyzji jest uczciwa i wpisana w D-069: aktualizacje po stronie
 * Google są od dziś naszą robotą. Powierzchnia jest jednak mała i stabilna
 * (dwa adresy i jeden format tokenu), a bez pakietu nie ma też jego
 * aktualizacji do pilnowania.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE SPRAWDZAMY PODPISU TOKENU TOŻSAMOŚCI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo nie musimy, i to jest napisane w specyfikacji, nie wymyślone tutaj:
 * OpenID Connect Core §3.1.3.7 pkt 6 zwalnia ze sprawdzania podpisu token,
 * który klient odebrał **wprost z punktu tokenu, po TLS-ie** — a to jest
 * dokładnie nasz przypadek. Nie bierzemy tokenu z przekierowania
 * w przeglądarce (czyli od człowieka, który mógł go podmienić), tylko sami
 * pytamy o niego `https://oauth2.googleapis.com/token`, przedstawiając się
 * sekretem klienta.
 *
 * Alternatywą byłoby pobieranie i odświeżanie kluczy publicznych Google
 * (JWKS) plus weryfikacja RS256 — czyli cache kluczy, obsługa rotacji
 * i nowa klasa awarii („logowanie padło, bo nie dociągnęliśmy kluczy”)
 * w zamian za zerowy zysk przy tym sposobie odbioru tokenu.
 *
 * **Sprawdzamy natomiast to, co i tak trzeba** — bo brak podpisu nie zwalnia
 * z reszty §3.1.3.7: wydawcę (`iss`), odbiorcę (`aud` musi być NASZ
 * identyfikator klienta), termin ważności (`exp`) i `nonce` wiążący token
 * z TĄ sesją. Bez `aud` token wystawiony dla innej aplikacji tego samego
 * dostawcy byłby u nas dobry.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ZASADA NADRZĘDNA: AWARIA GOOGLE ODSYŁA NA HASŁO, NIE ZAMYKA DRZWI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cokolwiek pójdzie nie tak — timeout, 5xx, zły sekret, odpowiedź
 * w nieznanym kształcie — oddajemy `null`, a kontroler odsyła człowieka na
 * ekran logowania ze zdaniem po polsku i z hasłem oraz linkiem e-mail przed
 * oczami (D-056). Nigdy: pusty ekran, nigdy angielski komunikat od
 * dostawcy, nigdy przycisk, który milczy.
 */
final class KlientGoogle
{
    /**
     * Długość weryfikatora PKCE w znakach.
     *
     * RFC 7636 §4.1 dopuszcza 43–128 znaków z alfabetu `unreserved`.
     * Bierzemy górną granicę, bo nie ma powodu brać mniej: to wartość
     * generowana maszynowo, żyjąca w sesji przez kilkanaście sekund.
     * `Str::random()` daje `[A-Za-z0-9]`, czyli podzbiór `unreserved`.
     */
    private const DLUGOSC_WERYFIKATORA = 128;

    /** Zapas na rozjazd zegarów przy sprawdzaniu `exp` — w sekundach. */
    private const ZAPAS_ZEGARA = 60;

    /**
     * Adres, na który odsyłamy człowieka po zgodę.
     *
     * `access_type=online` JEST TU DECYZJĄ, NIE DOMYŚLNOŚCIĄ: mówi Google,
     * żeby NIE wystawiał nam tokenu odświeżania. Nie chodzi o to, że go nie
     * zapiszemy — chodzi o to, żeby nie dostać trwałego pełnomocnictwa do
     * cudzego konta Google, którego do niczego nie używamy (issue #258 pkt 5).
     *
     * `prompt=select_account` — bo z jednego telefonu korzysta czasem całe
     * małżeństwo, a domyślnie Google wchodzi kontem ostatnio używanym, bez
     * pytania. Człowiek ma zobaczyć, JAKIM kontem wchodzi.
     */
    public function adresZgody(string $state, string $nonce, string $wyzwaniePkce, string $adresPowrotu): string
    {
        return Google::ADRES_AUTORYZACJI.'?'.http_build_query([
            'client_id' => Google::identyfikatorKlienta(),
            'redirect_uri' => $adresPowrotu,
            'response_type' => 'code',
            'scope' => Google::ZAKRES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $wyzwaniePkce,
            'code_challenge_method' => 'S256',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Kod autoryzacyjny → tożsamość. `null`, gdy cokolwiek nie zagra.
     *
     * `null` nie mówi, CO nie zagrało, i to jest celowe: człowiek przed
     * ekranem ma zrobić w każdym z tych przypadków dokładnie to samo
     * (spróbować jeszcze raz albo wejść hasłem), a odpowiedź „bo `aud` nie
     * pasuje" nie pomaga mu w niczym. Przyczyna idzie do dziennika, dla
     * właściciela.
     */
    public function wymienKod(string $kod, string $weryfikatorPkce, string $nonce, string $adresPowrotu): ?TozsamoscGoogle
    {
        $limit = Google::limitCzasu();

        try {
            $odpowiedz = Http::asForm()
                ->connectTimeout(min($limit, 3))
                ->timeout($limit)
                ->post(Google::ADRES_TOKENU, [
                    'code' => $kod,
                    'client_id' => Google::identyfikatorKlienta(),
                    'client_secret' => Google::sekretKlienta(),
                    'redirect_uri' => $adresPowrotu,
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $weryfikatorPkce,
                ]);
        } catch (ConnectionException $e) {
            return $this->nieUdalo('Google nie odpowiedział na wymianę kodu autoryzacyjnego.', [
                'wyjatek' => $e::class,
            ]);
        } catch (Throwable $e) {
            // `Throwable`, nie `Exception`: nie zgadujemy, czym potrafi się
            // wywrócić cudzy klient HTTP. Wejście hasłem jest ważniejsze niż
            // nasza pewność co do tego, co poszło nie tak.
            return $this->nieUdalo('Wymiana kodu z Google wywróciła się w nieoczekiwany sposób.', [
                'wyjatek' => $e::class,
            ]);
        }

        if ($odpowiedz->failed()) {
            /*
             * TU LĄDUJE ZŁY SEKRET KLIENTA I ZŁY ADRES POWROTU — czyli dwa
             * najczęstsze błędy konfiguracji. Google oddaje wtedy 400
             * z `error: invalid_client` albo `redirect_uri_mismatch`.
             *
             * `Log::error`, nie `warning`: od tej chwili droga przez Google
             * nie działa dla nikogo, a przycisk na ekranie wygląda normalnie.
             * Kodu błędu od Google NIE pokazujemy człowiekowi — jest po
             * angielsku i mówi o naszej konfiguracji, nie o nim.
             */
            Log::error('Google odrzucił wymianę kodu — sprawdź konfigurację.', [
                'status' => $odpowiedz->status(),
                'blad' => (string) $odpowiedz->json('error', ''),
                'co_zrobic' => 'Sprawdź GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET oraz to, '
                    .'czy adres powrotu jest wpisany w Google Cloud Console co do znaku '
                    .'(docs/infra/DEPLOYMENT_RUNBOOK.md, krok 8D).',
            ]);

            return null;
        }

        $token = $odpowiedz->json('id_token');

        if (! is_string($token) || $token === '') {
            return $this->nieUdalo('Odpowiedź Google nie zawiera tokenu tożsamości.', []);
        }

        return $this->tozsamoscZTokenu($token, $nonce);
    }

    /**
     * Odczyt i SPRAWDZENIE tokenu tożsamości — patrz komentarz klasy.
     */
    private function tozsamoscZTokenu(string $token, string $nonce): ?TozsamoscGoogle
    {
        $czesci = explode('.', $token);

        if (count($czesci) !== 3) {
            return $this->nieUdalo('Token tożsamości od Google nie ma trzech części.', []);
        }

        $dane = json_decode($this->odBase64Url($czesci[1]), true);

        if (! is_array($dane)) {
            return $this->nieUdalo('Nie da się odczytać zawartości tokenu tożsamości od Google.', []);
        }

        // WYDAWCA. Bez tego token z innego, byle jakiego dostawcy OIDC
        // byłby u nas dobry, gdyby ktoś podmienił adres tokenu.
        if (! in_array((string) ($dane['iss'] ?? ''), Google::WYDAWCY, true)) {
            return $this->nieUdalo('Token tożsamości nie jest od Google.', ['iss' => (string) ($dane['iss'] ?? '')]);
        }

        // ODBIORCA. Token wystawiony dla INNEJ aplikacji Google jest dla nas
        // bezwartościowy — i przyjęcie go byłoby luką, nie uprzejmością.
        if (! hash_equals(Google::identyfikatorKlienta(), (string) ($dane['aud'] ?? ''))) {
            return $this->nieUdalo('Token tożsamości jest wystawiony dla innej aplikacji.', []);
        }

        // TERMIN. Zapas na rozjazd zegarów, bo `exp` liczy Google, a porównuje
        // nasz serwer.
        $wygasa = (int) ($dane['exp'] ?? 0);

        if ($wygasa > 0 && $wygasa + self::ZAPAS_ZEGARA < time()) {
            return $this->nieUdalo('Token tożsamości od Google już wygasł.', []);
        }

        /*
         * `nonce` WIĄŻE TOKEN Z TĄ SESJĄ.
         *
         * `state` chroni przed podrzuceniem NAM cudzego kodu w naszym
         * przekierowaniu; `nonce` chroni przed podrzuceniem cudzego TOKENU
         * i jest drugą, niezależną warstwą tego samego pytania: „czy to
         * odpowiedź na moje pytanie". OpenID Connect Core §3.1.3.7 pkt 11
         * wymaga tego sprawdzenia, gdy `nonce` został wysłany — a my go
         * wysyłamy zawsze.
         */
        if (! hash_equals($nonce, (string) ($dane['nonce'] ?? ''))) {
            return $this->nieUdalo('Token tożsamości nie odpowiada tej sesji (nonce).', []);
        }

        $sub = trim((string) ($dane['sub'] ?? ''));
        $email = User::normalizeEmail((string) ($dane['email'] ?? ''));

        if ($sub === '' || $email === '') {
            return $this->nieUdalo('Token tożsamości od Google jest bez identyfikatora albo bez adresu.', []);
        }

        /*
         * `email_verified` bierzemy DOKŁADNIE tak, jak przyszło, i nie
         * naciągamy: `true` znaczy `true`, wszystko inne (brak pola, `false`,
         * napis „true") znaczy NIE. To jest jedno miejsce w tym pliku,
         * w którym pobłażliwość byłaby luką na przejęcie konta — patrz
         * `TozsamoscGoogle` i D-069.
         */
        $potwierdzony = ($dane['email_verified'] ?? null) === true;

        return new TozsamoscGoogle(
            sub: $sub,
            email: $email,
            emailPotwierdzony: $potwierdzony,
            // `given_name` przed `name`, bo na ekranie pytamy „jak mamy Cię
            // nazywać" — imię pasuje tam lepiej niż imię z nazwiskiem.
            imie: trim((string) ($dane['given_name'] ?? $dane['name'] ?? '')),
        );
    }

    /** Nowy weryfikator PKCE — wartość, która NIE opuszcza naszej sesji. */
    public static function nowyWeryfikator(): string
    {
        return Str::random(self::DLUGOSC_WERYFIKATORA);
    }

    /**
     * Wyzwanie PKCE: `base64url(sha256(weryfikator))`, metoda `S256`.
     *
     * PKCE nie jest tu obrzędem. Bez niego kod autoryzacyjny przechwycony
     * w drodze powrotnej (dziennik pośrednika, historia przeglądarki, cudzy
     * dodatek) da się wymienić na token każdemu, kto zna nasz sekret; z nim
     * kod jest bezużyteczny bez weryfikatora, który nigdy nie opuścił naszej
     * sesji. RFC 7636.
     */
    public static function wyzwanie(string $weryfikator): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $weryfikator, true)), '+/', '-_'), '=');
    }

    /** Jednorazowa, losowa wartość do `state` i do `nonce`. */
    public static function losowaWartosc(): string
    {
        return Str::random(64);
    }

    private function odBase64Url(string $wartosc): string
    {
        return (string) base64_decode(strtr($wartosc, '-_', '+/'), false);
    }

    /**
     * Jedno miejsce, w którym powstaje „nie udało się": zawsze ze wpisem
     * w dzienniku, nigdy w ciszy. Bez adresu e-mail i bez tokenu
     * (AGENTS.md §7).
     *
     * @param  array<string, mixed>  $kontekst
     */
    private function nieUdalo(string $powod, array $kontekst): null
    {
        Log::warning($powod.' Odsyłamy człowieka na logowanie hasłem.', $kontekst);

        return null;
    }
}
