<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Komentarz a STATUS KONTA jego autora (audyt komentarzy).
 *
 * TA SAMA KLASA BŁĘDU, KTÓRA WRACA W KAŻDEJ FALI: reguła istnieje poprawnie
 * w jednej warstwie, a druga implementuje ją inaczej albo wcale.
 *
 * `UserPolicy::viewProfile()` daje 403 na profilu konta `banned` albo
 * `pending_delete`. `RecipePolicy::view()` zamknęło tę samą dziurę dla
 * przepisów (audyt A5, „obietnica bez pokrycia w drugą stronę"), listy
 * dostały ją w W5-08, feed obserwowanych i powiadomienia — dziś.
 * `Comment::scopeWidoczneDla()` nie miała jej nigdy: liczyła wyłącznie
 * blokadę między dwiema osobami.
 *
 * ZMIERZONE PRZED POPRAWKĄ (ten plik, na czerwono):
 *   - komentarzy widocznych dla czytelnika: 2, powinien być 1;
 *   - strona wpisu pokazywała treść komentarza zbanowanego konta razem
 *     z jego nazwą i awatarem, a link „profil" pod nim dawał 403;
 *   - licznik na karcie wpisu (`withCount(['comments' => …widoczneDla])`)
 *     mówił 2 — czyli dokładnie ta sama usterka co licznik obserwujących
 *     pokazujący 2 zamiast 1.
 *
 * D-022 PRZEPISAŁO WIERSZ DANYCH, NIE WYCISZYŁO TESTU.
 * Zestaw `statusy()` niżej pilnował wcześniej ZAPRZECZENIA obietnicy
 * D-018: że komentarz konta po wykonanej anonimizacji nie wraca nigdy.
 * Rozdzielenie stanu „trwa karencja" od „dane wymazane" jest właśnie tą
 * zmianą, razem z którą ten wiersz musiał się zmienić — patrz komentarz
 * przy dostawcy danych.
 *
 * KAŻDY TEST MA ASERCJĘ KONTROLNĄ. „Nie widać komentarza zbanowanego"
 * przechodzi także wtedy, gdy nie widać NICZEGO — dlatego w tej samej
 * odpowiedzi musi być widoczny identyczny komentarz osoby bez sankcji,
 * a liczby są podane WPROST, nie jako „mniej niż".
 */
class KomentarzeGranicaStatusuAutoraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Granica jest POLITYCZNA (`User::jestDostepnyJakoAutor()`), nie
     * promocyjna: `suspended` ZOSTAJE. Zawieszenie jest karą za pisanie
     * i nie kasuje tego, co ktoś już napisał — a komentarz nie jest treścią
     * polecaną nieznajomym, tylko częścią rozmowy pod treścią, na którą widz
     * już wszedł. Ten wiersz danych jest zabezpieczeniem przed NAPRAWĄ ZBYT
     * SZEROKĄ, czyli sięgnięciem po `Post::scopeTylkoOdAktywnychAutorow`.
     *
     * WIERSZ „konto wymazane" PRZEPISANY ŚWIADOMIE (D-022, weryfikacja W1).
     * Ten zestaw danych miał wcześniej JEDEN wiersz na usuwanie konta —
     * `pending_delete` z oczekiwaniem `false` — i przez to zamroził jako
     * poprawne coś, czego D-018 nigdy nie chciało: że po WYKONANEJ
     * anonimizacji komentarz znika z cudzego wątku na zawsze. Test
     * przechodził, więc nikt tego nie ruszał przez kilkanaście commitów.
     *
     * Teraz są dwa wiersze, bo są dwa różne stany i dwie różne odpowiedzi:
     *
     *  - `pending_delete` — karencja TRWA, komentarz jest schowany. Nadal
     *    `false`, bo to jest poprawne: człowiek poprosił o usunięcie, ma
     *    30 dni na zmianę zdania, a w tym czasie jego treści nie ma na
     *    stronie. Cofnięcie przywraca wszystko.
     *  - `erased` — karencja WYKONANA, dane wymazane. `true`: zanonimizowany
     *    komentarz zostaje w cudzej rozmowie, podpisany „Użytkownik
     *    usunięty". To jest dosłownie treść D-018, dowieziona dopiero teraz.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function statusy(): array
    {
        return [
            'aktywny' => [User::STATUS_ACTIVE, true],
            'zawieszony' => [User::STATUS_SUSPENDED, true],
            'zbanowany' => [User::STATUS_BANNED, false],
            'trwa karencja na usunięcie' => [User::STATUS_PENDING_DELETE, false],
            'konto wymazane' => [User::STATUS_ERASED, true],
        ];
    }

    /**
     * @return array{0: User, 1: Post, 2: User}
     */
    private function wpisZDwomaKomentarzami(string $statusDrugiegoAutora): array
    {
        $autorWpisu = $this->user('autorkawpisu');
        $bezSankcji = $this->user('bezsankcji');
        $zeStatusem = $this->user('zestatusem');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        Comment::create([
            'author_id' => $bezSankcji->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz kontrolny bez sankcji.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $zeStatusem->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz osoby ze statusem.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Status ustawiamy JAWNĄ metodą stanu konta, nie masowym
        // przypisaniem — `status` jest celowo poza `$fillable` (AGENTS.md §7).
        //
        // Stanu `erased` NIE da się (i nie wolno) ustawić wprost: baza
        // wymaga równoważności z `data_erased_at`, a jedyną drogą do niego
        // jest przejście PEŁNEJ ścieżki produkcyjnej — zgłoszenie plus
        // egzekucja karencji. Test, który by tu wstawił sam status, mógłby
        // przechodzić przy zepsutej anonimizacji.
        match ($statusDrugiegoAutora) {
            User::STATUS_ACTIVE => null,
            User::STATUS_SUSPENDED => $zeStatusem->suspend(now()->addWeek()),
            User::STATUS_BANNED => $zeStatusem->ban(),
            User::STATUS_PENDING_DELETE => $zeStatusem->markForDeletion(),
            User::STATUS_ERASED => $this->wymazKonto($zeStatusem),
            default => throw new \LogicException('Nieznany status w teście.'),
        };

        return [$autorWpisu, $wpis, $zeStatusem->refresh()];
    }

    /**
     * Pełna ścieżka do stanu końcowego: zgłoszenie w zakresie domyślnym
     * (`minimum` — teksty zostają) plus egzekucja karencji.
     */
    private function wymazKonto(User $user): void
    {
        $user->markForDeletion(User::DELETE_SCOPE_MINIMUM);

        $this->assertTrue(
            (new EraseAccountData)->handle($user->refresh()),
            'Anonimizacja nie wykonała się — test sprawdzałby inny stan, niż zakłada.',
        );

        $this->assertSame(
            User::STATUS_ERASED,
            $user->refresh()->status,
            'Konto po wykonanej karencji nie jest w stanie końcowym — to była usterka D-022.',
        );
    }

    #[DataProvider('statusy')]
    public function test_strona_wpisu_pokazuje_komentarz_dokladnie_wtedy_co_profil_autora(
        string $status,
        bool $powinnoByc,
    ): void {
        [, $wpis] = $this->wpisZDwomaKomentarzami($status);

        $czytelnik = $this->user('czytelniczka');

        $odpowiedz = $this->actingAs($czytelnik)->get(route('posts.show', $wpis))->assertOk();

        // KONTROLA: identyczny komentarz osoby bez żadnej sankcji MUSI być
        // widoczny w tej samej odpowiedzi. Bez tego test przechodziłby też
        // wtedy, gdyby poprawka wycięła wszystkie komentarze naraz.
        $odpowiedz->assertSee('Komentarz kontrolny bez sankcji');

        if ($powinnoByc) {
            $odpowiedz->assertSee('Komentarz osoby ze statusem');
        } else {
            $odpowiedz->assertDontSee('Komentarz osoby ze statusem');
        }
    }

    #[DataProvider('statusy')]
    public function test_licznik_na_karcie_wpisu_podaje_dokladna_liczbe(
        string $status,
        bool $powinnoByc,
    ): void {
        [, $wpis] = $this->wpisZDwomaKomentarzami($status);

        $czytelnik = $this->user('czytelniczka');

        $wFeedzie = app(DiscoverFeed::class)->paginate($czytelnik)
            ->getCollection()
            ->firstWhere('id', $wpis->getKey());

        $this->assertNotNull(
            $wFeedzie,
            'Wpis zniknął z feedu — test sprawdzałby wtedy co innego, niż zakłada.',
        );

        // KONTROLNA LICZBA, nie „mniej niż". Dokładnie tak wyszła dziś luka
        // w liczniku obserwujących: 2 zamiast 1.
        $this->assertSame(
            $powinnoByc ? 2 : 1,
            (int) $wFeedzie->comments_count,
            'Licznik na karcie wpisu nie zgadza się z liczbą komentarzy, które '
            .'widać po wejściu. Karta jest wtedy oracle\'em istnienia: mówi, '
            .'że pod wpisem KTOŚ jest, choć tej wypowiedzi nie wolno pokazać.',
        );

        // Druga połowa tej samej reguły: licznik musi zgadzać się z tym, co
        // naprawdę wyjdzie z zapytania budującego listę.
        $this->assertSame(
            $wpis->comments()->widoczneDla($czytelnik)->count(),
            (int) $wFeedzie->comments_count,
            'Licznik i lista odpowiadają na to samo pytanie różnie.',
        );
    }

    #[DataProvider('statusy')]
    public function test_gosc_widzi_dokladnie_to_samo_co_zalogowany_czytelnik(
        string $status,
        bool $powinnoByc,
    ): void {
        [, $wpis] = $this->wpisZDwomaKomentarzami($status);

        Auth::logout();

        // Zakres kończył się wcześniej na `return` dla `$widz === null`, więc
        // gość nie przechodził przez ŻADEN filtr. Status konta autora nie jest
        // relacją dwóch osób — obowiązuje też tego, kto nie jest zalogowany.
        $odpowiedz = $this->get(route('posts.show', $wpis))->assertOk();

        $odpowiedz->assertSee('Komentarz kontrolny bez sankcji');

        if ($powinnoByc) {
            $odpowiedz->assertSee('Komentarz osoby ze statusem');
        } else {
            $odpowiedz->assertDontSee('Komentarz osoby ze statusem');
        }
    }

    /**
     * Zagnieżdżenie nie może obchodzić reguły.
     *
     * Odpowiedzi (`Comment::replies()`) to osobna relacja i osobny eager
     * load. Filtr postawiony wyłącznie na komentarzach głównych zostawiłby
     * zbanowane konto widoczne o jeden poziom niżej — pod cudzą wypowiedzią,
     * w tej samej rozmowie.
     */
    public function test_odpowiedz_zbanowanego_konta_nie_wraca_pod_widocznym_rodzicem(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $rozmowca = $this->user('rozmowca');
        $zbanowany = $this->user('zbanowany');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $rodzic = Comment::create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Rodzic watku, ktory zostaje.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $rozmowca->getKey(),
            'post_id' => $wpis->getKey(),
            'parent_id' => $rodzic->getKey(),
            'body' => 'Odpowiedz kontrolna bez sankcji.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $zbanowany->getKey(),
            'post_id' => $wpis->getKey(),
            'parent_id' => $rodzic->getKey(),
            'body' => 'Odpowiedz zbanowanego konta.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $zbanowany->ban();

        $this->actingAs($this->user('czytelniczka'))
            ->get(route('posts.show', $wpis))
            ->assertOk()
            // KONTROLA: rodzic i druga odpowiedź zostają na ekranie.
            ->assertSee('Rodzic watku, ktory zostaje')
            ->assertSee('Odpowiedz kontrolna bez sankcji')
            ->assertDontSee('Odpowiedz zbanowanego konta');
    }

    /**
     * Licznik komentarzy głównych NIE zmienia się od odpowiedzi.
     *
     * `withCount('comments')` idzie przez relację `comments()`, która ma
     * `whereNull('parent_id')`. Gdyby ktoś kiedyś tę relację poszerzył,
     * licznik zacząłby zliczać odpowiedzi ukryte o poziom niżej — i znowu
     * obiecywałby treść, której nie ma na ekranie.
     */
    public function test_licznik_komentarzy_nie_liczy_odpowiedzi(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $rozmowca = $this->user('rozmowca');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $rodzic = Comment::create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Jedyny komentarz glowny.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $rozmowca->getKey(),
            'post_id' => $wpis->getKey(),
            'parent_id' => $rodzic->getKey(),
            'body' => 'Odpowiedz, ktora nie jest komentarzem glownym.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $czytelnik = $this->user('czytelniczka');

        $wFeedzie = app(DiscoverFeed::class)->paginate($czytelnik)
            ->getCollection()
            ->firstWhere('id', $wpis->getKey());

        $this->assertNotNull($wFeedzie);
        $this->assertSame(1, (int) $wFeedzie->comments_count, 'Licznik wlicza odpowiedzi.');
    }
}
