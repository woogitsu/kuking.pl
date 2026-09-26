<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #943: `search_performed` liczy WYSŁANIE frazy, nie każdą odsłonę
 * jej wyników.
 *
 * Przed poprawką każdy GET z poprawną frazą zapisywał sygnał — także „Pokaż
 * więcej", „Wróć do początku" i przełączenie zakresu. Jedno wyszukanie dawało
 * kilka rekordów, a puste dalsze okno zapisywało `has_results=false` dla
 * frazy, która w pierwszym oknie miała wyniki.
 *
 * Test idzie wyłącznie przez formularz i odnośniki WYGENEROWANE przez widok —
 * nie przez adresy złożone ręcznie — żeby mierzyć to, w co człowiek klika.
 *
 * Kontrola ujemna: usunięcie `! $nawigacja` z warunku zapisu w
 * `SearchController::index()` daje kilkanaście sygnałów, w tym
 * `has_results=false`, i ten test oblewa.
 */
class NawigacjaPoWynikachNieJestWyszukaniemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function kto(): array
    {
        return ['gość' => [false], 'zalogowana osoba' => [true]];
    }

    #[DataProvider('kto')]
    public function test_jedno_wyslanie_frazy_to_jeden_sygnal_mimo_nawigacji_po_wynikach(bool $zalogowany): void
    {
        $autor = $this->user('autor_kalarepy');
        Recipe::factory()->count(201)->create([
            'author_id' => $autor->id,
            'title' => 'Kalarepa pieczona',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $widz = $zalogowany ? $this->user('szukajaca') : null;

        // Wejście na pusty ekran nie jest wyszukaniem (#737).
        $ekran = $this->otworz($widz, route('search'));
        $this->assertSame(0, ProductSignal::query()->count());

        // KONTROLA DODATNIA: wysłanie formularza zapisuje dokładnie jeden sygnał.
        $wyniki = $this->otworz($widz, $this->wyslijFormularz($ekran, 'Kalarepa'));
        $this->assertSame(1, ProductSignal::query()->count(), 'Wysłanie frazy nie zapisało sygnału.');

        // Zakresy.
        $przepisy = $this->otworz($widz, $this->jedenLink($wyniki, 'Przepisy'));
        $this->otworz($widz, $this->jedenLink($przepisy, 'Ludzie'));
        $this->otworz($widz, $this->jedenLink($przepisy, 'Do 30 minut'));

        // „Pokaż więcej" aż do okna przesuniętego (ile=200 → od_przepisu=200).
        $okno = $przepisy;
        for ($i = 0; $i < 10 && ! str_contains($this->jedenLink($okno, 'Pokaż więcej przepisów'), 'od_przepisu=200'); $i++) {
            $okno = $this->otworz($widz, $this->jedenLink($okno, 'Pokaż więcej przepisów'));
        }

        // Dane zmieniają się między kliknięciami: dalsze okno robi się puste.
        $dalej = $this->jedenLink($okno, 'Pokaż więcej przepisów');
        $this->assertStringContainsString('od_przepisu=200', $dalej);
        $widoczne = $okno->viewData('recipes')->modelKeys();
        Recipe::query()->whereNotIn('id', $widoczne)->delete();

        $puste = $this->otworz($widz, $dalej);
        $this->assertTrue($puste->viewData('recipes')->isEmpty(), 'Dalsze okno miało być puste.');
        $puste->assertSee('W tym zakresie nie ma już przepisów.');

        $this->otworz($widz, $this->jedenLink($puste, 'Wróć do początku przepisów'));

        $sygnaly = ProductSignal::query()->get();
        $this->assertCount(1, $sygnaly, 'Nawigacja po wynikach zapisała kolejne search_performed.');
        $this->assertSame(0, ProductSignal::query()->where('properties->has_results', false)->count(), 'Puste dalsze okno zapisało has_results=false.');

        // Nowe, niezależne wysłanie tej samej frazy liczy się ponownie.
        $this->otworz($widz, $this->wyslijFormularz($puste, 'Kalarepa'));

        $sygnaly = ProductSignal::query()->orderBy('occurred_at')->get();
        $this->assertCount(2, $sygnaly, 'Ponowne wysłanie frazy nie zostało policzone.');
        foreach ($sygnaly as $sygnal) {
            $this->assertSame(ZapiszSygnal::SEARCH_PERFORMED, $sygnal->signal_name);
            $this->assertSame($widz?->getKey(), $sygnal->user_id);
            $this->assertEqualsCanonicalizing(['query_length', 'has_results'], array_keys($sygnal->properties));
            $this->assertSame(mb_strlen('Kalarepa'), $sygnal->properties['query_length']);
            $this->assertTrue($sygnal->properties['has_results']);
        }
    }

    private function otworz(?User $widz, string $url): TestResponse
    {
        return ($widz === null ? $this : $this->actingAs($widz))->get($url)->assertOk();
    }

    private function xpath(TestResponse $odpowiedz): DOMXPath
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$odpowiedz->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);
        }

        return new DOMXPath($dom);
    }

    /** Adres, pod który formularz wyszukiwania na tej stronie wyśle podaną frazę. */
    private function wyslijFormularz(TestResponse $odpowiedz, string $fraza): string
    {
        $formularze = $this->xpath($odpowiedz)->query('//main//form[contains(@class, "panel-formularza")]');
        $this->assertSame(1, $formularze->length);
        /** @var DOMElement $formularz */
        $formularz = $formularze->item(0);
        $this->assertSame('get', strtolower($formularz->getAttribute('method')));

        $pola = [];
        foreach ((new DOMXPath($formularz->ownerDocument))->query('.//input[@name]', $formularz) as $pole) {
            $pola[$pole->getAttribute('name')] = $pole->getAttribute('value');
        }
        $this->assertArrayHasKey('q', $pola);
        $pola['q'] = $fraza;

        return $formularz->getAttribute('action').'?'.http_build_query($pola);
    }

    private function jedenLink(TestResponse $odpowiedz, string $etykieta): string
    {
        $linki = [];
        foreach ($this->xpath($odpowiedz)->query('//main//a') as $link) {
            if (trim($link->textContent) === $etykieta) {
                $linki[] = $link->getAttribute('href');
            }
        }
        $this->assertCount(1, $linki, "Oczekiwano jednego odnośnika „{$etykieta}”.");

        return $linki[0];
    }
}
