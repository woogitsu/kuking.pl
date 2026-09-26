<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\Block;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\RegistrationInvite;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\ObchodEkranow;
use Tests\TestCase;

/**
 * ŻADNEGO MARTWEGO PRZYCISKU — OBCHÓD CAŁEGO SERWISU W ZWYKŁYM PRZEBIEGU TESTÓW.
 *
 * DLACZEGO TO POWSTAŁO, SKORO JEST JUŻ `scripts/martwe-przyciski.mjs`
 * Tamten skrypt obchodzi serwis PRZEGLĄDARKĄ i robi to lepiej niż cokolwiek
 * innego: widzi ułożony dokument, wchodzi w odnośniki znalezione po drodze
 * i zna trzy role naraz. Ma jednak dwie granice, których nie da się przesunąć:
 *
 *   1. NIE CHODZI W `php artisan test`. Wymaga postawionego serwera,
 *      zbudowanego arkusza, Chromium i własnej bazy, którą czyści
 *      `migrate:fresh`. Nikt nie odpala go przed każdym commitem, więc
 *      martwy przycisk wjeżdża na gałąź i czeka tam do następnego audytu.
 *   2. CHODZI PO ODNOŚNIKACH, WIĘC WIDZI TYLKO TO, DO CZEGO PROWADZI ODNOŚNIK.
 *      Ekran błędu (403, 419, 429, 500, 503), ekran z podpisem w liście
 *      (`/podsumowanie/wypisz/…`), szkic wpisu, konto zawieszone, konto
 *      w trakcie usuwania, pusty zeszyt u nowej osoby — na żaden z nich
 *      nie prowadzi ani jeden `<a href>`. Dla skanu one nie istnieją.
 *
 * Ten test bierze te dwie granice na siebie: chodzi bez przeglądarki, za to
 * wchodzi na ekrany WPROST, w stanach, które trzeba najpierw zbudować.
 *
 * SZEŚĆ RODZAJÓW MARTWEGO PRZYCISKU — BO `href="#"` TO TYLKO JEDEN Z NICH
 * Co dokładnie sprawdza każdy obchód, opisuje `Tests\Support\ObchodEkranow`.
 * Krótko: 404/500 pod odnośnikiem, 403 pod odnośnikiem POKAZANYM TEJ OSOBIE,
 * formularz bez trasy, `<button>` bez formularza i bez skryptu, odnośnik bez
 * napisu, dwa różne cele pod tą samą nazwą w jednym miejscu.
 *
 * PROGI MINIMALNE SĄ CZĘŚCIĄ TESTU, NIE OZDOBĄ (pułapka 2)
 * Każda metoda kończy się `zakonczObchod()`, które oblewa, gdy obchód
 * odwiedził za mało ekranów albo znalazł za mało odnośników. Bez tego skan,
 * który przestał cokolwiek czytać, byłby nie do odróżnienia od serwisu bez
 * ani jednego martwego przycisku.
 *
 * DLACZEGO OSOBNE METODY, A NIE JEDEN WIELKI OBCHÓD
 * Żeby czerwień nazywała obszar. „Ekrany moderacji" i „ekrany błędu" to dwie
 * różne prace dla dwóch różnych osób; jeden wspólny test kazałby obu czytać
 * cudzą listę.
 */
class ObchodEkranowNieZostawiaMartwegoPrzyciskuTest extends TestCase
{
    use ObchodEkranow;
    use RefreshDatabase;

