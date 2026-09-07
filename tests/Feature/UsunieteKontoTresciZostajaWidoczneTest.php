<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Domain\Users\Actions\EraseAccountData;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DRUGA POŁOWA D-018, ZMIERZONA NA EKRANIE, A NIE W BAZIE (D-022).
 *
 * D-018 obiecało: „zdjęcia kasujemy wszystkie, tekst zostaje zanonimizowany".
 * Pierwsza połowa działała. Druga nigdy: `EraseAccountData` nie zmieniało
 * `users.status`, więc po anonimizacji konto zostawało `pending_delete` — a na
 * tym statusie stoi `User::jestDostepnyJakoAutor()` i sześć Policy. Tekst
 * zostawał w BAZIE i znikał ze SERWISU.
 *
 * ZMIERZONE PRZED POPRAWKĄ (ten plik, na czerwono — `DB_DATABASE=kuking_test_usuwanie`):
 *   - przepis zanonimizowanego konta: 403 (miało być 200),
 *   - wpis: 403,
 *   - profil: 403,
 *   - przepis w CUDZYM zeszycie: wypadał z listy,
 *   - komentarz pod cudzym wpisem: niewidoczny.
 *
 * DLACZEGO POPRZEDNI TEST TEGO NIE ZŁAPAŁ
 * `UsuwanieKontaKasujeZdjeciaTest::test_tekst_zostaje_ale_bez_nazwiska`
 * asertował WYŁĄCZNIE `assertDatabaseHas('recipes', ...)`. Wiersz w bazie
 * i widoczność na ekranie to dwie różne rzeczy, a obietnica z ekranu dotyczy
 * drugiej. Ten plik pyta wyłącznie o to, co człowiek widzi.
 *
 * KAŻDY TEST MA ASERCJĘ POZYTYWNĄ, NEGATYWNĄ I KONTROLNĄ.
 * Pozytywna: treść zanonimizowanego konta JEST widoczna. Negatywna: nazwisko
 * i nazwa użytkownika tej osoby NIE są. Kontrolna: identyczna treść aktywnej
 * osoby jest widoczna w tej samej odpowiedzi — bez niej „nie widać nazwiska"
 * przechodziłoby także wtedy, gdyby nie było widać niczego.
 */
class UsunieteKontoTresciZostajaWidoczneTest extends TestCase
{
    use RefreshDatabase;

    private const NAZWISKO = 'Barbara Wisniewska';

    /**
     * Konto z kompletem treści, PO wykonanej anonimizacji w zakresie
     * domyślnym (D-022: haczyk nietknięty — teksty zostają).
     *
     * @return array{basia: User, halina: User, przepis: Recipe, przepisKontrolny: Recipe, wpis: Post, wpisKontrolny: Post, cudzyWpis: Post, zeszyt: Collection}
     */
    private function scena(): array
    {
        $basia = $this->user('basia', ['display_name' => self::NAZWISKO]);
        $halina = $this->user('halina', ['display_name' => 'Halina Kontrolna']);
        $ktos = $this->user('ktos', ['display_name' => 'Ktos Trzeci']);

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);
        $przepisKontrolny = Recipe::factory()->for($halina, 'author')->create(['title' => 'Kontrolny bigos Haliny']);

        $wpis = Post::factory()->for($basia, 'author')->create(['body' => 'Dzisiejszy wpis Basi o rosole.']);
        $wpisKontrolny = Post::factory()->for($halina, 'author')->create(['body' => 'Kontrolny wpis Haliny o bigosie.']);

        // Cudzy wątek: komentarz Basi i komentarz kontrolny pod wpisem osoby
        // trzeciej. To jest dokładnie ta „cudza historia", której D-018 nie
        // chciało kasować.
        $cudzyWpis = Post::factory()->for($ktos, 'author')->create(['body' => 'Wpis osoby trzeciej.']);

