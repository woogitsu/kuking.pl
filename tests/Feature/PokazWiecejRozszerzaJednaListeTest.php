<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #984 — na zakładce „Wszystko" „Pokaż więcej przepisów" rozszerza
 * tylko przepisy, a „Pokaż więcej osób" tylko osoby.
 *
 * Wcześniej oba odnośniki zwiększały jeden wspólny `ile`, więc kliknięcie
 * przy jednej liście wydłużało też drugą. Testy idą po odnośnikach
 * z FAKTYCZNIE wyrenderowanego HTML, nie po adresach złożonych w teście.
 *
 * Kontrola dodatnia: z jednym wspólnym rozmiarem okna (oba `ile_*` z tej
 * samej wartości) pierwszy test oblewa na liczbie osób — 40 zamiast 20.
 */
class PokazWiecejRozszerzaJednaListeTest extends TestCase
{
    use RefreshDatabase;

    private function wyniki(int $przepisow, int $osob): void
    {
        $autor = $this->user('kucharz_autor');
        Recipe::factory()->count($przepisow)->create(['author_id' => $autor->id, 'title' => 'Rozmaryna']);
        for ($i = 0; $i < $osob; $i++) {
            $this->user('rozmaryna_'.$i, ['display_name' => 'Rozmaryna']);
        }
    }

    private function link(TestResponse $odpowiedz, string $podpis): string
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$odpowiedz->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);
        }
        $pasujace = [];
        foreach ((new DOMXPath($dom))->query('//main//a') as $a) {
            if (trim($a->textContent) === $podpis) {
                $pasujace[] = $a->getAttribute('href');
            }
        }
        $this->assertCount(1, $pasujace, "Na stronie powinien być dokładnie jeden odnośnik „{$podpis}”.");

        return $pasujace[0];
    }

    /** @return array{0: int, 1: int} */
    private function liczby(TestResponse $odpowiedz): array
    {
        return [$odpowiedz->viewData('recipes')->count(), $odpowiedz->viewData('people')->count()];
    }

    private function start(): TestResponse
    {
        return $this->get(route('search', ['q' => 'Rozmaryna', 'sekcja' => 'wszystko']))->assertOk();
    }

    public function test_wiecej_przepisow_nie_rusza_osob(): void
    {
        $this->wyniki(45, 45);
        $start = $this->start();
        $this->assertSame([20, 20], $this->liczby($start));

        $wiecej = $this->get($this->link($start, 'Pokaż więcej przepisów'))->assertOk();

        $this->assertSame([40, 20], $this->liczby($wiecej));
        $this->assertSame($start->viewData('people')->modelKeys(), $wiecej->viewData('people')->modelKeys());
    }

    public function test_wiecej_osob_nie_rusza_przepisow_i_pamieta_rozwinieta_liste(): void
    {
        $this->wyniki(45, 45);
        $start = $this->start();

        $osoby = $this->get($this->link($start, 'Pokaż więcej osób'))->assertOk();
        $this->assertSame([20, 40], $this->liczby($osoby));

        // Druga lista zostaje rozwinięta po kolejnych kliknięciach pierwszej.
        $przepisy = $this->get($this->link($osoby, 'Pokaż więcej przepisów'))->assertOk();
        $this->assertSame([40, 40], $this->liczby($przepisy));
        $osobyZnowu = $this->get($this->link($przepisy, 'Pokaż więcej osób'))->assertOk();
        $this->assertSame([40, 45], $this->liczby($osobyZnowu));
        $this->assertStringNotContainsString('ile=', str_replace(['ile_przepisow=', 'ile_osob='], '', $this->link($osobyZnowu, 'Pokaż więcej przepisów')));
    }

    public function test_krotka_lista_osob_nie_zmienia_sie_przy_rozwijaniu_przepisow(): void
    {
        $this->wyniki(45, 3);
        $start = $this->start();

        $wiecej = $this->get($this->link($start, 'Pokaż więcej przepisów'))->assertOk();

        $this->assertSame([40, 3], $this->liczby($wiecej));
        $wiecej->assertSee('Znaleziono 3 osoby', escape: false);
    }

    public function test_prog_dwustu_przesuwa_tylko_klikniete_okno(): void
    {
        $this->wyniki(201, 21);
        $start = $this->get(route('search', [
            'q' => 'Rozmaryna', 'sekcja' => 'wszystko', 'ile_przepisow' => 200,
        ]))->assertOk();
        $this->assertSame([200, 20], $this->liczby($start));

        $dalej = $this->get($this->link($start, 'Pokaż więcej przepisów'))->assertOk();
        $this->assertSame([1, 20], $this->liczby($dalej));
        $this->assertSame(200, $dalej->viewData('odPrzepisu'));

        $powrot = $this->get($this->link($dalej, 'Wróć do początku przepisów'))->assertOk();
        $this->assertSame([200, 20], $this->liczby($powrot));
    }

    public function test_bledne_rozmiary_okien_wracaja_do_domyslnych(): void
    {
        $this->wyniki(25, 25);
        foreach (['ludzie' => [0, 20], 'przepisy' => [20, 0]] as $sekcja => $oczekiwane) {
            foreach ([-5, 'abc', ['40'], 1000000] as $zla) {
                $odpowiedz = $this->get(route('search', [
                    'q' => 'Rozmaryna', 'sekcja' => $sekcja, 'ile_przepisow' => $zla, 'ile_osob' => $zla,
                ]))->assertOk();
                $spodziewane = $zla === 1000000 ? array_map(fn ($n) => $n === 0 ? 0 : 25, $oczekiwane) : $oczekiwane;
                $this->assertSame($spodziewane, $this->liczby($odpowiedz));
            }
        }
    }
}
