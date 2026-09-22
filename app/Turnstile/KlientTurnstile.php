<?php

declare(strict_types=1);

namespace App\Turnstile;

use App\Support\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Weryfikacja tokenu Turnstile po stronie serwera — cienki klient na
 * `Illuminate\Support\Facades\Http`.
 *
 * DLACZEGO BEZ NOWEJ PACZKI COMPOSERA
 * Bo Laravel ma klient HTTP w standardzie, a całe API to jeden `POST` z trzema
 * polami (AGENTS.md §3: „kolejna biblioteka, gdy Laravel ma to w standardzie"
 * jest na liście zakazów). To ta sama droga, którą poszedł transport poczty
 * w D-047.
 *
 * DLACZEGO WERYFIKACJA MUSI BYĆ PO STRONIE SERWERA
 * Token w polu formularza to zwykły łańcuch znaków, który każdy może wpisać
 * ręcznie. Bez `siteverify` widget byłby ozdobą: wystarczyłoby wysłać
 * `cf-turnstile-response=cokolwiek`, żeby go „przejść".
 *
 * ŹRÓDŁO KSZTAŁTU API (sprawdzone 9 września 2026)
 * `POST https://challenges.cloudflare.com/turnstile/v0/siteverify`, ciało
 * `application/x-www-form-urlencoded`, pola `secret`, `response`
 * i nieobowiązkowe `remoteip`. Odpowiedź JSON: `success` (bool),
 * `error-codes` (lista), `challenge_ts`, `hostname`.
 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 *
 * ZASADA NADRZĘDNA: NIEDOSTĘPNOŚĆ CUDZEJ USŁUGI NIE ZAMYKA REJESTRACJI
 * Ten klient stoi w środku wysyłania NASZEGO formularza. Cokolwiek pójdzie
 * nie tak po drodze — timeout, 5xx, błędny sekret, odpowiedź w nieznanym
 * kształcie — oddajemy `Nierozstrzygniety`, czyli formularz przechodzi,
 * a ostrzeżenie idzie do dziennika. `Odrzucony` oddajemy WYŁĄCZNIE wtedy,
 * gdy Cloudflare powiedział „nie" o samym tokenie.
 *
 * Odwrotna decyzja („nie wiem" = odrzucamy) wyglądałaby na bezpieczniejszą
 * i byłaby najgorszym możliwym błędem w tym miejscu: awaria u Cloudflare
 * albo jedna literówka w sekrecie zamykałaby rejestrację, odzyskiwanie hasła
 * i formularz DSA naraz — a z zewnątrz wyglądałoby to jak działający serwis.
 */
final class KlientTurnstile
{
    public const ADRES = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Kody, które mówią o NASZYM błędzie konfiguracji albo o awarii po
     * stronie Cloudflare — nie o tokenie.
     *
     * `invalid-input-secret` i `missing-input-secret` to zły albo brakujący
     * sekret w Railway. `bad-request` znaczy, że to my wysłaliśmy złe żądanie.
     * `internal-error` to awaria u nich. W żadnym z tych przypadków człowiek
     * przed ekranem nie zrobił nic złego, więc go nie zatrzymujemy — ale
     * właściciel musi się o tym dowiedzieć, i to głośno (`Log::error`).
     *
     * @var list<string>
     */
    private const KODY_NASZEGO_BLEDU = [
        'missing-input-secret',
        'invalid-input-secret',
        'bad-request',
        'internal-error',
    ];

    public function sprawdz(string $token, ?string $ip = null): WynikTurnstile
    {
        if (! Turnstile::skonfigurowany()) {
            // Bez kluczy nie ma czego i czym sprawdzać. Nie pytamy Cloudflare
            // z pustym sekretem — dostalibyśmy `missing-input-secret`, czyli
            // ostrzeżenie o stanie, który jest tu poprawny i zamierzony.
            return WynikTurnstile::Nierozstrzygniety;
        }

        $limit = Turnstile::limitCzasu();

        try {
            $odpowiedz = Http::asForm()
                ->connectTimeout(min($limit, 3))
                ->timeout($limit)
                ->post(self::ADRES, array_filter([
                    'secret' => Turnstile::sekret(),
                    'response' => $token,
                    // Adres pomaga Cloudflare ocenić ruch, ale jest
                    // NIEOBOWIĄZKOWY — `array_filter` wycina `null`, gdy
                    // żądanie przyszło bez rozpoznanego adresu.
                    'remoteip' => $ip,
                ], static fn (mixed $wartosc): bool => $wartosc !== null && $wartosc !== ''));
        } catch (ConnectionException $e) {
            // Najczęstszy przypadek „nie wiem": Cloudflare nie odpowiedział
            // w zadanym czasie albo nie było wyjścia na HTTPS.
            return $this->nieWiemy('Cloudflare nie odpowiedział na weryfikację Turnstile.', [
                'wyjatek' => $e::class,
                'komunikat' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // `Throwable`, nie `Exception`: nie zgadujemy, czym potrafi się
            // wywrócić cudzy klient HTTP. Wysłanie formularza jest ważniejsze
            // niż nasza pewność co do tego, co poszło nie tak.
            return $this->nieWiemy('Weryfikacja Turnstile wywróciła się w nieoczekiwany sposób.', [
                'wyjatek' => $e::class,
                'komunikat' => $e->getMessage(),
            ]);
        }

        if ($odpowiedz->failed()) {
            return $this->nieWiemy('Weryfikacja Turnstile oddała kod błędu HTTP.', [
                'status' => $odpowiedz->status(),
            ]);
        }

        $sukces = $odpowiedz->json('success');

        if ($sukces === true) {
            return WynikTurnstile::Przeszedl;
        }

        if ($sukces !== false) {
            // HTTP 200 i coś, czego nie rozumiemy — zmiana API, strona
            // pośrednika, odpowiedź nie-JSON. Nie udajemy, że to znaczy „nie".
            return $this->nieWiemy('Weryfikacja Turnstile oddała odpowiedź w nieznanym kształcie.', [
                'status' => $odpowiedz->status(),
            ]);
        }

        $kody = $this->kody($odpowiedz->json('error-codes'));

        if (array_intersect($kody, self::KODY_NASZEGO_BLEDU) !== []) {
            // NASZ błąd, nie jego. Głośno — bo od tej chwili Turnstile nie
            // chroni niczego, a wyglądałby na działający.
            Log::error('Turnstile odmawia z powodu po NASZEJ stronie — nikogo nie zatrzymujemy.', [
                'kody' => $kody,
                'co_zrobic' => 'Sprawdź TURNSTILE_SECRET_KEY w Railway (musi być Secret Key tego samego widgetu co Site Key).',
            ]);

            return WynikTurnstile::Nierozstrzygniety;
        }

        // Zostają kody o samym tokenie: `invalid-input-response` (podrobiony
        // albo z innego widgetu), `timeout-or-duplicate` (zużyty albo starszy
        // niż 5 minut), `missing-input-response` (puste pole — tu nie powinno
        // się zdarzyć, bo pustego tokenu w ogóle nie wysyłamy).
        //
        // `Log::info`, nie `warning`: odrzucony token to normalna, zamierzona
        // praca tego mechanizmu, a nie usterka. Zapisujemy go, żeby dało się
        // odpowiedzieć na pytanie „czy ktoś nas w ogóle atakuje" — bez adresu
        // IP i bez treści formularza (AGENTS.md §7).
        Log::info('Turnstile odrzucił token.', ['kody' => $kody]);

        return WynikTurnstile::Odrzucony;
    }

    /**
     * Jedno miejsce, w którym powstaje „nie wiem": zawsze z ostrzeżeniem
     * w dzienniku, nigdy w ciszy.
     *
     * @param  array<string, mixed>  $kontekst
     */
    private function nieWiemy(string $powod, array $kontekst): WynikTurnstile
    {
        Log::warning($powod.' Formularz przepuszczamy — zostają limity zapytań.', $kontekst);

        return WynikTurnstile::Nierozstrzygniety;
    }

    /**
     * Kody błędów jako lista łańcuchów. Cloudflare dokumentuje `error-codes`
     * jako listę, ale nie zakładamy tego na słowo — do dziennika i do
     * porównania ma wejść tylko to, co naprawdę jest łańcuchem.
     *
     * @return list<string>
     */
    private function kody(mixed $kody): array
    {
        if (! is_array($kody)) {
            return [];
        }

        return array_values(array_filter($kody, 'is_string'));
    }
}
