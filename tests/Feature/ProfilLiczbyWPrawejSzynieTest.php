<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfilLiczbyWPrawejSzynieTest extends TestCase
{
    use RefreshDatabase;

    private const KLASA_KARTY = 'profil-liczby-karta';

    private const KLASA_SZYNY = 'profil-liczby-szyna';

    public function test_liczby_wystepuja_raz_w_dokumencie(): void
    {
        $this->osobaZTrescia('jedna_lista');
        $html = (string) $this->actingAs($this->user('widz_listy'))->get(route('profile.show', 'jedna_lista'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="profil-liczniki '));
        $this->assertStringNotContainsString(self::KLASA_SZYNY, $html);
        $this->assertStringContainsString('stat-value', $this->wycinekKarty($html));
    }

    public function test_gosc_widzi_liczby_w_karcie_i_nie_dostaje_drugiego_egzemplarza(): void
    {
        $this->osobaZTrescia('gosc_patrzy');

        $html = (string) $this->get(route('profile.show', 'gosc_patrzy'))->assertOk()->getContent();

        // Asercja kontrolna.
        $this->assertStringContainsString('gosc_patrzy', $html);

        $karta = $this->wycinekKarty($html);
        $this->assertStringContainsString('<span class="stat-label">wpisy</span>', $karta);

        $this->assertStringNotContainsString(
            self::KLASA_SZYNY,
            $html,
            'Gość nie dostaje bloku liczb w szynie: te same liczby zostają mu '
            .'w karcie profilu, więc blok byłby ich drugim egzemplarzem.',
        );

        $this->assertSame(
            1,
            substr_count($html, 'class="profil-liczniki '),
            'Gość ma zobaczyć dokładnie jedną listę liczb — tę w karcie.',
        );
    }

    public function test_wartosci_sa_prawdziwe_takze_gdy_wynosza_zero(): void
    {
        // 3 wpisy, 0 przepisów, 2 razy Ugotowałem, 1 obserwujący, 0 obserwowanych.
        // Zero stoi na dwóch z pięciu pozycji celowo: zera pokazujemy.
        $gospodarz = $this->user('liczby_prawdziwe', ['display_name' => 'Liczby Prawdziwe']);

        for ($i = 0; $i < 3; $i++) {
            Post::factory()->create([
                'author_id' => $gospodarz->getKey(),
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => 'published',
                'published_at' => now()->subMinutes($i),
            ]);
        }

        $cudzyPrzepis = Recipe::factory()->create([
            'author_id' => $this->user('autor_przepisu')->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Rosół sąsiada',
            'slug' => 'rosol-sasiada-'.Str::lower(Str::random(6)),
        ]);

        CookedEvent::factory()->count(2)->create([
            'recipe_id' => $cudzyPrzepis->getKey(),
            'user_id' => $gospodarz->getKey(),
        ]);

        $ogladajacy = $this->user('obserwator_liczb');
        app(FollowUser::class)->handle($ogladajacy, $gospodarz);

        $html = (string) $this->actingAs($ogladajacy)
            ->get(route('profile.show', 'liczby_prawdziwe'))
            ->assertOk()
            ->getContent();

        // Asercja kontrolna.
        $this->assertStringContainsString('Liczby Prawdziwe', $html);

        $oczekiwane = [
            '<span class="stat-value">3</span> <span class="stat-label">wpisy</span>',
            '<span class="stat-value">0</span> <span class="stat-label">przepisów</span>',
            '<span class="stat-value">2</span> <span class="stat-label">razy „Ugotowałem”</span>',
            '<span class="stat-value">1</span> <span class="stat-label">obserwujący</span>',
            '<span class="stat-value">0</span> <span class="stat-label">obserwowanych</span>',
        ];

        $karta = $this->wycinekKarty($html);

        foreach ($oczekiwane as $wiersz) {
            $this->assertStringContainsString($wiersz, $karta, "Karta profilu ma pokazać: {$wiersz}");

        }
    }

    public function test_odnosniki_obserwujacych_i_obserwowanych_prowadza_tam_gdzie_prowadzily(): void
    {
        $this->osobaZTrescia('odnosniki_szyny');

        $html = (string) $this->actingAs($this->user('klikajacy'))
            ->get(route('profile.show', 'odnosniki_szyny'))
            ->assertOk()
            ->getContent();

        $karta = $this->wycinekKarty($html);

        // Asercja kontrolna: wycinki nie są puste.

        $this->assertStringContainsString('stat-value', $karta);

        foreach (['social.followers', 'social.following'] as $trasa) {
            $adres = route($trasa, 'odnosniki_szyny');

            foreach (['karta' => $karta] as $gdzie => $wycinek) {
                // Odnośnik, a nie sam tekst — i z klasą, która daje mu 48 px
                // pola klikalnego (`.profil-licznik-pole`, UX_50_PLUS.md).
                $this->assertMatchesRegularExpression(
                    '~<a class="profil-licznik-pole link-jak-tekst" href="'.preg_quote($adres, '~').'">~',
                    $wycinek,
                    "W wycinku „{$gdzie}” liczba ma być odnośnikiem do {$adres} "
                    .'z pełnym polem klikalnym.',
                );
            }
        }
    }

    public function test_wlasny_profil_i_cudzy_zachowuja_wlasny_rzad_przyciskow(): void
    {
        $gospodarz = $this->osobaZTrescia('dwa_warianty');

        $swoj = (string) $this->actingAs($gospodarz)
            ->get(route('profile.show', 'dwa_warianty'))
            ->assertOk()
            ->getContent();

        // Rząd przycisków własnego profilu zostaje nietknięty.
        $this->assertStringContainsString('Zmień swój profil', $swoj);

        $cudzy = (string) $this->actingAs($this->user('obcy_wariant'))
            ->get(route('profile.show', 'dwa_warianty'))
            ->assertOk()
            ->getContent();

        // Rząd przycisków cudzego profilu też zostaje nietknięty.
        $this->assertStringContainsString('Obserwuj', $cudzy);
        // „Zgłoś" W GŁÓWCE PROFILU, a nie gdziekolwiek w dokumencie
        // (pułapka 1): to samo słowo stoi w stopce KAŻDEJ strony („Zgłoś
        // nielegalną treść") i w menu każdej karty wpisu („Zgłoś ten wpis").
        // Zmierzone 12.09.2026 — po skasowaniu przycisku z rzędu akcji
        // asercja na całym HTML-u nadal przechodziła. Pytamy więc o odnośnik
        // o DOKŁADNIE takim napisie i tylko w główce.
        $this->assertSame(
            1,
            $this->odnosnikiWGlowce($cudzy, 'Zgłoś'),
            'W główce cudzego profilu nie ma przycisku „Zgłoś".',
        );
        $this->assertStringContainsString('Zablokuj', $cudzy);
        $this->assertStringNotContainsString('Zmień swój profil', $cudzy);
    }

    public function test_liczby_nie_dokladaja_ani_jednego_zapytania(): void
    {
        $gospodarz = $this->osobaZTrescia('bez_wachlarza');
        $ogladajacy = $this->user('licznik_zapytan');

        $agregaty = $this->agregatyNaStronie(
            fn () => $this->actingAs($ogladajacy)
                ->get(route('profile.show', 'bez_wachlarza'))
                ->assertOk(),
        );

        fwrite(STDERR, sprintf(
            "\n[liczby profilu] zapytan z count(*): %d\n",
            $agregaty,
        ));

        $this->assertSame(
            7,
            $agregaty,
            'Liczby o osobie mają być policzone i pokazane RAZ. '
            .'Wzrost tej liczby znaczy, że drugi egzemplarz liczy sobie sam.',
        );

        // Kontrola dodatnia dla samego pomiaru: gdyby licznik nie widział
        // żadnego zapytania, asercja wyżej też by nie zadziałała.
        $this->assertGreaterThan(0, $agregaty);
    }

    /**
     * Profil z odrobiną treści — na tyle, żeby żaden licznik nie był zerem
     * z braku danych, a nie z braku poprawnego zapytania.
     */
    private function osobaZTrescia(string $nazwa): User
    {
        $osoba = $this->user($nazwa, ['display_name' => 'Osoba '.$nazwa]);

        Post::factory()->count(2)->create([
            'author_id' => $osoba->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Recipe::factory()->create([
            'author_id' => $osoba->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Przepis '.$nazwa,
            'slug' => Str::slug('przepis-'.$nazwa).'-'.Str::lower(Str::random(6)),
        ]);

        return $osoba;
    }

    /** Lista liczb z KARTY profilu (egzemplarz wąskiego ekranu). */
    private function wycinekKarty(string $html): string
    {
        return $this->wycinekPoKlasie($html, 'ul', self::KLASA_KARTY);
    }

    /**
     * Wycięcie JEDNEGO elementu po klasie — bo karta, szyna, stopka
     * i nawigacja zawierają te same słowa i te same liczby.
     */
    private function wycinekPoKlasie(string $html, string $znacznik, string $klasa): string
    {
        $wezel = $this->wezelPoKlasie($html, $znacznik, $klasa);

        $this->assertInstanceOf(
            DOMElement::class,
            $wezel,
            "Nie znalazłem elementu <{$znacznik}> z klasą „{$klasa}” — bez niego ".
            'każda asercja niżej byłaby asercją na pustym napisie.',
        );

        return (string) $wezel->ownerDocument?->saveHTML($wezel);
    }

    /**
     * Ile odnośników o DOKŁADNIE takim widocznym napisie stoi w główce profilu.
     *
     * Główka to `<header>` wewnątrz `<main>` — poza nią zostaje i stopka,
     * i belka, i karty wpisów, a wszystkie trzy niosą słowo „Zgłoś"
     * w dłuższych napisach (pułapka 1 z `docs/PULAPKI_TESTOW.md`).
     */
    private function odnosnikiWGlowce(string $html, string $napis): int
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);
        $glowka = $xpath->query('//main//header')->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $glowka,
            'Strona profilu nie ma główki — bez niej asercja niżej nic nie mierzy.',
        );

        $wynik = $xpath->query(sprintf('.//a[normalize-space(.)="%s"]', $napis), $glowka);

        return $wynik === false ? 0 : $wynik->length;
    }

    private function wezelPoKlasie(string $html, string $znacznik, string $klasa): ?DOMElement
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);
        $wynik = $xpath->query(
            sprintf(
                '//%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
                $znacznik,
                $klasa,
            ),
        );

        $wezel = $wynik === false ? null : $wynik->item(0);

        return $wezel instanceof DOMElement ? $wezel : null;
    }

    /** Liczba zapytań agregujących (`count(*)`) wykonanych przy jednym wejściu. */
    private function agregatyNaStronie(callable $akcja): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $akcja();

        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // `select count(*) as "aggregate"` NA POCZĄTKU zapytania, a nie
        // „count(*) gdziekolwiek": to drugie łapie też zwykły SELECT wpisów,
        // który ma policzone komentarze w podzapytaniu — czyli zapytanie,
        // które z licznikami profilu nie ma nic wspólnego.
        return count(array_filter(
            $zapytania,
            fn (array $zapytanie): bool => str_starts_with(
                mb_strtolower(ltrim((string) $zapytanie['query'])),
                'select count(*) as "aggregate"',
            ),
        ));
    }
}
