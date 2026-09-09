<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runbook wdrożeniowy nie każe odtwarzać luki, którą zamknęło D-020 (audyt G14).
 *
 * CO BYŁO NIE TAK
 * `docs/infra/DEPLOYMENT_RUNBOOK.md` §2.3 był poprawnie WYCOFANY — z dużym
 * ostrzeżeniem i uzasadnieniem. Pod tym ostrzeżeniem stała jednak dalej sama
 * instrukcja, nieodróżnialna od żywej: „→ Custom Domains → Add → Domena:
 * `cdn.kuking.pl`". A sprawdzenie „`curl -I https://cdn.kuking.pl/test.txt`,
 * oczekiwane 404" wylądowało na końcu §2.4 (CORS), czyli rozdziału, który
 * wykonać TRZEBA.
 *
 * Do tego w trzech innych miejscach dokument mówił rzeczy nieprawdziwe:
 * tabela zmiennych produkcyjnych kazała ustawić `R2_PUBLIC_URL`, którego
 * dziś nie czyta ANI JEDNA linijka kodu; weryfikacja po wdrożeniu nazywała
 * błąd DNS awarią, choć po D-020 jest on wynikiem POPRAWNYM; a test ręczny
 * 14 kazał sprawdzić, że adres zdjęcia zaczyna się od `cdn.kuking.pl` —
 * czyli że luka JEST.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Runbook czyta się pod presją, przy pierwszym wdrożeniu albo przy awarii,
 * i wykonuje po kolei. Utworzenie tej domeny — a zwłaszcza razem z regułą
 * cache'u na 30 dni z §10.5 — przywraca stan, w którym adres pliku raz
 * skopiowany działa dalej po zablokowaniu, po cofnięciu obserwowania i po
 * decyzji moderacyjnej. Najgorszy przypadek nazwał audyt W7-02 wprost: skan
 * odręcznej kartki z rodzinnym przepisem, a na niej nazwiska i adresy.
 *
 * CO TEN TEST SPRAWDZA
 * Nie „czy dokument brzmi ładnie", tylko dwie rzeczy sprawdzalne maszynowo:
 * czy poza blokami jawnie oznaczonymi jako historyczne (`<details>`) nie
 * ostała się instrukcja ustawienia `R2_PUBLIC_URL`, i czy prefiks adresu
 * zdjęcia podany w runbooku jest tym, który NAPRAWDĘ produkuje `Media::url()`.
 */
class RunbookNieKazeTworzycDomenyZdjecTest extends TestCase
{
    use RefreshDatabase;

    private function runbook(): string
    {
        return (string) file_get_contents(base_path('docs/infra/DEPLOYMENT_RUNBOOK.md'));
    }

    /** Dokument bez bloków `<details>`, czyli to, co operator wykonuje. */
    private function czescDoWykonania(): string
    {
        return (string) preg_replace('/<details>.*?<\/details>/su', '', $this->runbook());
    }

    public function test_poza_blokami_historycznymi_nie_ma_instrukcji_ustawienia_r2_public_url(): void
    {
        $doWykonania = $this->czescDoWykonania();

        // Szukamy PRZYPISANIA WARTOŚCI, nie samej nazwy zmiennej: wzmianka
        // „nie ustawiaj `R2_PUBLIC_URL`" ma zostać i jest tu pożądana.
        $this->assertDoesNotMatchRegularExpression(
            '/R2_PUBLIC_URL`?\s*=\s*`?https/u',
            $doWykonania,
            'Runbook znowu każe ustawić `R2_PUBLIC_URL` na adres. Nic w kodzie tej '
            .'zmiennej nie czyta, a domena, którą opisuje, przywraca lukę zamkniętą '
            .'przez D-020 i audyt W7-02. Jeśli to opis historyczny — schowaj go '
            .'w bloku `<details>`, tak jak resztę wycofanego §2.3.',
        );
    }

    /**
     * Kontrola metody pomiaru: bloki `<details>` naprawdę coś zasłaniają.
     *
     * Bez tego asercja wyżej przechodziłaby również wtedy, gdyby ktoś skasował
     * całą historię z dokumentu — a wtedy test mówiłby „czysto", nie mierząc
     * niczego. Sprawdzamy więc, że wycięty fragment ISTNIEJE i że to właśnie
     * w nim siedzi stara instrukcja.
     */
    public function test_kontrola_bloki_historyczne_istnieja_i_to_w_nich_stoi_stara_instrukcja(): void
    {
        $caly = $this->runbook();
        $doWykonania = $this->czescDoWykonania();

        $this->assertNotSame(
            $caly,
            $doWykonania,
            'W runbooku nie ma ANI JEDNEGO bloku `<details>`. Test wyżej nie mierzy '
            .'wtedy niczego — sprawdza dokument, z którego nic nie wycięto.',
        );

        $this->assertMatchesRegularExpression(
            '/R2_PUBLIC_URL`?\s*=\s*`?https/u',
            $caly,
            'Stara instrukcja zniknęła z dokumentu w całości. Miała zostać jako '
            .'historia — `r2_legacy` i `kuking:przenies-zdjecia` bez niej są '
            .'nie do zrozumienia.',
        );
    }

    /**
     * Prefiks adresu zdjęcia w runbooku ma być tym, który produkuje kod.
     *
     * To jest ta sama klasa usterki co w `DATABASE.md` (G13): dokument mówi
     * o systemie coś, czego system nie robi. Tu skutek jest gorszy niż
     * pomyłka w opisie — operator sprawdzający po wdrożeniu, że adres zaczyna
     * się od `cdn.kuking.pl`, uznałby DZIAŁAJĄCĄ ochronę za awarię i poszedł
     * ją „naprawić".
     */
    public function test_runbook_podaje_ten_sam_prefiks_adresu_zdjecia_co_kod(): void
    {
        $zdjecie = Media::factory()->create([
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['feed' => ['key' => 'media/x_feed.webp', 'width' => 800, 'height' => 800]]],
        ]);

        $sciezka = (string) parse_url($zdjecie->url(), PHP_URL_PATH);

        // Kontrola: gdyby `url()` przestało zwracać trasę aplikacji, dalsze
        // asercje porównywałyby runbook z placeholderem albo z adresem pliku.
        $this->assertStringStartsWith(
            '/zdjecia/',
            $sciezka,
            'Media::url() nie zwraca już trasy `/zdjecia/...`. Jeśli to zamierzona '
            .'zmiana, popraw razem z nią runbook (§11, test ręczny 14) i D-020 — '
            .'bo adres pliku w buckecie omija Policy.',
        );

        $runbook = $this->runbook();

        $this->assertStringContainsString(
            'https://kuking.pl/zdjecia/',
            $runbook,
            'Runbook nie podaje prefiksu adresu zdjęcia, który naprawdę produkuje '
            .'`Media::url()`. Operator nie ma wtedy jak sprawdzić po wdrożeniu, '
            .'czy zdjęcia idą przez kontrolę dostępu.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/zaczyna się od `https:\/\/cdn\.kuking\.pl/u',
            $runbook,
            'Runbook znowu każe sprawdzić, że adres zdjęcia zaczyna się od '
            .'`cdn.kuking.pl`. To jest instrukcja uznania działającej ochrony '
            .'za awarię (D-020, audyt W7-02).',
        );
    }
}
