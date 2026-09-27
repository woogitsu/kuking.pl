<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `.env.example` wymienia obie zmienne czyszczenia cache CDN (#1741).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * `config/kuking.php` (`media.cdn_purge`) czyta `CLOUDFLARE_ZONE_ID`
 * i `CLOUDFLARE_PURGE_TOKEN`, `docs/infra/DEPLOYMENT_RUNBOOK.md` opisuje je
 * jako zmienne wdrożeniowe (token — wymagany), a `PurgePublicMediaCache`
 * i `HealthController` na nich polegają. `.env.example` ich nie miał, więc
 * osoba zakładająca środowisko z szablonu nie widziała, że taka
 * konfiguracja w ogóle istnieje — musiała ją znaleźć dopiero w kodzie albo
 * w runbooku.
 *
 * CO PILNUJE TEN TEST
 *  1. obie nazwy występują w `.env.example` DOKŁADNIE RAZ i są dokładnie
 *     tymi samymi nazwami, które czyta `config/kuking.php`;
 *  2. żadna z nich nie ma wpisanej wartości (byłby to wyciek albo fałszywy
 *     pozór poprawnej konfiguracji) — to samo pilnuje z drugiej strony
 *     `PoswiadczeniaPozaRepozytoriumTest`, ten test patrzy węziej i celowo;
 *  3. `docs/infra/DEPLOYMENT_RUNBOOK.md` wciąż zna te same dwie nazwy —
 *     rozjazd nazw między szablonem a runbookiem byłby tą samą klasą błędu
 *     w drugą stronę.
 */
class EnvExampleMaZmienneCzyszczeniaCdnTest extends TestCase
{
    private const ENV_EXAMPLE = '.env.example';

    private const RUNBOOK = 'docs/infra/DEPLOYMENT_RUNBOOK.md';

    /** Zmienne czytane przez `config/kuking.php` → `media.cdn_purge`. */
    private const ZMIENNE = ['CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_PURGE_TOKEN'];

    public function test_obie_zmienne_sa_w_env_example_dokladnie_raz_i_puste(): void
    {
        $tresc = (string) file_get_contents(base_path(self::ENV_EXAMPLE));

        foreach (self::ZMIENNE as $zmienna) {
            $wystapienia = preg_match_all(
                '/^'.preg_quote($zmienna, '/').'=(.*)$/m',
                $tresc,
                $dopasowania,
            );

            $this->assertSame(
                1,
                $wystapienia,
                self::ENV_EXAMPLE." ma {$wystapienia} wystąpień `{$zmienna}` — oczekiwane dokładnie 1. ".
                    'config/kuking.php (media.cdn_purge) czyta tę nazwę literalnie.',
            );

            $this->assertSame(
                '',
                trim($dopasowania[1][0]),
                self::ENV_EXAMPLE." — `{$zmienna}` ma wpisaną wartość. Szablon jest w repozytorium, ".
                    'więc każda wartość poświadczenia purge CDN tutaj jest wyciekiem.',
            );
        }
    }

    public function test_konfiguracja_czyta_te_same_dwie_nazwy(): void
    {
        $konfiguracja = (string) file_get_contents(base_path('config/kuking.php'));

        foreach (self::ZMIENNE as $zmienna) {
            $this->assertStringContainsString(
                "env('{$zmienna}'",
                $konfiguracja,
                "config/kuking.php przestał czytać `{$zmienna}` — dopasuj ten test albo napraw konfigurację.",
            );
        }
    }

    public function test_runbook_zna_te_same_dwie_nazwy(): void
    {
        $runbook = (string) file_get_contents(base_path(self::RUNBOOK));

        foreach (self::ZMIENNE as $zmienna) {
            $this->assertStringContainsString(
                $zmienna,
                $runbook,
                self::RUNBOOK." nie wspomina już `{$zmienna}` — nazwy w szablonie i w runbooku się rozjechały.",
            );
        }
    }
}
