<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ekrany panelu używają typografii, która NAPRAWDĘ ISTNIEJE (#581).
 *
 * SKĄD SIĘ WZIĘŁA USTERKA
 * Dwa ekrany panelu otwierały się akapitem wprowadzającym `class="lead"`.
 * Takiej reguły nie ma w żadnym arkuszu i nigdy nie było — `git log -S` nie
 * pokazuje ani jednego commita, który by ją dodawał. Reszta nowego interfejsu
 * (strona powitalna, przepis, koniec onboardingu, „o nas") używa `text-lead`,
 * czyli klasy generowanej przez Tailwind 4 z tokenu `--text-lead` z bloku
 * `@theme` w `tokens.css`.
 *
 * Zmierzone na zbudowanym arkuszu (`public/build/assets/app-*.css`):
 * `.lead` występuje jako selektor **zero** razy, `.text-lead` dwa razy.
 * Akapit z martwą klasą renderował się więc wielkością tekstu podstawowego
 * (18 px) zamiast wprowadzenia (22 px) — na dwóch ekranach panelu, które
 * moderator widzi jako pierwsze zdanie ekranu.
 *
 * DLACZEGO TEST PYTA O KLASĘ, SKORO REPO NIE LUBI TESTÓW NA NAZWY KLAS
 * Bo tu nazwa klasy JEST zachowaniem: różnica między `lead` a `text-lead` to
 * różnica między akapitem bez żadnej reguły a akapitem o rozmiarze z systemu.
 * Żeby test nie był samym napisem, sprawdza OBIE strony tej zależności:
 * że w arkuszach nie ma reguły `.lead`, i że token `--text-lead` stoi
 * w `@theme`, czyli że klasa, o którą pytamy, ma z czego powstać.
 */
class PanelUzywaTypografiiMarkiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ekrany panelu, które otwierają się akapitem wprowadzającym.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ekranyZWprowadzeniem(): array
    {
        return [
            'sygnały automatu' => ['/admin/sygnaly', 'Treści, przy których automat podniósł rękę'],
            'tagi promowane' => ['/admin/tagi-promowane', 'Ta lista zastępuje dawne Tematy'],
        ];
    }

    #[DataProvider('ekranyZWprowadzeniem')]
    public function test_akapit_wprowadzajacy_panelu_ma_rozmiar_z_systemu(string $adres, string $fragment): void
    {
        $odpowiedz = $this->actingAs($this->moderator())->get($adres);

        $odpowiedz->assertOk();

        $html = $odpowiedz->getContent();
        $this->assertIsString($html);

        // Kontrola dodatnia: to naprawdę ten akapit, a nie dowolny inny tekst.
        $this->assertStringContainsString($fragment, $html,
            "Na ekranie {$adres} nie ma akapitu wprowadzającego — nie ma czego mierzyć.");

        $akapit = $this->akapitZ($html, $fragment);

        // Porównujemy POJEDYNCZE KLASY z atrybutu, nie wzorzec na całym napisie:
        // w wyrażeniu regularnym `\blead\b` pasuje także do `text-lead`, bo
        // myślnik jest granicą słowa. Test na takim wzorcu oblewałby poprawny
        // kod, a przeszedłby dopiero po zgadnięciu składni — zmierzone.
        $klasy = $this->klasyZ($akapit);

        $this->assertContains('text-lead', $klasy,
            "Akapit wprowadzający na {$adres} nie ma rozmiaru wprowadzenia z systemu marki.");

        // Martwa klasa nie może wrócić: `class="lead"` nie ma żadnej reguły.
        $this->assertNotContains('lead', $klasy,
            "Akapit na {$adres} wrócił do klasy „lead”, która nie istnieje w żadnym arkuszu.");
    }

    public function test_arkusze_potwierdzaja_ze_lead_nie_istnieje_a_text_lead_ma_z_czego_powstac(): void
    {
        /*
         * Druga połowa dowodu (pułapka 4): asercje wyżej byłyby samym napisem,
         * gdyby obie klasy znaczyły to samo. Tu mierzymy, że nie znaczą.
         */
        $arkusze = '';
        foreach (glob(resource_path('css/*.css')) ?: [] as $plik) {
            $arkusze .= (string) file_get_contents($plik)."\n";
        }

        $this->assertNotSame('', $arkusze, 'Nie wczytano ani jednego arkusza — skan nie miałby czego sprawdzić.');

        // Reguła `.lead` nie istnieje…
        $this->assertDoesNotMatchRegularExpression(
            '/(^|[^-\w.])\.lead\s*[,{]/m',
            $this->bezKomentarzy($arkusze),
            'W arkuszach pojawiła się reguła `.lead` — wtedy ten test opisuje nieaktualny stan i trzeba go przeczytać na nowo.',
        );

        // …a `text-lead` ma token, z którego Tailwind ją generuje.
        $this->assertMatchesRegularExpression(
            '/--text-lead\s*:/',
            $arkusze,
            'Zniknął token `--text-lead` — klasa `text-lead` nie miałaby z czego powstać.',
        );
    }

    /**
     * Lista klas z pierwszego atrybutu `class` we fragmencie.
     *
     * @return list<string>
     */
    private function klasyZ(string $fragment): array
    {
        if (preg_match('/class="([^"]*)"/', $fragment, $dopasowanie) !== 1) {
            $this->fail('Akapit wprowadzający nie ma atrybutu `class`.');
        }

        return array_values(array_filter(preg_split('/\s+/', $dopasowanie[1]) ?: []));
    }

    /** Fragment HTML jednego akapitu zawierającego wskazany tekst. */
    private function akapitZ(string $html, string $fragment): string
    {
        $pozycja = mb_strpos($html, $fragment);
        $this->assertNotFalse($pozycja);

        $otwarcie = mb_strrpos(mb_substr($html, 0, $pozycja), '<p');
        $this->assertNotFalse($otwarcie, 'Tekst wprowadzenia nie stoi w akapicie.');

        $zamkniecie = mb_strpos($html, '</p>', $otwarcie);
        $this->assertNotFalse($zamkniecie);

        return mb_substr($html, $otwarcie, $zamkniecie - $otwarcie + 4);
    }

    /** Arkusz bez komentarzy — żeby proza o klasie nie udawała reguły. */
    private function bezKomentarzy(string $css): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }
}
