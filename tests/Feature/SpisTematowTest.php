<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Spis wszystkich tagów (#273, druga połowa — `tags.index`, D-087
 * w `docs/DECISIONS.md` ma pełne uzasadnienie kolejności i liczników).
 *
 * DLACZEGO NIE PO PROSTU `assertSee` NA CAŁYM HTML-U
 * Strona ma dwie sekcje z tagami plus nawigację i stopkę, które mogą
 * zawierać te same nazwy i cyfry (`docs/PULAPKI_TESTOW.md` #1). Testy
 * kolejności i liczników wycinają konkretną sekcję przez DOMXPath, po
 * `aria-label` nadanym w widoku (`<nav aria-label="…">`), zamiast szukać
 * podciągu w całej stronie.
 */
class SpisTematowTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    private function promowany(string $slug, string $nazwa, int $pozycja): Tag
    {
        $tag = $this->tag($slug, $nazwa);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $pozycja]);

        return $tag;
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function wpis(Tag $tag, User $autor, array $atrybuty = []): Post
    {
        $post = Post::factory()->create(array_merge([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ], $atrybuty));

        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    /**
     * Teksty chipów (nazwa + licznik) WYŁĄCZNIE z jednej sekcji, po
     * `aria-label` nadanym tej sekcji w `pages/tags/index.blade.php`.
     *
     * @return list<string>
     */
    private function chipyWSekcji(string $html, string $ariaLabel): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $sekcja = $xpath->query("//nav[@aria-label='{$ariaLabel}']")->item(0);
        $this->assertNotNull($sekcja, "Nie znalazłem sekcji „{$ariaLabel}” (nav[aria-label]) w dokumencie.");

        $linki = [];
        foreach ($xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' chip ')]", $sekcja) as $a) {
            $linki[] = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        }

        return $linki;
    }

    public function test_gosc_widzi_strone_spisu_tematow(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $this->wpis($zupy, $this->user('autor_gosc'));

        $html = (string) $this->get(route('tags.index'))->assertOk()->getContent();

        // NA TREŚCI EKRANU (pułapka 1b): „Wszystkie tagi" jest też `<title>`
        // tej strony, więc asercja na całej odpowiedzi przechodziła po
        // skasowaniu nagłówka i spisu z `<main>`. Zmierzone 12.09.2026.
        $this->assertStringContainsString('Wszystkie tagi', $this->trescEkranu($html));
        $this->assertContains('Zupy (1 wpis)', $this->chipyWSekcji($html, 'Wszystkie tagi, alfabetycznie'));
    }

    public function test_zalogowana_osoba_widzi_strone_spisu_tematow(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $this->wpis($zupy, $this->user('autor_zalogowany'));

        $html = (string) $this->actingAs($this->user('widz_zalogowany'))
            ->get(route('tags.index'))
            ->assertOk()
            ->getContent();

        $this->assertContains('Zupy (1 wpis)', $this->chipyWSekcji($html, 'Wszystkie tagi, alfabetycznie'));
    }

    public function test_kolejnosc_wszystkich_tematow_jest_alfabetyczna_nie_po_liczbie_wpisow(): void
    {
        // "Zupy" dostaje DZIESIĘĆ wpisów, "Aromatyczne zioła" ani jednego —
        // gdyby kolejność szła po liczbie wpisów (ranking), "Zupy" byłoby
        // pierwsze mimo litery Z. Alfabet ma je odwrócić.
        $zupy = $this->tag('zupy', 'Zupy');
        $autor = $this->user('autor_kolejnosc');
        for ($i = 0; $i < 10; $i++) {
            $this->wpis($zupy, $autor, ['published_at' => now()->subMinutes($i)]);
        }
        $this->tag('aromatyczne-ziola', 'Aromatyczne zioła');

        $html = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        $chipy = $this->chipyWSekcji($html, 'Wszystkie tagi, alfabetycznie');

        $pozycjaZiol = array_search('Aromatyczne zioła (0 wpisów)', $chipy, true);
        $pozycjaZup = array_search('Zupy (10 wpisów)', $chipy, true);

        $this->assertNotFalse($pozycjaZiol, 'Nie znalazłem tagu „Aromatyczne zioła” z prawdziwym zerem w spisie.');
        $this->assertNotFalse($pozycjaZup, 'Nie znalazłem tagu „Zupy” z dziesięcioma wpisami w spisie.');
        $this->assertLessThan(
            $pozycjaZup,
            $pozycjaZiol,
            'Temat z zerem wpisów powinien stać PRZED tematem z dziesięcioma — kolejność jest alfabetem, nie liczbą wpisów.',
        );
    }

    public function test_tematy_promowane_sa_w_osobnej_sekcji_w_kolejnosci_gospodarza(): void
    {
        // "Zupy" ma WIĘCEJ wpisów niż "Barszcz", ale gospodarz ustawił
        // "Zupy" na wcześniejszej pozycji — sekcja "Polecane" ma respektować
        // TĘ kolejność, nie alfabet (Barszcz < Zupy) ani liczbę wpisów.
        $zupy = $this->promowany('zupy', 'Zupy', 0);
        $barszcz = $this->promowany('barszcz', 'Barszcz', 1);
        $autor = $this->user('autor_promowane');
        for ($i = 0; $i < 5; $i++) {
            $this->wpis($zupy, $autor, ['published_at' => now()->subMinutes($i)]);
        }
        $this->wpis($barszcz, $autor);

        $html = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $karty = $xpath->query('//nav[@aria-label="Polecane tagi"]/a');
        $this->assertSame(2, $karty->length);
        foreach ([[$zupy, 'Zupy', '(5 wpisów)'], [$barszcz, 'Barszcz', '(1 wpis)']] as $i => [$tag, $nazwa, $licznik]) {
            $karta = $karty->item($i);
            $this->assertSame(route('tags.show', $tag), $karta->getAttribute('href'), 'Kolejność kart musi odpowiadać pozycji gospodarza.');
            $this->assertSame($nazwa, trim($xpath->query('.//strong', $karta)->item(0)->textContent));
            $this->assertStringContainsString($licznik, trim(preg_replace('/\s+/u', ' ', $karta->textContent)));
        }
    }

    public function test_pusty_temat_pokazuje_prawdziwa_zerowa_liczbe_i_ten_sam_pusty_stan_co_strona_tagu(): void
    {
        $pusty = $this->tag('pusty-temat', 'Pusty temat');

        $spis = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        $this->assertContains(
            'Pusty temat (0 wpisów)',
            $this->chipyWSekcji($spis, 'Wszystkie tagi, alfabetycznie'),
            'Temat bez wpisów musi się pokazać na spisie, z PRAWDZIWYM zerem — issue #273 pozwala na puste tematy, zakazuje udawania.',
        );

        // Spójność z tym, co temat już dziś pokazuje po wejściu: ten sam
        // pusty stan, żadnego nowego tekstu wymyślonego na potrzeby spisu.
        $this->get(route('tags.show', $pusty))
            ->assertOk()
            ->assertSee('Tu jeszcze nikt nic nie ugotował');
    }

    public function test_tag_ukryty_i_scalony_nie_pojawia_sie_na_spisie(): void
    {
        $ukryty = Tag::factory()->hidden()->create(['name' => 'Ukryty temat', 'slug' => 'ukryty-temat']);

        $kanoniczny = $this->tag('kanoniczny', 'Kanoniczny temat');
        $scalony = $this->tag('scalony', 'Scalony temat');
        $scalony->status = Tag::STATUS_MERGED;
        $scalony->merged_into_tag_id = $kanoniczny->getKey();
        $scalony->save();

        $html = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        $chipy = $this->chipyWSekcji($html, 'Wszystkie tagi, alfabetycznie');

        $this->assertNotContains('Ukryty temat (0 wpisów)', $chipy);
        $this->assertNotContains('Scalony temat (0 wpisów)', $chipy);
        // Kontrola dodatnia: kanoniczny (aktywny) MUSI się pokazać, inaczej
        // powyższe dwa `assertNotContains` przeszłyby też wtedy, gdyby
        // spis w ogóle nic nie renderował.
        $this->assertContains('Kanoniczny temat (0 wpisów)', $chipy);
    }

    public function test_liczba_wpisow_nie_liczy_prywatnych_wpisow_obserwujacych_ani_zawieszonych_autorow(): void
    {
        $temat = $this->tag('temat-widocznosci', 'Temat widoczności');
        $autor = $this->user('autor_widocznosc');

        // JEDYNY wpis, który ma się policzyć: publiczny, opublikowany,
        // od aktywnego autora.
        $this->wpis($temat, $autor);

        // Trzy wpisy, które NIE mają się policzyć — każdy z innego powodu.
        $this->wpis($temat, $autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->wpis($temat, $autor, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $zawieszony = $this->user('autor_zawieszony');
        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();
        $this->wpis($temat, $zawieszony);

        $html = (string) $this->get(route('tags.index'))->assertOk()->getContent();

        $this->assertContains(
            'Temat widoczności (1 wpis)',
            $this->chipyWSekcji($html, 'Wszystkie tagi, alfabetycznie'),
            'Cztery wpisy mają ten tag, ale tylko JEDEN jest publiczny, opublikowany i od aktywnego autora — licznik nie może pokazać więcej niż prawda dla gościa.',
        );
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_spis_tematow_nie_generuje_zapytania_na_kazdy_temat(): void
    {
        $autor = $this->user('autor_wachlarz');

        // MAŁO: dwa tematy, każdy z trzema wpisami.
        for ($t = 0; $t < 2; $t++) {
            $tag = $this->tag('malo-'.$t, 'Malo'.$t);
            for ($i = 0; $i < 3; $i++) {
                $this->wpis($tag, $autor);
            }
        }

        $maloZapytan = $this->policzZapytania(
            fn () => $this->get(route('tags.index'))->assertOk(),
        );

        // Sprzątamy, żeby druga próba nie liczyła danych z pierwszej.
        DB::table('post_tags')->delete();
        Post::query()->forceDelete();
        Tag::query()->delete();

        // DUŻO: dwadzieścia tagów, też po trzy wpisy. Gdyby licznik
        // wpisów szedł osobnym zapytaniem na temat, byłoby tu ok. +20.
        for ($t = 0; $t < 20; $t++) {
            $tag = $this->tag('duzo-'.$t, 'Duzo'.$t);
            for ($i = 0; $i < 3; $i++) {
                $this->wpis($tag, $autor);
            }
        }

        $this->assertSame(
            20,
            Tag::query()->count(),
            'asercja kontrolna: w bazie musi naprawdę być 20 tagów, inaczej test nie mierzy niczego',
        );

        $duzoHtml = null;
        $duzoZapytan = $this->policzZapytania(function () use (&$duzoHtml): void {
            $duzoHtml = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        });

        // Kontrola dodatnia: w TYM SAMYM przebiegu, na którym mierzymy
        // zapytania, licznik musi naprawdę pokazywać "3 wpisy" — inaczej
        // "tyle samo zapytań" przechodziłoby też wtedy, gdyby licznik nic
        // nie liczył (docs/PULAPKI_TESTOW.md #4).
        $this->assertContains(
            'Duzo5 (3 wpisy)',
            $this->chipyWSekcji((string) $duzoHtml, 'Wszystkie tagi, alfabetycznie'),
        );

        fwrite(STDERR, sprintf(
            "\n[#273 /tagi] malo (2 tematy x 3 wpisy): %d zapytan, duzo (20 tematow x 3 wpisy): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą tagów (N+1): {$maloZapytan} przy 2 tematach, {$duzoZapytan} przy 20.",
        );
    }

    public function test_stronicowanie_pokazuje_kolejne_tematy_bez_powtorzen(): void
    {
        config(['kuking.tags.index_page_size' => 2]);

        foreach (['A', 'B', 'C', 'D', 'E'] as $litera) {
            $this->tag('temat-'.mb_strtolower($litera), 'Temat'.$litera);
        }

        $pierwsza = (string) $this->get(route('tags.index'))->assertOk()->getContent();
        $pierwszaLista = $this->chipyWSekcji($pierwsza, 'Wszystkie tagi, alfabetycznie');

        $this->assertSame(['TematA (0 wpisów)', 'TematB (0 wpisów)'], $pierwszaLista);
        $this->assertStringContainsString('Pokaż więcej tagów', $pierwsza);

        $druga = (string) $this->get(route('tags.index', ['page' => 2]))->assertOk()->getContent();
        $drugaLista = $this->chipyWSekcji($druga, 'Wszystkie tagi, alfabetycznie');

        $this->assertSame(['TematC (0 wpisów)', 'TematD (0 wpisów)'], $drugaLista);
    }

    public function test_martwe_punkty_prowadza_teraz_do_spisu_tematow(): void
    {
        // `/ustawienia/tagi` bez obserwowanych i bez promowanych tagów
        // dziś kierowało donikąd ("zacznij od strony dowolnego tagu" bez
        // adresu, do którego dojść) — PROSTOTA_JAK_GARNEK.md §3 pkt 1.
        $osoba = $this->user('bez_tagow');
        $this->actingAs($osoba)
            ->get(route('settings.tags'))
            ->assertOk()
            ->assertSee(route('tags.index'), false);

        // Strona pojedynczego tagu ma teraz drogę powrotną do pełnego spisu.
        $tag = $this->tag('dowolny-temat', 'Dowolny temat');
        $this->get(route('tags.show', $tag))
            ->assertOk()
            ->assertSee(route('tags.index'), false);
    }
}
