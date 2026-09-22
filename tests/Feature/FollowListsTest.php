<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Listy obserwujących / obserwowanych na profilu (issue #16, część 1).
 */
class FollowListsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * STAN PRZYCISKU NA OBU LISTACH TO STAN WIDZA, NIE CZYJKOLWIEK INNY (#648).
     *
     * Usterka: `withExists` na relacji User→User (`followers`) dostawał warunek
     * `users.id = <widz>`. W podzapytaniu `users` jest zaaliasowane, więc
     * `users.id` wskazywało WIERSZ ZEWNĘTRZNY — osobę z listy, nie
     * obserwującego. Warunek zmieniał się cicho w „czy ta osoba to widz, i czy
     * ktokolwiek ją obserwuje" — bywał więc prawdziwy najwyżej na jednym
     * wierszu, własnym wierszu widza, który widok i tak rysuje jako „To Ty".
     * Obie listy pokazywały przez to „Obserwuj" przy każdej osobie, także po
     * udanym POST-cie i po świeżym GET-cie.
     *
     * Dlatego ten test rozdziela trzy stany, które usterka skleiła w jeden:
     *
     *  - WIDZ — jego relacja jest jedyną, o którą pyta przycisk;
     *  - WŁAŚCICIEL listy — obserwuje obie osoby z listy, więc gdyby kolumna
     *    liczyła jego stan, obie osoby miałyby „Przestań obserwować";
     *  - OSOBA OBSERWOWANA — obserwuje widza, choć widz jej nie; gdyby
     *    podzapytanie pomyliło kierunek, „Przestań obserwować" pojawiłoby się
     *    zanim widz cokolwiek kliknął.
     *
     * Lista jest mieszana (jedna osoba obserwowana, druga nie), bo warunek
     * stały — „wszystkim tak" albo „wszystkim nie" — przechodzi każdą asercję
     * zrobioną na jednej osobie.
     */
    public function test_obie_listy_pokazuja_stan_obserwowania_widza_po_post_i_po_delete(): void
    {
        $widz = $this->user('widz648', ['display_name' => 'Widzaca Osoba']);
        $gospodarz = $this->user('gospodarz648', ['display_name' => 'Gospodarz Listy']);
        $obserwowana = $this->user('obserwowana648', ['display_name' => 'Obserwowana Osoba']);
        $obca = $this->user('obca648', ['display_name' => 'Obca Osoba']);

        // Wszyscy troje stoją na OBU listach gospodarza: obserwują go
        // i są przez niego obserwowani.
        foreach ([$widz, $obserwowana, $obca] as $osoba) {
            app(FollowUser::class)->handle($osoba, $gospodarz);
            app(FollowUser::class)->handle($gospodarz, $osoba);
        }

        // Kierunek: to ONA obserwuje widza, nie odwrotnie.
        app(FollowUser::class)->handle($obserwowana, $widz);

        $this->actingAs($widz);

        $relacja = ['follower_id' => $widz->getKey(), 'followed_id' => $obserwowana->getKey()];

        $this->assertDatabaseMissing('follows', $relacja);
        $this->assertFalse($widz->isFollowing($obserwowana));
        $this->sprawdzObieListy('Obserwuj', 'Obserwuj');

        $adres = route('social.followers', 'gospodarz648');
        $this->from($adres)->post(route('social.follow', 'obserwowana648'))
            ->assertRedirect($adres)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('follows', $relacja);
        $this->assertTrue($widz->fresh()->isFollowing($obserwowana));
        $this->sprawdzObieListy('Przestań obserwować', 'Obserwuj');

        $this->from($adres)->delete(route('social.unfollow', 'obserwowana648'))
            ->assertRedirect($adres)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('follows', $relacja);
        $this->assertFalse($widz->fresh()->isFollowing($obserwowana));
        $this->sprawdzObieListy('Obserwuj', 'Obserwuj');
    }

    /**
     * Gość nie dostaje ŻADNEGO formularza relacji — ani „Obserwuj", ani
     * „Przestań obserwować".
     *
     * Kontrola dodatnia jest w `sprawdzKarte()`: karta osoby musi na liście
     * być. Bez niej „nie ma formularza" przechodziłoby także dla pustej strony
     * (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
     */
    public function test_gosc_nie_widzi_na_listach_zadnego_formularza_obserwowania(): void
    {
        $gospodarz = $this->user('gospodarz648', ['display_name' => 'Gospodarz Listy']);
        $osoba = $this->user('obserwowana648', ['display_name' => 'Obserwowana Osoba']);

        app(FollowUser::class)->handle($osoba, $gospodarz);
        app(FollowUser::class)->handle($gospodarz, $osoba);

        foreach (['social.followers', 'social.following'] as $trasa) {
            $html = $this->get(route($trasa, 'gospodarz648'))->assertOk()->getContent();

            $this->sprawdzKarte($html, 'obserwowana648', null, $trasa);
        }
    }

    /**
     * Kolumna ze stanem obserwowania NIE wskrzesza wiersza, który odsiewa
     * filtr konta zamkniętego.
     *
     * Osobne testy pilnują samego filtru
     * (`ProfilListyRelacjiUkrywajaZbanowaneKontaTest`) — tutaj chodzi o
     * połączenie: widz OBSERWUJE osobę, która została zbanowana. Kontrolą
     * dodatnią jest druga obserwowana osoba, aktywna: musi wyjść na listę
     * z przyciskiem „Przestań obserwować", inaczej „zbanowanego nie widać"
     * znaczyłoby tylko tyle, że nie widać nikogo.
     *
     * Trzecia osoba — aktywna i NIEobserwowana — jest tu po to, żeby ten test
     * nie trafiał w tę samą gałąź co pozostałe (pułapka 3b). Bez niej lista
     * miała jeden stan, więc sabotaż „wszystkim Przestań obserwować"
     * przechodził przez ten test bez czerwieni.
     */
    public function test_zbanowane_konto_nie_wraca_na_liste_przez_stan_obserwowania(): void
    {
        $widz = $this->user('widz648');
        $gospodarz = $this->user('gospodarz648');
        $aktywna = $this->user('aktywna648', ['display_name' => 'Aktywna Obserwowana']);
        $zbanowana = $this->user('zbanowana648', ['display_name' => 'Zbanowana Obserwowana']);
        $nieobserwowana = $this->user('nieobserwowana648', ['display_name' => 'Aktywna Nieobserwowana']);

        foreach ([$aktywna, $zbanowana, $nieobserwowana] as $osoba) {
            app(FollowUser::class)->handle($osoba, $gospodarz);
        }

        foreach ([$aktywna, $zbanowana] as $osoba) {
            app(FollowUser::class)->handle($widz, $osoba);
        }

        // Ban PO nawiązaniu relacji — nie kasuje wierszy z `follows`.
        $zbanowana->ban();

        $odpowiedz = $this->actingAs($widz)->get(route('social.followers', 'gospodarz648'))->assertOk();

        $this->sprawdzKarte($odpowiedz->getContent(), 'aktywna648', 'Przestań obserwować', 'social.followers');
        $this->sprawdzKarte($odpowiedz->getContent(), 'nieobserwowana648', 'Obserwuj', 'social.followers');

        // Asercja „czegoś nie ma" zostaje na CAŁYM dokumencie (pułapka 1).
        $odpowiedz->assertDontSee('Zbanowana Obserwowana');
    }

    /**
     * Stan obserwowania jest czytany ZBIORCZO, nie osobnym zapytaniem na wiersz.
     *
     * CZEGO TEN TEST NIE DUBLUJE.
     * `StronyTresciBezWachlarzaZapytanTest::test_lista_obserwujacych_nie_ma_wachlarza_zapytan_dla_zalogowanego_widza`
     * mierzy pełną stronę `/@kto/obserwujacy` (2 → 20 osób, każda ze zdjęciem
     * profilowym) i to on pilnuje kosztu tamtej listy. Tutaj mierzona jest
     * DRUGA lista (`/@kto/obserwowani`, w tamtym pliku nieobecna) i przy
     * MIESZANYM stanie relacji widza — bo kolumna `obserwowany` musi dawać
     * różne odpowiedzi w obrębie jednej strony, a nie jedną dla wszystkich.
     *
     * Kontrola dodatnia (pułapka 4): przy każdym pomiarze sprawdzamy liczbę
     * osób na liście i OBA warianty przycisku. Płaska liczba zapytań na pustej
     * albo jednostanowej liście nie dowodzi niczego.
     */
    public function test_stan_obserwowania_na_liscie_obserwowanych_nie_doklada_zapytania_na_osobe(): void
    {
        $widz = $this->user('widz648');
        $gospodarz = $this->user('gospodarz648');

        $osoby = [];
        for ($i = 0; $i < 8; $i++) {
            $osoby[$i] = $this->user('osoba648nr'.$i, ['display_name' => 'Osoba numer '.$i]);

            // Na liście stoi najpierw połowa; resztę dokładamy po pierwszym
            // pomiarze, żeby porównać ten sam ekran „mało" i „dużo".
            if ($i < 4) {
                app(FollowUser::class)->handle($gospodarz, $osoby[$i]);
            }

            // Co druga obserwowana przez widza — lista ma być mieszana.
            if ($i % 2 === 0) {
                app(FollowUser::class)->handle($widz, $osoby[$i]);
            }
        }

        $this->actingAs($widz);

        $adres = route('social.following', 'gospodarz648');

        // Rozgrzanie: pierwsze żądanie w teście dokłada zapytania, których
        // kolejne już nie mają (ustalenie trasy, konfiguracja, sesja).
        $this->get($adres)->assertOk();

        $malo = $this->policzZapytaniaListy($adres, 4);

        for ($i = 4; $i < 8; $i++) {
            app(FollowUser::class)->handle($gospodarz, $osoby[$i]);
        }

        $duzo = $this->policzZapytaniaListy($adres, 8);

        $this->assertSame(
            $malo,
            $duzo,
            "Lista obserwowanych pyta o stan relacji osobno dla każdej osoby: {$malo} zapytań przy 4 osobach, {$duzo} przy 8.",
        );
    }

    /**
     * WŁAŚCICIEL LISTY OGLĄDA WŁASNĄ LISTĘ — najczęstszy z tych dwóch ekranów.
     *
     * Widzem jest tu osoba, której profil lista opisuje. W usterce #648
     * warunek pytał „czy ta osoba to widz", więc akurat na tym ekranie
     * odpowiedź brzmiała „nie" przy każdym wierszu (właściciel nie stoi na
     * własnej liście) i nadal wszędzie stało „Obserwuj".
     *
     * Lista jest mieszana, bo jednorodna przeszłaby także wtedy, gdyby
     * kolumna miała stałą wartość.
     *
     * CZEGO TEN TEST NIE DOWODZI: że kolumna liczy stan WIDZA, a nie stan
     * właściciela listy. Na tym ekranie to ta sama osoba, więc rozróżnić się
     * ich tutaj nie da — i nie ma potrzeby, bo rozdziela je
     * `test_obie_listy_pokazuja_stan_obserwowania_widza_po_post_i_po_delete`,
     * gdzie widz, właściciel i osoba obserwowana to trzy różne konta.
     */
    public function test_wlasciciel_listy_widzi_na_niej_swoj_wlasny_stan_obserwowania(): void
    {
        $gospodarz = $this->user('gospodarz648');
        $obserwowana = $this->user('obserwowana648', ['display_name' => 'Obserwowana Osoba']);
        $obca = $this->user('obca648', ['display_name' => 'Obca Osoba']);

        foreach ([$obserwowana, $obca] as $osoba) {
            app(FollowUser::class)->handle($osoba, $gospodarz);
        }

        app(FollowUser::class)->handle($gospodarz, $obserwowana);

        $html = $this->actingAs($gospodarz)->get(route('social.followers', 'gospodarz648'))->assertOk()->getContent();

        $this->sprawdzKarte($html, 'obserwowana648', 'Przestań obserwować', 'social.followers/własna lista');
        $this->sprawdzKarte($html, 'obca648', 'Obserwuj', 'social.followers/własna lista');
    }

    // -----------------------------------------------------------------
    // Pomocnicy regresji #648
    // -----------------------------------------------------------------

    /**
     * Obie listy gospodarza naraz — świeży GET za każdym razem, bo cała
     * usterka #648 polegała na tym, że stan po przeładowaniu był zły.
     */
    private function sprawdzObieListy(string $stanObserwowanej, string $stanObcej): void
    {
        foreach (['social.followers', 'social.following'] as $trasa) {
            $odpowiedz = $this->get(route($trasa, 'gospodarz648'))->assertOk();
            $html = $odpowiedz->getContent();

            // Licznik listy nie ma prawa drgnąć od tego, kogo obserwuje WIDZ:
            // kolumna ze stanem relacji dokłada wartość do wiersza, a nie
            // warunek do zapytania.
            $this->assertSame(3, $odpowiedz->viewData('people')->total(), $trasa.': zmieniła się liczba osób na liście.');

            $this->sprawdzKarte($html, 'obserwowana648', $stanObserwowanej, $trasa);
            $this->sprawdzKarte($html, 'obca648', $stanObcej, $trasa);
            $this->sprawdzKarte($html, 'widz648', 'To Ty', $trasa);
        }
    }

    /**
     * Stan JEDNEJ karty osoby na liście, sprawdzony w wycinku tej karty.
     *
     * `assertSee('Obserwuj')` na całej stronie nie nadaje się tu do niczego
     * (pułapka 1 i 1b z `docs/PULAPKI_TESTOW.md`): to słowo pada w tytule
     * strony, w nagłówku listy, w belce i przy każdej innej osobie. Pytanie
     * brzmi „co jest przy TEJ osobie", więc najpierw wycinamy jej kartę po
     * adresie profilu, a dopiero w niej pytamy o formularz.
     *
     * @param  string|null  $oczekiwany  napis przycisku, „To Ty" albo `null`
     *                                   dla karty bez żadnego formularza
     */
    private function sprawdzKarte(string $html, string $username, ?string $oczekiwany, string $gdzie): void
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dokument);

        $adresProfilu = route('profile.show', ['username' => $username]);
        // KARTA, nie „jakikolwiek div zawierający link do tego profilu".
        // Rodzajowy selektor trafiał na liście w to, co trzeba, ale na innym
        // ekranie potrafił wyciąć nagłówek z odnośnikiem „Wróć do profilu"
        // i asercja zaczęłaby pytać nie o ten element.
        $karty = $xpath->query('//main//div[contains(concat(" ", normalize-space(@class), " "), " card ")][.//a[@href="'.$adresProfilu.'"]]');

        $this->assertNotNull($karty);
        $this->assertSame(1, $karty->length, "{$gdzie}: karta osoby @{$username} nie jest na liście dokładnie raz.");

        $karta = $karty->item(0);
        $this->assertInstanceOf(DOMElement::class, $karta);

        $formularze = $xpath->query('.//form', $karta);
        $this->assertNotNull($formularze);

        if ($oczekiwany === null || $oczekiwany === 'To Ty') {
            $this->assertSame(0, $formularze->length, "{$gdzie}: karta @{$username} nie ma prawa mieć formularza relacji.");

            if ($oczekiwany === 'To Ty') {
                $this->assertSame('To Ty', trim((string) $xpath->evaluate('string(.//span[@class="badge"])', $karta)));
            }

            return;
        }

        $this->assertSame(1, $formularze->length, "{$gdzie}: karta @{$username} ma inną liczbę formularzy niż jeden.");

        $formularz = $formularze->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz);

        // Formularz bez tokenu odpadłby na `VerifyCsrfToken` — to nie jest
        // dowód egzekwowania CSRF (tego testy HTTP tu nie mierzą), tylko
        // sprawdzenie, że przycisk w ogóle ma szansę zadziałać po kliknięciu.
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $formularz)->length, "{$gdzie}: formularz @{$username} nie ma pola `_token`.");

        $oczekiwanaTrasa = $oczekiwany === 'Obserwuj' ? 'social.follow' : 'social.unfollow';
        $this->assertSame(route($oczekiwanaTrasa, $username), $formularz->getAttribute('action'), "{$gdzie}: formularz @{$username} prowadzi pod zły adres.");
        $this->assertSame(
            $oczekiwany,
            trim((string) $xpath->evaluate('string(.//button)', $formularz)),
            "{$gdzie}: przy @{$username} stoi inny przycisk, niż wynika ze stanu relacji widza.",
        );

        // „Przestań obserwować" idzie metodą DELETE, podrobioną polem `_method`.
        $this->assertSame(
            $oczekiwany === 'Obserwuj' ? '' : 'DELETE',
            (string) $xpath->evaluate('string(.//input[@name="_method"]/@value)', $formularz),
        );
    }

    /** Liczba zapytań jednego GET-a listy, z kontrolą dodatnią na treści. */
    private function policzZapytaniaListy(string $adres, int $ile): int
    {
        $zapytania = 0;
        DB::listen(function () use (&$zapytania): void {
            $zapytania++;
        });

        $odpowiedz = $this->get($adres)->assertOk();

        $osoby = $odpowiedz->viewData('people');
        $this->assertCount($ile, $osoby, 'Pomiar nie dotyczy listy o oczekiwanej długości.');

        $html = $odpowiedz->getContent();
        $this->sprawdzKarte($html, 'osoba648nr0', 'Przestań obserwować', 'social.following');
        $this->sprawdzKarte($html, 'osoba648nr1', 'Obserwuj', 'social.following');

        return $zapytania;
    }

    public function test_lista_obserwujacych_pokazuje_kto_obserwuje(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);

        app(FollowUser::class)->handle($marek, $basia);

        $response = $this->actingAs($marek)->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
    }

    public function test_lista_obserwowanych_pokazuje_kogo_ktos_obserwuje(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);

        app(FollowUser::class)->handle($basia, $marek);

        $response = $this->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
    }

    public function test_osoba_ktora_nikogo_nie_obserwuje_pokazuje_pusty_stan(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee('Jeszcze nikogo nie obserwuje');
        $response->assertDontSee('Wystąpił błąd');
    }

    public function test_osoba_bez_obserwujacych_pokazuje_pusty_stan(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee('Jeszcze nikt nie obserwuje');
    }

    public function test_zablokowana_osoba_nie_pojawia_sie_na_liscie_obserwujacych(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);
        $spamer = $this->user('spamer', ['display_name' => 'Spamer Uciazliwy']);

        app(FollowUser::class)->handle($marek, $basia);
        app(FollowUser::class)->handle($spamer, $basia);

        // Marek (który ogląda listę) zablokował spamera — spamer nie może
        // się pojawić na liście, mimo że naprawdę obserwuje Basię.
        app(BlockUser::class)->handle($marek, $spamer);

        $response = $this->actingAs($marek)->get(route('social.followers', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
        $response->assertDontSee($spamer->displayName());
    }

    public function test_zablokowana_osoba_nie_pojawia_sie_na_liscie_obserwowanych(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek', ['display_name' => 'Marek Testowy']);
        $ela = $this->user('ela', ['display_name' => 'Ela Zablokowana']);

        app(FollowUser::class)->handle($basia, $marek);
        app(FollowUser::class)->handle($basia, $ela);

        // Osoba oglądająca listę zablokowała Elę — Ela znika z listy,
        // mimo że blokada nie dotyczy Basi ani Marka.
        $ogladajacy = $this->user('ogladajacy');
        app(BlockUser::class)->handle($ogladajacy, $ela);

        $response = $this->actingAs($ogladajacy)->get(route('social.following', 'basia'));

        $response->assertOk();
        $response->assertSee($marek->displayName());
        $response->assertDontSee($ela->displayName());
    }

    public function test_listy_maja_naglowek_noindex(): void
    {
        $this->user('basia');

        $this->get(route('social.followers', 'basia'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get(route('social.following', 'basia'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_zablokowany_widz_nie_moze_wejsc_na_liste_zablokowanego_profilu(): void
    {
        $basia = $this->user('basia');
        $spamer = $this->user('spamer');

        app(BlockUser::class)->handle($basia, $spamer);

        $this->actingAs($spamer)->get(route('social.followers', 'basia'))->assertForbidden();
    }

    public function test_liczby_na_profilu_prowadza_do_list(): void
    {
        $basia = $this->user('basia');

        $response = $this->get(route('profile.show', 'basia'));

        $response->assertOk();
        $response->assertSee(route('social.followers', 'basia'), false);
        $response->assertSee(route('social.following', 'basia'), false);
    }
}
