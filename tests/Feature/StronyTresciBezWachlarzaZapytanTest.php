<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Strony pojedynczych treści i listy osób — czy liczba zapytań ROŚNIE
 * z liczbą wierszy.
 *
 * DRUGA POŁOWA TEGO, CZEGO NIKT NIE ZMIERZYŁ (pierwsza:
 * `KolejkiModeracjiBezWachlarzaZapytanTest`). Ekrany tutaj mają wspólną
 * cechę: rosną nie od liczby WPISÓW, tylko od liczby rozmów i ludzi pod
 * jedną treścią, więc żaden pomiar strumienia ich nie obejmuje.
 *
 * CZEGO TU ŚWIADOMIE NIE MA, ŻEBY NIE DUBLOWAĆ ISTNIEJĄCYCH TESTÓW:
 *
 *  - miniatur w strumieniach, na stronie tagu, profilu i w zeszycie —
 *    `MiniaturyBezWachlarzaZapytanTest`;
 *  - prawej szyny profilu, `/zeszyt` i pojedynczego zeszytu —
 *    `SzynaBezWachlarzaZapytanTest`;
 *  - licznika „ile osób zapisało" na `/odkryj` —
 *    `LicznikZapisowBezWachlarzaZapytanTest`;
 *  - spisu tematów — `SpisTematowTest`;
 *  - listy kont w panelu — `PanelUzytkownicyTest`;
 *  - liczników kolejek w menu moderacji — `LicznikiKolejekBezZapytanTest`;
 *  - podsumowania tygodnia — `PodsumowanieBezWachlarzaZapytanTest`.
 *
 * LICZBA WIERSZY JEST DOBRANA DO STRONY, NIE DO OKRĄGŁOŚCI. Komentarze idą
 * po `config('kuking.comments.page_size')` (dziś 12), wykonania po 12,
 * listy osób po 20 — dosypanie ponad te liczby mierzyłoby wzrost, którego
 * ekran i tak nie pokazuje, i test wyglądałby na mocniejszy, niż jest.
 *
 * ZMIERZONE (małe → duże, wszystkie płaskie):
 * `/wpisy/{wpis}` 12 → 12, `/przepisy/{slug}` 18 → 18, `/ugotowane/{id}`
 * 14 → 14, `/@kto/obserwujacy` 6 → 6 dla gościa.
 */
class StronyTresciBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private const MALO = 2;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    /** Pełna strona komentarzy — z konfiguracji, nie z liczby wpisanej na sztywno. */
    private function pelnaStronaKomentarzy(): int
    {
        return (int) config('kuking.comments.page_size');
    }

    public function test_strona_wpisu_nie_ma_wachlarza_zapytan_na_komentarze(): void
    {
        $autor = $this->user('autorka_wpisu');
        $wpis = Post::factory()->for($autor, 'author')->create(['body' => 'Niedzielny rosół.']);
        $wpis->media()->attach(
            Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
            ['position' => 0],
        );

        $this->komentarzeWpisu($wpis, self::MALO);
        $malo = $this->policzZapytania(fn () => $this->get(route('posts.show', $wpis))->assertOk());

        $this->komentarzeWpisu($wpis, $this->pelnaStronaKomentarzy() - self::MALO, od: self::MALO);

        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): płaska liczba zapytań
        // przechodzi także wtedy, gdy komentarzy w ogóle nie widać — a wtedy
        // pomiar dotyczy pustej strony, nie rozmowy pod wpisem.
        $this->assertStringContainsString(
            'Komentarz numer '.($this->pelnaStronaKomentarzy() - 1),
            $html,
            'Na stronie wpisu nie ma ostatniego komentarza z pełnej strony — pomiar nie mierzy rozmowy.',
        );

        $duzo = $this->policzZapytania(fn () => $this->get(route('posts.show', $wpis))->assertOk());

        $this->assertSame($malo, $duzo, $this->komunikat('/wpisy/{wpis}', $malo, $duzo, $this->pelnaStronaKomentarzy()));
    }

    /**
     * Strona przepisu — DWIE listy naraz, każda z własną paginacją.
     *
     * Komentarze i „Komu wyszło" żyją na tym samym ekranie i rosną
     * niezależnie od siebie (`RecipeController::show()` paginuje je osobnymi
     * parametrami `komentarze` i `wykonania`). Rosną więc w tym teście obie
     * — wachlarz na jednej z nich schowałby się za płaską drugą, gdyby
     * mierzyć je po kolei.
     */
    public function test_strona_przepisu_nie_ma_wachlarza_zapytan_na_komentarze_i_wykonania(): void
    {
        $autor = $this->user('autor_przepisu');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Rosół babci Wandy',
            'hero_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
        ]);

        $this->komentarzeIWykonania($przepis, self::MALO);
        $malo = $this->policzZapytania(fn () => $this->get(route('recipes.show', $przepis->slug))->assertOk());

        // 12 to pełna strona obu list (`config('kuking.comments.page_size')`
        // i limit galerii wykonań w `RecipeController::show()`).
        $this->komentarzeIWykonania($przepis, 12 - self::MALO, od: self::MALO);

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        // Kontrola dodatnia dla OBU list — pusta może być jedna z nich,
        // a wtedy pomiar dotyczy połowy ekranu.
        $this->assertStringContainsString('Komentarz numer 11', $html, 'Brak komentarzy na stronie przepisu.');
        $this->assertStringContainsString('Wyszło numer 11', $html, 'Brak wykonań w galerii „Komu wyszło”.');

        $duzo = $this->policzZapytania(fn () => $this->get(route('recipes.show', $przepis->slug))->assertOk());

        $this->assertSame($malo, $duzo, $this->komunikat('/przepisy/{slug}', $malo, $duzo, 12));
    }

    public function test_strona_wykonania_nie_ma_wachlarza_zapytan_na_komentarze(): void
    {
        $autor = $this->user('autor_do_wykonania');
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $gotujacy = $this->user('gotujaca');

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $gotujacy->getKey(),
            'note' => 'Wyszło dokładnie tak jak na zdjęciu.',
        ]);

        $this->komentarzeWykonania($wykonanie, self::MALO);
        $malo = $this->policzZapytania(fn () => $this->get(route('cooked.show', $wykonanie))->assertOk());

        // Od issue #938 ten ekran paginuje komentarze jak wpis i przepis,
        // więc „dużo" to pełna strona. Brak N+1 na dłuższej rozmowie
        // pilnuje `KomentarzeWykonaniaStronamiTest`.
        $pelna = $this->pelnaStronaKomentarzy();
        $this->komentarzeWykonania($wykonanie, $pelna - self::MALO, od: self::MALO);

        $html = $this->get(route('cooked.show', $wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Komentarz numer '.($pelna - 1).' ',
            $html,
            'Na stronie wykonania nie ma ostatniego komentarza — pomiar nie mierzy rozmowy.',
        );

        $duzo = $this->policzZapytania(fn () => $this->get(route('cooked.show', $wykonanie))->assertOk());

        $this->assertSame($malo, $duzo, $this->komunikat('/ugotowane/{id}', $malo, $duzo, $pelna));
    }

    /**
     * Lista obserwujących — OGLĄDANA PRZEZ ZALOGOWANEGO, i to jest cały sens
     * tego testu.
     *
     * `SocialController::connections()` opisuje usterkę, którą kiedyś zdjęto:
     * filtr blokad szedł po jednym `SELECT EXISTS` na osobę, a widok pytał
     * jeszcze `isFollowing()` per wiersz — „razem około czterdziestu"
     * zapytań na stronę. Obie te rzeczy dotyczą WYŁĄCZNIE widza, który jest
     * zalogowany: dla gościa cała gałąź `when($viewer !== null, …)` się nie
     * wykonuje. Pomiar na gościu przeszedłby więc nad usterką bez jednego
     * czerwonego przebiegu.
     */
    public function test_lista_obserwujacych_nie_ma_wachlarza_zapytan_dla_zalogowanego_widza(): void
    {
        $gospodarz = $this->user('gospodyni', ['display_name' => 'Gospodyni domu']);
        $widz = $this->user('ogladajaca');

        $this->obserwujacy($gospodarz, self::MALO);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('social.followers', 'gospodyni'))->assertOk(),
        );

        // 20 to pełna strona tej listy (`paginate(20)` w kontrolerze).
        $this->obserwujacy($gospodarz, 20 - self::MALO, od: self::MALO);

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodyni'))->assertOk()->getContent();
        $this->assertStringContainsString(
            'Obserwująca numer 19',
            $html,
            'Lista nie pokazuje ostatniej z dwudziestu osób — pomiar nie mierzy pełnej strony.',
        );

        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('social.followers', 'gospodyni'))->assertOk(),
        );

        $this->assertSame($malo, $duzo, $this->komunikat('/@kto/obserwujacy', $malo, $duzo, 20));
    }

    // -----------------------------------------------------------------
    // Dane
    // -----------------------------------------------------------------

    /** Komentarze pod wpisem — każdy od INNEJ osoby, ze zdjęciem profilowym. */
    private function komentarzeWpisu(Post $wpis, int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            Comment::factory()->create([
                'post_id' => $wpis->getKey(),
                'author_id' => $this->osobaZeZdjeciem('Komentująca numer '.$i)->getKey(),
                'body' => 'Komentarz numer '.$i.' — wygląda pysznie.',
            ]);
        }
    }

    /** Komentarze pod wykonaniem — każdy od innej osoby, ze zdjęciem profilowym. */
    private function komentarzeWykonania(CookedEvent $wykonanie, int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            Comment::factory()->create([
                'post_id' => null,
                'cooked_event_id' => $wykonanie->getKey(),
                'author_id' => $this->osobaZeZdjeciem('Komentująca numer '.$i)->getKey(),
                'body' => 'Komentarz numer '.$i.' — gratulacje.',
            ]);
        }
    }

    /** Komentarze i wykonania pod przepisem — każde od innej osoby, ze zdjęciem. */
    private function komentarzeIWykonania(Recipe $przepis, int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $osoba = $this->osobaZeZdjeciem('Gotująca numer '.$i);

            Comment::factory()->create([
                'post_id' => null,
                'recipe_id' => $przepis->getKey(),
                'author_id' => $osoba->getKey(),
                'body' => 'Komentarz numer '.$i.' — robiłam wczoraj.',
            ]);

            $wykonanie = CookedEvent::factory()->create([
                'recipe_id' => $przepis->getKey(),
                'user_id' => $osoba->getKey(),
                'note' => 'Wyszło numer '.$i.' — cała rodzina zjadła.',
            ]);
            $wykonanie->media()->attach(
                Media::factory()->create(['owner_id' => $osoba->getKey()])->getKey(),
                ['position' => 0],
            );
        }
    }

    /** Osoby obserwujące gospodarza — każda z własnym zdjęciem profilowym. */
    private function obserwujacy(User $gospodarz, int $ile, int $od = 0): void
    {
        for ($i = $od; $i < $od + $ile; $i++) {
            $this->osobaZeZdjeciem('Obserwująca numer '.$i)
                ->following()
                ->attach($gospodarz->getKey(), ['created_at' => now()->subMinutes($i)]);
        }
    }

    /**
     * Konto ze zdjęciem profilowym.
     *
     * Zdjęcie nie jest ozdobą danych: bez niego `zdjecieDoPokazania()`
     * kończy się na pustym `avatar_media_id` i warstwa wachlarza sięgająca
     * po wiersz `media` nie ma jak się pokazać — test mierzyłby wtedy
     * połowę tego, o czym mówi.
     */
    private function osobaZeZdjeciem(string $nazwa): User
    {
        $osoba = $this->user(null, ['display_name' => $nazwa]);

        Profile::query()->where('user_id', $osoba->getKey())->update([
            'avatar_media_id' => Media::factory()->create(['owner_id' => $osoba->getKey()])->getKey(),
        ]);

        return $osoba;
    }

    private function komunikat(string $ekran, int $malo, int $duzo, int $duzoWierszy): string
    {
        return "Liczba zapytań rośnie z liczbą wierszy (N+1) na {$ekran}: {$malo} przy "
            .self::MALO." wierszach, {$duzo} przy {$duzoWierszy}.";
    }
}
