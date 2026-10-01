<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Połączenie aplikacji z PostgreSQL ma `jit = off` (issue #2291, audyt
 * wydajności F4).
 *
 * DLACZEGO. Obciążenie to OLTP. Każde zapytanie, którego szacunek kosztu
 * przekracza `jit_above_cost` (domyślnie 100000), było kompilowane przez JIT
 * przy każdym wykonaniu — setki ms do kilku sekund (#2288, #2289). Punktowe
 * przepisywanie zapytań łata objaw; to ustawienie zamyka klasę.
 *
 * JAK. `server_options` w `config/database.php` -> `-c jit=off` w pakiecie
 * startowym połączenia. Zwykły użytkownik bazy może ustawić `jit` sam, więc
 * nie trzeba superużytkownika ani zmiany w panelu bazy.
 *
 * CZEGO TEN PLIK NIE DOWODZI. Że sama baza produkcyjna ma `jit = off` —
 * ustawienie serwera nas nie obchodzi, liczy się sesja aplikacji, i to ją
 * mierzymy (`SHOW jit` na świeżo otwartym połączeniu).
 */
class PolaczenieBazyMaWylaczonyJitTest extends TestCase
{
    private function jitNaSwiezymPolaczeniu(string $nazwa = 'pgsql'): string
    {
        DB::purge($nazwa);

        return (string) DB::connection($nazwa)->scalar('SHOW jit');
    }

    #[Test]
    public function swieze_polaczenie_aplikacji_ma_jit_off(): void
    {
        $this->assertSame('off', $this->jitNaSwiezymPolaczeniu(), 'JIT_2291_SWIEZA_SESJA_WYMAGA_OFF');
    }

    #[Test]
    public function drugie_polaczenie_z_tej_samej_konfiguracji_tez_ma_jit_off(): void
    {
        // Worker kolejki, harmonogram i migracje klonują konfigurację `pgsql`
        // pod inną nazwą — opcja nie może zależeć od nazwy połączenia.
        config()->set('database.connections.drugie', config('database.connections.pgsql'));

        $this->assertSame('off', $this->jitNaSwiezymPolaczeniu('drugie'));

        DB::purge('drugie');
    }

    #[Test]
    public function opcja_przezywa_konfiguracje_z_adresu_db_url(): void
    {
        // Na Railway połączenie powstaje z `DB_URL`; parser adresu nie może
        // zgubić `server_options`.
        $c = config('database.connections.pgsql');
        config()->set('database.connections.z_adresu', array_merge($c, [
            'url' => sprintf(
                'pgsql://%s:%s@%s:%s/%s',
                rawurlencode((string) $c['username']),
                rawurlencode((string) $c['password']),
                $c['host'],
                $c['port'],
                $c['database'],
            ),
        ]));

        $this->assertSame('off', $this->jitNaSwiezymPolaczeniu('z_adresu'));

        DB::purge('z_adresu');
    }

    #[Test]
    public function zmienna_db_jit_on_przywraca_ustawienie_serwera(): void
    {
        // Kontrola dodatnia mechanizmu: ta sama droga, wartość `on` — test
        // widzi różnicę, więc `off` wyżej nie bierze się z przypadku
        // (np. z ustawienia serwera testowego).
        config()->set('database.connections.pgsql.server_options', ['jit' => 'on']);

        $this->assertSame('on', $this->jitNaSwiezymPolaczeniu());

        config()->set('database.connections.pgsql.server_options', ['jit' => 'off']);
        DB::purge('pgsql');
    }

    #[Test]
    public function domyslna_konfiguracja_wylacza_jit(): void
    {
        $this->assertSame(['jit' => 'off'], config('database.connections.pgsql.server_options'));
    }

    #[Test]
    public function puste_db_jit_w_srodowisku_tez_daje_off_a_nie_bledne_c_jit(): void
    {
        // `DB_JIT=` w panelu hostingu albo w `.env` to w `env()` pusty napis,
        // nie null — domyślna wartość `env('DB_JIT', 'off')` go nie zastępuje,
        // a pakiet startowy dostawał `-c jit=` i połączenie padało.
        $poZmiennych = [];
        $poprzednio = getenv('DB_JIT');

        try {
            foreach (['brak' => false, 'puste' => '', 'on' => 'on'] as $opis => $wartosc) {
                $wartosc === false ? putenv('DB_JIT') : putenv('DB_JIT='.$wartosc);
                $poZmiennych[$opis] = (require config_path('database.php'))['connections']['pgsql']['server_options'];
            }
        } finally {
            $poprzednio === false ? putenv('DB_JIT') : putenv('DB_JIT='.$poprzednio);
        }

        $this->assertSame([
            'brak' => ['jit' => 'off'],
            'puste' => ['jit' => 'off'],
            'on' => ['jit' => 'on'],
        ], $poZmiennych);
    }
}
