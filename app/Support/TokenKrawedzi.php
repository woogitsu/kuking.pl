<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Token krawędziowy `X-Kuking-Edge-Token` (issue #1306, Blok B
 * w `docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`).
 *
 * PO CO
 * `NormalizeForwardedFor` liczy `X-Forwarded-For` od prawej i to wystarcza
 * dla ruchu, który NAPRAWDĘ przeszedł przez Cloudflare. Żądanie, które weszło
 * na origin z pominięciem Cloudflare, niesie łańcuch złożony wyłącznie z tego,
 * co wpisał klient — i żaden odczyt nagłówka tego nie rozpozna. Rozpoznaje to
 * dopiero sekret, który dopisuje Cloudflare (reguła Request Header Transform),
 * a którego klient nie zna.
 *
 * TRZY TRYBY — I DLACZEGO DOMYŚLNY NIC NIE BLOKUJE
 *
 *   - brak sekretu (`KUKING_EDGE_TOKEN` puste) — bramka wyłączona. Lokalnie,
 *     w testach, na preview i na produkcji przed krokiem 2 Bloku B serwis
 *     działa dokładnie jak przedtem;
 *   - `obserwacja` (domyślny, gdy sekret jest) — niezgodność tylko trafia
 *     do logu. Literówka w regule Cloudflare nie może więc odciąć serwisu,
 *     a log pokazuje, czy CAŁY prawdziwy ruch niesie już token;
 *   - `egzekwowanie` — żądanie bez ważnego tokenu dostaje 403, zanim
 *     aplikacja cokolwiek z nim zrobi. Wyjątkiem są sondy zdrowia (niżej).
 *
 * Tryb blokujący trzeba więc włączyć JAWNIE. Pomyłka w konfiguracji kończy
 * się brakiem ochrony, której i tak dotąd nie było — nie wyłączonym serwisem.
 *
 * ROTACJA: `KUKING_EDGE_TOKEN_POPRZEDNI` jest przyjmowany na równi z bieżącym.
 * Kolejność: nowy sekret do `KUKING_EDGE_TOKEN`, stary do `_POPRZEDNI`,
 * potem reguła w Cloudflare, na końcu usunięcie `_POPRZEDNI`.
 *
 * SEKRET NIE WYCHODZI DALEJ: nagłówek jest usuwany z żądania zaraz po
 * sprawdzeniu, więc nie trafi do logów, raportów błędów ani widoków,
 * a porównanie idzie przez `hash_equals`.
 */
final class TokenKrawedzi
{
    public const NAGLOWEK = 'X-Kuking-Edge-Token';

    public const TRYB_OBSERWACJA = 'obserwacja';

    public const TRYB_EGZEKWOWANIE = 'egzekwowanie';

    public const ZGODNY = 'zgodny';

    public const BRAK = 'brak';

    public const NIEZGODNY = 'niezgodny';

    /** Bramka wyłączona — nie ma z czym porównywać. */
    public const WYLACZONY = 'wylaczony';

    /**
     * Sprawdza token i ZAWSZE usuwa go z żądania.
     */
    public static function sprawdz(Request $request): string
    {
        $podany = $request->headers->get(self::NAGLOWEK);

        $request->headers->remove(self::NAGLOWEK);
        $request->server->remove('HTTP_X_KUKING_EDGE_TOKEN');

        $sekrety = self::sekrety();

        if ($sekrety === []) {
            return self::WYLACZONY;
        }

        if (! is_string($podany) || $podany === '') {
            return self::BRAK;
        }

        $zgodny = false;

        // Bez przerywania pętli: czas odpowiedzi nie mówi, który sekret pasował.
        foreach ($sekrety as $sekret) {
            $zgodny = hash_equals($sekret, $podany) || $zgodny;
        }

        return $zgodny ? self::ZGODNY : self::NIEZGODNY;
    }

    public static function egzekwowanie(): bool
    {
        return config('proxy.token_krawedzi.tryb') === self::TRYB_EGZEKWOWANIE;
    }

    /**
     * Sondy zdrowia chodzą wprost do kontenera (healthcheck Railwaya nie
     * przechodzi przez Cloudflare), więc tokenu mieć nie mogą. Wpuszczamy
     * tylko GET/HEAD na te ścieżki — i bez nagłówków `X-Forwarded-*`.
     */
    public static function wolnoBezTokenu(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        $sciezki = (array) config('proxy.token_krawedzi.bez_tokenu', []);

        return in_array(trim($request->path(), '/'), $sciezki, true);
    }

    /**
     * @return list<string>
     */
    private static function sekrety(): array
    {
        return array_values(array_filter([
            (string) config('proxy.token_krawedzi.aktualny', ''),
            (string) config('proxy.token_krawedzi.poprzedni', ''),
        ], static fn (string $sekret): bool => $sekret !== ''));
    }
}
