<?php

declare(strict_types=1);

namespace Tests\Feature;

use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Tests\TestCase;

/**
 * Prefiks reguły lifecycle R2 z runbooka musi być TYM prefiksem, pod który
 * Livewire naprawdę zapisuje surowe uploady (issue #2051).
 *
 * Reguła w panelu Cloudflare żyje poza repozytorium. Jedyne, czego repo może
 * pilnować, to że instrukcja dla właściciela i dokumentacja podają prefiks
 * zgodny z efektywnym `config('livewire.temporary_file_upload.directory')`
 * (a gdy to `null` — z domyślnym `livewire-tmp` pakietu). Gdy ktoś zmieni
 * katalog, a reguła zostanie na starym prefiksie, surowe zdjęcia z GPS-em
 * przestaną wygasać bez żadnego sygnału. Ten test jest tym sygnałem.
 *
 * Test NIE dowodzi, że reguła istnieje w Cloudflare — to dowód z runbooka.
 *
 * @bez-kontroli-dodatniej Kontrola ujemna jest w tej samej klasie: `test_kontrola_ujemna_…` zmienia katalog Livewire na prawdziwych dokumentach i podaje dokumenty bez znacznika albo ze starą wzmianką, a każdy dokument musi mieć co najmniej jeden znacznik, więc brak dopasowań daje czerwień, nie cichą zieleń.
 */
class PrefiksRetencjiLivewireZgodnyZKonfiguracjaTest extends TestCase
{
    /** Dokumenty, które podają właścicielowi prefiks reguły. */
    private const DOKUMENTY = [
        'docs/infra/LIVEWIRE_TMP_R2_RETENCJA_2051.md',
        'docs/infra/DIRECT_UPLOAD_R2_ANALIZA_602.md',
    ];

    /** Jawny znacznik w dokumencie: jedno miejsce, z którego człowiek przepisuje prefiks do panelu. */
    private const ZNACZNIK = '/Prefiks reguły lifecycle: `([^`]*)`/u';

    public function test_prefiks_w_runbooku_i_analizie_zgadza_sie_z_efektywnym_katalogiem_livewire(): void
    {
        // `root` na dysku dopisałby się przed katalog Livewire i prefiks
        // w buckecie byłby inny niż w dokumentach.
        $this->assertArrayNotHasKey(
            'root',
            (array) config('filesystems.disks.r2'),
            'Dysk `r2` dostał `root` — prefiks w buckecie to teraz `root/katalog/`. Popraw runbook #2051 i ten test.',
        );

        $this->assertSame([], $this->rozjazdy($this->oczekiwanyPrefiks(), $this->prawdziweDokumenty()));
    }

    public function test_katalog_null_znaczy_domyslny_prefiks_pakietu_a_wlasny_jest_normalizowany(): void
    {
        config()->set('livewire.temporary_file_upload.directory', null);
        $this->assertSame('livewire-tmp/', $this->oczekiwanyPrefiks());

        config()->set('livewire.temporary_file_upload.directory', '/uploady/');
        $this->assertSame('uploady/', $this->oczekiwanyPrefiks());
    }

    /**
     * Kontrola ujemna strażnika: bez niej pusty wynik `rozjazdy()` mógłby
     * znaczyć „nic nie sprawdziłem" (`docs/PULAPKI_TESTOW.md` §2 i §4).
     */
    public function test_kontrola_ujemna_zmiana_katalogu_albo_brak_znacznika_daje_rozjazd(): void
    {
        config()->set('livewire.temporary_file_upload.directory', 'uploady-tymczasowe');
        $rozjazdy = $this->rozjazdy($this->oczekiwanyPrefiks(), $this->prawdziweDokumenty());

        foreach (self::DOKUMENTY as $dokument) {
            $this->assertNotEmpty(
                array_filter($rozjazdy, fn (string $r): bool => str_starts_with($r, $dokument)),
                "Zmiana katalogu Livewire nie wywołała rozjazdu w {$dokument}.",
            );
        }

        $this->assertSame(
            ['bez.md: brak znacznika „Prefiks reguły lifecycle: `…`”'],
            $this->rozjazdy('livewire-tmp/', ['bez.md' => 'Reguła na `livewire-tmp/`, bez znacznika.']),
        );

        $this->assertSame(
            ['stara.md: wzmianka `livewire-tmp/stare` nie zaczyna się od `uploady/`'],
            $this->rozjazdy('uploady/', ['stara.md' => "Prefiks reguły lifecycle: `uploady/`\nKiedyś `livewire-tmp/stare`."]),
        );

        $this->assertSame(
            [],
            $this->rozjazdy('livewire-tmp/', ['dobra.md' => "Prefiks reguły lifecycle: `livewire-tmp/`\nObiekt `livewire-tmp/kontrola.txt`."]),
        );
    }

    /** Prefiks klucza w buckecie, wyliczony tą samą funkcją pakietu, której używa zapis uploadu. */
    private function oczekiwanyPrefiks(): string
    {
        return FileUploadConfiguration::directory().'/';
    }

    /** @return array<string, string> ścieżka => treść */
    private function prawdziweDokumenty(): array
    {
        $tresci = [];

        foreach (self::DOKUMENTY as $dokument) {
            $sciezka = base_path($dokument);
            $this->assertFileExists($sciezka, "Brak dokumentu {$dokument}, który podaje prefiks reguły lifecycle.");
            $tresci[$dokument] = (string) file_get_contents($sciezka);
        }

        return $tresci;
    }

    /**
     * @param  array<string, string>  $dokumenty  ścieżka => treść
     * @return list<string>
     */
    private function rozjazdy(string $oczekiwany, array $dokumenty): array
    {
        $rozjazdy = [];

        foreach ($dokumenty as $dokument => $tresc) {
            preg_match_all(self::ZNACZNIK, $tresc, $znaczniki);

            if ($znaczniki[1] === []) {
                $rozjazdy[] = "{$dokument}: brak znacznika „Prefiks reguły lifecycle: `…`”";
            }

            foreach ($znaczniki[1] as $prefiks) {
                if ($prefiks !== $oczekiwany) {
                    $rozjazdy[] = "{$dokument}: znacznik `{$prefiks}` zamiast `{$oczekiwany}`";
                }
            }

            // Każda inna wzmianka o katalogu Livewire w całym tekście, także
            // w blokach poleceń: `livewire-tmp/` w opisie, `--prefix …`, klucz
            // obiektu kontrolnego. Poprzedzający `/` wyklucza ścieżki kodu
            // w rodzaju `vendor/livewire/…`.
            preg_match_all('#(?<![\w./-])([\w.-]*livewire[\w.-]*/[^\s`"\'<>)]*)#u', $tresc, $wzmianki);

            foreach (array_unique($wzmianki[1]) as $wzmianka) {
                if (! str_starts_with($wzmianka, $oczekiwany)) {
                    $rozjazdy[] = "{$dokument}: wzmianka `{$wzmianka}` nie zaczyna się od `{$oczekiwany}`";
                }
            }
        }

        return $rozjazdy;
    }
}