    /** Przepis z krokami i składnikiem — bez kroków tryb gotowania odsyła na przepis. */
    private function przepisZKrokami(User $autor): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'szklanka mąki',
            'position' => 0,
        ]);

        for ($i = 0; $i < 3; $i++) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
            ]);
        }

        return $recipe;
    }

    // ------------------------------------------------------------------
    //  Kontrola dodatnia PRZYRZĄDU — osiem usterek na jednym ekranie
    // ------------------------------------------------------------------

    /**
     * OBCHÓD, KTÓRY NICZEGO NIE ZNAJDUJE, WYGLĄDA TAK SAMO JAK OBCHÓD,
     * KTÓRY NIE DZIAŁA (docs/PULAPKI_TESTOW.md, pułapki 2 i 4).
     *
     * Osiem obchodów w tym pliku kończy się zdaniem „nie znalazłem nic", i to jest
     * dobra wiadomość wyłącznie wtedy, gdy przyrząd naprawdę umie coś
     * znaleźć. Ten test stawia ekran ZEPSUTY NA OSIEM SPOSOBÓW i wymaga,
     * żeby każdy z nich został zameldowany — z nazwą ekranu, na którym stoi.
     *
     * Progi minimalne z `zakonczObchod()` pilnują, że obchód gdzieś BYŁ.
     * Ten test pilnuje, że kiedy tam był, to PATRZYŁ.
     */
    public function test_obchod_lapie_kazdy_rodzaj_martwego_przycisku(): void
    {
        $ktos = $this->user('nakontrole', ['display_name' => 'Osoba Na Kontrolę']);

        Route::get('/obchod-kontrola-dodatnia', fn () => response(<<<HTML
            <!doctype html><html lang="pl"><head><title>Kontrola</title></head><body>
            <main id="tresc">
                <a href="/nie-ma-takiej-trasy">Poradnik krok po kroku</a>
                <a href="/podsumowanie/wypisz/{$ktos->getKey()}">Wypisz mnie z podsumowania</a>
                <form method="post" action="/tez-nie-ma-takiej-trasy"><button type="submit">Wyślij zgłoszenie</button></form>
                <button type="button">Pokaż więcej</button>
                <a href="/pomoc"><span></span></a>
                <nav><a href="/pomoc">Zobacz</a><a href="/zasady">Zobacz</a></nav>
                <a href="#">Rozwiń</a>
                <a href="#nie-ma-takiej-kotwicy">Przejdź do składników</a>
            </main></body></html>
            HTML))->middleware('web');

        $this->obejdzEkran('/obchod-kontrola-dodatnia', 'kontrola dodatnia');

        $oczekiwane = [
            '404' => '404 (kontrola dodatnia) na /obchod-kontrola-dodatnia: „Poradnik krok po kroku"',
            '403 pokazane tej osobie' => '403 (kontrola dodatnia) na /obchod-kontrola-dodatnia: „Wypisz mnie z podsumowania"',
            'formularz bez trasy' => 'FORMULARZ BEZ TRASY (kontrola dodatnia) na /obchod-kontrola-dodatnia: przycisk „Wyślij zgłoszenie"',
            'przycisk bez obsługi' => 'PRZYCISK BEZ OBSŁUGI (kontrola dodatnia) na /obchod-kontrola-dodatnia: „Pokaż więcej"',
            'odnośnik bez napisu' => 'ODNOŚNIK BEZ NAPISU (kontrola dodatnia) na /obchod-kontrola-dodatnia',
            'dwa cele pod jedną nazwą' => 'DWA RÓŻNE CELE POD TĄ SAMĄ NAZWĄ (kontrola dodatnia) na /obchod-kontrola-dodatnia',
            'odnośnik donikąd' => 'ODNOŚNIK DONIKĄD (kontrola dodatnia) na /obchod-kontrola-dodatnia: „Rozwiń"',
            'kotwica bez celu' => 'KOTWICA BEZ CELU (kontrola dodatnia) na /obchod-kontrola-dodatnia: „Przejdź do składników"',
        ];

        $meldunek = implode("\n", $this->obchodUsterki);

        foreach ($oczekiwane as $rodzaj => $poczatekZdania) {
            $this->assertStringContainsString($poczatekZdania, $meldunek,
                "Obchód NIE ZAUWAŻYŁ martwego przycisku rodzaju „{$rodzaj}\" na ekranie, który "
                .'został zepsuty właśnie po to, żeby go zauważył. Znalazł za to:'."\n".$meldunek);
        }

        // Kontrola ujemna do tej kontroli dodatniej: ekran zepsuty na osiem
        // sposobów ma dać OSIEM meldunków, nie jeden powtórzony osiem razy.
        $this->assertCount(8, $this->obchodUsterki,
            'Ekran ma osiem usterek, obchód zameldował '.count($this->obchodUsterki).":\n".$meldunek);
    }

    // ------------------------------------------------------------------
    //  Gość — połowa serwisu, którą widać bez konta
    // ------------------------------------------------------------------

    public function test_ekrany_goscia_nie_maja_martwego_przycisku(): void
    {
        $autor = $this->user('anna', ['display_name' => 'Anna Kowalska']);
        $przepis = $this->przepisZKrokami($autor);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $tag = Tag::factory()->create();
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        foreach ([
            '/', '/odkryj', '/login', '/register', '/szukaj?q=rosol', '/tagi', '/pomoc',
            '/zasady', '/o-kuking', '/regulamin', '/prywatnosc', '/napisz-do-nas',
            '/napisz-do-nas/dziekujemy', '/zglos-nielegalna-tresc', '/zglos-nielegalna-tresc/przyjete',
            '/nie-pamietam-hasla', '/logowanie/link', '/odwolanie', '/cofnij-usuniecie-konta',
            '/@anna', '/@anna/obserwowani', '/@anna/obserwujacy',
            '/tag/'.$tag->slug,
            '/przepisy/'.$przepis->slug,
            '/przepisy/'.$przepis->slug.'/gotuj',
            '/wpisy/'.$wpis->getKey(),
            '/ugotowane/'.$wykonanie->getKey(),
            '/szukaj?q=czegotunieznajdziesz',
            '/szukaj',
        ] as $adres) {
            $this->obejdzEkran($adres, 'gość');
        }

        $this->zakonczObchod(minEkranow: 25, minOdnosnikow: 280, minFormularzy: 30, minPrzyciskow: 55);
    }

    // ------------------------------------------------------------------
    //  Stany puste — „nie masz jeszcze wpisów", „zeszyt pusty"
    // ------------------------------------------------------------------

    /**
     * PUSTY EKRAN U NOWEJ OSOBY TO KONIEC KORZYSTANIA Z SERWISU (AGENTS.md §8).
     * Dlatego stan pusty ma przyciski — i dlatego akurat tam martwy przycisk
     * kosztuje najwięcej: trafia w kogoś, kto jeszcze nie wie, czy zostanie.
     *
     * `scripts/martwe-przyciski.mjs` chodzi po bazie z `DemoSeeder`, w której
     * konto pomiarowe ma wpisy, obserwowanych i pełny zeszyt. Stanu pustego
     * ten skrypt nie widzi ani razu.
     */
    public function test_puste_stany_nowego_konta_nie_maja_martwego_przycisku(): void
    {
        $nowy = $this->user('nowaosoba', ['display_name' => 'Nowa Osoba']);
        $this->actingAs($nowy);

        foreach ([
            '/home', '/odkryj', '/dodaj', '/dodaj/zdjecie', '/dodaj/przepis',
            '/dodaj/przepis/jedna-strona', '/zeszyt', '/powiadomienia', '/zgloszenia',
            '/ustawienia', '/ustawienia/profil', '/ustawienia/zdjecie', '/ustawienia/tagi',
            '/ustawienia/czytelnosc', '/ustawienia/prywatnosc', '/ustawienia/twoje-dane',
            '/ustawienia/bezpieczenstwo', '/ustawienia/e-mail', '/ustawienia/2fa',
            '/ustawienia/2fa/wlacz',
            '/witaj/zainteresowania', '/witaj/ludzie', '/witaj/gotowe',
            '/@nowaosoba', '/@nowaosoba/obserwowani', '/@nowaosoba/obserwujacy',
            '/szukaj?q=czegotunieznajdziesz',
        ] as $adres) {
            $this->obejdzEkran($adres, 'puste konto');
        }

        $this->zakonczObchod(minEkranow: 22, minOdnosnikow: 400, minFormularzy: 100, minPrzyciskow: 100);
    }

    // ------------------------------------------------------------------
    //  Konto z treścią — i ten sam serwis oczami gościa
    // ------------------------------------------------------------------

    public function test_ekrany_konta_z_trescia_nie_maja_martwego_przycisku(): void
    {
        $ja = $this->user('gotujaca', ['display_name' => 'Gotująca Grażyna']);
        $ktosInny = $this->user('sasiadka', ['display_name' => 'Sąsiadka Zofia']);

        $przepis = $this->przepisZKrokami($ja);
        $cudzyPrzepis = $this->przepisZKrokami($ktosInny);
        $wpis = Post::factory()->create(['author_id' => $ja->getKey()]);
        $cudzyWpis = Post::factory()->create(['author_id' => $ktosInny->getKey()]);

        $mojeWykonanie = CookedEvent::factory()->create([
            'user_id' => $ja->getKey(),
            'recipe_id' => $cudzyPrzepis->getKey(),
        ]);
        $cudzeWykonanieMojegoPrzepisu = CookedEvent::factory()->create([
            'user_id' => $ktosInny->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $ja->getKey(),
            'name' => 'Mój zeszyt',
            'visibility' => 'private',
            'is_default' => true,
        ]);
        $zeszyt->recipes()->attach($cudzyPrzepis->getKey(), ['created_at' => now()]);

        // Zeszyt pusty OBOK zeszytu pełnego — to dwa różne ekrany z dwoma
        // różnymi zestawami przycisków, a nie jeden ekran w dwóch stanach.
        $pustyZeszyt = Collection::create([
            'owner_id' => $ja->getKey(),
            'name' => 'Na później',
            'visibility' => 'private',
            'is_default' => false,
        ]);

        Notification::create([
            'user_id' => $ja->getKey(),
            'actor_id' => $ktosInny->getKey(),
            'type' => Notification::TYPE_COOKED,
        ]);

        $zgloszenie = Report::create([
            'reporter_id' => $ja->getKey(),
            'target_type' => 'post',
            'target_id' => $cudzyWpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        // Komentarz, odpowiedź na komentarz i komentarz pod cudzym wpisem —
        // pasek akcji pod każdym z nich pokazuje co innego komu innemu.
        $mojKomentarz = Comment::factory()->create([
            'author_id' => $ja->getKey(),
            'post_id' => $cudzyWpis->getKey(),
        ]);
        Comment::factory()->create([
            'author_id' => $ktosInny->getKey(),
            'post_id' => $cudzyWpis->getKey(),
            'parent_id' => $mojKomentarz->getKey(),
        ]);
        Comment::factory()->create([
            'author_id' => $ktosInny->getKey(),
            'post_id' => $wpis->getKey(),
        ]);

        $this->actingAs($ja);

        foreach ([
            '/home', '/zeszyt', '/zeszyt/'.$zeszyt->getKey(), '/zeszyt/'.$pustyZeszyt->getKey(),
            '/powiadomienia', '/zgloszenia', '/zgloszenia/'.$zgloszenie->getKey(),
            '/@gotujaca', '/@sasiadka', '/@gotujaca/obserwowani', '/@gotujaca/obserwujacy',
            '/wpisy/'.$wpis->getKey(), '/wpisy/'.$wpis->getKey().'/edycja',
            '/wpisy/'.$wpis->getKey().'/zdjecia',
            '/wpisy/'.$cudzyWpis->getKey(),
            '/przepisy/'.$przepis->slug, '/przepisy/'.$przepis->slug.'/edycja',
            '/przepisy/'.$przepis->slug.'/szczegoly',
            '/przepisy/'.$cudzyPrzepis->slug,
            '/przepisy/'.$cudzyPrzepis->slug.'/gotuj',
            '/przepisy/'.$cudzyPrzepis->slug.'/ugotowalem',
            '/ugotowane/'.$mojeWykonanie->getKey(),
            '/ugotowane/'.$cudzeWykonanieMojegoPrzepisu->getKey().'/wyszlo',
            '/zglos/post/'.$cudzyWpis->getKey(),
            '/zglos/recipe/'.$cudzyPrzepis->slug,
            '/zglos/user/sasiadka',
            '/szukaj?q=a',
        ] as $adres) {
            $this->obejdzEkran($adres, 'konto z treścią');
        }

        $this->app['auth']->logout();

        /*
         * TEN SAM SERWIS OCZAMI GOŚCIA.
         *
         * Ekran, który zalogowanemu pokazuje komplet przycisków, gościowi
         * pokazuje ich PODZBIÓR — i to w tym podzbiorze najłatwiej zostaje
         * przycisk prowadzący tam, gdzie gościa nie wpuszczą. Warunek
         * `@auth` wokół całego paska łatwo przeoczyć przy jednej pozycji.
         */
        foreach ([
            '/@gotujaca', '/@gotujaca/obserwowani', '/@gotujaca/obserwujacy',
            '/wpisy/'.$wpis->getKey(),
            '/przepisy/'.$przepis->slug,
            '/przepisy/'.$przepis->slug.'/gotuj',
            '/ugotowane/'.$mojeWykonanie->getKey(),
        ] as $adres) {
            $this->obejdzEkran($adres, 'gość na ekranie zalogowanego');
        }

        $this->zakonczObchod(minEkranow: 28, minOdnosnikow: 450, minFormularzy: 130, minPrzyciskow: 135);
    }

    // ------------------------------------------------------------------
    //  Moderacja i administracja
    // ------------------------------------------------------------------

    public function test_ekrany_moderacji_nie_maja_martwego_przycisku(): void
    {
        $moderator = $this->admin();
        $autor = $this->user('autorka', ['display_name' => 'Autorka Halina']);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $zgloszenie = Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => 'hide',
            'reason_code' => 'spam_link',
            'note' => 'Link reklamowy.',
            'user_message' => 'Ukryliśmy Twój wpis.',
        ]);

        Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => 'author',
            'body' => 'To nie była reklama, tylko przepis mojej babci.',
            'status' => 'open',
        ]);

        $wiadomosc = ContactMessage::factory()->create();

        $this->actingAs($moderator);

        foreach ([
            '/admin/zgloszenia', '/admin/sygnaly', '/admin/wiadomosci',
            '/admin/wiadomosci/'.$wiadomosc->getKey(),
            '/admin/odwolania', '/admin/bez-odpowiedzi', '/admin/kolaz-powitalny',
            '/admin/kuking-na-dzis', '/admin/tagi-promowane', '/admin/uzytkownicy',
            '/admin/uzytkownicy?szukaj=halina', '/admin/uzytkownicy?status=active',
            '/admin/uzytkownicy/'.$autor->getKey(),
            '/home',
        ] as $adres) {
            $this->obejdzEkran($adres, 'moderator');
        }

        $this->zakonczObchod(minEkranow: 12, minOdnosnikow: 280, minFormularzy: 55, minPrzyciskow: 55);
    }

    // ------------------------------------------------------------------
    //  Ekrany procesu
    // ------------------------------------------------------------------

    /**
     * EKRANY PROCESU — zaproszenie, odzyskiwanie hasła, eksport danych,
     * usuwanie konta, odwołanie, konto zawieszone. Wchodzi się na nie raz,
     * w chwili, w której człowiek nie ma nastroju na szukanie obejścia.
     */
    public function test_ekrany_procesu_nie_maja_martwego_przycisku(): void
    {
        $osoba = $this->user('wprocesie', ['display_name' => 'Osoba W Procesie']);

        config([
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);

        $tokenZaproszenia = RegistrationInvite::nowyToken();
        $zaproszenie = new RegistrationInvite;
        $zaproszenie->email = 'zapraszana@przyklad.test';
        $zaproszenie->token_hash = RegistrationInvite::skrot($tokenZaproszenia);
        $zaproszenie->created_at = now();
        $zaproszenie->expires_at = now()->addHours(24);
        $zaproszenie->save();

        foreach ([
            '/zaproszenie/'.$tokenZaproszenia,
            // Zaproszenie, którego nie ma — ekran „ten link już nie działa"
            // ma własny komplet przycisków i własną szansę na martwy.
            '/zaproszenie/'.RegistrationInvite::nowyToken(),
            '/nowe-haslo/jakis-token-resetu',
            '/logowanie/kod',
        ] as $adres) {
            $this->obejdzEkran($adres, 'gość w procesie', [200, 302]);
        }

        // PACZKA Z DANYMI W KAŻDYM ZE STANÓW — każdy pokazuje inne przyciski.
        $this->actingAs($osoba);

        foreach ([
            DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING, DataExport::STATUS_FAILED,
        ] as $stan) {
            DataExport::query()->where('user_id', $osoba->getKey())->delete();
            DataExport::create([
                'user_id' => $osoba->getKey(),
                'status' => $stan,
                'failure_reason' => $stan === DataExport::STATUS_FAILED ? DataExport::REASON_TIMEOUT : null,
            ]);

            $this->obejdzEkran('/ustawienia/twoje-dane', "eksport danych: {$stan}");
        }

        $this->obejdzEkran('/ustawienia/2fa/kody-zapasowe', 'proces 2FA', [200, 302]);

        // ODWOŁANIE OD DECYZJI MODERACYJNEJ — ekran, na który człowiek trafia
        // z listu, w najgorszym możliwym nastroju.
        $moderator = $this->admin();
        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'user',
            'target_id' => $osoba->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'action' => 'warn',
            'reason_code' => 'spam_link',
            'note' => 'Ostrzeżenie.',
            'user_message' => 'Prosimy o niewstawianie linków reklamowych.',
        ]);

        $this->actingAs($osoba);
        $this->obejdzEkran('/odwolanie/'.$decyzja->getKey(), 'odwołanie od decyzji');

        // KONTO ZGŁOSZONE DO USUNIĘCIA — ekran ma wtedy jeden przycisk,
        // który musi zadziałać: „nie usuwaj jednak".
        $osoba->markForDeletion();

        foreach (['/ustawienia/twoje-dane', '/ustawienia'] as $adres) {
            $this->obejdzEkran($adres, 'konto w trakcie usuwania', [200, 302]);
        }

        $this->app['auth']->logout();

        // KONTO ZAWIESZONE. Dla tej osoby prawie każdy przycisk serwisu
        // kończy się odmową — i właśnie dlatego trzeba obejść to, co widzi.
        $zawieszona = $this->user('zawieszona', ['display_name' => 'Zawieszona Osoba']);
        $zawieszona->suspend(now()->addDays(3));

        $this->actingAs($zawieszona->refresh());

        foreach (['/home', '/ustawienia', '/odwolanie'] as $adres) {
            $this->obejdzEkran($adres, 'konto zawieszone', [200, 302, 403]);
        }

        $this->zakonczObchod(minEkranow: 12, minOdnosnikow: 140, minFormularzy: 32, minPrzyciskow: 35);
    }

    // ------------------------------------------------------------------
    //  Ekrany, do których nie prowadzi żaden odnośnik
    // ------------------------------------------------------------------

    /**
     * EKRANY Z PODPISEM APLIKACJI — te, do których w serwisie NIE PROWADZI
     * ANI JEDEN ODNOŚNIK, bo adres przychodzi listem.
     *
     * Automat chodzący po odnośnikach nie ma jak na nie trafić: bez podpisu
     * dostaje 403 i słusznie zapisuje to jako odmowę zamierzoną. Martwy
     * przycisk stoi tam więc najbezpieczniej w całym serwisie.
     */
    public function test_ekrany_z_podpisem_w_liscie_nie_maja_martwego_przycisku(): void
    {
        $osoba = $this->user('zlistu', ['display_name' => 'Osoba Z Listu']);

        foreach ([
            URL::signedRoute('podsumowanie.wypisz', ['user' => $osoba->getKey()]),
            URL::signedRoute('podsumowanie.wracam', ['user' => $osoba->getKey()]),
        ] as $adres) {
            $this->obejdzEkran($adres, 'gość z podpisem w liście');
        }

        // POTWIERDZENIE ADRESU — konto jeszcze niepotwierdzone, bo tylko
        // wtedy ten ekran w ogóle się pokazuje.
        $niepotwierdzona = $this->user('niepotwierdzona', ['display_name' => 'Niepotwierdzona Osoba']);
        $niepotwierdzona->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($niepotwierdzona->refresh());
        $this->obejdzEkran('/potwierdz-email', 'konto bez potwierdzonego adresu');

        $this->zakonczObchod(minEkranow: 3, minOdnosnikow: 28, minFormularzy: 7, minPrzyciskow: 8);
    }

    // ------------------------------------------------------------------
    //  Stany treści
    // ------------------------------------------------------------------

    /**
     * STANY TREŚCI — szkic, wpis ukryty przez moderację, wpis tylko dla
     * obserwujących, blokada między dwiema osobami, konto zbanowane.
     *
     * `scripts/martwe-przyciski.mjs` chodzi po bazie demonstracyjnej, w której
     * tych stanów nie ma ani jednego. To właśnie tu wyszedł jedyny martwy
     * przycisk znaleziony przy zakładaniu tego obchodu: karta SZKICU
     * renderowała `<a href="…"><time datetime=""></time></a>`, czyli odnośnik
     * bez jednej litery napisu, bo `published_at` szkicu jest `null`.
     */
    public function test_stany_tresci_nie_maja_martwego_przycisku(): void
    {
        $autor = $this->user('autorstanow', ['display_name' => 'Autor Stanów']);
        $ogladajaca = $this->user('ogladajaca', ['display_name' => 'Oglądająca Osoba']);
        $zablokowana = $this->user('zablokowana', ['display_name' => 'Zablokowana Osoba']);

        $szkicWpisu = Post::factory()->draft()->create(['author_id' => $autor->getKey()]);
        $ukrytyWpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Post::STATUS_HIDDEN,
        ]);
        $tylkoObserwujacy = Post::factory()->followersOnly()->create(['author_id' => $autor->getKey()]);
        $szkicPrzepisu = Recipe::factory()->draft()->create(['author_id' => $autor->getKey()]);

        Block::create([
            'blocker_id' => $ogladajaca->getKey(),
            'blocked_id' => $zablokowana->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($autor);

        foreach ([
            '/wpisy/'.$szkicWpisu->getKey(),
            '/wpisy/'.$szkicWpisu->getKey().'/edycja',
            '/wpisy/'.$ukrytyWpis->getKey(),
            '/wpisy/'.$tylkoObserwujacy->getKey(),
            '/przepisy/'.$szkicPrzepisu->slug,
            '/przepisy/'.$szkicPrzepisu->slug.'/edycja',
            '/@autorstanow',
        ] as $adres) {
            $this->obejdzEkran($adres, 'autor w stanach treści', [200, 302]);
        }

        $this->app['auth']->logout();

        // Osoba, która kogoś zablokowała — jej ekran profilu tamtej osoby
        // pokazuje inny zestaw przycisków niż komukolwiek innemu.
        $this->actingAs($ogladajaca);

        foreach (['/@zablokowana', '/@ogladajaca', '/ustawienia/prywatnosc'] as $adres) {
            $this->obejdzEkran($adres, 'osoba po blokadzie', [200, 403]);
        }

        $this->app['auth']->logout();

        $zbanowany = $this->user('zbanowany', ['display_name' => 'Zbanowane Konto']);
        $zbanowany->ban();

        $this->obejdzEkran('/@zbanowany', 'gość na profilu zbanowanego', [200, 403, 404]);

        $this->zakonczObchod(minEkranow: 9, minOdnosnikow: 160, minFormularzy: 50, minPrzyciskow: 50);
    }

    // ------------------------------------------------------------------
    //  Ekrany błędu
    // ------------------------------------------------------------------

    /**
     * EKRANY BŁĘDU. Trafia na nie ktoś już zirytowany, więc przycisk „Strona
     * główna", który sam kończy się błędem, jest tu najdroższy w serwisie.
     *
     * `scripts/martwe-przyciski.mjs` widzi z nich tylko 404 i tylko przy
     * okazji — 403, 419, 429, 500 i 503 nie stoją pod żadnym adresem, na
     * który dałoby się wejść odnośnikiem.
     */
    public function test_ekrany_bledu_nie_maja_martwego_przycisku(): void
    {
        /*
         * TRASA, KTÓRA ODMAWIA — jedyny sposób, żeby zobaczyć ekran 500 albo
         * 503 tak, jak widzi go człowiek, nie psując niczego w aplikacji.
         * `config(['app.debug' => false])`, bo przy włączonym debugowaniu
         * Laravel pokazuje własną stronę wyjątku, a nie nasz ekran.
         */
        foreach ([403, 419, 429, 500, 503] as $kod) {
            Route::get("/obchod-bledu-{$kod}", fn () => abort($kod))->middleware('web');
        }

        config(['app.debug' => false]);

        $this->obejdzEkran('/nie-ma-takiej-strony', 'gość — ekran 404', [404]);

        foreach ([403, 419, 429, 500, 503] as $kod) {
            $this->obejdzEkran("/obchod-bledu-{$kod}", "gość — ekran {$kod}", [$kod]);
        }

        // TEN SAM EKRAN BŁĘDU ZALOGOWANEJ OSOBIE pokazuje inne przyciski
        // („Strona główna" prowadzi gdzie indziej, „Zaloguj się" znika).
        $zalogowana = $this->user('nabledzie', ['display_name' => 'Osoba Na Błędzie']);
        $this->actingAs($zalogowana);

        $this->obejdzEkran('/nie-ma-takiej-strony', 'zalogowana — ekran 404', [404]);

        foreach ([403, 419, 429, 500, 503] as $kod) {
            $this->obejdzEkran("/obchod-bledu-{$kod}", "zalogowana — ekran {$kod}", [$kod]);
        }

        $this->zakonczObchod(minEkranow: 12, minOdnosnikow: 80, minFormularzy: 16, minPrzyciskow: 18);
    }
}
