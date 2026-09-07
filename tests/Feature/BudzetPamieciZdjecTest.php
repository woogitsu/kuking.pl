<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Limit megapikseli mieści się w pamięci, którą worker naprawdę ma (audyt F-05).
 *
 * DLACZEGO TO NIE JEST OCZYWISTE
 * `config/kuking.php`, `docker/entrypoint.sh` i `.railway/railway.ts` to trzy
 * niezależne pliki w trzech różnych ekosystemach. Żaden nie czyta pozostałych.
 * Przed tym testem stały w nich trzy niezgodne liczby, każda wzięta z innego
 * momentu w historii projektu:
 *
 *   * config dopuszczał 50 Mpx,
 *   * entrypoint zabijał workera po 384 MB,
 *   * railway.ts twierdził, że 24 Mpx to „~100 MB".
 *
 * ZMIERZONE (szczyt RSS procesu, gd, trzy warianty, PHP 8.4):
 *
 *     12 Mpx → 161 MB      24 Mpx → 254 MB      50 Mpx → 452 MB
 *
 * Czyli: 24 Mpx kosztuje 2,5 raza więcej, niż mówił komentarz, a próg 384 MB
 * stał PONIŻEJ kosztu jednego dozwolonego zadania — worker restartował się po
 * każdym dużym zdjęciu, wyglądając przy tym na wyciek pamięci.
 *
 * UWAGA, KTÓRA MYLIŁA WSZYSTKICH: `memory_limit` PHP tych liczb nie obejmuje.
 * Przy 50 Mpx licznik PHP pokazuje 28 MB przy 452 MB RSS — libgd alokuje
 * bitmapę poza licznikiem PHP. Brak pamięci przy dekodowaniu nie zgłosi więc
 * „Allowed memory size exhausted"; proces po prostu zniknie.
 */
class BudzetPamieciZdjecTest extends TestCase
{
    /*
     * Model kosztu pamięci DOPASOWANY do trzech pomiarów, nie zgadnięty.
     *
     * Regresja liniowa przez (12, 161), (24, 254) i (50, 452) daje
     * 69,7 MB + 7,65 MB/Mpx i odtwarza wszystkie trzy punkty co do megabajta —
     * koszt jest liniowy, bo bitmapa to stała liczba bajtów na piksel, a reszta
     * (PHP, Laravel, połączenie z bazą) jest stała.
     *
     * Pierwsza wersja tego testu liczyła najgorszym ilorazem (13,5 MB/Mpx
     * z punktu 12 Mpx) I dokładała stały narzut osobno — czyli liczyła go dwa
     * razy. Wychodziło 735 MB tam, gdzie pomiar mówi 452, i test kazał
     * podnosić limity, które są w porządku. Szacunek ma być ostrożny, ale
     * ostrożny to nie to samo co zawyżony: test, który każe zmieniać działającą
     * konfigurację, zostanie w końcu wyłączony.
     *
     * Do obu stałych doliczone 25% zapasu na to, czego pomiar nie obejmuje:
     * inną wersję biblioteki, nietypowy plik, pamięć nieoddaną systemowi przez
     * glibc po poprzednim zdjęciu.
     */
    private const STALY_NARZUT_MB = 87.0;

    private const MB_NA_MEGAPIKSEL = 9.56;

    /**
     * Ile razy kontener musi przekraczać koszt jednego zdjęcia.
     *
     * Nie „mieści się": przekroczenie pamięci kontenera nie daje wyjątku ani
     * wpisu w Sentry — proces znika, zabity przez OOM, a zdjęcia zostają
     * w statusie `processing`. Awaria bez śladu wymaga większego zapasu niż
     * awaria, którą widać.
     */
    private const ZAPAS_KONTENERA = 1.5;

    private function szacowanyKosztMb(float $megapiksele): float
    {
        return self::STALY_NARZUT_MB + $megapiksele * self::MB_NA_MEGAPIKSEL;
    }

    private function liczbaZPliku(string $sciezka, string $wzorzec): ?int
    {
        $tresc = (string) file_get_contents(base_path($sciezka));

        return preg_match($wzorzec, $tresc, $trafienie) === 1 ? (int) $trafienie[1] : null;
    }

