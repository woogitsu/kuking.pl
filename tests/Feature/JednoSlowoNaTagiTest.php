<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * NA EKRANIE OBOWIĄZUJE JEDNO SŁOWO: „TAG" (decyzja właściciela,
 * 11 września 2026).
 *
 * Do tej decyzji ekrany tagów mówiły „tematy" — nagłówek `<h1>Wszystkie
 * tematy</h1>`, `<title>`, `meta description`, dwa `aria-label`, pusty stan
 * i odnośnik w okruszkach — a formularz publikacji prosił o „tagi". Najgorsze
 * miejsce miało oba słowa w JEDNYM zdaniu: „Wybierz temat i kliknij
 * «Obserwuj ten tag»" (`pages/settings/tags.blade.php`).
 *
 * Dwa słowa na jedną rzecz to dokładnie ten stan, przez który D-021 usunęła
 * obiekt `Temat`: „osoba 50+ musi zrozumieć, czym «temat» różni się od
 * «tagu», a to jest pytanie, na które sam produkt nie ma dobrej odpowiedzi".
 * Tamta decyzja usunęła OBIEKT; słowo zostało w napisach i wróciło jako
 * problem.
 *
 * DLACZEGO TEN STRAŻNIK JEST ZAWĘŻONY DO EKRANÓW TAGÓW, A NIE GLOBALNY
 * Bo „temat" ma w tym repozytorium DRUGIE, całkowicie uprawnione znaczenie:
 * temat listu. Pada w `app/Mail/*` i `app/Notifications/*`
 * (`PodsumowanieTygodnia::temat()`, docblocki o tym, co ma mówić temat
 * wiadomości), a `docs/brand/COPY_STYLE.md` wymienia „temat listu" jako
 * jedno z miejsc, gdzie nazwa serwisu zostaje zwykłym „Kuking". Zakaz
 * globalny oblewałby na poczcie i zostałby wyłączony w tydzień.
 *
 * DLACZEGO TAGI W DANYCH MAJĄ NEUTRALNE NAZWY
 * Ten test mierzy NASZ tekst, nie treść od ludzi. Człowiek ma prawo utworzyć
 * tag nazwany „Temat dnia" i wtedy to słowo pojawi się na stronie zgodnie
 * z prawem — dlatego dane testowe nie zawierają go ani raz, i każde trafienie
 * pochodzi z szablonu.
 */
class JednoSlowoNaTagiTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * DOPASOWANIE NA GRANICACH WYRAZU, NIE PODCIĄGIEM.
     *
     * Pierwsza wersja tego detektora szukała podciągu „temat" i zwracała dwa
     * trafienia na jednym słowie („temat" w środku „tematy"). Gorsze jest to,
     * co robiłaby dalej: oblewałaby na słowie „tematyczny" — a „grupy
     * tematyczne" to nazwa całkowicie uprawniona i stoi w issue #22.
     *
     * Stąd `(?<!\p{L})` i `(?!\p{L})`: zakaz łapie odmiany rzeczownika
     * „temat", a nie każde słowo, które się od niego zaczyna.
     */
    private const WZORZEC = '/(?<!\p{L})temat(?:y|ów|u|em|ach|owi|ami|ce)?(?!\p{L})/ui';

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create([
            'slug' => $slug,
            'name' => $nazwa,
            'normalized_name' => mb_strtolower($nazwa),
        ]);
    }

    /** @return list<string> */
    private function znalezioneOdmiany(string $html): array
    {
        preg_match_all(self::WZORZEC, $html, $trafienia);

        $slowa = array_map(
            static fn (string $slowo): string => mb_strtolower($slowo),
            $trafienia[0],
        );

        return array_values(array_unique($slowa));
    }

    public function test_spis_tagow_nie_mowi_o_tematach(): void
    {
        $promowany = $this->tag('pierogi', 'Pierogi');
        TagPromotion::create(['tag_id' => $promowany->getKey(), 'position' => 1]);
        $this->tag('zupy', 'Zupy');

        $html = $this->get(route('tags.index'))->assertOk()->getContent();

        // Kontrola dodatnia: strona naprawdę się wyrenderowała i to jest
        // spis tagów, a nie ekran błędu. Bez tego asercja „czegoś nie ma"
        // przechodziłaby także na pustej odpowiedzi (docs/PULAPKI_TESTOW.md).
        //
        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1b): `<title>`
        // tej strony to dokładnie „Wszystkie tagi", więc kontrola dodatnia
        // przechodziła także wtedy, gdy w `<main>` nie było ani nagłówka,
        // ani spisu — czyli nie kontrolowała niczego. Zmierzone 12.09.2026.
        $tresc = $this->trescEkranu((string) $html);

        $this->assertStringContainsString('Wszystkie tagi', $tresc);
        $this->assertStringContainsString('Pierogi', $html);

        $this->assertSame(
            [],
            $this->znalezioneOdmiany($html),
            'Spis tagów mówi o „tematach". Na ekranie obowiązuje jedno słowo: „tag" '
            .'(decyzja właściciela z 11 września 2026, powód w D-021). Znalezione odmiany: '
            .implode(', ', $this->znalezioneOdmiany($html)),
        );
    }

    public function test_strona_pojedynczego_tagu_nie_mowi_o_tematach(): void
    {
        $tag = $this->tag('bigos', 'Bigos');

        // WPIS JEST TU OBOWIĄZKOWY, NIE OZDOBNY. Karta wpisu pokazuje chipsy
        // z tagami tylko tam, gdzie relacja jest doładowana (`relationLoaded`),
        // czyli m.in. na tym ekranie — a to w nich stał `aria-label="Tematy
        // tego wpisu"`. Bez wpisu ten fragment w ogóle się nie renderuje
        // i test przechodziłby, nie mierząc go: czwarta z przyczyn nieoblanej
        // kontroli ujemnej (sabotowany fragment nie renderuje się
        // w scenariuszu testu).
        $autor = $this->user('autorbigosu');
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $html = $this->get(route('tags.show', $tag))->assertOk()->getContent();

        // Kontrola dodatnia: to strona tego tagu, z okruszkami, w których
        // stał zmieniany odnośnik, ORAZ z wyrenderowaną kartą wpisu.
        $this->assertStringContainsString('Bigos', $html);
        $this->assertStringContainsString('wszystkie tagi', $html);
        $this->assertStringContainsString('aria-label="Tagi tego wpisu"', $html);

        $this->assertSame(
            [],
            $this->znalezioneOdmiany($html),
            'Strona tagu mówi o „tematach". Znalezione odmiany: '
            .implode(', ', $this->znalezioneOdmiany($html)),
        );
    }

    public function test_ustawienia_obserwowanych_tagow_nie_mieszaja_dwoch_slow(): void
    {
        // PUSTY STAN, nie pełny: to w nim stało zdanie z OBOMA słowami naraz
        // („Wybierz temat i kliknij «Obserwuj ten tag»"), a ekran o dwóch
        // stanach bywa mierzony w niewłaściwym (D-099, D-106).
        // `TestCase::user()`, nie `User::factory()`: belka serwisu czyta
        // `$user->profile->username`, a sama fabryka konta profilu nie daje —
        // ekran oddaje wtedy 500 i test mierzyłby stronę błędu.
        $osoba = $this->user('obserwujaca');

        $html = $this->actingAs($osoba)
            ->get(route('settings.tags'))
            ->assertOk()
            ->getContent();

        // Kontrola dodatnia: to naprawdę pusty stan tego ekranu.
        $this->assertStringContainsString('Nie obserwujesz jeszcze żadnego tagu', $html);
        $this->assertStringContainsString('Zobacz wszystkie tagi', $html);

        $this->assertSame(
            [],
            $this->znalezioneOdmiany($html),
            'Ustawienia obserwowanych tagów mieszają „temat" z „tagiem" w jednym ekranie. '
            .'Znalezione odmiany: '.implode(', ', $this->znalezioneOdmiany($html)),
        );
    }

    public function test_detektor_odmian_naprawde_lapie_slowo_temat(): void
    {
        // KONTROLA SAMEGO DETEKTORA. Bez niej trzy testy wyżej mogłyby być
        // zielone dlatego, że wyszukiwanie nie działa — a nie dlatego, że
        // słowa nie ma.
        $this->assertSame(['tematy'], $this->znalezioneOdmiany('<h1>Wszystkie tematy</h1>'));
        $this->assertSame(['temat'], $this->znalezioneOdmiany('Wybierz temat i kliknij'));
        $this->assertSame(['tematów'], $this->znalezioneOdmiany('Spis tematów w Kuking'));
        $this->assertSame([], $this->znalezioneOdmiany('<h1>Wszystkie tagi</h1>'));

        // Wielkość litery nie może być drogą ucieczki.
        $this->assertSame(['tematy'], $this->znalezioneOdmiany('TEMATY'));
        $this->assertSame(['temat'], $this->znalezioneOdmiany('Temat'));

        // GRANICA WYRAZU. „Grupy tematyczne" to nazwa uprawniona (issue #22),
        // a „tematyka" nie jest odmianą rzeczownika, którego tu zakazujemy.
        // Detektor szukający podciągu oblewałby na obu — pierwsza wersja tego
        // pliku właśnie tak robiła.
        $this->assertSame([], $this->znalezioneOdmiany('grupy tematyczne'));
        $this->assertSame([], $this->znalezioneOdmiany('tematyka wpisu'));

        // JEDNO trafienie na słowo, nie dwa. Wersja podciągowa zwracała tu
        // ['temat', 'tematy'] dla jednego wyrazu.
        $this->assertSame(['temat'], $this->znalezioneOdmiany('Wybierz temat i oznacz tagiem'));
        $this->assertSame(['tematy'], $this->znalezioneOdmiany('Wszystkie tematy od A do Z'));
    }
}
