<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turnstile musi mieć DROGĘ POWROTNĄ, nie tylko prawo pobrania skryptu —
 * issue #697.
 *
 * CO BYŁO NIE TAK
 * `ApplySecurityHeaders` dokładał `https://challenges.cloudflare.com` do
 * `script-src` i do `frame-src`, ale NIE do `connect-src`. Widget rysował się
 * poprawnie i dopiero potem przechodził w „Weryfikacja negatywna": jego
 * wywołanie do `challenges.cloudflare.com/cdn-cgi/challenge-platform/…`
 * ginęło na naszej własnej polityce, a w konsoli stawał
 * `TurnstileError 600010`.
 *
 * Skutek był taki, że **nikt nie mógł zalogować się hasłem ani założyć
 * konta** — polityka idzie z każdą odpowiedzią, więc dotyczyło to wszystkich,
 * nie jednego konta. Serwis nie był zamknięty tylko dlatego, że logowanie
 * kontem Google, kontem Facebooka i list z odnośnikiem nie przechodzą przez
 * Turnstile. Człowiek nie dostawał przy tym żadnej wskazówki, że ma pójść
 * inną drogą — widział samo „Weryfikacja negatywna".
 *
 * DLACZEGO TEST, A NIE KOMENTARZ
 * W tym samym pliku stał już wtedy akapit opisujący dokładnie tę pułapkę —
 * napisany o analityce Cloudflare, która pobiera skrypt z jednego hosta,
 * a zdarzenia wysyła na drugi. Komentarz nie zatrzymał powtórzenia błędu przy
 * następnym haśle. Nagłówek jest niewidoczny, a zła polityka nie psuje
 * niczego, co widać w kodzie — psuje coś, co widać dopiero w przeglądarce
 * prawdziwej osoby.
 *
 * DLATEGO TEN TEST NIE WYMIENIA HOSTA Z NAZWY W ASERCJI GŁÓWNEJ.
 * Sprawdza REGUŁĘ: każdy obcy host dopuszczony dla Turnstile w `script-src`
 * albo w `frame-src` ma być dopuszczony także w `connect-src`. Gdyby jutro
 * Cloudflare kazał dołożyć drugi host, test upomni się o niego sam — zamiast
 * przejść, bo literalny adres z 2026 roku nadal się zgadza.
 */
class PolitykaCspDopuszczaPowrotTurnstileTest extends TestCase
{
    use RefreshDatabase;

    /** Klucze testowe Cloudflare („zawsze przepuszcza"), te same co w pozostałych testach Turnstile. */
    private function wlaczTurnstile(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => '1x00000000000000000000AA',
            'kuking.turnstile.sekret' => '1x0000000000000000000000000000000AA',
        ]);
    }

    /** @return array<string, list<string>> dyrektywa => źródła */
    private function politykaLogowania(): array
    {
        $naglowek = (string) $this->get(route('login'))
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $naglowek, 'Strona logowania musi wysyłać politykę CSP.');

        $polityka = [];

        foreach (explode(';', $naglowek) as $fragment) {
            $czesci = preg_split('/\s+/', trim($fragment), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($czesci === []) {
                continue;
            }

            $polityka[array_shift($czesci)] = $czesci;
        }

        return $polityka;
    }

    /** Obce hosty, czyli bez `'self'`, `'nonce-…'`, `data:` i reszty słów kluczowych. */
    private function obceHosty(array $zrodla): array
    {
        return array_values(array_filter(
            $zrodla,
            static fn (string $zrodlo): bool => str_starts_with($zrodlo, 'https://'),
        ));
    }

    public function test_kazdy_host_turnstile_ze_script_src_i_frame_src_jest_tez_w_connect_src(): void
    {
        $this->wlaczTurnstile();

        $polityka = $this->politykaLogowania();

        $this->assertArrayHasKey('connect-src', $polityka, 'Polityka wypisuje `connect-src` osobno — bez niego nie ma czego sprawdzać.');
        $this->assertArrayHasKey('frame-src', $polityka, '`frame-src` pojawia się w polityce wyłącznie z Turnstile.');

        $wRamce = $this->obceHosty($polityka['frame-src']);
        $this->assertNotSame([], $wRamce, 'Przy skonfigurowanym Turnstile `frame-src` musi dopuszczać jego host.');

        $doPobrania = $this->obceHosty($polityka['script-src']);
        $turnstileWSkryptach = array_values(array_intersect($doPobrania, $wRamce));
        $this->assertNotSame([], $turnstileWSkryptach, 'Host Turnstile musi być też w `script-src` — widget dociąga własne skrypty.');

        $doPowrotu = $this->obceHosty($polityka['connect-src']);

        foreach (array_unique([...$wRamce, ...$turnstileWSkryptach]) as $host) {
            $this->assertContains(
                $host,
                $doPowrotu,
                "Host `{$host}` wolno pobrać i osadzić w ramce, ale nie wolno mu odpowiedzieć: brakuje go w `connect-src`. ".
                'Dokładnie to zabijało logowanie hasłem w #697 (TurnstileError 600010).',
            );
        }
    }

    /**
     * Kontrola ujemna dla reguły wyżej: bez kluczy żaden host Turnstile nie ma
     * prawa siedzieć w polityce. Bez tego testu regułę „ma być w connect-src"
     * dałoby się spełnić, wpisując host na stałe — a wtedy polityka
     * opisywałaby coś, czego strona nie ładuje (issue #12).
     */
    public function test_bez_kluczy_turnstile_nie_rozluznia_polityki(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => '',
            'kuking.turnstile.sekret' => '',
        ]);

        $polityka = $this->politykaLogowania();

        $this->assertArrayNotHasKey('frame-src', $polityka, 'Bez kluczy `frame-src` w ogóle nie powinien wyjść — ramki dziedziczą `default-src ʼselfʼ`.');

        foreach (['connect-src', 'script-src'] as $dyrektywa) {
            $this->assertNotContains(
                'https://challenges.cloudflare.com',
                $polityka[$dyrektywa] ?? [],
                "Bez kluczy Turnstile nie renderuje się wcale, więc `{$dyrektywa}` nie ma czego dopuszczać.",
            );
        }
    }
}
