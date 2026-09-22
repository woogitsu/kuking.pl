<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;

/**
 * Cele dotknięcia w paczce z danymi (#492).
 *
 * Zmierzone w przeglądarce na PRAWDZIWIE rozpakowanym archiwum
 * (Chromium 151.0.7922.34 i Firefox 155.0, `file://`, szerokości 320/390/768/1440,
 * jasno i ciemno): odnośnik „Otwórz katalog ze zdjęciami" w `index.html` miał
 * 22 px wysokości. To jedyna droga do katalogu ze zdjęciami w całej paczce
 * i jest osobnym akapitem, a nie słowem w zdaniu — czyli ani produktowe 48 px
 * (`AGENTS.md` §5, `docs/UX_50_PLUS.md`), ani nawet 24 px z WCAG 2.2 AA 2.5.8,
 * którego wyjątek „inline" tutaj nie działa.
 *
 * Czego ten test NIE dowodzi: nie uruchamia przeglądarki, więc nie mierzy
 * pikseli. Mierzy to, co pikselom nadaje wartość — czy reguła `min-height`
 * z arkusza paczki NAPRAWDĘ dotyczy tego węzła. Pomiar geometrii jest
 * w dowodach z Playwrighta (`output/492b-claude/ekran.json`).
 */
class EksportWygladCeleDotknieciaTest extends EksportWygladStylPaczki
{
    public function test_odnosnik_do_katalogu_ze_zdjeciami_ma_cel_dotkniecia_48_px(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        $this->zdjecieDla($basia, 'rosol');

        $html = $this->zPaczki($this->zbudujPaczke($basia), 'index.html');
        $arkusz = $this->regulyPodstawowe($this->arkusz($html));
        $xpath = $this->dokument($html);

        $odnosniki = $this->elementy($xpath, '//a[@href="zdjecia/"]');

        // Kontrola dodatnia (pułapka 2): bez tego test przechodzi także wtedy,
        // gdy odnośnika w paczce w ogóle nie ma i nie ma czego mierzyć.
        $this->assertCount(1, $odnosniki, 'W spisie treści nie ma odnośnika do katalogu ze zdjęciami.');

        $this->assertSame(
            '48px',
            $this->wartoscDla($arkusz, $odnosniki[0], 'min-height'),
            'Odnośnik „Otwórz katalog ze zdjęciami" jest osobną akcją, a nie słowem w zdaniu — '.
            'musi mieć cel dotknięcia 48 px (AGENTS.md §5).',
        );

        $this->assertSame(
            'inline-block',
            $this->wartoscDla($arkusz, $odnosniki[0], 'display'),
            'Sama `min-height` nie urośnie elementowi liniowemu — potrzebny jest `inline-block`.',
        );
    }

    public function test_odnosnik_w_srodku_zdania_zostaje_slowem_a_nie_przyciskiem(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        $this->zdjecieDla($basia, 'rosol');

        $html = $this->zPaczki($this->zbudujPaczke($basia), 'wpisy.html');
        $arkusz = $this->regulyPodstawowe($this->arkusz($html));
        $xpath = $this->dokument($html);

        $wStopce = $this->elementy($xpath, '//p[contains(@class,"stopka")]/a');

        // Kontrola dodatnia: jeśli tego odnośnika nie ma, ten test nie mierzy nic.
        $this->assertCount(1, $wStopce, 'W stopce „Wróć do spisu treści." brakuje odnośnika.');

        // TO JEST SEDNO TEGO TESTU, a nie ozdobnik. Naprawa celu dotknięcia
        // ma pokusę zrobienia jej selektorem `p > a:only-child` — a `:only-child`
        // liczy RODZEŃSTWO ELEMENTÓW, nie tekst, więc łapie też odnośnik
        // w środku zdania. Zmierzone w przeglądarce przy takiej wersji poprawki:
        // wysokość tego odnośnika rosła z 20 px do 48 px i rozpychała wiersz
        // stopki. Odnośnik w zdaniu ma zostać słowem.
        $this->assertNull(
            $this->wartoscDla($arkusz, $wStopce[0], 'min-height'),
            'Odnośnik w środku zdania nie może dostawać celu dotknięcia 48 px — '.
            'rozpycha wtedy wiersz tekstu. Reguła jest za szeroka.',
        );
    }

    public function test_spis_tresci_i_powrot_nadal_maja_48_px(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);

        $export = $this->zbudujPaczke($basia);

        $indeks = $this->zPaczki($export, 'index.html');
        $xpathIndeksu = $this->dokument($indeks);
        $arkuszIndeksu = $this->regulyPodstawowe($this->arkusz($indeks));

        $wSpisie = $this->elementy($xpathIndeksu, '//ul[contains(@class,"spis")]//a');
        $this->assertNotEmpty($wSpisie, 'Spis treści nie ma ani jednego odnośnika.');

        foreach ($wSpisie as $odnosnik) {
            $this->assertSame('48px', $this->wartoscDla($arkuszIndeksu, $odnosnik, 'min-height'),
                'Pozycja spisu treści „'.trim($odnosnik->textContent).'" straciła cel dotknięcia.');
        }

        $plikiPrzepisow = array_values(array_filter(
            $this->plikiPaczki($export),
            static fn (string $plik): bool => str_starts_with($plik, 'przepisy/'),
        ));
        $this->assertCount(1, $plikiPrzepisow, 'Paczka miała zawierać jeden przepis: '.$przepis->title);

        $stronaPrzepisu = $this->zPaczki($export, $plikiPrzepisow[0]);
        $powrot = $this->elementy($this->dokument($stronaPrzepisu), '//p[contains(@class,"powrot")]/a');

        $this->assertCount(1, $powrot, 'Strona przepisu nie ma odnośnika powrotu.');
        $this->assertSame('48px',
            $this->wartoscDla($this->regulyPodstawowe($this->arkusz($stronaPrzepisu)), $powrot[0], 'min-height'),
        );
    }
}
