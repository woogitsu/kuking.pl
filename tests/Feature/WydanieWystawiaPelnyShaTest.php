<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/wydanie` to kontrakt testu dymnego po wdrożeniu (issue #1012): sonda
 * w `deploy.yml` porównuje pole `commit` z pełnym SHA zdarzenia
 * `deployment_status`. Skrót ze stopki się do tego nie nadaje — siedem znaków
 * jest niejednoznaczne, a HTML może przyjść z cache.
 */
class WydanieWystawiaPelnyShaTest extends TestCase
{
    private const SHA = '1a4ab54c4141bccb6cce9e1947cbfb0a227e4894';

    #[Test]
    public function wystawia_pelny_sha_wdrozonego_commita(): void
    {
        config(['kuking.wersja.commit' => self::SHA]);

        $this->get('/wydanie')
            ->assertOk()
            ->assertExactJson(['commit' => self::SHA]);
    }

    #[Test]
    public function bez_wdrozenia_mowi_null_a_nie_lokalnie(): void
    {
        // Sonda ma rozpoznać BRAK sygnału, a nie porównywać SHA z napisem
        // „lokalnie" przeznaczonym dla ludzi.
        config(['kuking.wersja.commit' => null]);

        $this->get('/wydanie')
            ->assertOk()
            ->assertExactJson(['commit' => null]);
    }

    #[Test]
    public function odpowiedz_nie_moze_przyjsc_z_cache_zadnej_warstwy(): void
    {
        config(['kuking.wersja.commit' => self::SHA]);

        $odpowiedz = $this->get('/wydanie');

        // Odpowiedź sprzed wdrożenia podana z cache to dokładnie ta fałszywa
        // zieleń, przed którą ten punkt ma chronić. `CDN-Cache-Control` ma
        // u brzegu pierwszeństwo nad `Cache-Control`, więc nie wolno go
        // wystawić z czymkolwiek poza `no-store`.
        $this->assertStringContainsString('no-store', (string) $odpowiedz->headers->get('Cache-Control'));

        foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control', 'Surrogate-Control'] as $naglowek) {
            $wartosc = $odpowiedz->headers->get($naglowek);
            $this->assertTrue(
                $wartosc === null || str_contains($wartosc, 'no-store'),
                "`/wydanie` wystawia `{$naglowek}: {$wartosc}` — brzeg mógłby podać SHA sprzed wdrożenia.",
            );
        }
    }

    #[Test]
    public function nie_startuje_sesji_i_nie_stawia_ciasteczek(): void
    {
        // Sonda odpytuje `/wydanie` co kilka sekund. Grupa `web` przy każdym
        // takim pytaniu zakładała nową sesję (wiersz w bazie sesji) i odsyłała
        // `Set-Cookie` z sesją i tokenem CSRF — dla punktu, który zwraca
        // jeden SHA i niczego od klienta nie przyjmuje.
        config(['kuking.wersja.commit' => self::SHA]);

        $odpowiedz = $this->get('/wydanie');

        // Kontrola dodatnia: to jest właściwa odpowiedź, nie 404 czy
        // przekierowanie, które też nie miałyby ciasteczek.
        $odpowiedz->assertOk()->assertExactJson(['commit' => self::SHA]);

        $this->assertSame([], $odpowiedz->headers->getCookies(), '`/wydanie` stawia ciasteczka.');
        $this->assertFalse($odpowiedz->headers->has('Set-Cookie'), '`/wydanie` wysyła Set-Cookie.');
        $this->assertFalse($this->app['session']->isStarted(), '`/wydanie` wystartował sesję.');
    }
}
