<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Support\Facebook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * „Ten człowiek odebrał nam dostęp na Facebooku" (issue #259).
 *
 * Facebook woła ten adres POST-em, gdy ktoś usunie naszą aplikację
 * w swoich ustawieniach Facebooka. Adres wpisuje się w panelu Meta
 * w polu `Deauthorize callback URL`:
 *
 *     https://kuking.pl/wejdz/facebook/odebranie-dostepu
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TA TRASA JEST WYŁĄCZONA Z OCHRONY CSRF — I DLACZEGO TO JEST
 *  BEZPIECZNE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Żądanie przychodzi z serwerów Facebooka, a nie z przeglądarki człowieka:
 * nie ma sesji, nie ma ciasteczka, nie ma skąd wziąć tokenu CSRF. Wyłączenie
 * stoi w `bootstrap/app.php`.
 *
 * Autentyczność potwierdza więc PODPIS, nie sesja. `signed_request` to
 * `podpis.dane`, obie części w base64url; podpis jest HMAC-SHA256 z części
 * `dane` na sekrecie naszej aplikacji, którego zna wyłącznie Facebook i my.
 * Porównujemy go przez `hash_equals`, czyli w czasie niezależnym od tego,
 * ile pierwszych bajtów się zgadza — zwykłe `===` na łańcuchach pozwala
 * odgadywać podpis bajt po bajcie, mierząc czas odpowiedzi.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  OGRANICZENIE, KTÓREGO NIE DA SIĘ OBEJŚĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Pole `Deauthorize callback URL` jest w panelu Meta JEDNO NA APLIKACJĘ,
 * a jedna aplikacja obsługuje u nas produkcję i staging. **Staging tych
 * powiadomień nie dostanie.** Drugiej aplikacji w Meta dla stagingu nie
 * zakładamy — to osobne konto, osobna weryfikacja i osobny zestaw kluczy
 * do pomylenia. Piszę to tutaj, żeby nie wyglądało na usterkę przy
 * następnym czytaniu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TU NIE MA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie logujemy `signed_request` ani jego rozpakowanej treści: podpis jest
 * wyliczony z naszego sekretu i nie ma powodu, żeby leżał w dzienniku.
 * Nie kasujemy też wiersza powiązania — uzasadnienie stoi w migracji
 * `2026_09_11_700000_dodaj_znacznik_odebrania_dostepu`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  STARY PODPIS NIE NADPISUJE NOWSZEJ ZGODY (issue #1025)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Poprawny podpis nie mówi, KIEDY wiadomość powstała — mówi to `issued_at`.
 * Bez niego raz przechwycone (albo po prostu spóźnione) powiadomienie
 * usypiałoby powiązanie także po tym, jak człowiek na nowo dał nam zgodę.
 * Dlatego:
 *
 *  - `issued_at` jest WYMAGANE i musi być liczbą całkowitą — inaczej 400,
 *    tak samo jak zły podpis;
 *  - `issued_at` z przyszłości (ponad `TOLERANCJA_ZEGARA_S`) to też 400:
 *    taka wiadomość byłaby „nowsza" od każdej przyszłej zgody i nie dałoby
 *    się jej unieważnić ponownym logowaniem;
 *  - stara wiadomość NIE jest odrzucana samym wiekiem — rozstrzyga granica
 *    ostatniej zgody (`zgoda_potwierdzona_at`, a gdy pusta: `connected_at`).
 *    Wiadomość starsza od tej granicy kończy się spokojnym 200 i niczego
 *    nie zmienia; Facebook uznaje ją za dostarczoną i nie ponawia.
 */
final class FacebookDeauthorizeController extends Controller
{
    /**
     * Ile sekund `issued_at` może wyprzedzać nasz zegar. Zegary serwerów
     * Facebooka i naszego nie chodzą idealnie równo; pięć minut to zapas na
     * rozjazd, a nie furtka na wiadomości „z przyszłości".
     */
    private const TOLERANCJA_ZEGARA_S = 300;

    public function __invoke(Request $request): Response
    {
        /*
         * TYP SPRAWDZAMY PRZED RZUTOWANIEM (issue #1344). `signed_request[]=x`
         * daje tablicę, a `(string)` na tablicy to ostrzeżenie „Array to
         * string conversion" — w trybie, w którym Laravel zamienia
         * ostrzeżenia na wyjątki, publiczny adres odpowiadałby 500 zamiast
         * 400. Odpowiedź jest ta sama co przy złym podpisie: pytający nie
         * dowiaduje się, na którym etapie odpadł.
         */
        $surowe = $request->input('signed_request', '');

        $wiadomosc = is_string($surowe) ? $this->wiadomoscZPodpisu($surowe) : null;

        if ($wiadomosc === null) {
            Log::warning('Powiadomienie o odebraniu dostępu z Facebooka odrzucone: podpis się nie zgadza.');

            return response('', 400);
        }

        [$identyfikator, $wydanoO] = $wiadomosc;

        $user = User::findByFacebookId($identyfikator);

        if ($user === null) {
            /*
             * NIE JEST TO AWARIA. Powiadomienie może dotyczyć osoby, która
             * u nas nigdy konta nie założyła (kliknęła „Wejdź kontem
             * Facebooka", rozmyśliła się na ekranie domknięcia, a potem
             * posprzątała listę aplikacji u siebie). Odpowiadamy 200, bo
             * z punktu widzenia Facebooka wiadomość została przyjęta —
             * a 4xx czy 5xx liczyłoby się tam jako nieudane dostarczenie.
             */
            Log::info('Odebranie dostępu z Facebooka dla identyfikatora, którego nie mamy — nic do zrobienia.');

            return response('', 200);
        }

        // Zero oznaczonych to nie awaria: powiązanie już było uśpione
        // (ponowienie tej samej wiadomości) albo wiadomość jest starsza od
        // ostatniej zgody (issue #1025). W obu przypadkach nic do zrobienia.
        $oznaczone = $user->oznaczOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK, $wydanoO);

        Log::info('Odnotowane odebranie dostępu kontu Facebooka.', ['oznaczonych_powiazan' => $oznaczone]);

        return response('', 200);
    }

    /**
     * Identyfikator konta Facebooka i chwila wystawienia wiadomości
     * z `signed_request` — albo `null`, gdy cokolwiek się nie zgadza.
     *
     * Jedna metoda i jedno `null` dla wszystkich powodów odrzucenia: brak
     * sekretu, zły kształt, zły podpis, zły algorytm, brak `user_id`, brak
     * albo zły `issued_at`.
     * Rozdzielenie ich na osobne odpowiedzi powiedziałoby pytającemu, JAK
     * blisko był — a nie ma powodu, żeby mu to mówić.
     *
     * @return array{0: string, 1: Carbon}|null
     */
    private function wiadomoscZPodpisu(string $signedRequest): ?array
    {
        $sekret = (string) config('kuking.facebook.sekret_klienta');

        if ($sekret === '' || $signedRequest === '') {
            return null;
        }

        $czesci = explode('.', $signedRequest);

        if (count($czesci) !== 2) {
            return null;
        }

        [$podpisB64, $daneB64] = $czesci;

        $podpis = $this->base64url($podpisB64);
        $dane = $this->base64url($daneB64);

        if ($podpis === null || $dane === null) {
            return null;
        }

        $oczekiwany = hash_hmac('sha256', $daneB64, $sekret, true);

        if (! hash_equals($oczekiwany, $podpis)) {
            return null;
        }

        /** @var array<string, mixed>|null $tresc */
        $tresc = json_decode($dane, true);

        if (! is_array($tresc)) {
            return null;
        }

        /*
         * ALGORYTM SPRAWDZAMY MIMO UDANEGO PODPISU. Dokumentacja Facebooka
         * każe to robić i ma rację: gdyby kiedyś doszedł drugi algorytm,
         * przyjmowanie dowolnego byłoby zgodą na ten słabszy z nich.
         */
        if (($tresc['algorithm'] ?? null) !== Facebook::ALGORYTM_PODPISU) {
            return null;
        }

        $identyfikator = $tresc['user_id'] ?? null;

        if (! is_string($identyfikator) && ! is_int($identyfikator)) {
            return null;
        }

        $identyfikator = trim((string) $identyfikator);

        if ($identyfikator === '') {
            return null;
        }

        // Facebook podaje `issued_at` jako liczbę sekund od epoki. Tekst,
        // ułamek czy brak pola to nie jest wiadomość, którą umiemy ułożyć
        // w czasie — więc nie jest wiadomością, której wolno coś zmienić.
        $wydano = $tresc['issued_at'] ?? null;

        if (! is_int($wydano) || $wydano <= 0 || $wydano > Carbon::now()->getTimestamp() + self::TOLERANCJA_ZEGARA_S) {
            return null;
        }

        return [$identyfikator, Carbon::createFromTimestamp($wydano)];
    }

    /**
     * Facebook koduje obie części w base64url (`-` i `_` zamiast `+` i `/`,
     * bez dopełnienia `=`). `base64_decode` w trybie ścisłym odrzuci wszystko,
     * co nie jest poprawnym base64 — i o to chodzi.
     */
    private function base64url(string $wejscie): ?string
    {
        $zwykly = strtr($wejscie, '-_', '+/');

        $wynik = base64_decode($zwykly, true);

        return $wynik === false ? null : $wynik;
    }
}
