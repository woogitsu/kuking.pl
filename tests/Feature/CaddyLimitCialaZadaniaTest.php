<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Caddy ogranicza rozmiar ciała żądania — i nie obcina zdjęć (audyt A5-16).
 *
 * Caddy nie działa w testach, więc test czyta `docker/Caddyfile` i sprawdza
 * trzy rzeczy, które się rozjeżdżają po cichu:
 *
 *  1. każda trasa, która przyjmuje zdjęcie, stoi na liście wyjątków od progu
 *     2 MB — inaczej zdjęcie powyżej 2 MB dostanie 413 (Content-Length) albo,
 *     przy ciele bez długości, zostanie ucięte;
 *  2. obie listy wyjątków (odmowa 413 i `request_body`) są identyczne;
 *  3. sufit globalny jest równy `post_max_size` z `docker/php.ini`.
 *
 * Zachowanie samego Caddy (413 po nagłówku, ciche obcinanie po `max_size`)
 * zmierzono na FrankenPHP 1.12 / Caddy 2.11 — opis w Caddyfile.
 */
class CaddyLimitCialaZadaniaTest extends TestCase
{
    /**
     * Trasy przyjmujące plik: nazwa trasy albo prefiks adresu pakietu.
     *
     * @var list<string>
     */
    private const TRASY_Z_PLIKAMI = [
        'posts.store',
        'posts.update',
        'questions.store',
        'recipes.store',
        'recipes.update',
        'cooked.store',
        'settings.avatar.update',
        'livewire.upload-file',
        'storage.local.upload',
    ];

    private function caddyfile(): string
    {
        return (string) file_get_contents(base_path('docker/Caddyfile'));
    }

    /** @return list<string> */
    private function wyjatki(string $blok): array
    {
        $this->assertSame(1, preg_match('/'.$blok.'\s*\{?\s*not path ([^\n]+)/', $this->caddyfile(), $m), "Brak `not path` w {$blok}.");

        return preg_split('/\s+/', trim($m[1])) ?: [];
    }

    public function test_kazda_trasa_ze_zdjeciem_jest_wyjeta_spod_progu_2_mb(): void
    {
        $wzorce = $this->wyjatki('@zaDuzeBezPlikow');

        foreach (self::TRASY_Z_PLIKAMI as $nazwa) {
            $trasa = Route::getRoutes()->getByName($nazwa);
            $this->assertNotNull($trasa, "Nie ma trasy `{$nazwa}` — lista w teście jest nieaktualna.");

            // Parametry {x} zastępujemy przykładową wartością.
            $adres = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', 'x', $trasa->uri()), '/');

            $pasuje = collect($wzorce)->contains(fn (string $w): bool => Str::is($w, $adres));

            $this->assertTrue(
                $pasuje,
                "Trasa `{$nazwa}` ({$adres}) przyjmuje zdjęcia, a Caddy odrzuci ją powyżej 2 MB. "
                .'Dopisz ją do obu list `not path` w docker/Caddyfile.',
            );
        }
    }

    public function test_zwykla_trasa_nie_jest_wyjeta(): void
    {
        // Kontrola dodatnia: lista wyjątków nie może obejmować wszystkiego.
        $wzorce = $this->wyjatki('@zaDuzeBezPlikow');

        foreach (['/login', '/register', '/napisz-do-nas', '/ustawienia/profil'] as $adres) {
            $this->assertFalse(
                collect($wzorce)->contains(fn (string $w): bool => Str::is($w, $adres)),
                "{$adres} nie przyjmuje plików, a stoi poza progiem 2 MB.",
            );
        }
    }

    public function test_obie_listy_wyjatkow_sa_identyczne(): void
    {
        $this->assertSame($this->wyjatki('@zaDuzeBezPlikow'), $this->wyjatki('@bezPlikow'));
    }

    public function test_prog_naglowka_i_request_body_to_te_same_2_000_000_bajtow(): void
    {
        $this->assertSame(1, preg_match('/header_regexp Content-Length (\S+)/', $this->caddyfile(), $m));
        $wzorzec = '/'.$m[1].'/';

        $this->assertSame(0, preg_match($wzorzec, '1999999'));
        $this->assertSame(1, preg_match($wzorzec, '2000000'));
        $this->assertSame(1, preg_match($wzorzec, '15000000'));
        $this->assertSame(1, preg_match($wzorzec, '117440512'));

        $this->assertStringContainsString("request_body @bezPlikow {\n\t\tmax_size 2MB", $this->caddyfile());
    }

    public function test_sufit_globalny_rowny_post_max_size(): void
    {
        $this->assertSame(1, preg_match('/^post_max_size=(\d+)M$/m', (string) file_get_contents(base_path('docker/php.ini')), $php));
        $this->assertSame(1, preg_match("/request_body \{\n\t\tmax_size (\d+)MiB/", $this->caddyfile(), $caddy));

        $this->assertSame($php[1], $caddy[1], 'Sufit Caddy rozjechał się z post_max_size w docker/php.ini.');
    }
}
