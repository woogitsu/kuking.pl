<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sharing\Udostepnianie;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Podziel się" — wysłanie przepisu albo wpisu znajomym.
 *
 * TRZY RZECZY, KTÓRE TEN TEST PILNUJE, I ŻADNA Z NICH NIE JEST KOSMETYCZNA
 *
 * 1. WERSJA BEZ JAVASCRIPTU JEST PEŁNOWARTOŚCIOWA. Odpowiedź serwera musi
 *    zawierać działające adresy `wa.me`, `mailto:` i `facebook.com/sharer`
 *    oraz widoczny adres strony. `navigator.share` jest JavaScriptem
 *    z definicji, więc nie ma prawa być jedyną drogą (AGENTS.md §5).
 *    Test czyta surowy HTML, czyli dokładnie to, co dostaje przeglądarka
 *    z wyłączonym skryptem.
 *
 * 2. PRZYCISK NIE OBIECUJE CZEGOŚ, CZEGO NIE MA. Adres wpisu „tylko dla
 *    obserwujących" wysłany na WhatsAppie otwiera odbiorcy 403. Przycisk
 *    ma się więc pojawiać WYŁĄCZNIE przy treści widocznej dla kogoś bez
 *    konta — także dla samego autora, który tę treść widzi bez przeszkód.
 *    To jest test NIEOBECNOŚCI, a nieobecność nigdy nie wywala się sama:
 *    widok cicho pokazuje za dużo, a strona ma status 200.
 *
 * 3. NIC MARTWEGO NA EKRANIE. „Skopiuj adres" wymaga schowka, więc
 *    przychodzi z serwera z `hidden` i odsłania go dopiero skrypt. Bez tego
 *    osoba bez JavaScriptu klikałaby przycisk, który nic nie robi — a to
 *    jest gorsze niż jego brak (docs/UX_50_PLUS.md).
 */
class PodzielSieTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obserwujacy;

    private User $obcy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->obserwujacy = $this->user('obserwujaca');
        $this->obcy = $this->user('obca');

        app(FollowUser::class)->handle($this->obserwujacy, $this->autor);
    }

    private function przepis(string $widocznosc = 'public', array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create(array_merge([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Żurek na zakwasie',
            'slug' => 'zurek-na-zakwasie-'.Str::lower(Str::random(6)),
        ], $atrybuty));
    }

    private function wpis(string $widocznosc = 'public', array $atrybuty = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Dzisiejszy obiad',
        ], $atrybuty));
    }

    private function adres(Model $tresc): string
    {
        return $tresc instanceof Recipe
            ? route('recipes.show', $tresc->slug)
            : route('posts.show', $tresc);
    }

    // -----------------------------------------------------------------
    // 1. Wersja podstawowa — bez JavaScriptu
    // -----------------------------------------------------------------

    public function test_przepis_publiczny_ma_jawna_liste_drog_bez_javascriptu(): void
    {
        $przepis = $this->przepis();
        $adres = $this->adres($przepis);

        $html = $this->get($adres)->assertOk()->getContent();

        // Przycisk widać od razu, jeszcze przed rozwinięciem.
        $this->assertStringContainsString('Podziel się', $html);

        // Trzy drogi, każda jako zwykły odnośnik w HTML-u.
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('href="mailto:?subject=', $html);
        $this->assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=', $html);

        // Adres strony jest w treści wiadomości — inaczej odbiorca dostaje
        // zachętę bez linku.
        $this->assertStringContainsString(rawurlencode($adres), $html);

        // I stoi osobno, widoczny i zaznaczalny, w polu tekstowym.
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*data-podziel-pole[^>]*>'.preg_quote(e($adres), '/').'<\/textarea>/',
            $html,
            'Adres ma stać w polu, które da się zaznaczyć bez JavaScriptu.',
        );
        $this->assertStringContainsString('readonly', $html);
    }

    public function test_wpis_publiczny_ma_jawna_liste_drog_bez_javascriptu(): void
    {
        $wpis = $this->wpis();
        $adres = $this->adres($wpis);

        $html = $this->get($adres)->assertOk()->getContent();

        $this->assertStringContainsString('Podziel się', $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('href="mailto:?subject=', $html);
        $this->assertStringContainsString('>'.e($adres).'</textarea>', $html);
    }

    /**
     * Gość bez konta ma dostać ten sam komplet dróg co zalogowany.
     *
     * Osoba, która trafiła tu z Google i chce wysłać przepis siostrze, jest
     * najtańszym kanałem dotarcia, jaki ten serwis ma
     * (docs/research/AUDIENCE_50_PLUS.md) — nie ma powodu kazać jej najpierw
     * zakładać konto.
     */
    public function test_gosc_tez_moze_wyslac_publiczny_przepis(): void
    {
        $przepis = $this->przepis();

        Auth::logout();
        $this->assertGuest();

        $this->get($this->adres($przepis))
            ->assertOk()
            ->assertSee('Podziel się')
            ->assertSee('https://wa.me/?text=', false);
    }

    /**
     * Nigdzie nie ma napisu „twoja przeglądarka nie obsługuje" ani pustego
     * przycisku czekającego na skrypt. Jedyny element zależny od skryptu —
     * „Skopiuj adres" — przychodzi z `hidden`.
     */
    public function test_nic_martwego_nie_stoi_na_ekranie_bez_skryptu(): void
    {
        $html = $this->get($this->adres($this->przepis()))->assertOk()->getContent();

        $this->assertStringNotContainsString('nie obsługuje', $html);

        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-podziel-kopiuj[^>]*\shidden/',
            $html,
            'Przycisk „Skopiuj adres" musi przyjść z serwera ukryty — bez JavaScriptu nie ma czego kopiować.',
        );

        // Sam adres zostaje widoczny, więc bez skryptu też da się go
        // przepisać albo zaznaczyć myszą.
        $this->assertStringContainsString('data-podziel-pole', $html);
    }

    /** Arkusz systemowy dostaje z serwera komplet danych do wysłania. */
    public function test_dane_dla_arkusza_systemowego_sa_w_html(): void
    {
        $przepis = $this->przepis();

        $html = $this->get($this->adres($przepis))->assertOk()->getContent();

        $this->assertStringContainsString('data-podziel-sie', $html);
        $this->assertStringContainsString('data-podziel-tytul="Żurek na zakwasie"', $html);
        $this->assertStringContainsString('data-podziel-adres="'.e($this->adres($przepis)).'"', $html);
    }

    // -----------------------------------------------------------------
    // 2. Macierz: przy czym przycisk się pokazuje, a przy czym nie
    // -----------------------------------------------------------------

    /**
     * Kanoniczna tabela: przycisk istnieje wtedy i tylko wtedy, gdy treść
     * zobaczy ktoś BEZ KONTA.
     *
     *   widoczność | autor | obserwujący | obcy | niezalogowany
     *   -----------|-------|-------------|------|--------------
     *   public     |   ✓   |      ✓      |  ✓   |      ✓
     *   followers  |   ✗   |      ✗      |  —   |      —
     *   private    |   ✗   |      —      |  —   |      —
     *
     * („—" znaczy: strona i tak nie jest dla tej osoby dostępna.)
     */
    public function test_macierz_widocznosci_przycisku(): void
    {
        foreach (['public' => true, 'followers' => false, 'private' => false] as $widocznosc => $powinienByc) {
            foreach ([
                'przepis' => $this->przepis($widocznosc),
                'wpis' => $this->wpis($widocznosc),
            ] as $rodzaj => $tresc) {
                $adres = $this->adres($tresc);

                foreach (['autor' => $this->autor, 'obserwujący' => $this->obserwujacy] as $ktoNazwa => $kto) {
                    if ($widocznosc === 'private' && $ktoNazwa !== 'autor') {
                        continue;
                    }

                    $html = $this->actingAs($kto)->get($adres)->assertOk()->getContent();

                    $opis = sprintf(
                        '%s o widoczności „%s", widz „%s" — oczekiwano %s przycisku „Podziel się".',
                        $rodzaj,
                        $widocznosc,
                        $ktoNazwa,
                        $powinienByc ? 'OBECNOŚCI' : 'BRAKU',
                    );

                    if ($powinienByc) {
                        $this->assertStringContainsString('data-podziel-sie', $html, $opis);
                    } else {
                        $this->assertStringNotContainsString('data-podziel-sie', $html, $opis);
                        $this->assertStringNotContainsString('wa.me', $html, $opis);
                    }
                }
            }
        }
    }

    /**
     * Autor treści, której nie da się wysłać, dostaje ZDANIE, co zrobić —
     * nie samą pustkę po przycisku.
     */
    public function test_autor_dowiaduje_sie_dlaczego_nie_ma_przycisku(): void
    {
        $wpis = $this->wpis('followers');

        $this->actingAs($this->autor)
            ->get($this->adres($wpis))
            ->assertOk()
            ->assertSee('Zmień widoczność na „wszyscy”', false);
    }

    /** Obcy nie ma się z czego dowiadywać, że taki przycisk gdziekolwiek istnieje. */
    public function test_obserwujacy_nie_widzi_wyjasnienia_dla_autora(): void
    {
        $wpis = $this->wpis('followers');

        $this->actingAs($this->obserwujacy)
            ->get($this->adres($wpis))
            ->assertOk()
            ->assertDontSee('Zmień widoczność na', false);
    }

    /**
     * Szkic nie ma czego udostępniać — a autor ma usłyszeć, że najpierw
     * trzeba go opublikować.
     */
    public function test_szkic_przepisu_nie_dostaje_przycisku(): void
    {
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'title' => 'Niedokończony żurek',
            'slug' => 'niedokonczony-zurek-'.Str::lower(Str::random(6)),
        ]);

        $this->actingAs($this->autor)
            ->get(route('recipes.show', $szkic->slug))
            ->assertOk()
            ->assertDontSee('data-podziel-sie', false)
            ->assertSee('nie jest jeszcze opublikowany', false);
    }

    /**
     * Ban autora zdejmuje przycisk razem z treścią (audyt A5 —
     * ta sama granica co w `PostPolicy::view`).
     *
     * Sprawdzamy przez `Udostepnianie`, nie przez HTTP: zbanowane konto jest
     * wylogowywane przy każdym żądaniu (`EnsureAccountIsActive`), więc do
     * tej strony nikt by nie doszedł. Reguła musi mimo to stać w domenie,
     * bo ten sam blok renderuje się także dla moderatora.
     */
    public function test_ban_autora_zdejmuje_mozliwosc_wyslania(): void
    {
        $przepis = $this->przepis();
        $udostepnianie = app(Udostepnianie::class);

        $this->assertTrue($udostepnianie->wolnoWyslac($przepis));

        $this->autor->ban();
        $przepis->refresh()->load('author');

        $this->assertFalse(
            $udostepnianie->wolnoWyslac($przepis),
            'Przepis zbanowanego autora oddaje gościowi 403, więc nie wolno go proponować do wysłania.',
        );
    }

    /**
     * Blokada NIE zdejmuje przycisku, i tak ma być.
     *
     * Blokada jest relacją między dwiema osobami, a nie zmianą widoczności
     * treści: przepis dalej jest publiczny i dalej otwiera się każdemu,
     * kto dostanie adres. Gdyby przycisk znikał, znaczyłoby to, że o tym,
     * czy da się wysłać przepis, decyduje to, kogo widz zablokował — a widz
     * nie wysyła własnej treści zablokowanej osobie, tylko trzeciej.
     * Zablokowany i tak nie zobaczy STRONY, więc do przycisku nie dojdzie.
     */
    public function test_blokada_nie_zmienia_tego_co_wolno_wyslac(): void
    {
        $przepis = $this->przepis();
        $zablokowany = $this->user('zablokowana');
        app(BlockUser::class)->handle($this->autor, $zablokowany);

        $this->assertTrue(app(Udostepnianie::class)->wolnoWyslac($przepis));

        $this->actingAs($zablokowany)
            ->get($this->adres($przepis))
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // 3. Same adresy — kodowanie i treść wiadomości
    // -----------------------------------------------------------------

    /**
     * Polskie znaki i spacje w tytule muszą być zakodowane, inaczej WhatsApp
     * dostaje ucięty adres i wiadomość kończy się w połowie słowa.
     */
    public function test_tytul_z_polskimi_znakami_jest_zakodowany_w_adresie(): void
    {
        $przepis = $this->przepis('public', ['title' => 'Żurek na zakwasie & chrzan']);
        $drogi = app(Udostepnianie::class)->drogi($przepis);

        $whatsapp = $drogi[0]['adres'];

        $this->assertStringStartsWith('https://wa.me/?text=', $whatsapp);
        $this->assertStringNotContainsString(' ', $whatsapp);
        $this->assertStringNotContainsString('&chrzan', $whatsapp, 'Ampersand w tytule rozbiłby parametry adresu.');
        $this->assertStringContainsString(rawurlencode('Żurek na zakwasie & chrzan'), $whatsapp);
        $this->assertStringContainsString(rawurlencode($this->adres($przepis)), $whatsapp);
    }

    /** Wpis bez podpisu nie może dać wiadomości kończącej się dwukropkiem. */
    public function test_wpis_bez_podpisu_ma_sensowna_wiadomosc(): void
    {
        $wpis = $this->wpis('public', ['body' => null]);
        $udostepnianie = app(Udostepnianie::class);

        $opis = $udostepnianie->opis($wpis);

        $this->assertSame($udostepnianie->tytul($wpis), $opis);
        $this->assertStringEndsNotWith(':', $opis);
    }

    /**
     * Wersja podstawowa ma dokładnie trzy drogi i żadna z nich nie wymaga
     * zarejestrowanej aplikacji na Facebooku ani nie umiera na komputerze.
     * Uzasadnienie każdej nieobecności: docs/DECISIONS.md, D-044.
     */
    public function test_lista_drog_nie_obiecuje_messengera_ani_smsow(): void
    {
        $drogi = app(Udostepnianie::class)->drogi($this->przepis());

        $this->assertSame(['WhatsApp', 'E-mail', 'Facebook'], array_column($drogi, 'nazwa'));

        foreach ($drogi as $droga) {
            $this->assertStringNotContainsString('dialog/send', $droga['adres']);
            $this->assertStringNotContainsString('fb-messenger://', $droga['adres']);
            $this->assertStringNotContainsString('sms:', $droga['adres']);
        }
    }

    // -----------------------------------------------------------------
    // 4. Karta w Messengerze
    // -----------------------------------------------------------------

    /**
     * Strona, którą wolno wysłać, MUSI mieć komplet znaczników Open Graph.
     *
     * Bez nich wysłany link wygląda w Messengerze i na WhatsAppie jak goły
     * adres — i cała ta funkcja sprowadza się do wysyłania czegoś, w co nikt
     * nie kliknie. Znaczniki stoją w `x-layout` od issue #14
     * (`KartaDoUdostepnianiaTest`); tu pilnujemy, żeby te dwie rzeczy nie
     * rozjechały się w przyszłości: jest przycisk — jest karta.
     */
    public function test_kazda_strona_z_przyciskiem_ma_karte_open_graph(): void
    {
        foreach ([$this->przepis(), $this->wpis()] as $tresc) {
            $html = $this->get($this->adres($tresc))->assertOk()->getContent();

            $this->assertStringContainsString('data-podziel-sie', $html);
            $this->assertStringContainsString('property="og:title"', $html);
            $this->assertStringContainsString('property="og:image"', $html);
            $this->assertStringContainsString('property="og:url"', $html);
            $this->assertStringNotContainsString('<meta name="robots" content="noindex', $html);
        }
    }
}
