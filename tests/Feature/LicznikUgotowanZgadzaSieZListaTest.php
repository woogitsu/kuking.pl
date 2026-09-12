<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licznik „Ugotowane N ×" mówi dokładnie tyle, ile widać na liście.
 *
 * Rozjazd między liczbą a listą jest usterką, którą widać dopiero wtedy, gdy
 * ktoś się na nią natknie — i wtedy jest najgorsza z możliwych: strona sama
 * melduje widzowi, że osoba, którą zablokował, ugotowała ten przepis
 * („oracle istnienia"). Licznik nad pustym miejscem nie jest kosmetyką, tylko
 * wyciekiem.
 *
 * CO DOKŁADNIE JEST TU MIERZONE
 * Trzy miejsca, w których ta sama etykieta pokazuje tę samą liczbę:
 *  - pasek liczb i znaczek nad zdjęciem na stronie przepisu (`cookedCount`),
 *  - galeria „Komu wyszło" pod nimi (`cookedEvents`),
 *  - karta przepisu w wynikach wyszukiwania (`cooked_events_count`).
 *
 * GRANICE, KTÓRE TA LICZBA MA ZNAĆ (`CookedEvent::scopeWidoczneDla()`):
 *  - blokada między widzem a KUCHARZEM, w obie strony,
 *  - konto kucharza zbanowane albo oznaczone do usunięcia — odpada,
 *  - konto kucharza ZAWIESZONE — zostaje, bo zawieszenie jest karą za
 *    pisanie, nie kasowaniem dorobku.
 *
 * Każda asercja „tego nie widać" stoi tu obok kontroli dodatniej — wykonania,
 * które w tym samym przebiegu WIDAĆ (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
 * Bez niej cała klasa przeszłaby również wtedy, gdyby galeria nie pokazywała
 * nikogo, a licznik stał na zerze.
 */
class LicznikUgotowanZgadzaSieZListaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $kucharze = [];

    private Recipe $przepis;

    private User $widz;

    /**
     * Jeden przepis, pięcioro gotujących, każdy w innym stanie.
     *
     * Dla `$this->widz` widoczne mają być DWA wykonania: zwykłego kucharza
     * i kucharza zawieszonego. Odpadają: zablokowany przez widza, blokujący
     * widza i zbanowany.
     */
    private function przygotujScene(): void
    {
        $autor = $this->user('autor_licznika');
        $this->widz = $this->user('widz_licznika');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosol babciny kontrolny',
        ]);

        foreach (['zwykly', 'zablokowany', 'blokujacy', 'zbanowany', 'zawieszony'] as $kto) {
            $this->kucharze[$kto] = $this->user('kucharz_'.$kto);
        }

        $akcja = app(RecordCookedEvent::class);

        foreach ($this->kucharze as $kucharz) {
            $akcja->handle($kucharz, $this->przepis->fresh());
        }

        $this->assertSame(5, $this->przepis->cookedEvents()->count(), 'Scena nie powstała — test nie mierzy niczego.');

        app(BlockUser::class)->handle($this->widz, $this->kucharze['zablokowany']);
        app(BlockUser::class)->handle($this->kucharze['blokujacy'], $this->widz);

        $this->kucharze['zbanowany']->ban();
        $this->kucharze['zawieszony']->suspend(now()->addDays(3));
    }

    public function test_licznik_na_stronie_przepisu_liczy_dokladnie_to_co_galeria(): void
    {
        $this->przygotujScene();

        $odpowiedz = $this->actingAs($this->widz->fresh())
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk();

        $licznik = $odpowiedz->viewData('cookedCount');
        $galeria = $odpowiedz->viewData('cookedEvents');

        $this->assertSame(2, $licznik, 'Licznik pokazuje inną liczbę niż liczba wykonań widocznych dla tego widza.');
        $this->assertCount(2, $galeria, 'Galeria pokazuje inną liczbę kart niż licznik nad nią.');
        $this->assertSame(
            $licznik,
            $galeria->total(),
            'Licznik i galeria liczą z dwóch różnych zapytań — to jest dokładnie ten rozjazd, którego pilnuje ten test.',
        );

        // Te dwa wykonania, nie jakiekolwiek dwa.
        $widoczni = $galeria->pluck('user_id')->all();
        $this->assertContains($this->kucharze['zwykly']->getKey(), $widoczni);
        $this->assertContains(
            $this->kucharze['zawieszony']->getKey(),
            $widoczni,
            'Wykonanie osoby zawieszonej zniknęło — zawieszenie jest karą za pisanie, nie kasowaniem dorobku.',
        );
        $this->assertNotContains($this->kucharze['zablokowany']->getKey(), $widoczni);
        $this->assertNotContains($this->kucharze['blokujacy']->getKey(), $widoczni);
        $this->assertNotContains($this->kucharze['zbanowany']->getKey(), $widoczni);

        // Liczba naprawdę dochodzi do ekranu. Wycinamy sekcję „Komu wyszło"
        // (pułapka 1 i 1b): to samo słowo i ta sama cyfra stoją na tej
        // stronie także w `<title>`, w `<meta>`, w JSON-LD i w znaczku nad
        // zdjęciem, więc asercja na całym dokumencie nie mierzyłaby tego
        // miejsca.
        $sekcja = $this->wycinekKomuWyszlo($odpowiedz->getContent());
        $this->assertStringContainsString('2 osoby ugotowały to danie', $sekcja);
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: widz bez żadnej blokady widzi cztery
     * wykonania i licznik też mówi cztery.
     *
     * Bez tego testu poprzedni przechodziłby również wtedy, gdyby galeria
     * i licznik pokazywały zawsze to samo, byle mało.
     */
    public function test_widz_bez_blokad_widzi_wszystkie_wykonania_poza_zbanowanym(): void
    {
        $this->przygotujScene();

        $ktos_z_boku = $this->user('widz_bez_blokad');

        $odpowiedz = $this->actingAs($ktos_z_boku)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk();

        $this->assertSame(4, $odpowiedz->viewData('cookedCount'));
        $this->assertCount(4, $odpowiedz->viewData('cookedEvents'));

        $sekcja = $this->wycinekKomuWyszlo($odpowiedz->getContent());
        $this->assertStringContainsString('4 osoby ugotowały to danie', $sekcja);
    }

    /**
     * GOŚĆ: bez blokad do policzenia, ale granica konta kucharza obowiązuje
     * i jego też — `scopeWidoczneDla(null)` nie jest wyłącznikiem całej
     * reguły, tylko jej połowy.
     */
    public function test_gosc_widzi_te_sama_liczbe_co_widz_bez_blokad(): void
    {
        $this->przygotujScene();

        $odpowiedz = $this->get(route('recipes.show', $this->przepis->slug))->assertOk();

        $this->assertSame(4, $odpowiedz->viewData('cookedCount'));
        $this->assertCount(4, $odpowiedz->viewData('cookedEvents'));
    }

    /**
     * KARTA W WYNIKACH WYSZUKIWANIA pokazuje tę samą liczbę, co strona
     * przepisu, której dotyczy.
     *
     * Ta sama etykieta na dwóch ekranach ma znaczyć to samo. Gdyby karta
     * liczyła gołym `withCount`, wynik wyszukiwania zdradzałby istnienie
     * wykonań, których strona przepisu temu widzowi nie pokazuje — czyli ta
     * sama usterka przeniesiona o jeden plik dalej, a nie zamknięta.
     */
    public function test_karta_w_wyszukiwarce_pokazuje_te_sama_liczbe_co_strona_przepisu(): void
    {
        $this->przygotujScene();

        $widz = $this->widz->fresh();

        $zeStrony = $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->viewData('cookedCount');

        $zWyszukiwarki = app(SearchQuery::class)
            ->recipes('Rosol babciny kontrolny', $widz)
            ->firstWhere('id', $this->przepis->getKey());

        $this->assertNotNull($zWyszukiwarki, 'Wyszukiwarka nie zwróciła przepisu — licznik nie ma czego mierzyć.');
        $this->assertSame(
            $zeStrony,
            $zWyszukiwarki->cooked_events_count,
            'Karta w wynikach i strona przepisu pokazują tę samą etykietę z dwiema różnymi liczbami.',
        );

        // KONTROLA DODATNIA: dla widza bez blokad obie liczby też są równe —
        // i są WIĘKSZE. Sama równość przeszłaby przy dwóch zerach.
        $ktos_z_boku = $this->user('widz_wyszukiwarki');

        $zeStronyDlaObcego = $this->actingAs($ktos_z_boku)
            ->get(route('recipes.show', $this->przepis->slug))
            ->viewData('cookedCount');

        $zWyszukiwarkiDlaObcego = app(SearchQuery::class)
            ->recipes('Rosol babciny kontrolny', $ktos_z_boku)
            ->firstWhere('id', $this->przepis->getKey());

        $this->assertSame($zeStronyDlaObcego, $zWyszukiwarkiDlaObcego->cooked_events_count);
        $this->assertGreaterThan(
            $zWyszukiwarki->cooked_events_count,
            $zWyszukiwarkiDlaObcego->cooked_events_count,
            'Kontrola dodatnia: obie liczby są równe, bo obie są takie same dla każdego — reguła nie pracuje.',
        );
    }

    /**
     * Ta sama liczba na KARCIE renderowanej naprawdę, nie tylko w modelu.
     *
     * `cooked_events_count` bywa `null` (na profilu i w zeszycie karta nie
     * dostaje tej kolumny i znaczek się nie rysuje) — ten test pilnuje, że
     * tam, gdzie znaczek JEST, niesie liczbę widza.
     */
    public function test_znaczek_na_karcie_wyniku_niesie_liczbe_widza(): void
    {
        $this->przygotujScene();

        $html = $this->actingAs($this->widz->fresh())
            ->get(route('search', ['q' => 'Rosol babciny kontrolny']))
            ->assertOk()
            ->getContent();

        $wynik = $this->wycinekWynikow($html);

        $this->assertStringContainsString('Ugotowane 2 ×', $wynik);
        $this->assertStringNotContainsString('Ugotowane 5 ×', $wynik);
        $this->assertStringNotContainsString('Ugotowane 4 ×', $wynik);
    }

    /**
     * Sekcja „Komu wyszło" ze strony przepisu — od jej nagłówka do końca
     * listy wykonań.
     */
    private function wycinekKomuWyszlo(string $html): string
    {
        $od = strpos($html, 'aria-labelledby="komu-wyszlo"');
        $this->assertNotFalse($od, 'Nie ma sekcji „Komu wyszło" — wycinek by milczał, a test i tak by przeszedł.');

        $do = strpos($html, '</section>', $od);
        $this->assertNotFalse($do);

        return substr($html, $od, $do - $od);
    }

    /**
     * KARTA TEGO JEDNEGO PRZEPISU z wyników wyszukiwania.
     *
     * Zakotwiczona na ADRESIE przepisu, nie na jego tytule: tytuł stoi na tej
     * stronie także w polu wyszukiwania (`value="…"`), więc szukanie po nim
     * trafiłoby w formularz i wycięło pierwszą lepszą kartę spod niego —
     * pułapka 1 w czystej postaci. Od adresu cofamy się do otwarcia
     * `<article`, żeby w wycinku był cały kafelek razem ze znaczkiem.
     */
    private function wycinekWynikow(string $html): string
    {
        $adres = route('recipes.show', $this->przepis->slug);

        $wSrodku = strpos($html, $adres);
        $this->assertNotFalse($wSrodku, 'Wyszukiwarka nie zwróciła tego przepisu — wycinek nie mierzy niczego.');

        $od = strrpos(substr($html, 0, $wSrodku), '<article');
        $this->assertNotFalse($od, 'Odnośnik do przepisu nie stoi w żadnej karcie.');

        $do = strpos($html, '</article>', $wSrodku);
        $this->assertNotFalse($do);

        return substr($html, $od, $do - $od);
    }
}
