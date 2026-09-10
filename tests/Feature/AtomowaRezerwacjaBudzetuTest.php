<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dobowy budżet listów jest TWARDYM SUFITEM, nie sugestią (audyt
 * MAIL-01/RACE-03, `docs/DECISIONS.md` D-076).
 *
 * CO BYŁO ZŁE
 * `DziennyBudzetListow` rozdzielał odczyt od zapisu: `jestMiejsce()` czytało
 * licznik z cache, `zajmij()` go zwiększało, a wołający robił jedno i drugie
 * — w `LoginLinkController` z dziesięcioma linijkami między nimi. Przy
 * suficie 120 i zużyciu 119 dwa równoległe żądania czytały oba 119, oba
 * widziały wolne miejsce, oba wysyłały list i oba inkrementowały licznik:
 * 121 listów przy suficie 120. `Cache::increment()` jest atomowy jako
 * POJEDYNCZA operacja, ale para „sprawdź, a potem zajmij" nie jest atomowa
 * jako para i żadna liczba komentarzy tego nie zmienia.
 *
 * Skutek nie jest kosmetyczny. Wiadro u dostawcy to 300 listów na dobę na
 * CAŁY serwis (D-047) i z tego samego wiadra idzie POTWIERDZENIE
 * REJESTRACJI, które sufitu nie ma i mieć nie może. Sufit przeciekający
 * o kilka listów pod obciążeniem zabiera je właśnie tam.
 *
 * CZEGO PILNUJĄ TESTY W TYM PLIKU
 *  - rezerwacja oddaje dokładnie tyle miejsc, ile jest w budżecie, i ani
 *    jednego więcej;
 *  - ODMOWA NIC NIE ZAJMUJE — inaczej odrzucone próby wyjadałyby budżet;
 *  - rezerwacja naprawdę chodzi pod blokadą: gdy blokady nie da się zdobyć,
 *    wysyłki NIE MA (to jest ten test, który oblewa się po rozdzieleniu
 *    rezerwacji z powrotem na sprawdzenie i zajęcie);
 *  - licznik zakłada się z terminem ważności, bo bez terminu zostaje
 *    w cache na zawsze;
 *  - człowiek na drodze HTTP dostaje uczciwe zdanie, a nie 500 ani ciszę.
 */
class AtomowaRezerwacjaBudzetuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za „poczta nie działa" — formularz logowania linkiem
        // chowa się wtedy w całości i droga HTTP nie doszłaby do budżetu.
        config(['mail.default' => 'smtp']);
    }

    // ------------------------------------------------------------------
    //  Sedno: sufit oddaje tyle miejsc, ile ma, i ani jednego więcej
    // ------------------------------------------------------------------

    public function test_przy_budzecie_jednego_listu_druga_rezerwacja_odmawia(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $this->assertTrue($budzet->sprobujZarezerwowac(), 'Pierwsza rezerwacja przy wolnym budżecie musi się udać.');
        $this->assertFalse($budzet->sprobujZarezerwowac(), 'Druga rezerwacja przy budżecie 1 musi odmówić.');

        $this->assertSame(
            1,
            $budzet->zuzyte(),
            'Licznik przekroczył sufit. Dokładnie to zdarzało się na produkcji przy dwóch równoległych żądaniach.',
        );
    }

    public function test_przy_budzecie_n_rezerwacje_koncza_sie_dokladnie_na_suficie(): void
    {
        $sufit = 7;
        config(['kuking.login_link.dzienny_budzet' => $sufit]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($proba = 1; $proba <= $sufit; $proba++) {
            $this->assertTrue(
                $budzet->sprobujZarezerwowac(),
                "Rezerwacja numer {$proba} przy suficie {$sufit} musi się udać.",
            );
        }

        $this->assertFalse($budzet->sprobujZarezerwowac(), 'Rezerwacja ponad sufitem musi odmówić.');
        $this->assertFalse($budzet->sprobujZarezerwowac(), 'Kolejna próba też — i nadal nic nie zajmuje.');

        $this->assertSame($sufit, $budzet->zuzyte());
    }

    /**
     * BUDŻET USTAWIONY NA ZERO ZNACZY „DZIŚ NIC" — i to jest poprawna,
     * świadoma konfiguracja (awaryjne odcięcie poczty bez zamykania drogi
     * ludziom, którzy mają ważny link w skrzynce).
     */
    public function test_budzet_zerowy_nie_wypuszcza_ani_jednego_listu(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 0]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $this->assertFalse($budzet->sprobujZarezerwowac());
        $this->assertSame(0, $budzet->zuzyte());
    }

    /**
     * TEN SAM SUFIT DLA TYGODNIOWEGO PODSUMOWANIA, NA WŁASNYM LICZNIKU.
     *
     * `kuking:wyslij-podsumowania` szacuje rozmiar paczki z `zostalo()`,
     * a potem rezerwuje miejsce przy każdym liście osobno — więc drugi
     * przebieg komendy uruchomiony równolegle (ręcznie po awarii, gdy
     * harmonogram już chodzi) nie wypuści paczki ponad sufitem. Zatrzymanie
     * po stronie doby pilnuje `TygodniowePodsumowanieTest`; tutaj pilnujemy,
     * że wytwórnia podsumowania dostała tę samą twardą rezerwację, a nie
     * została przy starej parze „sprawdź i zajmij".
     */
    public function test_podsumowanie_tygodnia_rezerwuje_na_wlasnym_liczniku(): void
    {
        config([
            'kuking.digest.dzienny_limit' => 1,
            'kuking.login_link.dzienny_budzet' => 120,
        ]);

        $podsumowanie = DziennyBudzetListow::dlaPodsumowania();

        $this->assertTrue($podsumowanie->sprobujZarezerwowac());
        $this->assertFalse($podsumowanie->sprobujZarezerwowac());
        $this->assertSame(1, $podsumowanie->zuzyte());

        // Licznik logowania linkiem jest osobny i podsumowanie go nie tknęło.
        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
    }

    // ------------------------------------------------------------------
    //  Odmowa nie może kosztować miejsca w budżecie
    // ------------------------------------------------------------------

    /**
     * Gdyby odrzucona próba zwiększała licznik, odmowy zjadałyby budżet:
     * dwadzieścia prób przy pełnym już suficie podniosłoby zużycie do 140
     * przy sufcie 120 i następna doba startowałaby z dziury. Licznik ma
     * liczyć LISTY, nie próby.
     */
    public function test_odmowa_rezerwacji_nie_zwieksza_licznika(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 2]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $budzet->sprobujZarezerwowac();
        $budzet->sprobujZarezerwowac();

        $this->assertSame(2, $budzet->zuzyte());

        for ($proba = 0; $proba < 5; $proba++) {
            $this->assertFalse($budzet->sprobujZarezerwowac());
        }

        $this->assertSame(
            2,
            $budzet->zuzyte(),
            'Odrzucone próby podniosły licznik — odmowy wyjadają wtedy budżet prawdziwym listom.',
        );
    }

    /**
     * MIEJSCE, Z KTÓREGO NIC NIE WYSZŁO, WRACA DO PULI.
     *
     * Rezerwacja stoi PRZED wysyłką (inaczej nie jest sufitem), a przy
     * logowaniu linkiem list wychodzi tylko dla adresu, pod którym naprawdę
     * jest konto. Bez oddawania nieużytego miejsca automat wpisujący
     * nieistniejące adresy wyczerpywałby dobowy budżet w kilka minut, nie
     * wysławszy ani jednego listu.
     */
    public function test_oddane_miejsce_wraca_do_puli_i_nie_schodzi_pod_zero(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $this->assertTrue($budzet->sprobujZarezerwowac());
        $budzet->zwolnij();

        $this->assertSame(0, $budzet->zuzyte());
        $this->assertTrue($budzet->sprobujZarezerwowac(), 'Po oddaniu miejsca budżet ma znowu wypuścić list.');

        // Oddanie miejsca, którego nikt nie zajął, nie może zrobić z licznika
        // liczby ujemnej — bo ujemny licznik to budżet większy od sufitu.
        $budzet->zwolnij();
        $budzet->zwolnij();
        $budzet->zwolnij();

        $this->assertSame(0, $budzet->zuzyte());
    }

    // ------------------------------------------------------------------
    //  Rezerwacja naprawdę chodzi pod blokadą
    // ------------------------------------------------------------------

    /**
     * TO JEST TEST ATOMOWOŚCI — i jedyny w tym pliku, który oblewa się po
     * rozdzieleniu rezerwacji z powrotem na „sprawdź" i „zajmij".
     *
     * Prawdziwego wyścigu dwóch procesów nie da się odtworzyć w jednym
     * przebiegu PHPUnita w sposób powtarzalny, więc sprawdzamy MECHANIZM,
     * który ten wyścig rozstrzyga: blokada trzymana przez kogoś innego musi
     * ZATRZYMAĆ rezerwację. Rezerwacja bez blokady przeszłaby tu tak samo
     * jak bez cudzej blokady — i właśnie dlatego ten test ma sens.
     *
     * Odmowa, a nie „wyślij na wszelki wypadek": przekroczony budżet
     * u dostawcy odbija się na całej poczcie serwisu (także na potwierdzeniu
     * rejestracji), a jedna niewysłana wiadomość odbija się na jednej
     * osobie, która dostaje uczciwy komunikat i klika drugi raz.
     */
    public function test_rezerwacja_bez_zdobytej_blokady_odmawia_i_nic_nie_zajmuje(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 120]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        // Blokada W IMIENIU KOGOŚ INNEGO — tak jak zrobiłby to drugi proces
        // PHP obsługujący równoległe żądanie.
        $cudza = Cache::lock('poczta:budzet:blokada:link-logowania', 30);
        $this->assertTrue($cudza->get(), 'Nie udało się zająć blokady w teście — sam test byłby wtedy pusty.');

        try {
            $this->assertFalse(
                $budzet->sprobujZarezerwowac(),
                'Rezerwacja przeszła bez zdobycia blokady — czyli para „sprawdź i zajmij" znowu nie jest atomowa.',
            );

            $this->assertSame(
                0,
                $budzet->zuzyte(),
                'Odrzucona rezerwacja zajęła miejsce w budżecie.',
            );
        } finally {
            $cudza->release();
        }

        // Po zwolnieniu blokady ta sama rezerwacja przechodzi — czyli
        // odmowa wyżej wynikała z blokady, a nie z czegokolwiek innego.
        $this->assertTrue($budzet->sprobujZarezerwowac());
        $this->assertSame(1, $budzet->zuzyte());
    }

    /**
     * STEROWNIK CACHE DECYDUJE, CZY BLOKADA JEST PRAWDZIWA.
     *
     * `Cache::lock()` na sterowniku `array` jest blokadą tylko w obrębie
     * JEDNEGO procesu PHP — w testach to wystarcza, na produkcji nie
     * znaczyłoby nic, bo każde żądanie to inny proces. Produkcja chodzi na
     * `database` (`config/cache.php`, `.env.example`), czyli na blokadzie
     * współdzielonej i trwałej, opartej o tabelę `cache_locks`. Ten test
     * jest jedynym miejscem, w którym ten warunek jest zapisany — bez niego
     * `CACHE_STORE=array` w środowisku produkcyjnym zdjęłoby cały sufit
     * i nikt by tego nie zauważył.
     */
    public function test_produkcyjny_sterownik_cache_daje_prawdziwa_wspoldzielona_blokade(): void
    {
        $this->assertSame(
            'database',
            (string) config('cache.stores.database.driver'),
            'Sklep `database` w config/cache.php nie jest już sterownikiem bazodanowym.',
        );

        $sklep = Cache::store('database')->getStore();

        $this->assertInstanceOf(
            LockProvider::class,
            $sklep,
            'Produkcyjny sklep cache nie umie blokad — `Cache::lock()` wywróciłby wysyłkę poczty.',
        );

        $this->assertTrue(
            Schema::hasTable('cache_locks'),
            'Nie ma tabeli `cache_locks`, na której stoi blokada sterownika `database`.',
        );

        $przyklad = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'CACHE_STORE=database',
            $przyklad,
            '.env.example przestał ustawiać sterownik cache na `database`. Na `array` dobowy sufit poczty '
            .'przestaje być sufitem, bo blokada nie wychodzi poza jeden proces PHP.',
        );
    }

    // ------------------------------------------------------------------
    //  Termin ważności klucza licznika
    // ------------------------------------------------------------------

    /**
     * LICZNIK MUSI MIEĆ TERMIN WAŻNOŚCI.
     *
     * Klucz niesie datę, więc nowa doba liczy się od nowa sama z siebie —
     * ale wpis ze starą datą zostaje w cache i bez terminu ważności nie
     * usunie go już nic. Na sterowniku `database` to znaczy: wiersz na każdy
     * dzień działania serwisu, na zawsze. `Cache::increment()` na
     * NIEISTNIEJĄCYM kluczu zakłada właśnie taki wieczny wpis, dlatego
     * `zajmij()` poprzedza go `Cache::add($klucz, 0, termin)`.
     *
     * Sprawdzamy to na sterowniku `database`, czyli tym z produkcji, i przez
     * zaglądnięcie WPROST do kolumny `expiration` — a nie przez API cache,
     * które terminu nie pokazuje.
     */
    public function test_rezerwacja_zaklada_licznik_z_terminem_waznosci(): void
    {
        config([
            'cache.default' => 'database',
            'kuking.login_link.dzienny_budzet' => 5,
        ]);

        $this->assertTrue(DziennyBudzetListow::dlaLinkuLogowania()->sprobujZarezerwowac());

        $wiersz = DB::table('cache')
            ->where('key', 'like', '%poczta:budzet:link-logowania%')
            ->first();

        $this->assertNotNull($wiersz, 'Rezerwacja nie zapisała licznika w sklepie `database`.');

        $termin = (int) $wiersz->expiration;

        $this->assertGreaterThan(
            now()->timestamp,
            $termin,
            'Licznik dobowego budżetu ma termin ważności z przeszłości.',
        );

        // Górna granica jest tu ważniejsza niż dolna: to ona oblewa się,
        // gdy ktoś zamieni `Cache::add($klucz, 0, termin)` na wpis bez
        // terminu (Laravel zapisuje wtedy „na zawsze", czyli dziesięć lat).
        $this->assertLessThan(
            now()->addDays(7)->timestamp,
            $termin,
            'Licznik dobowego budżetu został założony bez terminu ważności — zostanie w cache na zawsze.',
        );
    }

    // ------------------------------------------------------------------
    //  Droga HTTP: człowiek dostaje uczciwe zdanie
    // ------------------------------------------------------------------

    /**
     * WYCZERPANY BUDŻET NA PRAWDZIWEJ DRODZE LOGOWANIA LINKIEM.
     *
     * Nie 500 i nie cisza: człowiek ma zobaczyć zdanie mówiące, że list nie
     * wyjdzie i co zrobić zamiast czekania. Cicha odmowa jest tu najgorsza
     * z możliwych — 63-letnia osoba siedzi wtedy przy skrzynce i czeka na
     * wiadomość, która nie ma przyjść.
     */
    public function test_droga_http_przy_wyczerpanym_budzecie_mowi_prawde_zamiast_milczec(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        // Wyczerpujemy pulę tak, jak zrobiłby to prawdziwy pierwszy list.
        $this->assertTrue(DziennyBudzetListow::dlaLinkuLogowania()->sprobujZarezerwowac());

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $odpowiedz = $this->wyslijFormularz($basia->email);

        $odpowiedz->assertRedirect(route('login.link'));
        $odpowiedz->assertSessionHasNoErrors();

        Notification::assertNotSentTo($basia, LinkDoLogowania::class);
        $this->assertDatabaseCount('login_link_tokens', 0);

        $komunikat = (string) $odpowiedz->getSession()->get('status', '');

        $this->assertStringContainsString('nie czekaj na niego', $komunikat);
        $this->assertStringContainsString('Zaloguj się hasłem', $komunikat);
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $komunikat);

        // I to samo zdanie musi być WIDOCZNE na stronie — w akapicie
        // komunikatu, nie gdziekolwiek w HTML-u.
        $this->assertStringContainsString('nie czekaj na niego', $this->akapitKomunikatu());
    }

    /**
     * ŚCISK NA BLOKADZIE TO INNY POWÓD NIŻ WYCZERPANY BUDŻET — i człowiek
     * ma usłyszeć co innego.
     *
     * Zdanie „wysłaliśmy już wszystkie e-maile na dziś" byłoby tu
     * NIEPRAWDĄ: budżet jest wolny, nie udało się tylko zdobyć blokady.
     * Ten serwis ma jedną twardą regułę o komunikatach — nie obiecują
     * i nie opowiadają rzeczy, które się nie stały.
     */
    public function test_droga_http_przy_scisku_na_blokadzie_prosi_o_ponowna_probe(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 120]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $cudza = Cache::lock('poczta:budzet:blokada:link-logowania', 30);
        $this->assertTrue($cudza->get());

        try {
            $odpowiedz = $this->wyslijFormularz($basia->email);
        } finally {
            $cudza->release();
        }

        $odpowiedz->assertRedirect(route('login.link'));

        // ODMOWA WYSYŁKI, nie „wyślij na wszelki wypadek".
        Notification::assertNotSentTo($basia, LinkDoLogowania::class);

        $komunikat = (string) $odpowiedz->getSession()->get('status', '');

        $this->assertStringContainsString('Kliknij „Wyślij mi link” jeszcze raz', $komunikat);
        $this->assertStringNotContainsString(
            'wszystkie e-maile z linkiem',
            $komunikat,
            'Komunikat mówi o wyczerpanym budżecie, a budżet jest wolny — to nieprawda.',
        );
    }

    /**
     * KOMENDA PODSUMOWAŃ REZERWUJE MIEJSCE PRZED WSTAWIENIEM DO KOLEJKI.
     *
     * Kolejność jest tu całym sensem sufitu i nie da się jej sprawdzić
     * licząc listy: rezerwacja zrobiona PO `Mail::queue()` daje identyczne
     * liczby w spokojnym przebiegu, a pod obciążeniem przepuszcza paczkę
     * ponad limit dostawcy. List odrzucony limitem EmailLabs przepada
     * w `failed_jobs` po trzech próbach w sześciu minutach i nikt się
     * o tym nie dowie — cofnąć wstawienia nie umiemy.
     *
     * Trzymamy więc blokadę „w imieniu drugiego przebiegu" i sprawdzamy,
     * że komenda NIE WSTAWIŁA DO KOLEJKI ANI JEDNEGO listu. Gdyby
     * rezerwacja stała za `Mail::queue()`, listy byłyby już w kolejce,
     * zanim sufit powiedziałby cokolwiek.
     */
    public function test_komenda_podsumowan_nie_kolejkuje_bez_zdobytej_rezerwacji(): void
    {
        config([
            'kuking.digest.wlaczony' => true,
            'kuking.digest.dzienny_limit' => 5,
            'kuking.digest.odstep_dni' => 7,
            'kuking.digest.okno_dni' => 7,
            'kuking.digest.odstep_sekund' => 20,
            'kuking.digest.max_pozycji' => 3,
        ]);

        Mail::fake();

        for ($numer = 0; $numer < 3; $numer++) {
            $this->autorZWykonaniem('czeka_'.$numer);
        }

        $cudza = Cache::lock('poczta:budzet:blokada:podsumowanie-tygodnia', 30);
        $this->assertTrue($cudza->get(), 'Nie udało się zająć blokady w teście — sam test byłby wtedy pusty.');

        try {
            Artisan::call('kuking:wyslij-podsumowania');
        } finally {
            $cudza->release();
        }

        Mail::assertNothingQueued();

        $this->assertSame(0, DziennyBudzetListow::dlaPodsumowania()->zuzyte());

        // Znacznik „wysłane" też nie mógł powstać: te osoby mają być jutro
        // pierwsze w kolejce, a nie zapomniane.
        $this->assertSame(
            0,
            User::query()->whereNotNull('weekly_digest_sent_at')->count(),
            'Znacznik wysyłki dostał ktoś, komu nie wysłano listu.',
        );
    }

    // ------------------------------------------------------------------

    /**
     * Osoba, której PRZEPIS ktoś w tym tygodniu ugotował — czyli ktoś, kto
     * ma realny powód dostać tygodniowe podsumowanie.
     */
    private function autorZWykonaniem(string $nazwa): User
    {
        $autor = $this->user($nazwa);
        $kucharz = $this->user($nazwa.'_kucharz');

        $przepis = Recipe::factory()->for($autor, 'author')->create();

        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }

    private function wyslijFormularz(string $adres): TestResponse
    {
        return $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }

    /**
     * Treść akapitu z komunikatem, wycięta z HTML-a.
     *
     * Asercja na CAŁEJ odpowiedzi łapie to samo słowo z innego miejsca
     * strony i przechodzi, nie sprawdzając niczego — dlatego sprawdzamy
     * wnętrze konkretnego elementu.
     */
    private function akapitKomunikatu(): string
    {
        $html = $this->get(route('login.link'))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('#<p class="flash">(.*?)</p>#s', $html, 'Na stronie nie ma akapitu z komunikatem.');

        preg_match('#<p class="flash">(.*?)</p>#s', $html, $dopasowanie);

        return html_entity_decode($dopasowanie[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