    public function test_worker_nie_restartuje_sie_po_kazdym_dozwolonym_zdjeciu(): void
    {
        $megapiksele = (float) config('kuking.media.max_megapixels');

        $prog = $this->liczbaZPliku('docker/entrypoint.sh', '/QUEUE_MEMORY:-(\d+)/');

        $this->assertNotNull($prog, 'Nie znalazłem progu --memory w docker/entrypoint.sh.');

        // Próg `--memory` sprawdzany jest MIĘDZY zadaniami. Gdy stoi poniżej
        // kosztu jednego dozwolonego zdjęcia, worker kończy się po każdym
        // takim zdjęciu — i wygląda to jak wyciek pamięci, którym nie jest.
        $this->assertGreaterThan(
            $this->szacowanyKosztMb($megapiksele),
            (float) $prog,
            "Próg --memory ({$prog} MB) stoi poniżej kosztu przetworzenia jednego zdjęcia ".
            "{$megapiksele} Mpx. Albo podnieś próg w docker/entrypoint.sh, albo obniż ".
            'media.max_megapixels w config/kuking.php — patrz tabela w docs/MEDIA_PIPELINE.md.',
        );
    }

    public function test_najgorsze_dozwolone_zdjecie_miesci_sie_w_pamieci_kontenera(): void
    {
        $megapiksele = (float) config('kuking.media.max_megapixels');

        $kontener = $this->liczbaZPliku('.railway/railway.ts', '/memoryBytes:\s*(\d+)\s*\*\s*MB,\s*\n\s*cpu:\s*2,/');

        $this->assertNotNull($kontener, 'Nie znalazłem przydziału pamięci workera w .railway/railway.ts.');

        // Zapas półtorakrotny, nie „mieści się". Kontener trzyma nie tylko bitmapę:
        // jest jeszcze PHP, Laravel, połączenie z bazą i to, czego glibc nie
        // oddał systemowi po poprzednim zdjęciu.
        //
        // Przekroczenie tego limitu nie daje wyjątku ANI wpisu w Sentry —
        // proces znika, zabity przez OOM. Dlatego liczymy z zapasem, a nie
        // styk w styk.
        $this->assertGreaterThan(
            $this->szacowanyKosztMb($megapiksele) * self::ZAPAS_KONTENERA,
            (float) $kontener,
            "Worker ma {$kontener} MB, a jedno zdjęcie {$megapiksele} Mpx kosztuje około ".
            round($this->szacowanyKosztMb($megapiksele)).' MB RSS. Za mały zapas: przekroczenie '.
            'kończy się zabiciem procesu przez OOM, bez wyjątku i bez wpisu w Sentry.',
        );
    }

    public function test_dokumentacja_nie_obiecuje_ze_memory_limit_chroni_przed_gd(): void
    {
        // To nie jest test kosmetyczny. Poprzedni komentarz w `docker/php.ini`
        // radził wprost: „zobaczysz «Allowed memory size exhausted»
        // w ProcessUploadedImage — podnieś PHP_WORKER_MEMORY_LIMIT". Ta rada
        // by nie zadziałała, bo bufory GD nie idą przez licznik PHP — a przy
        // incydencie o drugiej w nocy czyta się dokładnie taki komentarz
        // i traci na nim godzinę.
        $ini = (string) file_get_contents(base_path('docker/php.ini'));

        $this->assertStringContainsString(
            'NIE CHRONI PRZED PAMIĘCIĄ ZJADANĄ PRZEZ GD',
            $ini,
            'Zniknęło ostrzeżenie, że memory_limit nie obejmuje buforów GD.',
        );

        // Ta sama wiedza musi stać tam, gdzie ktoś szuka liczb: w opisie potoku.
        $potok = (string) file_get_contents(base_path('docs/MEDIA_PIPELINE.md'));

        $this->assertStringContainsString('szczyt RSS', $potok);
        $this->assertStringContainsString('libgd alokuje bitmapę poza licznikiem PHP', $potok);
    }
}
