<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ciasteczko sesji na produkcji leci wyłącznie po HTTPS.
 *
 * DLACZEGO TEN TEST W OGÓLE POWSTAŁ
 * Wiersz listy gotowości „`SESSION_SECURE_COOKIE` ustawione na produkcji"
 * (`docs/legal/COMPLIANCE.md` §7) miał dotąd dowód klasy
 * `DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` — „odczytać w panelu Railway".
 * Taki dowód starzeje się w tym samym tempie co cudza pamięć: ktoś
 * odczytał, odhaczył, a pół roku później zmienna znika przy przenosinach
 * usługi i nic tego nie zauważa.
 *
 * CZEGO TEN TEST NIE DOWODZI — I TO JEST WAŻNIEJSZE NIŻ TO, CO DOWODZI
 * Nie sięga na produkcję i nie wie, co stoi w panelu Railway. Dowodzi
 * wyłącznie, że **przez pominięcie** nie da się dostać niezabezpieczonego
 * ciasteczka: brak zmiennej na `APP_ENV=production` daje `true`, a nie
 * `null`. Jawne `SESSION_SECURE_COOKIE=false` nadal wygrywa — konfiguracja
 * ma być do zmiany, ale świadomie.
 *
 * Stan faktyczny produkcji z 20 września 2026 jest osobnym dowodem
 * i stoi przy tym wierszu w `COMPLIANCE.md`: `https://kuking.pl/login`
 * oddawał wtedy `Set-Cookie: kuking-session=…; path=/; secure; httponly;
 * samesite=lax`. Pomiar i test mierzą dwie różne rzeczy i żaden nie
 * zastępuje drugiego.
 *
 * DLACZEGO PRZEZ `require`, A NIE PRZEZ `config()`
 * `config('session.secure')` w przebiegu testów oddaje wartość policzoną
 * dla `APP_ENV=testing` — czyli nie tę, o którą pytamy. Plik konfiguracyjny
 * jest zwykłym plikiem PHP zwracającym tablicę, więc da się go policzyć
 * jeszcze raz, przy podstawionym środowisku, nie ruszając bieżącej aplikacji.
 */
final class CiasteczkoSesjiJestSecureNaProdukcjiTest extends TestCase
{
    /** @var array<string, string|false|null> */
    private array $zachowane = [];

    protected function tearDown(): void
    {
        foreach ($this->zachowane as $klucz => $wartosc) {
            $this->przywroc($klucz, $wartosc);
        }

        $this->zachowane = [];

        parent::tearDown();
    }

    public function test_brak_zmiennej_na_produkcji_daje_ciasteczko_secure(): void
    {
        $this->podstaw('APP_ENV', 'production');
        $this->podstaw('SESSION_SECURE_COOKIE', null);

        $this->assertTrue(
            $this->policzKonfiguracjeSesji()['secure'],
            'Na `APP_ENV=production` bez ustawionej zmiennej `SESSION_SECURE_COOKIE` '
            .'ciasteczko sesji NIE jest oznaczone `secure`. To znaczy, że sesja zalogowanej '
            .'osoby może wyjść po zwykłym HTTP — a jedyną rzeczą, która temu zapobiega, '
            .'jest zmienna w panelu dostawcy, której nie widać z repozytorium.',
        );
    }

    /**
     * KONTROLA DODATNIA. Gdyby `config/session.php` zaczął twardo oddawać
     * `true` niezależnie od czegokolwiek, test wyżej dalej byłby zielony,
     * a przestałby mierzyć. Lokalnie ciasteczko `secure` łamie pracę na
     * `http://localhost`, więc poza produkcją domyślnik MUSI być inny.
     */
    public function test_kontrola_poza_produkcja_domyslnik_nie_wymusza_secure(): void
    {
        $this->podstaw('APP_ENV', 'local');
        $this->podstaw('SESSION_SECURE_COOKIE', null);

        $this->assertNotTrue(
            $this->policzKonfiguracjeSesji()['secure'],
            'Poza produkcją domyślnik wymusza `secure` — praca na `http://localhost` '
            .'przestanie utrzymywać sesję, a test produkcyjny wyżej przestanie cokolwiek mierzyć.',
        );
    }

    /**
     * Jawne wyłączenie nadal działa. Bez tej asercji nie byłoby wiadomo,
     * czy domyślnik jest domyślnikiem, czy blokadą — a to jest różnica,
     * którą ktoś będzie musiał znać w dniu awarii certyfikatu.
     */
    public function test_jawne_wylaczenie_wygrywa_z_domyslnikiem(): void
    {
        $this->podstaw('APP_ENV', 'production');
        $this->podstaw('SESSION_SECURE_COOKIE', 'false');

        $this->assertFalse(
            $this->policzKonfiguracjeSesji()['secure'],
            'Jawne `SESSION_SECURE_COOKIE=false` przestało działać. Domyślnik miał '
            .'zabezpieczać przed pominięciem, a nie odbierać możliwość świadomej zmiany.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function policzKonfiguracjeSesji(): array
    {
        /** @var array<string, mixed> $konfiguracja */
        $konfiguracja = require base_path('config/session.php');

        return $konfiguracja;
    }

    /**
     * Podstawia zmienną środowiskową we WSZYSTKICH trzech miejscach, z których
     * czyta `env()` (`$_SERVER`, `$_ENV`, `putenv`). Podstawienie w jednym
     * nie wystarcza: repozytorium Dotenva pyta je po kolei i oddaje pierwsze
     * trafienie, a `phpunit.xml` ustawia `APP_ENV` w dwóch z nich naraz.
     */
    private function podstaw(string $klucz, ?string $wartosc): void
    {
        if (! array_key_exists($klucz, $this->zachowane)) {
            $this->zachowane[$klucz] = $_SERVER[$klucz] ?? $_ENV[$klucz] ?? getenv($klucz);
        }

        $this->przywroc($klucz, $wartosc);
    }

    private function przywroc(string $klucz, string|false|null $wartosc): void
    {
        if ($wartosc === null || $wartosc === false) {
            unset($_SERVER[$klucz], $_ENV[$klucz]);
            putenv($klucz);

            return;
        }

        $_SERVER[$klucz] = $wartosc;
        $_ENV[$klucz] = $wartosc;
        putenv($klucz.'='.$wartosc);
    }
}
