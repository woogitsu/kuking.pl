<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ZAKRES USUNIĘCIA KONTA WYBIERA CZŁOWIEK (decyzja D-022).
 *
 * „Może dorzucić opcję, żeby użytkownik wybrał, czy chce wszystko usunąć?
 * Domyślnie zostawia przepisy itp. odhaczone, tylko te minimum i niech sobie
 * wybierze" — właściciel, 7 września 2026.
 *
 * DWIE RZECZY, KTÓRE TEN PLIK PILNUJE, I ŻADNA Z NICH NIE JEST KOSMETYCZNA:
 *
 *  1. DOMYŚLNA WARTOŚĆ. Haczyk odhaczony musi znaczyć `minimum`, bo domyślna
 *     opcja ma być tą, której skutków nie da się cofnąć w mniejszym stopniu.
 *     Gdyby domyślnie kasowało wszystko, jedno nieuważne kliknięcie
 *     zabierałoby człowiekowi cały jego zeszyt — bezpowrotnie.
 *  2. ZAPIS WYBORU PRZY ŻĄDANIU, nie odczyt przy egzekucji. Między jednym
 *     a drugim mija 30 dni; formularza, na którym stawiano haczyk, może już
 *     wtedy nie być w tej formie.
 *
 * Do tego trzecia, wynikająca z AGENTS.md §5 i z tego, że mówimy tu o cudzych
 * przepisach: KAŻDE ZDANIE Z EKRANU MUSI BYĆ PRAWDĄ W KODZIE. Ostatni test
 * w tym pliku wiąże tekst z zachowaniem, bo ta para rozjechała się w tym
 * miejscu już DWA RAZY (D-018, potem D-022).
 */
class UsuwanieKontaZakresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Konto z kompletem treści plus treści osoby trzeciej jako KONTROLA.
     *
     * @return array{basia: User, halina: User, przepis: Recipe, wpis: Post, mojKomentarz: Comment, cudzyKomentarzPodMoimWpisem: Comment, wykonanie: CookedEvent, zeszyt: Collection, przepisHaliny: Recipe}
     */
    private function scena(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Barbara Wisniewska']);
        $halina = $this->user('halina', ['display_name' => 'Halina Kontrolna']);

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);
        $wpis = Post::factory()->for($basia, 'author')->create(['body' => 'Wpis Basi o rosole.']);

        $przepisHaliny = Recipe::factory()->for($halina, 'author')->create(['title' => 'Bigos Haliny']);

        // Komentarz Basi pod CUDZYM przepisem.
        $mojKomentarz = Comment::create([
            'author_id' => $basia->getKey(),
            'recipe_id' => $przepisHaliny->getKey(),
            'body' => 'Komentarz Basi pod przepisem Haliny.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Komentarz HALINY pod wpisem Basi — cena pełnego usunięcia, którą
        // ekran musi powiedzieć wprost.
        $cudzyKomentarzPodMoimWpisem = Comment::create([
            'author_id' => $halina->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz Haliny pod wpisem Basi.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Wykonanie Basi pod przepisem Haliny.
        $wykonanie = CookedEvent::create([
            'user_id' => $basia->getKey(),
            'recipe_id' => $przepisHaliny->getKey(),
            'note' => 'Wyszlo swietnie.',
            'cooked_on' => now()->toDateString(),
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $basia->getKey(),
            'name' => 'Zeszyt Basi',
            'visibility' => 'public',
        ]);
        $zeszyt->recipes()->attach($przepisHaliny->getKey());

        return compact(
            'basia', 'halina', 'przepis', 'wpis', 'mojKomentarz',
            'cudzyKomentarzPodMoimWpisem', 'wykonanie', 'zeszyt', 'przepisHaliny',
        );
    }

    // -----------------------------------------------------------------
    // FORMULARZ: co zapisuje haczyk, a co jego brak
    // -----------------------------------------------------------------

    public function test_formularz_bez_haczyka_zapisuje_zakres_minimum(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('settings.data.delete'), [
                'password' => 'haslo-testowe-123',
                'confirm' => '1',
                // `usun_tresci` NIE JEST WYSŁANE — dokładnie tak wygląda
                // żądanie z przeglądarki, gdy haczyk został nietknięty.
            ])
            ->assertRedirect(route('landing'));

        $basia->refresh();

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->status);
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, $basia->delete_scope);
        $this->assertFalse($basia->chceUsunacTresci());

        // Komunikat po zgłoszeniu mówi, co się stanie Z TEKSTAMI — i mówi to
        // zgodnie z zapisanym zakresem, nie ogólnie.
        $this->assertStringContainsString(
            'zostaną w serwisie bez Twojego nazwiska',
            (string) session('status'),
        );
    }

    public function test_formularz_z_haczykiem_zapisuje_zakres_pelny(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('settings.data.delete'), [
                'password' => 'haslo-testowe-123',
                'confirm' => '1',
                'usun_tresci' => '1',
            ])
            ->assertRedirect(route('landing'));

        $basia->refresh();

        // POZYTYWNA i NEGATYWNA obok siebie: to samo żądanie z haczykiem
        // i bez niego MUSI dać dwie różne wartości w bazie, inaczej cały
        // wybór jest fasadą.
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $basia->delete_scope);
        $this->assertTrue($basia->chceUsunacTresci());

        $this->assertStringContainsString(
            'usuniemy też Twoje przepisy, wpisy, komentarze, wykonania i zeszyty',
            (string) session('status'),
        );
    }

    public function test_zle_haslo_nie_zapisuje_zadnego_zakresu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('settings.data.delete'), [
                'password' => 'nie-to-haslo',
                'confirm' => '1',
                'usun_tresci' => '1',
            ])
            ->assertSessionHasErrors('password');

        $basia->refresh();

        // KONTROLNA: konto zostaje aktywne, więc test nie przechodzi tylko
        // dlatego, że formularz w ogóle nie działa.
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->delete_scope);
    }

    public function test_cofniecie_usuniecia_zeruje_zapisany_zakres(): void
    {
        $basia = $this->user('basia');
        $basia->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $basia->refresh()->delete_scope);

        $basia->cancelDeletion();

        $this->assertSame(User::STATUS_ACTIVE, $basia->refresh()->status);
        $this->assertNull($basia->delete_scope, 'Po cofnięciu na koncie został zakres usunięcia, do którego nic już się nie odnosi.');
    }

    public function test_nieznany_zakres_jest_odrzucany_w_modelu(): void
    {
        $basia = $this->user('basia');

        $this->expectException(\InvalidArgumentException::class);

        $basia->markForDeletion('polowicznie');
    }

    // -----------------------------------------------------------------
    // EGZEKUCJA: co robi każdy z dwóch zakresów
    // -----------------------------------------------------------------

    public function test_zakres_minimum_zostawia_tekst_w_bazie(): void
    {
        $s = $this->scena();

        $s['basia']->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        $this->assertTrue((new EraseAccountData)->handle($s['basia']->refresh()));

        // POZYTYWNA: wszystko, co jest tekstem, zostaje.
        $this->assertDatabaseHas('recipes', ['id' => $s['przepis']->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('posts', ['id' => $s['wpis']->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('comments', ['id' => $s['mojKomentarz']->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('cooked_events', ['id' => $s['wykonanie']->getKey()]);
        $this->assertDatabaseHas('collections', ['id' => $s['zeszyt']->getKey()]);

        // NEGATYWNA: dane osobowe znikają mimo to.
        $this->assertSame('Użytkownik usunięty', $s['basia']->refresh()->profile->display_name);
    }

    public function test_zakres_pelny_kasuje_tresci_na_stale(): void
    {
        $s = $this->scena();

        $s['basia']->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue((new EraseAccountData)->handle($s['basia']->refresh()));

        // POZYTYWNA: wiersze znikają CAŁKOWICIE, nie „miękko". Soft delete
        // zostawiłby tekst w bazie i zamienił tę obietnicę w to samo, czym
        // była przed D-022 — zapis w kolumnie.
        $this->assertDatabaseMissing('recipes', ['id' => $s['przepis']->getKey()]);
        $this->assertDatabaseMissing('posts', ['id' => $s['wpis']->getKey()]);
        $this->assertDatabaseMissing('comments', ['id' => $s['mojKomentarz']->getKey()]);
        $this->assertDatabaseMissing('cooked_events', ['id' => $s['wykonanie']->getKey()]);
        $this->assertDatabaseMissing('collections', ['id' => $s['zeszyt']->getKey()]);

        // CENA, KTÓRĄ EKRAN MÓWI WPROST: cudzy komentarz pod moim wpisem
        // znika razem z wpisem (kaskada `comments.post_id`).
        $this->assertDatabaseMissing('comments', ['id' => $s['cudzyKomentarzPodMoimWpisem']->getKey()]);

        // KONTROLNA: treść osoby trzeciej zostaje nietknięta. Bez tej
        // asercji test przechodziłby także wtedy, gdyby kasowanie sięgnęło
        // za daleko — a to jest tu najgorsza możliwa awaria.
        $this->assertDatabaseHas('recipes', ['id' => $s['przepisHaliny']->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $s['halina']->getKey(), 'status' => User::STATUS_ACTIVE]);

        // Samo konto istnieje dalej, tylko puste i w stanie końcowym —
        // wiersza `users` nie kasujemy, bo `moderation_actions.moderator_id`
        // i `recipe_versions.editor_id` mają `RESTRICT`.
        $this->assertSame(User::STATUS_ERASED, $s['basia']->refresh()->status);
    }

    public function test_zakres_pelny_kasuje_takze_tresc_wczesniej_skasowana_przez_autora(): void
    {
        // Przepis skasowany przez autorkę pół roku wcześniej leży dalej
        // w tabeli z pełnym tekstem — soft delete to ukrycie, nie usunięcie.
        // Bez `withTrashed()` „usuń wszystko" pomijałoby dokładnie te
        // wiersze, o których człowiek jest najbardziej przekonany, że ich
        // już nie ma.
        $basia = $this->user('basia');

        $schowany = Recipe::factory()->for($basia, 'author')->create(['title' => 'Przepis skasowany dawno']);
        $widoczny = Recipe::factory()->for($basia, 'author')->create(['title' => 'Przepis wciaz widoczny']);
        $schowany->delete();

        // KONTROLNA: wiersz NAPRAWDĘ jeszcze tam jest, tylko schowany.
        $this->assertDatabaseHas('recipes', ['id' => $schowany->getKey()]);
        $this->assertNotNull($schowany->fresh()->deleted_at);

        $basia->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue((new EraseAccountData)->handle($basia->refresh()));

        $this->assertDatabaseMissing('recipes', ['id' => $schowany->getKey()]);
        $this->assertDatabaseMissing('recipes', ['id' => $widoczny->getKey()]);
    }

    public function test_egzekutor_czyta_zakres_z_bazy_a_nie_z_zadania(): void
    {
        // Ścieżka produkcyjna w całości: formularz z haczykiem, potem
        // upływ 30 dni, potem komenda z harmonogramu. Między jednym
        // a drugim nie ma już żadnego żądania HTTP — jedyne, co niesie
        // wybór człowieka, to kolumna.
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);

        $this->actingAs($basia)->post(route('settings.data.delete'), [
            'password' => 'haslo-testowe-123',
            'confirm' => '1',
            'usun_tresci' => '1',
        ])->assertRedirect(route('landing'));

        // Cofamy zegar zgłoszenia, żeby karencja minęła.
        DB::table('users')->where('id', $basia->getKey())
            ->update(['delete_requested_at' => now()->subDays(31)]);

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertDatabaseMissing('recipes', ['id' => $przepis->getKey()]);
        $this->assertSame(User::STATUS_ERASED, $basia->refresh()->status);

        // Wybór zostaje w dzienniku audytu — „dlaczego moje przepisy
        // zniknęły" musi mieć odpowiedź bez zgadywania.
        $this->assertDatabaseHas('audit_log', [
            'action' => 'account.data_erased',
            'subject_id' => $basia->getKey(),
        ]);
    }

    // -----------------------------------------------------------------
    // BAZA: niezmienniki, których nie da się złamać z PHP
    // -----------------------------------------------------------------

    public function test_baza_nie_pozwala_udawac_stanu_koncowego(): void
    {
        // Gdyby dało się ustawić `erased` bez faktycznego wymazania danych,
        // ktoś (albo jakiś kod) mógłby „odblokować" widoczność treści konta,
        // którego danych nikt nie tknął. CHECK pilnuje RÓWNOWAŻNOŚCI.
        $basia = $this->user('basia');

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $basia->getKey())
            ->update(['status' => User::STATUS_ERASED]);
    }

    public function test_brak_zapisanego_zakresu_znaczy_zostaw_teksty(): void
    {
        // Konta sprzed tej zmiany oraz każdy wiersz, do którego zakres nie
        // dojechał, MUSZĄ zachowywać się jak `minimum`. To jest jedyna
        // bezpieczna strona pomyłki: zostawiony tekst da się skasować
        // później, skasowanego nie da się przywrócić.
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);

        // Stan „zgłoszone, ale bez zapisanego zakresu" — obchodzimy
        // `markForDeletion()`, bo ono zakres ustawia zawsze; chodzi
        // dokładnie o wiersz, który tą drogą NIE przeszedł.
        DB::table('users')->where('id', $basia->getKey())->update([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now(),
            'delete_scope' => null,
        ]);

        $basia->refresh();

        // KONTROLNA: to naprawdę jest stan bez zakresu.
        $this->assertNull($basia->delete_scope);
        $this->assertFalse($basia->chceUsunacTresci());

        $this->assertTrue((new EraseAccountData)->handle($basia));

        $this->assertDatabaseHas('recipes', ['id' => $przepis->getKey(), 'deleted_at' => null]);
    }

    public function test_baza_nie_przyjmuje_nieznanego_zakresu(): void
    {
        $basia = $this->user('basia');

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $basia->getKey())
            ->update(['delete_scope' => 'polowicznie']);
    }

    // -----------------------------------------------------------------
    // EKRAN OBIECUJE DOKŁADNIE TO, CO KOD ROBI
    // -----------------------------------------------------------------

    /**
     * Ta para — zdanie na ekranie i zachowanie kodu — rozjechała się w tym
     * jednym miejscu DWA RAZY: raz obiecując usunięcie treści, których kod
     * nie kasował (D-018), raz obiecując, że teksty zostaną, choć kod je
     * ukrywał (D-022). Test wiąże je ze sobą.
     */
    public function test_ekran_mowi_o_haczyku_i_o_obu_skutkach(): void
    {
        $widok = (string) file_get_contents(
            resource_path('views/pages/settings/data.blade.php'),
        );

        // Haczyk istnieje, jest DOMYŚLNIE PUSTY i nie jest wymagany.
        $this->assertStringContainsString('name="usun_tresci"', $widok);
        $this->assertStringNotContainsString('name="usun_tresci" value="1" checked', $widok);

        // Obie strony wyboru są nazwane wprost.
        $this->assertStringContainsString(
            'Usuń także moje przepisy, wpisy, komentarze, wykonania i zeszyty',
            $widok,
        );
        $this->assertStringContainsString(
            'Bez tego haczyka Twoje teksty zostaną w serwisie, podpisane',
            $widok,
        );
        $this->assertStringContainsString('Twoje przepisy znikną też z zeszytów innych osób.', $widok);

        // Nie obiecujemy już usunięcia wpisów i przepisów jako rzeczy
        // dziejącej się zawsze (to była usterka D-018).
        $this->assertStringNotContainsString(
            'moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe',
            $widok,
        );
    }

    /**
     * Zdanie „Twoje przepisy znikną też z zeszytów innych osób" — sprawdzone
     * na danych, nie tylko obecne w pliku.
     */
    public function test_pelne_usuniecie_wyjmuje_przepis_z_cudzego_zeszytu(): void
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);
        $przepisHaliny = Recipe::factory()->for($halina, 'author')->create(['title' => 'Bigos Haliny']);

        $zeszytHaliny = Collection::create([
            'owner_id' => $halina->getKey(),
            'name' => 'Zeszyt Haliny',
            'visibility' => 'public',
        ]);
        $zeszytHaliny->recipes()->attach([$przepis->getKey(), $przepisHaliny->getKey()]);

        // KONTROLNA: przed usunięciem oba przepisy są w zeszycie.
        $this->assertSame(2, $zeszytHaliny->recipes()->count());

        $basia->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue((new EraseAccountData)->handle($basia->refresh()));

        $pozostale = $zeszytHaliny->recipes()->pluck('recipes.id')->all();

        $this->assertSame([$przepisHaliny->getKey()], $pozostale);
    }
}
