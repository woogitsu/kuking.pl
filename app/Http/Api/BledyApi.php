<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Exceptions\BladDlaCzlowieka;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Jeden format błędu dla całego `/api/*` (D-270).
 *
 * ```json
 * { "message": "Zdanie po polsku, mówiące co zrobić.", "code": "nie_znaleziono" }
 * ```
 *
 * Przy 422 dochodzi `errors` — słownik „pole → lista komunikatów", tych
 * samych z `lang/pl/validation.php`, które widzi formularz na WWW.
 *
 * `message` jest dla człowieka: aplikacja może je pokazać wprost, bez
 * własnego tłumaczenia. `code` jest dla programu: stały, w `snake_case`,
 * nie zmienia się razem z treścią zdania.
 *
 * CZEGO TU NIE MA NIGDY: treści wyjątku technicznego, śladu stosu, nazwy
 * klasy, SQL-a. `BladDlaCzlowieka` jest jedynym wyjątkiem, którego treść
 * wychodzi na zewnątrz — z tego samego powodu co na WWW (patrz jego
 * komentarz). Reszta dostaje zdanie z tej klasy, niezależnie od
 * `APP_DEBUG`: API nie ma „trybu dla programisty", który ktoś zapomni
 * wyłączyć, a szczegóły zostają w dzienniku serwera.
 */
final class BledyApi
{
    /**
     * Zdania dla kodów HTTP. Każde mówi, co zrobić (AGENTS.md §5, §11).
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const ZDANIA = [
        400 => ['zle_zadanie', 'Aplikacja wysłała niepełne żądanie. Zaktualizuj aplikację i spróbuj jeszcze raz.'],
        401 => ['brak_logowania', 'Zaloguj się w aplikacji jeszcze raz. Ten dostęp wygasł albo został odwołany.'],
        403 => ['brak_dostepu', 'Nie masz dostępu do tej treści.'],
        404 => ['nie_znaleziono', 'Nie ma tu niczego takiego. Mogło zostać usunięte albo adres jest niepełny.'],
        405 => ['zla_metoda', 'Ta operacja nie działa pod tym adresem. Zaktualizuj aplikację i spróbuj jeszcze raz.'],
        409 => ['konflikt', 'Ktoś zmienił to w międzyczasie. Odśwież ekran i spróbuj jeszcze raz.'],
        413 => ['za_duze', 'Plik jest za duży. Wybierz mniejsze zdjęcie.'],
        415 => ['zly_format', 'Aplikacja wysłała dane w formacie, którego serwer nie rozumie. Zaktualizuj aplikację.'],
        422 => ['bledne_dane', 'Popraw zaznaczone pola i wyślij jeszcze raz.'],
        429 => ['za_duzo_prob', 'Za dużo prób w krótkim czasie. Odczekaj chwilę i spróbuj jeszcze raz.'],
        503 => ['przerwa', 'Kuking ma teraz krótką przerwę techniczną. Spróbuj za kilka minut.'],
    ];

    private const BLAD_SERWERA = ['blad_serwera', 'Coś poszło nie tak po naszej stronie. Spróbuj jeszcze raz za chwilę.'];

    /**
     * Domyślna treść `AuthorizationException`, gdy Policy nie podała własnej.
     * Po niej poznajemy, że zdanie jest angielskie i nie wolno go pokazać.
     */
    private const DOMYSLNA_ODMOWA_FRAMEWORKA = 'This action is unauthorized.';

    public static function dotyczy(Request $request): bool
    {
        return $request->is('api/*') || $request->is('api');
    }

    /**
     * Odpowiedź JSON dla wyjątku rzuconego w trakcie żądania API.
     *
     * `null` dla `HttpResponseException`: ta niesie GOTOWĄ odpowiedź
     * (np. z `abort(response()->json(...))`) i framework ma ją oddać bez zmian.
     */
    public static function odpowiedz(Throwable $e): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return self::json($e->status, self::ZDANIA[422][0], self::ZDANIA[422][1], [
                'errors' => $e->errors(),
            ]);
        }

        if ($e instanceof BladDlaCzlowieka) {
            return self::json(422, 'odmowa', $e->getMessage());
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, ...self::ZDANIA[401]);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$kod, $zdanie] = self::ZDANIA[$status] ?? ($status >= 500 ? self::BLAD_SERWERA : self::ZDANIA[400]);

            if ($status === 403 && ($zPolicy = self::zdanieZPolicy($e)) !== null) {
                $zdanie = $zPolicy;
            }

            if ($status === 429 && ($sekundy = self::ponowZa($e)) !== null) {
                $zdanie = "Za dużo prób w krótkim czasie. Spróbuj jeszcze raz za {$sekundy} s.";
            }

            return self::json($status, $kod, $zdanie, [], $e->getHeaders());
        }

        return self::json(500, ...self::BLAD_SERWERA);
    }

    /**
     * Zdanie z `Response::deny('…')` Policy, jeśli ktoś je napisał.
     * Policy w tym repozytorium piszą po polsku (np. `ReportPolicy`).
     */
    private static function zdanieZPolicy(HttpExceptionInterface $e): ?string
    {
        $poprzedni = $e instanceof Throwable ? $e->getPrevious() : null;

        if (! $poprzedni instanceof AuthorizationException) {
            return null;
        }

        $zdanie = trim($poprzedni->getMessage());

        return $zdanie === '' || $zdanie === self::DOMYSLNA_ODMOWA_FRAMEWORKA ? null : $zdanie;
    }

    private static function ponowZa(HttpExceptionInterface $e): ?int
    {
        $naglowek = $e->getHeaders()['Retry-After'] ?? null;

        return is_numeric($naglowek) ? max(1, (int) $naglowek) : null;
    }

    /**
     * @param  array<string, mixed>  $dodatki
     * @param  array<string, mixed>  $naglowki
     */
    private static function json(int $status, string $kod, string $zdanie, array $dodatki = [], array $naglowki = []): JsonResponse
    {
        return new JsonResponse(
            ['message' => $zdanie, 'code' => $kod, ...$dodatki],
            $status,
            $naglowki,
            JSON_UNESCAPED_UNICODE,
        );
    }
}
