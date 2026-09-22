<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Rytm formularza „Napisz komentarz" — issue #433, druga połowa zgłoszenia:
 *
 * > „patrz ile miejsca nad «napisz komentarz» a żadnego między «też jest
 * > w porządku» a polem do pisania"
 *
 * CO BYŁO ZMIERZONE (Chromium, `scripts/uklad-komentarzy.mjs`):
 *
 *     odstęp                              przed → po      przy czcionce 200%
 *     nad etykietą, PONAD wcięciem panelu   24 →  0 px       48 →  0 px
 *     etykieta → podpowiedź                  8 →  8 px       16 → 16 px
 *     podpowiedź → pole                      0 → 12 px        0 → 24 px
 *     pole → „Wyślij komentarz"             24 → 24 px       48 → 48 px
 *
 * DWIE PRZYCZYNY, OBIE W REGULE, NIE W SZABLONIE
 *
 *  1. `.field:first-child { margin-top: 0 }` miało zdjąć pustkę nad pierwszą
 *     etykietą i nie zdejmowało jej NIGDY w formularzu POST: `@csrf` renderuje
 *     `<input type="hidden">`, a ukryte pole jest elementem, więc to ono jest
 *     pierwszym dzieckiem. 24 px marginesu doklejało się do 24 px wcięcia
 *     panelu — stąd „ile miejsca nad «napisz komentarz»".
 *  2. Odstępu między podpowiedzią a polem nie deklarowała ŻADNA ze stron pary.
 *     Dostała go strona dolna, jako `margin-top` — D-154. Reguła wisi na
 *     SĄSIEDZTWIE (`.field-help + .field-input`), nie na klasie — D-158:
 *     bezwarunkowy `margin-top` na `.field-input` rozsunąłby etykietę i pole
 *     w kilkudziesięciu polach bez podpowiedzi, o które nikt się nie zgłaszał.
 *
 * CZEGO TEN TEST PILNUJE
 * Obie reguły opierają się na SĄSIEDZTWIE w wyrenderowanym dokumencie, więc
 * sprawdzanie samego arkusza pilnowałoby połowy (D-158). Wstawienie
 * czegokolwiek między ukryte pole a pierwsze `.field` albo między podpowiedź
 * a pole wyłącza odstęp, nie ruszając ani jednej linii CSS-a. Dlatego każda
 * reguła ma tu dwie asercje: jedną na arkuszu i jedną na dokumencie.
 */
class RytmFormularzaKomentarzaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** Treść arkusza BEZ komentarzy — nad obiema regułami stoi ich opis. */
    private function css(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/tokens.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Deklaracje reguł, których lista selektorów pasuje do wzorca.
     *
     * @return list<string>
     */
    private function regulyDla(string $wzorzecSelektora): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css(), $reguly, PREG_SET_ORDER);

        $znalezione = [];

        foreach ($reguly as $regula) {
            $selektory = (string) preg_replace('/\s+/', ' ', trim($regula[1]));

            if (preg_match($wzorzecSelektora, $selektory) === 1) {
                $znalezione[] = $regula[2];
            }
        }

        return $znalezione;
    }

    /** Formularz „Napisz komentarz" z ekranu wpisu, jako drzewo. */
    private function formularzKomentarza(): array
    {
        $gospodarz = $this->user('gospodyni');
        $wpis = Post::factory()->create(['author_id' => $gospodarz->getKey()]);

        $ekran = $this->trescEkranu(
            (string) $this->actingAs($gospodarz)->get(route('posts.show', $wpis))->assertOk()->getContent(),
        );

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$ekran, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $formularze = $xpath->query(
            "//form[contains(concat(' ', normalize-space(@class), ' '), ' panel-formularza ')]",
        );

        $this->assertInstanceOf(DOMNodeList::class, $formularze);
        $this->assertGreaterThan(
            0,
            $formularze->length,
            'Na ekranie wpisu nie ma formularza „Napisz komentarz". Pusty ekran przechodzi '.
            'każdą asercję — popraw przygotowanie danych, nie asercję.',
        );

        $formularz = $formularze->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz);

        return [$xpath, $formularz];
    }

    public function test_pierwsze_pole_nie_dokleja_wlasnego_odstepu_do_wciecia_panelu(): void
    {
        $reguly = $this->regulyDla('/input\[type=["\']?hidden["\']?\]\s*\+\s*\.field\s*$/');

        $this->assertNotEmpty(
            $reguly,
            'W resources/css/tokens.css nie ma reguły zdejmującej górny margines polu, '.
            'które stoi zaraz po ukrytym polu. Bez niej `@csrf` sprawia, że `.field:first-child` '.
            'nie trafia w żadne widoczne pole i nad etykietą „Napisz komentarz" znów stoi '.
            '48 px pustki (24 px wcięcia panelu + 24 px marginesu pola), a przy czcionce '.
            'przeglądarki 200% — 72 px.',
        );

        $deklaracje = (string) preg_replace('/\s+/', ' ', implode(' ', $reguly));

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])margin-top\s*:\s*0/',
            $deklaracje,
            'Reguła istnieje, ale nie zeruje `margin-top`. Odstęp MIĘDZY polami ma zostać '.
            '24 px — znika wyłącznie ten, który wyciekał na krawędź kontenera. '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }

    public function test_pole_po_ukrytym_odzyskuje_odstep_gdy_nie_jest_pierwsze(): void
    {
        $reguly = $this->regulyDla('/\.field\s*~\s*input\[type=["\']?hidden["\']?\]\s*\+\s*\.field\s*$/');

        $this->assertNotEmpty(
            $reguly,
            'Brakuje reguły oddającej odstęp polu, przed którym stoi ukryte pole, ale które '.
            'NIE jest pierwszym polem formularza. Bez niej poprawka #433 psuje ekrany '.
            '`errors/419` i `errors/429`: odtwarzają one cudzy formularz w pętli, w której '.
            'pole długie wychodzi jako `.field`, a krótkie jako `<input type="hidden">` — '.
            'więc dwa pola długie przedzielone krótkim zlepiają się w jedno. '.
            'To nie jest hipoteza: tak wygląda odbicie z formularza przepisu.',
        );

        $deklaracje = (string) preg_replace('/\s+/', ' ', implode(' ', $reguly));

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])margin-top\s*:\s*var\(--spacing-[1-9]\d*\)/',
            $deklaracje,
            'Reguła przywracająca odstęp istnieje, ale nie ustawia go na ten sam token, '.
            'który mają między sobą pozostałe pola. '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }

    public function test_ukryte_pole_naprawde_stoi_zaraz_przed_pierwszym_polem(): void
    {
        [$xpath, $formularz] = $this->formularzKomentarza();

        $pola = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' field ')]",
            $formularz,
        );

        $this->assertInstanceOf(DOMNodeList::class, $pola);
        $this->assertGreaterThan(0, $pola->length, 'Formularz komentarza nie ma ani jednego pola.');

        $pierwsze = $pola->item(0);
        $this->assertInstanceOf(DOMElement::class, $pierwsze);

        $poprzednie = $xpath->query('preceding-sibling::*[1]', $pierwsze);
        $this->assertInstanceOf(DOMNodeList::class, $poprzednie);

        $poprzednik = $poprzednie->item(0);

        $this->assertTrue(
            $poprzednik instanceof DOMElement
                && $poprzednik->tagName === 'input'
                && $poprzednik->getAttribute('type') === 'hidden',
            'Przed pierwszym polem formularza komentarza nie stoi już ukryte pole, tylko '.
            ($poprzednik instanceof DOMElement
                ? '<'.$poprzednik->tagName.'>'
                : 'nic').
            '. Odstęp zdejmuje reguła z `+`, więc wstawienie czegokolwiek pomiędzy przywraca '.
            '24 px pustki nad „Napisz komentarz". Jeśli szablon naprawdę ma się zmienić, '.
            'popraw NAJPIERW regułę w arkuszu, a potem ten test.',
        );
    }

    public function test_odstep_pod_podpowiedzia_nalezy_do_pola_i_idzie_z_tokenu(): void
    {
        $reguly = $this->regulyDla('/\.field-help\s*\+\s*\.field-input\s*$/');

        $this->assertNotEmpty(
            $reguly,
            'W resources/css/tokens.css nie ma reguły odsuwającej pole od jego podpowiedzi. '.
            'Zmierzone przed #433: podpowiedź „Choćby jedno zdanie…" stała 0 px nad polem, '.
            'czyli dotykała obwódki grubej na 2 px w mocnym kolorze.',
        );

        $deklaracje = (string) preg_replace('/\s+/', ' ', implode(' ', $reguly));

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])margin-top\s*:\s*var\(--spacing-[1-9]\d*\)/',
            $deklaracje,
            'Odstęp pod podpowiedzią nie jest `margin-top` z tokenu `--spacing-N`. '.
            'STRONA PARY: dolna (D-154) — górna, czyli `.field-help`, niczego pod sobą nie '.
            'deklaruje. TOKEN, NIE PIKSELE: to odstęp typograficzny, więc ma rosnąć razem '.
            'z pismem przy czcionce przeglądarki 200% (druga strona D-082/D-107). '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }

    public function test_podpowiedz_naprawde_stoi_zaraz_nad_polem(): void
    {
        [$xpath, $formularz] = $this->formularzKomentarza();

        $pola = $xpath->query(
            ".//textarea[contains(concat(' ', normalize-space(@class), ' '), ' field-input ')]",
            $formularz,
        );

        $this->assertInstanceOf(DOMNodeList::class, $pola);
        $this->assertGreaterThan(
            0,
            $pola->length,
            'Formularz komentarza nie ma pola wielowierszowego — test nie sprawdziłby niczego.',
        );

        $pole = $pola->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole);

        $poprzednie = $xpath->query('preceding-sibling::*[1]', $pole);
        $this->assertInstanceOf(DOMNodeList::class, $poprzednie);

        $poprzednik = $poprzednie->item(0);
        $klasy = $poprzednik instanceof DOMElement
            ? preg_split('/\s+/', trim($poprzednik->getAttribute('class'))) ?: []
            : [];

        $this->assertContains(
            'field-help',
            $klasy,
            'Podpowiedź nie jest już bezpośrednim poprzednikiem pola — stoi tam '.
            ($poprzednik instanceof DOMElement
                ? '<'.$poprzednik->tagName.' class="'.$poprzednik->getAttribute('class').'">'
                : 'nic').
            '. Odstęp daje reguła z `+`, więc od tej zmiany podpis znów skleja się z polem, '.
            'a w arkuszu nic nie drgnęło.',
        );
    }
}
