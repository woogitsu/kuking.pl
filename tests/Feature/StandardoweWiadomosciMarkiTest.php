<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use App\Notifications\OdpowiedzNaOdwolanieZglaszajacego;
use App\Notifications\PilneZgloszenieOdCzlowieka;
use App\Notifications\PilnyAlarmModeracyjny;
use App\Notifications\PodsumowanieKolejkiAutomatu;
use App\Notifications\PotwierdzenieOdwolaniaZglaszajacego;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use App\Notifications\TerminOdwolaniaBlisko;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Mail\Markdown;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Renderowanie prawdziwych powiadomień bez kolejki, wysyłki ani bazy danych. */
class StandardoweWiadomosciMarkiTest extends TestCase
{
    public static function wiadomosci(): array
    {
        return array_combine(
            $nazwy = ['zgloszenie', 'decyzja', 'odwolanie', 'odpowiedz', 'alarm', 'alarm-od-czlowieka', 'podsumowanie', 'termin'],
            array_map(fn ($nazwa) => [$nazwa], $nazwy),
        );
    }

    #[DataProvider('wiadomosci')]
    public function test_rzeczywista_wiadomosc_ma_marke_czytelny_tekst_i_niezmienione_linki(string $nazwa): void
    {
        $zgloszenie = (new Report)->forceFill([
            'id' => '12345678-1234-4234-8234-123456789abc',
            'numer_sprawy' => 'KUK-2026-TEST',
            'target_url' => 'https://kuking.test/wpisy/testowy-wpis',
            'details' => 'Powód testowy oznaczenia.',
            'reason' => 'minor',
        ]);
        $decyzja = (new ModerationAction)->forceFill([
            'report_id' => $zgloszenie->getKey(),
            'action' => ModerationAction::ACTION_NONE,
            'created_at' => now(),
        ]);
        $odwolanie = (new Appeal)->forceFill([
            'status' => Appeal::STATUS_UPHELD,
            'decision_note' => 'Uzasadnienie testowe rozstrzygnięcia.',
        ])->setRelation('report', $zgloszenie);

        [$powiadomienie, $kotwica] = match ($nazwa) {
            'zgloszenie' => [new PotwierdzenieZgloszeniaNielegalnejTresci($zgloszenie), 'Numer sprawy:'],
            'decyzja' => [new DecyzjaWSprawieZgloszenia($zgloszenie, $decyzja), 'Jeśli się z nami nie zgadzasz'],
            'odwolanie' => [new PotwierdzenieOdwolaniaZglaszajacego($zgloszenie), 'Dostaliśmy Twoje odwołanie'],
            'odpowiedz' => [new OdpowiedzNaOdwolanieZglaszajacego($odwolanie), 'Uzasadnienie testowe rozstrzygnięcia.'],
            'alarm' => [new PilnyAlarmModeracyjny($zgloszenie), 'Powód testowy oznaczenia.'],
            // Alarm o zgłoszeniu OD CZŁOWIEKA świadomie nie niesie `details`
            // (to niesprawdzony tekst od dowolnej osoby z internetu), więc
            // kotwicą jest kategoria z zamkniętej listy `Report::REASONS`.
            'alarm-od-czlowieka' => [new PilneZgloszenieOdCzlowieka($zgloszenie), 'Dotyczy dziecka'],
            'podsumowanie' => [new PodsumowanieKolejkiAutomatu(2, 3, ['Powód testowy' => 2]), 'Powód testowy'],
            'termin' => [new TerminOdwolaniaBlisko(1, 2, '14 września 2026'), '14 września 2026'],
        };
        $list = $powiadomienie->toMail(new \stdClass);
        $html = (string) $list->render();
        $tekst = (string) app(Markdown::class)->renderText($list->markdown, $list->data());

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $tresc = $this->element($xpath, '//td[@class="content-cell"]');
        $this->assertStringContainsString($kotwica, $tresc->textContent);
        $this->assertStringContainsString($kotwica, $tekst);
        $this->assertStringNotContainsString('<table', $tekst);

        $this->styl($this->element($xpath, '//table[@class="wrapper"]'), 'background-color', '#F3F4F1');
        $this->styl($this->element($xpath, '//table[@class="inner-body"]'), 'background-color', '#FFFFFF');
        $this->styl($this->element($xpath, '//table[@class="inner-body"]'), 'border-radius', '24px');
        $this->styl($this->element($xpath, '//td[@class="content-cell"]/p'), 'font-size', '19px');
        $this->styl($this->element($xpath, '//td[@class="content-cell"]/p'), 'font-family', 'Arial, Helvetica, sans-serif');
        $this->styl($this->element($xpath, '//td[@class="content-cell"]/p'), 'overflow-wrap', 'anywhere');
        $this->styl($this->element($xpath, '//h1'), 'color', '#151714');
        $this->styl($this->element($xpath, '//span[@class="marka-king"]'), 'color', '#BE3025');
        $this->assertSame('KuKing.pl', $this->element($xpath, '//td[@class="header"]/a')->textContent);
        $this->assertStringNotContainsString('laravel.com', $html);
        $this->assertStringNotContainsString('Georgia', $html);

        if ($list->actionText !== null) {
            $przycisk = $this->element($xpath, '//a[contains(@class,"button ")]');
            $this->assertSame($list->actionText, trim($przycisk->textContent));
            $this->assertSame($list->actionUrl, $przycisk->getAttribute('href'));
            $this->styl($przycisk, 'background-color', '#BE3025');
            $this->styl($przycisk, 'font-size', '20px');
            $this->styl($przycisk, 'min-height', '48px');
            $zapasowy = $this->element($xpath, '//table[@class="subcopy"]//a');
            $this->assertSame($list->actionUrl, $zapasowy->getAttribute('href'));
            $this->assertStringContainsString($list->actionUrl, $tekst);
            if ($nazwa === 'decyzja') {
                // PODPIS ZOSTAJE, TERMIN ZNIKA Z ADRESU (issue #798, decyzja
                // właściciela 20.09.2026). `expires=` w linku znaczyło, że
                // strona sprawy umiera razem z terminem na ZŁOŻENIE odwołania
                // — i dawało 403 na własnej, wciąż otwartej sprawie. Dziś
                // o życiu strony decyduje stan sprawy
                // (`App\Domain\Moderation\DostepDoStronySprawy`), więc brak
                // `expires=` jest tu WYMAGANY, nie tolerowany.
                $this->assertStringContainsString('signature=', $przycisk->getAttribute('href'));
                $this->assertStringNotContainsString('expires=', $przycisk->getAttribute('href'));
            }
        } else {
            $this->assertSame(0, $xpath->query('//a[contains(@class,"button ")]')->length);
        }
    }

    private function element(DOMXPath $xpath, string $zapytanie): DOMElement
    {
        $element = $xpath->query($zapytanie)->item(0);
        $this->assertInstanceOf(DOMElement::class, $element, $zapytanie);

        return $element;
    }

    private function styl(DOMElement $element, string $wlasciwosc, string $wartosc): void
    {
        $reguly = [];
        foreach (explode(';', $element->getAttribute('style')) as $regula) {
            if (str_contains($regula, ':')) {
                [$nazwa, $tresc] = explode(':', $regula, 2);
                $reguly[trim($nazwa)] = trim($tresc);
            }
        }
        $this->assertSame(strtolower($wartosc), strtolower($reguly[$wlasciwosc] ?? ''), $wlasciwosc);
    }
}
