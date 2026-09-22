<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Support\ZapowiedzWpisu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Długi wpis nie zjada na głównej całego ekranu (issue #354).
 *
 * CZEGO PILNUJE TEN PLIK
 * Karta w feedzie pokazuje początek długiego wpisu i odnośnik „Czytaj dalej"
 * do strony wpisu. Trzy rzeczy, które łatwo zgubić przy następnej zmianie
 * karty, i każda ma tu własny test:
 *
 * 1. wpis krótki NIE dostaje „Czytaj dalej" — bez tego odnośnik stałby pod
 *    każdym wpisem, także dwuzdaniowym, i po kliknięciu nie pokazywałby nic
 *    nowego (martwy przycisk, D-053);
 * 2. wpis długi ZNAKAMI jest skrócony, a odnośnik prowadzi do `posts.show`
 *    TEGO wpisu;
 * 3. wpis długi WIERSZAMI, ale krótki znakami (lista składników) też jest
 *    skrócony — `white-space: pre-line` sprawia, że to wiersze zajmują ekran,
 *    więc próg liczony wyłącznie w znakach przepuściłby dokładnie ten wpis,
 *    od którego zaczęło się zgłoszenie.
 *
 * KAŻDY Z TYCH TESTÓW WYKLUCZA DRUGI PRÓG. Test znakowy dostaje treść
 * w jednym wierszu, test wierszowy — treść krótszą niż limit znaków, i mówi
 * to własną asercją. Bez tego oba trafiałyby w tę samą gałąź warunku
 * i drugi nie sprawdzałby niczego (`docs/PULAPKI_TESTOW.md` §3b).
 */
class DlugiWpisNaKarcieMaCzytajDalejTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sama karta wpisu, a nie cały dokument.
     *
     * Strona główna ma nawigację, tablicę dnia, szynę i stopkę — asercja
     * „nie ma »Czytaj dalej«" na całym HTML-u przechodziłaby także wtedy,
     * gdyby karta w ogóle się nie wyrenderowała (`docs/PULAPKI_TESTOW.md` §1).
     */
    private function karta(string $html): string
    {
        $start = strpos($html, '<article class="card post-card">');

        $this->assertNotFalse(
            $start,
            'Na stronie nie ma ani jednej karty wpisu — nie ma czego sprawdzać.',
        );

        $koniec = strpos($html, '</article>', $start);

        $this->assertNotFalse($koniec, 'Karta wpisu nie ma zamknięcia — zmienił się jej kształt?');

        return substr($html, $start, $koniec - $start);
    }

    /** Strona główna widziana przez autorkę wpisu (feed zawiera własne wpisy). */
    private function kartaNaGlownej(Post $post): string
    {
        $html = (string) $this->actingAs($post->author)
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        return $this->karta($html);
    }

    private function wpis(string $tresc): Post
    {
        return Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'body' => $tresc,
        ]);
    }

    public function test_krotki_wpis_nie_dostaje_odnosnika_czytaj_dalej(): void
    {
        $post = $this->wpis("Rosół na niedzielę.\nWyszedł złocisty i czysty.");

        $karta = $this->kartaNaGlownej($post);

        // KONTROLA DODATNIA. Sama asercja negatywna przechodzi również
        // wtedy, gdy karta nie pokazuje treści wpisu wcale — wtedy nie ma
        // w niej ani „Czytaj dalej", ani niczego innego.
        $this->assertStringContainsString(
            'Wyszedł złocisty i czysty.',
            $karta,
            'Karta nie pokazuje treści krótkiego wpisu — dalsze asercje nic by nie znaczyły.',
        );

        $this->assertStringNotContainsString(
            'Czytaj dalej',
            $karta,
            'Krótki wpis dostał „Czytaj dalej". Po kliknięciu człowiek nie zobaczy '
            .'nic nowego, a martwy przycisk jest zakazany (D-053).',
        );
    }

    public function test_wpis_dlugi_znakami_jest_skrocony_i_prowadzi_do_strony_wpisu(): void
    {
        $zdanie = 'Rosół gotuje się na wolnym ogniu przez trzy godziny.';
        $tresc = trim(str_repeat($zdanie.' ', 14)).' ostatniewyrazenie';

        // Ten test ma trafiać w próg ZNAKÓW, a nie w próg wierszy.
        $this->assertStringNotContainsString("\n", $tresc);
        $this->assertGreaterThan(ZapowiedzWpisu::LIMIT_ZNAKOW, mb_strlen($tresc));

        $post = $this->wpis($tresc);
        $karta = $this->kartaNaGlownej($post);

        $this->assertMatchesRegularExpression(
            '~<a[^>]*href="'.preg_quote($post->url(), '~').'"[^>]*>\s*Czytaj dalej\s*</a>~u',
            $karta,
            'Pod skróconym wpisem nie ma odnośnika „Czytaj dalej" prowadzącego do tego wpisu.',
        );

        $this->assertStringNotContainsString(
            'ostatniewyrazenie',
            $karta,
            'Karta pokazuje całą treść — skrócenia nie ma, więc odnośnik niczego nie odsłania.',
        );

        // Cięcie idzie po CAŁYCH wyrazach (`Str::words`, nie `Str::limit`):
        // każdy wyraz zapowiedzi musi być wyrazem z oryginału.
        $zapowiedz = ZapowiedzWpisu::skroc($tresc);

        $this->assertStringEndsWith('…', $zapowiedz);

        // Bez wielokropka: `mb_substr`, a nie `rtrim('…')` — `rtrim` bierze
        // listę BAJTÓW i przy polskich literach potrafi uciąć o jeden za dużo.
        $bezWielokropka = mb_substr($zapowiedz, 0, -1);

        $this->assertStringContainsString(
            $bezWielokropka,
            $karta,
            'Na karcie stoi inny tekst niż zapowiedź liczona przez ZapowiedzWpisu.',
        );

        $oryginalne = preg_split('/\s+/u', $tresc, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (preg_split('/\s+/u', $bezWielokropka, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $wyraz) {
            $this->assertContains(
                $wyraz,
                $oryginalne,
                "Zapowiedź urwała się w środku wyrazu („{$wyraz}\") — to robi `Str::limit()`, ".
                'a tniemy po `Str::words()`.',
            );
        }
    }

    public function test_lista_skladnikow_jest_skrocona_mimo_malej_liczby_znakow(): void
    {
        $wiersze = [
            '500 g mąki',
            '350 ml wody',
            '7 g drożdży',
            '10 g soli',
            '1 łyżka oliwy',
            'Mieszam wszystko łyżką',
            'Odstawiam na noc',
            'Piekę w garnku',
            'Studzę na kratce',
            'Krojenie dopiero po godzinie',
        ];
        $tresc = implode("\n", $wiersze);

        // Ten test ma trafiać w próg WIERSZY. Gdyby treść przekroczyła też
        // limit znaków, przechodziłby po skasowaniu całego liczenia wierszy.
        $this->assertLessThan(ZapowiedzWpisu::LIMIT_ZNAKOW, mb_strlen($tresc));
        $this->assertGreaterThan(ZapowiedzWpisu::LIMIT_WIERSZY, count($wiersze));

        $post = $this->wpis($tresc);
        $karta = $this->kartaNaGlownej($post);

        $this->assertStringContainsString(
            '500 g mąki',
            $karta,
            'Karta nie pokazuje początku listy składników.',
        );

        $this->assertStringContainsString(
            'Czytaj dalej',
            $karta,
            'Lista składników ma dziesięć wierszy i zjada na telefonie cały ekran, '
            .'a nie została skrócona — próg liczy chyba tylko znaki.',
        );

        $this->assertStringNotContainsString(
            'Krojenie dopiero po godzinie',
            $karta,
            'Dziesiąty wiersz nadal stoi na karcie, choć próg to osiem wierszy.',
        );
    }

    /**
     * Ta sama karta stoi na stronie wpisu. Gdyby skracała także tam, całej
     * treści nie dałoby się przeczytać nigdzie, a „Czytaj dalej" prowadziłoby
     * na stronę, na której człowiek już stoi.
     */
    public function test_strona_wpisu_pokazuje_calosc_i_nie_ma_czytaj_dalej(): void
    {
        $tresc = trim(str_repeat('Rosół gotuje się na wolnym ogniu przez trzy godziny. ', 14)).' ostatniewyrazenie';
        $post = $this->wpis($tresc);

        $html = (string) $this->actingAs($this->user('czytelniczka'))
            ->get($post->url())
            ->assertOk()
            ->getContent();

        $karta = $this->karta($html);

        $this->assertStringContainsString(
            'ostatniewyrazenie',
            $karta,
            'Strona wpisu też skraca treść — całości nie da się przeczytać nigdzie.',
        );

        $this->assertStringNotContainsString(
            'Czytaj dalej',
            $karta,
            'Na stronie wpisu „Czytaj dalej" prowadziłoby samo do siebie (D-053).',
        );
    }

    /**
     * Cel dotykowy 48 px LICZONY W PIKSELACH.
     *
     * `rem` i `--control-height-touch` rosną razem z korzeniem, więc przy
     * czcionce przeglądarki 200% dawałyby 96 px zabrane z ekranu, nie dając
     * ani jednego czytelnego piksela (D-082/D-107). Pismo odwrotnie: token
     * `--text-body` to 18 px minimum produktowe i ma rosnąć z ustawieniem
     * czytelnika.
     */
    public function test_czytaj_dalej_ma_cel_dla_palca_w_pikselach_i_pismo_z_tokena(): void
    {
        $css = (string) preg_replace(
            '~/\*.*?\*/~s',
            '',
            (string) file_get_contents(resource_path('css/app.css')),
        );

        $this->assertSame(
            1,
            preg_match('~\.post-card-czytaj-dalej a\s*\{([^{}]*)\}~', $css, $regula),
            'Nie znalazłem reguły `.post-card-czytaj-dalej a` w app.css.',
        );

        $this->assertSame(
            1,
            preg_match('~min-height\s*:\s*(\d+)px~', $regula[1], $wysokosc),
            'Odnośnik „Czytaj dalej" nie ma wysokości podanej W PIKSELACH — '
            .'w `rem` przestaje być minimum dla palca, a staje się liczbą typograficzną.',
        );

        $this->assertGreaterThanOrEqual(48, (int) $wysokosc[1]);

        $this->assertSame(
            1,
            preg_match('~font-size\s*:\s*var\((--text-body(?:-lg)?)\)~', $regula[1]),
            'Pismo odnośnika ma iść z `--text-body` (18 px) albo `--text-body-lg` (20 px).',
        );

        $this->assertStringNotContainsString(
            '-webkit-line-clamp',
            $css,
            'Klamra CSS przycina tekst, ale nie mówi szablonowi, czy przycięła — '
            .'„Czytaj dalej" stanąłby wtedy także pod wpisem dwuzdaniowym (D-053).',
        );
    }
}
