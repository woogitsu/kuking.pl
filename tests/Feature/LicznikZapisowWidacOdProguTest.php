<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\ZapisyWpisu;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „3 osoby zapisały to u siebie w zeszycie" — dwa progi i granica liczenia
 * (issue #275, decyzja właściciela D-081).
 *
 * CO TU JEST PILNOWANE
 * Decyzja właściciela brzmi: pokazać, ile osób zapisało, „nie chodzi
 * o rywalizację a docenienie". Cała różnica między jednym i drugim siedzi
 * w tym, KOMU i OD ILU pokazujemy liczbę — więc to jest jedyna rzecz, którą
 * ten plik testuje, i testuje ją PRZEZ EKRAN, nie przez metodę domenową
 * (`ZapisyWpisu` liczy poprawnie tylko wtedy, gdy widok naprawdę tego woła).
 *
 * PUŁAPKA, KTÓRA W TYM REPOZYTORIUM ZŁAPAŁA JUŻ KILKU: `assertSee('3')` na
 * całej stronie łapie trójkę skądinąd — z licznika komentarzy, z daty,
 * z numeru porcji. Dlatego każda asercja tego pliku idzie przez
 * {@see self::linijkaZapisow()}, czyli WNĘTRZE konkretnego akapitu
 * (`data-rola="liczba-zapisow"`), a „nie widać liczby" znaczy „tego akapitu
 * nie ma w HTML-u", nie „nie ma gdzieś na stronie takiej cyfry".
 *
 * KONTROLA DODATNIA JEST TU OBOWIĄZKOWA
 * Testy progu przechodziłyby także wtedy, gdyby licznik nie pokazywał się
 * NIGDY I NIKOMU — bo dwa z nich sprawdzają właśnie brak. Dlatego przy każdym
 * teście na brak stoi test na obecność tej samej liczby powyżej progu
 * ({@see self::test_powyzej_progu_liczbe_widza_i_autor_i_obcy()}).
 */
class LicznikZapisowWidacOdProguTest extends TestCase
{
    use RefreshDatabase;

    private function wpisAutora(User $autor): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
        ]);
    }

    /**
     * Zapisuje wpis do zeszytu tej osoby TĄ SAMĄ AKCJĄ, którą woła kontroler.
     *
     * @return list<User>
     */
    private function zapisujacy(Post $wpis, int $ile, string $prefiks = 'zapisuje'): array
    {
        $akcja = app(SavePostToCollection::class);
        $osoby = [];

        for ($i = 0; $i < $ile; $i++) {
            $osoba = $this->user($prefiks.'_'.$i);
            $akcja->handle($osoba, $wpis);
            $osoby[] = $osoba;
        }

        return $osoby;
    }

    /**
     * Wnętrze akapitu z liczbą zapisów albo `null`, gdy takiego akapitu
     * na stronie nie ma.
     *
     * Świadomie NIE `assertSee`: patrz komentarz klasy.
     */
    private function linijkaZapisow(string $html): ?string
    {
        $znalazl = preg_match(
            '/<p[^>]*data-rola="liczba-zapisow"[^>]*>(.*?)<\/p>/su',
            $html,
            $trafienie,
        );

        return $znalazl === 1 ? trim(html_entity_decode($trafienie[1])) : null;
    }

    public function test_autor_widzi_liczbe_od_pierwszego_zapisu(): void
    {
        $autor = $this->user('autorka_od_jednego');
        $wpis = $this->wpisAutora($autor);

        $this->zapisujacy($wpis, 1);

        $html = $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '1 osoba zapisała to u siebie w zeszycie',
            $this->linijkaZapisow((string) $html),
            'Autor ma widzieć liczbę od PIERWSZEGO zapisu — na tym stoi cała decyzja D-081.',
        );
    }

    public function test_obcy_nie_widzi_liczby_ponizej_progu_a_autor_widzi(): void
    {
        $autor = $this->user('autorka_ponizej_progu');
        $obcy = $this->user('obcy_ponizej_progu');
        $wpis = $this->wpisAutora($autor);

        // DWA zapisy — o jeden mniej niż próg. Liczba jest tu wpisana na
        // sztywno, a NIE wyliczona z `ZapisyWpisu::PROG_DLA_OBCYCH`: test,
        // który bierze dane z pilnowanej stałej, dopasowuje się do jej
        // zmiany i przestaje o cokolwiek pytać. Sam próg pilnuje osobno
        // `test_prog_dla_obcych_jest_decyzja_wlasciciela()`.
        $this->zapisujacy($wpis, 2);

        $htmlObcego = (string) $this->actingAs($obcy)->get($wpis->url())->assertOk()->getContent();

        $this->assertNull(
            $this->linijkaZapisow($htmlObcego),
            'Poniżej progu obcy nie ma widzieć akapitu z liczbą — ani „2 osoby", ani zera.',
        );

        // Ta sama strona, te same dane, inne oczy: autor liczbę widzi. Bez tej
        // połowy test przechodziłby też wtedy, gdyby licznik był po prostu
        // wyłączony.
        $htmlAutora = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '2 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($htmlAutora),
        );
    }

    public function test_powyzej_progu_liczbe_widza_i_autor_i_obcy(): void
    {
        $autor = $this->user('autorka_powyzej_progu');
        $obcy = $this->user('obcy_powyzej_progu');
        $wpis = $this->wpisAutora($autor);

        $this->zapisujacy($wpis, 3);

        $htmlObcego = (string) $this->actingAs($obcy)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '3 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($htmlObcego),
            'KONTROLA DODATNIA: powyżej progu obcy MUSI liczbę zobaczyć.',
        );

        $htmlAutora = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '3 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($htmlAutora),
        );
    }

    public function test_liczba_pokazuje_sie_takze_na_karcie_w_feedzie(): void
    {
        $autor = $this->user('autorka_w_feedzie');
        $obcy = $this->user('obcy_w_feedzie');
        $wpis = $this->wpisAutora($autor);

        $this->zapisujacy($wpis, 3);

        $html = (string) $this->actingAs($obcy)->get(route('discover'))->assertOk()->getContent();

        $this->assertSame(
            '3 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($html),
            'Karta w feedzie ma dostawać liczbę TYM SAMYM zapytaniem — patrz ZapisyWpisu::dolicz().',
        );
    }

    public function test_zapisy_kont_zamknietych_i_zawieszonych_sie_nie_licza(): void
    {
        $autor = $this->user('autorka_statusy');
        $obcy = $this->user('obcy_statusy');
        $wpis = $this->wpisAutora($autor);

        // Trzy zapisy, które się liczą — dokładnie próg.
        $this->zapisujacy($wpis, 3, 'dobry');

        // I trzy, które liczyć się NIE MOGĄ. Zapis idzie normalną drogą, a status
        // zmienia się po nim — tak to wygląda w życiu: ktoś zapisał, a potem
        // dostał sankcję albo skasował konto.
        foreach ([User::STATUS_BANNED, User::STATUS_SUSPENDED, User::STATUS_PENDING_DELETE] as $index => $status) {
            [$osoba] = $this->zapisujacy($wpis, 1, 'zly_'.$index);
            $osoba->forceFill(['status' => $status])->save();
        }

        $html = (string) $this->actingAs($obcy)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '3 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($html),
            'Zapis od konta zbanowanego, zawieszonego albo w trakcie usuwania nie ma podbijać liczby '
            .'(bez filtra byłoby tu „6 osób").',
        );
    }

    public function test_blokada_dziala_w_obie_strony(): void
    {
        $autor = $this->user('autorka_blokady');
        $widz = $this->user('widz_blokady');
        $wpis = $this->wpisAutora($autor);

        $osoby = $this->zapisujacy($wpis, 5, 'blok');

        $blokada = app(BlockUser::class);

        // Widz odciął pierwszą osobę…
        $blokada->handle($widz, $osoby[0]);
        // …a druga odcięła widza. Blokada działająca „w jedną stronę"
        // nie działa (AGENTS.md §4).
        $blokada->handle($osoby[1], $widz);

        $htmlWidza = (string) $this->actingAs($widz)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '3 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($htmlWidza),
            'Widz nie ma widzieć śladu osób, z którymi łączy go blokada — w żadną stronę.',
        );

        // Autora ta blokada nie dotyczy: on widzi wszystkie pięć.
        $htmlAutora = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '5 osób zapisało to u siebie w zeszycie',
            $this->linijkaZapisow($htmlAutora),
        );
    }

    public function test_wlasny_zapis_autora_nie_podbija_licznika(): void
    {
        $autor = $this->user('autorka_wlasny_zapis');
        $wpis = $this->wpisAutora($autor);

        app(SavePostToCollection::class)->handle($autor, $wpis);
        $this->zapisujacy($wpis, 2, 'obca');

        $html = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '2 osoby zapisały to u siebie w zeszycie',
            $this->linijkaZapisow($html),
            'Licznik, który autor może sobie sam podbić, nie jest informacją o niczym.',
        );
    }

    public function test_jedna_osoba_z_dwoma_zeszytami_liczy_sie_raz(): void
    {
        $autor = $this->user('autorka_dwa_zeszyty');
        $wpis = $this->wpisAutora($autor);

        $zapisujaca = $this->user('ma_dwa_zeszyty');
        $akcja = app(SavePostToCollection::class);

        $akcja->handle($zapisujaca, $wpis);
        $drugiZeszyt = $zapisujaca->collections()->create([
            'name' => 'Na święta',
            'visibility' => 'private',
        ]);
        $akcja->handle($zapisujaca, $wpis, $drugiZeszyt);

        $html = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '1 osoba zapisała to u siebie w zeszycie',
            $this->linijkaZapisow($html),
            'Liczymy LUDZI, nie pozycje w zeszytach — count(distinct users.id).',
        );
    }

    public function test_gosc_nie_widzi_liczby_nawet_powyzej_progu(): void
    {
        $autor = $this->user('autorka_dla_goscia');
        $wpis = $this->wpisAutora($autor);

        $this->zapisujacy($wpis, 5, 'dla_goscia');

        // Pięć zapisów, czyli grubo nad progiem — a gość i tak nie widzi nic.
        // Liczba jest adresowana do społeczności, nie do otwartego internetu
        // (D-081), a strona powitalna układa wpisy w siatkę, więc liczby jedna
        // obok drugiej byłyby zestawieniem.
        $htmlGoscia = (string) $this->get($wpis->url())->assertOk()->getContent();

        $this->assertNull(
            $this->linijkaZapisow($htmlGoscia),
            'Gość nie ma widzieć liczby zapisów — nigdzie.',
        );

        // KONTROLA DODATNIA: te same dane, ten sam ekran, widz zalogowany.
        $htmlWidza = (string) $this->actingAs($this->user('widz_dla_goscia'))
            ->get($wpis->url())->assertOk()->getContent();

        $this->assertSame(
            '5 osób zapisało to u siebie w zeszycie',
            $this->linijkaZapisow($htmlWidza),
        );
    }

    public function test_prog_dla_obcych_jest_decyzja_wlasciciela(): void
    {
        // Wartość progu jest DECYZJĄ (D-081), nie szczegółem implementacji:
        // trzy, bo właściciel w #275 sam nazwał dwójkę liczbą, która wypada
        // słabo („to zapisało 10 osób a to tylko 2"). Ten test nie sprawdza
        // działania kodu — pilnuje, żeby nikt nie przesunął progu po cichu,
        // bez decyzji właściciela i bez wpisu w `docs/DECISIONS.md`.
        $this->assertSame(3, ZapisyWpisu::PROG_DLA_OBCYCH);
    }

    public function test_wpis_bez_zapisow_nie_ma_zadnej_linijki(): void
    {
        $autor = $this->user('autorka_bez_zapisow');
        $wpis = $this->wpisAutora($autor);

        $html = (string) $this->actingAs($autor)->get($wpis->url())->assertOk()->getContent();

        $this->assertNull(
            $this->linijkaZapisow($html),
            'Zero pod własnym daniem jest dokładnie tym, czego COPY_STYLE.md zabrania.',
        );
    }
}