        Comment::create([
            'author_id' => $basia->getKey(),
            'post_id' => $cudzyWpis->getKey(),
            'body' => 'Komentarz Basi w cudzym watku.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $halina->getKey(),
            'post_id' => $cudzyWpis->getKey(),
            'body' => 'Komentarz kontrolny bez sankcji.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Cudzy zeszyt z przepisem Basi i przepisem kontrolnym.
        $zeszyt = Collection::create([
            'owner_id' => $halina->getKey(),
            'name' => 'Zeszyt Haliny',
            'visibility' => 'public',
        ]);
        $zeszyt->recipes()->attach([$przepis->getKey(), $przepisKontrolny->getKey()]);

        return compact('basia', 'halina', 'przepis', 'przepisKontrolny', 'wpis', 'wpisKontrolny', 'cudzyWpis', 'zeszyt');
    }

    /**
     * Zgłoszenie usunięcia w zakresie domyślnym + egzekucja karencji.
     *
     * Świadomie przez JAWNE metody stanu konta i akcję domenową, nie przez
     * `update()` — `status` jest celowo poza `$fillable` (AGENTS.md §7).
     */
    private function wymaz(User $user): void
    {
        $user->markForDeletion();

        $this->assertTrue(
            (new EraseAccountData)->handle($user->refresh()),
            'Anonimizacja nie wykonała się — reszta testu sprawdzałaby co innego, niż zakłada.',
        );

        $user->refresh();
    }

    public function test_przepis_zanonimizowanego_konta_jest_dalej_widoczny(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        // POZYTYWNA: adres przepisu nadal działa dla gościa.
        $odpowiedz = $this->get(route('recipes.show', $s['przepis']->slug))->assertOk();

        $odpowiedz->assertSee('Rosol babci Zofii');
        // POZYTYWNA: podpis jest, tylko zanonimizowany.
        $odpowiedz->assertSee('Użytkownik usunięty');
        // NEGATYWNA: nic, co nazywa człowieka, nie zostaje.
        $odpowiedz->assertDontSee(self::NAZWISKO);
        $odpowiedz->assertDontSee('basia');

        // KONTROLNA: przepis aktywnej osoby otwiera się w tym samym przebiegu.
        $this->get(route('recipes.show', $s['przepisKontrolny']->slug))
            ->assertOk()
            ->assertSee('Kontrolny bigos Haliny')
            ->assertSee('Halina Kontrolna');
    }

    public function test_wpis_zanonimizowanego_konta_jest_dalej_widoczny(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        $odpowiedz = $this->get(route('posts.show', $s['wpis']))->assertOk();

        $odpowiedz->assertSee('Dzisiejszy wpis Basi o rosole.');
        $odpowiedz->assertSee('Użytkownik usunięty');
        $odpowiedz->assertDontSee(self::NAZWISKO);

        // KONTROLNA.
        $this->get(route('posts.show', $s['wpisKontrolny']))
            ->assertOk()
            ->assertSee('Kontrolny wpis Haliny o bigosie.');
    }

    public function test_komentarz_w_cudzym_watku_zostaje_na_ekranie(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        $odpowiedz = $this->actingAs($s['halina'])
            ->get(route('posts.show', $s['cudzyWpis']))
            ->assertOk();

        // KONTROLNA: identyczny komentarz osoby bez sankcji w tej samej
        // odpowiedzi. Bez tego test przechodziłby, gdyby zniknęły wszystkie.
        $odpowiedz->assertSee('Komentarz kontrolny bez sankcji.');
        // POZYTYWNA.
        $odpowiedz->assertSee('Komentarz Basi w cudzym watku.');
        // NEGATYWNA.
        $odpowiedz->assertDontSee(self::NAZWISKO);
    }

    public function test_przepis_zostaje_w_cudzym_zeszycie(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        $odpowiedz = $this->actingAs($s['halina'])
            ->get(route('collections.show', $s['zeszyt']))
            ->assertOk();

        $odpowiedz->assertSee('Kontrolny bigos Haliny');
        $odpowiedz->assertSee('Rosol babci Zofii');
        $odpowiedz->assertDontSee(self::NAZWISKO);
    }

    public function test_profil_zanonimizowanego_konta_jest_dalej_dostepny(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        $nazwaPoAnonimizacji = $s['basia']->profile->fresh()->username;

        $odpowiedz = $this->get(route('profile.show', $nazwaPoAnonimizacji))->assertOk();

        $odpowiedz->assertSee('Użytkownik usunięty');
        // Zakładka „wszystko" pokazuje wpisy; przepisy mają własną zakładkę.
        $odpowiedz->assertSee('Dzisiejszy wpis Basi o rosole.');
        $odpowiedz->assertDontSee(self::NAZWISKO);

        $this->get(route('profile.show', $nazwaPoAnonimizacji).'?zakladka=przepisy')
            ->assertOk()
            ->assertSee('Rosol babci Zofii')
            ->assertDontSee(self::NAZWISKO);

        // PROFIL MARTWEGO KONTA NIE ZAPRASZA DO NICZEGO.
        // Przycisk „Obserwuj" zawsze kończyłby się tu 403
        // (`UserPolicy::follow()` wymaga konta aktywnego), a zgłaszać
        // i blokować nie ma już kogo.
        $zalogowany = $this->actingAs($s['halina'])
            ->get(route('profile.show', $nazwaPoAnonimizacji))
            ->assertOk();

        $zalogowany->assertSee('To konto zostało usunięte. Nie da się go już obserwować ani zgłosić.');
        $zalogowany->assertDontSee('Obserwuj</button>', false);

        // KONTROLNA: profil aktywnej osoby dalej działa, pokazuje nazwisko
        // i MA przycisk „Obserwuj" — bez tego test wyżej przechodziłby też
        // wtedy, gdyby przycisk zniknął wszystkim.
        $this->actingAs($s['halina'])
            ->get(route('profile.show', 'ktos'))
            ->assertOk()
            ->assertSee('Ktos Trzeci')
            ->assertSee('Obserwuj</button>', false);

        // NEGATYWNA, druga: pod STARĄ nazwą użytkownika nie ma już nikogo.
        $this->get('/@basia')->assertNotFound();
    }

    /**
     * Dwa zdania z ekranu, które bez tego testu byłyby tylko obietnicą:
     * „Twoje »Ugotowałem« pod cudzymi przepisami — też podpisane »Użytkownik
     * usunięty«" oraz „Twoje publiczne zeszyty — zostanie nazwa zeszytu
     * i lista zapisanych w nim przepisów".
     */
    public function test_wykonanie_i_publiczny_zeszyt_zostaja_widoczne(): void
    {
        $s = $this->scena();

        // „Ugotowałem" Basi pod przepisem Haliny — cudza treść, do której
        // ten sygnał należy w połowie.
        CookedEvent::create([
            'user_id' => $s['basia']->getKey(),
            'recipe_id' => $s['przepisKontrolny']->getKey(),
            'note' => 'Wyszlo swietnie, robie znowu.',
            'cooked_on' => now()->toDateString(),
        ]);

        $wlasnyZeszyt = Collection::create([
            'owner_id' => $s['basia']->getKey(),
            'name' => 'Zeszyt Basi na niedziele',
            'visibility' => 'public',
        ]);
        $wlasnyZeszyt->recipes()->attach($s['przepisKontrolny']->getKey());

        $this->wymaz($s['basia']);

        // WYKONANIE — na stronie przepisu Haliny.
        $strona = $this->get(route('recipes.show', $s['przepisKontrolny']->slug))->assertOk();

        $strona->assertSee('Wyszlo swietnie, robie znowu.');
        $strona->assertSee('Użytkownik usunięty');
        $strona->assertDontSee(self::NAZWISKO);

        // ZESZYT — pod swoim adresem, dla osoby zalogowanej z zewnątrz.
        $this->actingAs($s['halina'])
            ->get(route('collections.show', $wlasnyZeszyt))
            ->assertOk()
            ->assertSee('Zeszyt Basi na niedziele')
            ->assertSee('Kontrolny bigos Haliny')
            ->assertDontSee(self::NAZWISKO);
    }

    // -----------------------------------------------------------------
    // STAN KOŃCOWY: treść widoczna, ale konto martwe.
    //
    // Bez tych trzech testów „naprawa" polegająca na wpuszczeniu
    // `pending_delete` wszędzie też świeciłaby na zielono — a to
    // wskrzesiłoby konto w trakcie karencji i po jej wykonaniu.
    // -----------------------------------------------------------------

    public function test_zanonimizowanego_konta_nie_da_sie_zalogowac(): void
    {
        $s = $this->scena();

        // KONTROLNA: te same dane logowania dla konta aktywnego DZIAŁAJĄ,
        // więc test nie przechodzi tylko dlatego, że hasło jest złe.
        $this->post(route('login'), [
            'login' => 'halina',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($s['halina']);
        $this->post(route('logout'));

        $this->wymaz($s['basia']);

        // Hasło jest po anonimizacji losowe, więc samo „złe hasło" nic nie
        // dowodzi. Logujemy się PRZEZ WYMUSZENIE stanu: gdyby granica
        // statusu nie istniała, `Auth::login()` wpuściłoby to konto do
        // serwisu, bo żaden inny warunek go nie odrzuca.
        $odpowiedz = $this->actingAs($s['basia'])
            ->get(route('home'))
            ->assertRedirect(route('login'));

        // `assertGuest()` bez argumentu — parametr tej metody to NAZWA
        // STRAŻNIKA, nie komunikat błędu.
        $this->assertGuest();

        // KOMUNIKAT MUSI BYĆ PRAWDĄ. Do konta wymazanego NIE MA drogi
        // powrotu, więc nie wolno go odsyłać na stronę „Cofnij usunięcie
        // konta" — tam czeka je odmowa.
        $odpowiedz->assertSessionHasErrors('login');

        $blad = session('errors')->getBag('default')->first('login');

        $this->assertStringContainsString('nie da się go odzyskać', (string) $blad);
        $this->assertStringNotContainsString(route('account.delete.cancel'), (string) $blad);
    }

    public function test_zanonimizowanego_konta_nie_da_sie_odzyskac(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        try {
            (new CancelAccountDeletion)->handle($s['basia']->refresh());
            $this->fail('Cofnięcie usunięcia zadziałało na koncie, którego dane już nie istnieją.');
        } catch (BladDlaCzlowieka $blad) {
            // Komunikat musi mówić, CO się stało i dokąd pisać — nie „nie ma
            // czego cofać", bo było co cofać, tylko już za późno.
            $this->assertStringContainsString('nie da się już odzyskać', $blad->getMessage());
        }

        $this->assertNotNull($s['basia']->fresh()->data_erased_at);
        $this->assertNotSame(User::STATUS_ACTIVE, $s['basia']->fresh()->status);
    }

    public function test_zanonimizowane_konto_nie_wychodzi_w_wyszukiwarce_osob(): void
    {
        $s = $this->scena();

        $this->wymaz($s['basia']);

        $szukaj = app(SearchQuery::class);

        // KONTROLNA: wyszukiwarka osób w ogóle działa w tym przebiegu.
        $this->assertNotEmpty(
            $szukaj->people('Halina')->all(),
            'Wyszukiwarka osób nie znalazła nawet konta aktywnego.',
        );

        // NEGATYWNA po nazwisku i po nowej, anonimowej nazwie.
        $this->assertEmpty($szukaj->people('Barbara')->all());
        $this->assertEmpty($szukaj->people('usuniety')->all());
    }

    public function test_zanonimizowane_konto_nie_stoi_na_liscie_obserwujacych(): void
    {
        $s = $this->scena();

        // Basia i Halina obserwują osobę trzecią; po wymazaniu Basi zostaje
        // na liście tylko Halina — i licznik musi to samo powiedzieć.
        $ktos = User::whereKey($s['cudzyWpis']->author_id)->firstOrFail();
        $s['basia']->following()->attach($ktos->getKey(), ['created_at' => now()]);
        $s['halina']->following()->attach($ktos->getKey(), ['created_at' => now()]);

        $this->wymaz($s['basia']);

        $odpowiedz = $this->actingAs($s['halina'])
            ->get(route('social.followers', 'ktos'))
            ->assertOk();

        // KONTROLNA + POZYTYWNA.
        $odpowiedz->assertSee('Halina Kontrolna');
        // NEGATYWNA: karta martwego konta nie stoi na liście osób.
        $odpowiedz->assertDontSee('Użytkownik usunięty');
        $odpowiedz->assertDontSee(self::NAZWISKO);
    }
}
