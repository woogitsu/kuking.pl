<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Support\Facebook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
 */
final class FacebookDeauthorizeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $identyfikator = $this->identyfikatorZPodpisu((string) $request->input('signed_request', ''));

        if ($identyfikator === null) {
            Log::warning('Powiadomienie o odebraniu dostępu z Facebooka odrzucone: podpis się nie zgadza.');

            return response('', 400);
        }

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

        $oznaczone = $user->oznaczOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

        Log::info('Odnotowane odebranie dostępu kontu Facebooka.', ['oznaczonych_powiazan' => $oznaczone]);

        return response('', 200);
    }

    /**
     * Identyfikator konta Facebooka z `signed_request` — albo `null`, gdy
     * cokolwiek się nie zgadza.
     *
     * Jedna metoda i jedno `null` dla wszystkich powodów odrzucenia: brak
     * sekretu, zły kształt, zły podpis, zły algorytm, brak `user_id`.
     * Rozdzielenie ich na osobne odpowiedzi powiedziałoby pytającemu, JAK
     * blisko był — a nie ma powodu, żeby mu to mówić.
     */
    private function identyfikatorZPodpisu(string $signedRequest): ?string
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

        return $identyfikator === '' ? null : $identyfikator;
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
