<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ZaufaneHosty;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Zbiór publicznych domen originu jest w repozytorium ZAMKNIĘTY (issue #1306).
 *
 * PO CO
 * Kod (`TokenKrawedzi`, `NormalizeForwardedFor`) potrafi rozpoznać żądanie
 * z pominięciem Cloudflare, ale dopiero po włączeniu trybu `egzekwowanie`.
 * Do tego czasu bezpieczeństwo adresu klienta opiera się na założeniu, że
 * jedyne wejście na origin prowadzi z brzegu Railway po domenach `kuking.pl`
 * i `www.kuking.pl`, za Cloudflare. Model zaufania proxy
 * (`KUKING_ZAUFANE_PRZESKOKI`, `trusted_proxies` w `docker/Caddyfile`) jest
 * policzony dla TEJ topologii. Dopisanie kolejnej domeny do IaC — także
 * domeny pomocniczej, testowej, „na chwilę” — otwiera nową drogę na origin
 * i zmienia ten model po cichu. Ten test sprawia, że zmiana jest głośna:
 * oblewa się, a jego komunikat mówi, co trzeba zrobić razem z nią.
 *
 * CO TEN TEST DOWODZI, A CZEGO NIE
 * Dowodzi tego, co JEST ZADEKLAROWANE w `.railway/railway.ts`: produkcja ma
 * dokładnie dwie domeny własne, obie pod `kuking.pl`, i żadnej domeny
 * dostawcy (`*.up.railway.app`, `*.railway.app`). Nie dowodzi stanu żywego
 * projektu Railway: domenę wygenerowaną ręcznie w panelu (Public Networking)
 * pokaże dopiero `railway config plan` (#595) albo odczyt panelu — krok C6
 * w `docs/infra/LISTA_KROKOW_ALFA.md`. Nie dowodzi też, że brzeg Railway
 * nie przyjmie żądania z `Host: kuking.pl` z pominięciem Cloudflare — od
 * tego jest token krawędzi.
 */
class DomenyOriginuSaZadeklarowaneWIacTest extends TestCase
{
    private function iac(): string
    {
        $sciezka = dirname(__DIR__, 2).'/.railway/railway.ts';

        $this->assertFileExists($sciezka, 'Nie ma .railway/railway.ts.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * @return list<string>
     */
    private function domeny(string $iac, string $stala): array
    {
        $this->assertSame(
            1,
            preg_match('/^const '.$stala.' = \[(.*?)\];$/ms', $iac, $blok),
            "Nie znalazłem `const {$stala} = [ … ];` w .railway/railway.ts — test czyta zły kształt pliku.",
        );

        preg_match_all('/domain:\s*"([^"]+)"/', $blok[1], $trafienia);

        $this->assertNotEmpty($trafienia[1], "`{$stala}` w railway.ts nie zawiera żadnego `domain: \"…\"`.");

        $domeny = $trafienia[1];
        sort($domeny);

        return $domeny;
    }

    private function komunikatZmiany(string $co): string
    {
        return $co.' Nowa domena originu to nowa droga na serwis, więc model zaufania proxy przestaje być '
            .'policzony dla tej topologii. Razem z tą zmianą: (1) potwierdź, że domena stoi za Cloudflare '
            .'z regułą nadpisującą X-Kuking-Edge-Token (docs/infra/LISTA_KROKOW_ALFA.md, krok C6), '
            .'(2) sprawdź `KUKING_ZAUFANE_PRZESKOKI` i `trusted_proxies` w docker/Caddyfile, '
            .'(3) dopisz host do App\\Support\\ZaufaneHosty, jeśli ma przyjmować ruch, '
            .'(4) dopiero potem zmień listę w tym teście.';
    }

    #[Test]
    public function produkcja_ma_dokladnie_dwie_domeny_wlasne_pod_kuking_pl(): void
    {
        $domeny = $this->domeny($this->iac(), 'PROD_DOMAINS');

        $oczekiwane = [ZaufaneHosty::KANONICZNY, ZaufaneHosty::WWW];
        sort($oczekiwane);

        $this->assertSame(
            $oczekiwane,
            $domeny,
            $this->komunikatZmiany('PROD_DOMAINS w .railway/railway.ts przestało być zbiorem {kuking.pl, www.kuking.pl}.'),
        );
    }

    #[Test]
    public function zadna_zadeklarowana_domena_nie_jest_domena_dostawcy(): void
    {
        $iac = $this->iac();

        foreach (['PROD_DOMAINS', 'STAGING_DOMAINS'] as $stala) {
            foreach ($this->domeny($iac, $stala) as $domena) {
                $this->assertMatchesRegularExpression(
                    '/(^|\.)kuking\.pl$/',
                    $domena,
                    $this->komunikatZmiany("{$stala} zawiera domenę „{$domena}” spoza kuking.pl."),
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/railway\.app$/',
                    $domena,
                    $this->komunikatZmiany("{$stala} zawiera domenę dostawcy „{$domena}”."),
                );
            }
        }
    }

    #[Test]
    public function domeny_z_iac_trafiaja_do_serwisu_tylko_przez_wybor_srodowiska(): void
    {
        $this->assertSame(
            1,
            preg_match('/^\s*domains: isProduction \? PROD_DOMAINS : isStaging \? STAGING_DOMAINS : \[\],$/m', $this->iac()),
            $this->komunikatZmiany('Przypisanie `domains:` w serwisie przestało wybierać wyłącznie PROD_DOMAINS / STAGING_DOMAINS.')
                .' Preview PR ma dostawać domenę od Railway, nie z tej listy.',
        );
    }

    #[Test]
    public function adres_aplikacji_produkcji_jest_stala_a_nie_domena_dostawcy(): void
    {
        $this->assertSame(
            1,
            preg_match(
                '/APP_URL: isProduction\s*\?\s*"https:\/\/kuking\.pl"\s*:\s*isStaging\s*\?\s*"https:\/\/staging\.kuking\.pl"\s*:/',
                $this->iac(),
            ),
            $this->komunikatZmiany('APP_URL produkcji przestał być stałą „https://kuking.pl”.'),
        );
    }
}
