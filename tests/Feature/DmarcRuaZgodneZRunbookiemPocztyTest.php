<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dokumenty poczty mówią jedno o rekordzie DMARC i opisują dowód, że raporty
 * `rua` naprawdę dochodzą (issue #2049).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Runbook publikował `rua=mailto:kontakt@kuking.pl` w ośmiu miejscach, a domena
 * nie miała rekordu MX (sprawdzone 27.09.2026): raporty zbiorcze nie miały
 * żadnej trasy odbioru i ginęły po cichu. Rekord TXT był, dowodu doręczenia nie.
 *
 * CO TEN TEST PILNUJE (i tylko to)
 *  1. `POCZTA_URUCHOMIENIE.md` §3A ma DOKŁADNIE JEDNĄ linię „Oczekiwany rekord
 *     DMARC” — jedyne źródło wartości.
 *  2. Każdy wiersz `_dmarc` w `POCZTA_URUCHOMIENIE.md` i `DEPLOYMENT_RUNBOOK.md`
 *     jest identyczny z tym źródłem. Zmiana odbiorcy raportów w jednym miejscu
 *     bez pozostałych daje czerwień z listą rozjechanych wierszy.
 *  3. Adres w `rua` jest jednym adresem `mailto:` — bez listy i bez pustki.
 *  4. Sekcja §3A wymaga dowodu doręczenia (MX, list z zewnątrz, pierwszy raport),
 *     a nie samej obecności TXT.
 *
 * CZEGO TU NIE MA
 * Nie sprawdzamy żywego DNS ani tego, czy skrzynka odbiera: to jest krok
 * właściciela w panelu Cloudflare i poza zasięgiem suity. Test pilnuje spójności
 * dokumentów, nie stanu domeny; zieleń NIE znaczy, że raporty dochodzą.
 *
 * @bez-kontroli-dodatniej Czyta dokumenty w docs/infra i docs/OTWARCIE.md, nie źródła aplikacji; kontrolę dodatnią ma w samym teście (wymaga co najmniej jednego wiersza `_dmarc` w każdym pliku i jednej linii źródła), a kontrola ujemna — rozjechanie jednego wiersza i usunięcie kroku dowodu — wykonana ręcznie i opisana w commicie.
 */
final class DmarcRuaZgodneZRunbookiemPocztyTest extends TestCase
{
    private const POCZTA = 'docs/infra/POCZTA_URUCHOMIENIE.md';

    private const RUNBOOK = 'docs/infra/DEPLOYMENT_RUNBOOK.md';

    private const OTWARCIE = 'docs/OTWARCIE.md';

    public function test_jest_jedno_zrodlo_oczekiwanego_rekordu(): void
    {
        $this->assertNotSame('', $this->oczekiwanyRekord());
    }

    public function test_oczekiwany_rekord_ma_jeden_adres_mailto_na_raporty(): void
    {
        $rekord = $this->oczekiwanyRekord();

        $this->assertStringStartsWith('v=DMARC1;', $rekord);
        $this->assertSame(
            1,
            preg_match_all('/\brua=/', $rekord),
            'Rekord ma mieć dokładnie jedno pole rua.',
        );
        $this->assertMatchesRegularExpression(
            '/\brua=mailto:[^\s,;@]+@[^\s,;@]+\.[a-z]{2,}(?:;|$)/i',
            $rekord,
            'rua ma wskazywać jeden adres mailto: (bez listy po przecinku i bez pustki).',
        );
    }

    public function test_kazdy_wiersz_dmarc_w_runbookach_jest_zgodny_ze_zrodlem(): void
    {
        $oczekiwany = $this->oczekiwanyRekord();

        foreach ([self::POCZTA, self::RUNBOOK] as $plik) {
            $wiersze = $this->wierszeDmarc($plik);

            // KONTROLA DODATNIA: dokument w ogóle podaje rekord DMARC. Bez niej
            // zmiana formatu tabeli (np. inna nazwa kolumny) wyłączyłaby test
            // po cichu i zostawiła zieleń.
            $this->assertNotEmpty($wiersze, "{$plik} nie ma już żadnego wiersza TXT `_dmarc` — test przestał czegokolwiek pilnować.");

            foreach ($wiersze as $numer => $wartosc) {
                $this->assertSame(
                    $oczekiwany,
                    $wartosc,
                    "{$plik}:{$numer} podaje inny rekord DMARC niż „Oczekiwany rekord DMARC” w ".self::POCZTA.' §3A.',
                );
            }
        }
    }

    public function test_sekcja_3a_wymaga_dowodu_doreczenia_a_nie_samego_txt(): void
    {
        $sekcja = $this->sekcja3A();

        foreach ([
            'dig kuking.pl MX +short' => 'sprawdzenie, że MX istnieje',
            'DNS only' => 'zakaz proxowania rekordów poczty',
            'List z zewnątrz dociera' => 'dowód odbioru listu od człowieka',
            'pierwszy raport zbiorczy' => 'dowód, że raport DMARC faktycznie przyszedł',
            'datę oraz' => 'zapis daty i metody w docs/OTWARCIE.md',
        ] as $fraza => $po_co) {
            $this->assertStringContainsStringIgnoringCase(
                $fraza,
                $sekcja,
                "Sekcja §3A straciła frazę „{$fraza}” ({$po_co}).",
            );
        }
    }

    public function test_otwarcie_odsyla_do_sekcji_3a_i_nie_zostawia_bramki_bez_braku_mx(): void
    {
        $otwarcie = $this->plik(self::OTWARCIE);

        $this->assertMatchesRegularExpression(
            '/^\| 3 \|[^\n]*MX[^\n]*POCZTA_URUCHOMIENIE\.md` §3A/mu',
            $otwarcie,
            'Wiersz 3 tabeli w docs/OTWARCIE.md ma mówić o braku MX i odsyłać do POCZTA_URUCHOMIENIE.md §3A.',
        );
    }

    private function oczekiwanyRekord(): string
    {
        $tresc = $this->plik(self::POCZTA);

        $liczba = preg_match_all('/^\*\*Oczekiwany rekord DMARC:\*\* `([^`]+)`\s*$/mu', $tresc, $trafienia);

        $this->assertSame(
            1,
            $liczba,
            'W '.self::POCZTA.' ma być dokładnie jedna linia „**Oczekiwany rekord DMARC:** `…`”, znaleziono '.$liczba.'.',
        );

        return trim($trafienia[1][0]);
    }

    /**
     * @return array<int, string> numer linii => wartość rekordu z tabeli
     */
    private function wierszeDmarc(string $sciezka): array
    {
        $wyniki = [];

        foreach (preg_split('/\R/u', $this->plik($sciezka)) ?: [] as $indeks => $linia) {
            if (preg_match('/^\|\s*TXT\s*\|\s*`_dmarc`\s*\|\s*`([^`]+)`/u', $linia, $m) === 1) {
                $wyniki[$indeks + 1] = trim($m[1]);
            }
        }

        return $wyniki;
    }

    private function sekcja3A(): string
    {
        $tresc = $this->plik(self::POCZTA);

        $this->assertSame(
            1,
            preg_match('/^## 3A\. .*?(?=^## 4\. )/msu', $tresc, $m),
            'Nie znaleziono sekcji „## 3A.” przed „## 4.” w '.self::POCZTA.'.',
        );

        return $m[0];
    }

    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);
        $this->assertFileExists($pelna);

        $tresc = (string) file_get_contents($pelna);
        $this->assertNotSame('', trim($tresc), "{$sciezka} jest pusty.");

        return $tresc;
    }
}
