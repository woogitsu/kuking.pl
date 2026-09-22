<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRZY ZGŁOSZENIA WŁAŚCICIELA ZE ZRZUTÓW FORMULARZA KOMENTARZA, JEDNA
 * PRZYCZYNA.
 *
 * 1. „ile miejsca zmarnowane nad «Napisz komentarz»"
 * 2. „między polem do pisania a przyciskiem «Wyślij komentarz» nie ma nic
 *     wolnego miejsca"
 * 3. „po co informacja «wymagane» przy napisz komentarz?"
 *
 * Pierwsze dwie rzeczy to ta sama usterka widziana z dwóch stron: `.field`
 * miał wyłącznie `margin-top`, bez `margin-bottom`. Pierwsze pole w karcie
 * dokładało więc swoje 24 px DO wcięcia karty (nad etykietą stawało ~44 px
 * pustki), a ostatnie nie zostawiało pod sobą niczego, więc przycisk
 * wysyłania dotykał obwódki pola.
 *
 * Trzecia to osobna sprawa i właściciel ma rację: oznaczenie „(wymagane)"
 * ma jeden sens — odróżnić pola obowiązkowe od pomijalnych. Przy formularzu
 * z jednym polem nie ma czego odróżniać.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEN PLIK SPRAWDZA I ARKUSZ, I HTML
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo osobno żadna z tych rzeczy nie wystarcza, a razem domykają się w obie
 * strony. Reguła w arkuszu bez `.field` w HTML-u to reguła, która nie ma na
 * czym działać. `.field` w HTML-u bez reguły w arkuszu to dokładnie stan
 * ze zrzutu. Ten sam podział ma
 * `tests/Feature/OdstepPodNaglowkiemStronyTest.php` i z tego samego powodu.
 *
 * Czego te testy NIE dowodzą: że odstęp wygląda dobrze. Piksele mierzy
 * `scripts/dostepnosc.mjs` w przeglądarce. Tu pilnujemy, żeby nikt nie
 * usunął reguły ani nie odciął jej od miejsca, w którym ma zadziałać.
 */
class OdstepyWFormularzachTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/tokens.css'));
    }

    #[Test]
    public function test_pierwsze_pole_w_kontenerze_nie_doklada_odstepu_do_wciecia(): void
    {
        $trafil = preg_match('/\.field:first-child\s*\{([^}]*)\}/', $this->css(), $dopasowanie);

        $this->assertSame(
            1,
            $trafil,
            'Brak reguły `.field:first-child { ... }` w `resources/css/tokens.css`. '.
            'Bez niej pierwsze pole dokłada swoje `margin-top` do wcięcia karty '.
            'i nad etykietą stoi podwójna pustka — usterka ze zgłoszenia właściciela.',
        );

        $this->assertMatchesRegularExpression(
            '/margin-top:\s*0\b/',
            $dopasowanie[1],
            '`.field:first-child` musi zerować `margin-top` — to jest cała treść tej reguły.',
        );
    }

    #[Test]
    public function test_przycisk_po_polu_ma_odstep_z_tokenu(): void
    {
        $trafil = preg_match(
            '/\.field\s*\+\s*button\s*,\s*\.field\s*\+\s*\.btn\s*\{([^}]*)\}/',
            $this->css(),
            $dopasowanie,
        );

        $this->assertSame(
            1,
            $trafil,
            'Brak reguły dającej odstęp przyciskowi stojącemu zaraz po polu. '.
            'Bez niej „Wyślij komentarz" dotyka obwódki pola tekstowego.',
        );

        $this->assertMatchesRegularExpression(
            '/margin-top:\s*var\(--spacing-\d+\)/',
            $dopasowanie[1],
            'Odstęp musi być z tokenu (`var(--spacing-N)`), nie liczbą na sztywno — '.
            'zasada z nagłówka `app.css`.',
        );
    }

    /**
     * Reguły wyżej nie mają na czym działać, jeśli formularz komentarza nie
     * układa się tak, jak one zakładają: pole jako PIERWSZE dziecko karty
     * i przycisk jako RODZEŃSTWO tego pola. Bez tego testu można by
     * przebudować ten formularz i zostawić dwie martwe reguły w arkuszu.
     */
    #[Test]
    public function test_formularz_komentarza_uklada_sie_tak_jak_zakladaja_reguly(): void
    {
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Rosol']);

        $html = $this->actingAs($this->user('czytelnik'))
            ->get(route('posts.show', $post))
            ->assertOk()
            ->getContent();

        $formularz = $this->formularzKomentarza($html);

        // Pole jest pierwszym dzieckiem formularza-karty: po `<form ...>`
        // (i po ukrytym `_token`, który nie ma pudełka) idzie `div.field`.
        $this->assertMatchesRegularExpression(
            // `panel-formularza`, nie `card`: formularz komentarza jest od
            // rozdzielenia ról powierzchni panelem formularza, a nie kartą
            // treści (`docs/design/ROLE_KART.md`). Wzorzec pilnuje dalej tego
            // samego — że pole jest PIERWSZYM dzieckiem powierzchni formularza,
            // bo inaczej `.field:first-child` do niego nie trafia.
            '/<form[^>]*class="[^"]*\bpanel-formularza\b[^"]*"[^>]*>\s*(<input[^>]*type="hidden"[^>]*>\s*)*<div class="field/',
            $formularz,
            'Pole komentarza nie jest pierwszym elementem z pudełkiem w karcie formularza, '.
            'więc `.field:first-child` do niego nie trafia i odstęp nad etykietą wraca.',
        );

        // Przycisk stoi bezpośrednio po polu, czyli tam, gdzie działa
        // `.field + button`.
        $this->assertMatchesRegularExpression(
            '/<\/div>\s*<button[^>]*type="submit"/',
            $formularz,
            'Przycisk „Wyślij komentarz" nie stoi bezpośrednio po `div.field`, '.
            'więc `.field + button` do niego nie trafia i przycisk wraca na obwódkę pola.',
        );
    }

    #[Test]
    public function test_pole_komentarza_nie_ma_dopisku_wymagane_ale_nadal_jest_wymagane(): void
    {
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Rosol']);

        $formularz = $this->formularzKomentarza(
            $this->actingAs($this->user('czytelnik'))
                ->get(route('posts.show', $post))
                ->assertOk()
                ->getContent(),
        );

        $this->assertStringNotContainsString(
            '(wymagane)',
            $formularz,
            'Formularz komentarza ma jedno pole, więc dopisek „(wymagane)" nie ma czego '.
            'odróżniać — zgłoszenie właściciela: „po co informacja «wymagane»?".',
        );
        $this->assertStringNotContainsString('(nieobowiązkowe)', $formularz);

        // I RZECZ NAJWAŻNIEJSZA: zdjęcie dopisku nie ma prawa zdjąć samego
        // wymogu. Bez tej asercji „naprawą" mogłoby być usunięcie `required`.
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*\brequired\b/',
            $formularz,
            'Pole komentarza przestało być `required` — dopisek miał zniknąć, wymóg nie.',
        );
    }

    /**
     * KONTROLA DODATNIA. Bez niej test wyżej przeszedłby także wtedy, gdyby
     * ktoś usunął oznaczenia z `x-field` W CAŁYM SERWISIE — a przy kilku
     * polach są one potrzebne, szczególnie „(nieobowiązkowe)": bez niego
     * człowiek wypełnia wszystko i porzuca formularz w połowie.
     */
    #[Test]
    public function test_formularz_z_kilkoma_polami_nadal_pokazuje_oznaczenia(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString(
            '(wymagane)',
            $html,
            'Rejestracja przestała oznaczać pola wymagane. Parametr `bez-oznaczenia` '.
            'jest dla formularzy z JEDNYM polem, nie do odchudzania formularzy w ogóle.',
        );
    }

    /** Wycina sam formularz dopisywania komentarza z całej strony wpisu. */
    private function formularzKomentarza(string $html): string
    {
        // `panel-formularza` od rozdzielenia ról powierzchni: formularz
        // komentarza jest jedyną rzeczą w wątku, która czegoś wymaga
        // (`docs/design/ROLE_KART.md`). Zapasowe szukanie po `name="body"`
        // niżej zostaje — ono ratowało ten test przy zmianie kolejności
        // atrybutów i uratowałoby też przy tej zmianie, ale wtedy test
        // pilnowałby czegoś innego, niż mówi jego pierwsza linijka.
        $start = mb_strpos($html, '<form class="panel-formularza" method="POST"');

        if ($start === false) {
            // Formularz mógł dostać inną kolejność atrybutów — szukamy po
            // nazwie pola, bo `body` jest tu unikalne.
            $start = mb_strpos($html, 'name="body"');
            $this->assertNotFalse($start, 'Nie znalazłem formularza komentarza na stronie wpisu.');
            $start = (int) mb_strrpos(mb_substr($html, 0, $start), '<form');
        }

        $koniec = mb_strpos($html, '</form>', $start);

        return mb_substr($html, $start, $koniec === false ? null : $koniec - $start);
    }
}
