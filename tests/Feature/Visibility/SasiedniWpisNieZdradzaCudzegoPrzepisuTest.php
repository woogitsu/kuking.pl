<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Posts\SasiedniWpisAutora;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Nawigacja „poprzedni / następny wpis" nie może być obejściem widoczności
 * PRZEPISU (issue #368).
 *
 * `SasiedniWpisAutora::kandydaci()` filtrował przez `widoczneDla($widz)`
 * i `dostepnyJakoAutor()` — i obie te granice działają. Ale wpis wskazujący
 * przepis jest NA STAŁE `visibility = 'public'`
 * (`WpisWskazujacyPrzepis::dopisz()`: „wpis nie niesie żadnej treści, której
 * miałby strzec, a jedyną bramką jest przepis"). Filtr po widoczności WPISU
 * przepuszczał więc zapowiedź przepisu, którego widz zobaczyć nie ma prawa.
 * Brakowało trzeciej granicy: `Post::scopeZWidocznymPrzepisem($widz)`.
 *
 * CO DOKŁADNIE WYCIEKAŁO — I TO NIE JEST „TYLKO ISTNIENIE".
 * Sama szyna nawigacji rysuje niewiele: miniaturę z WŁASNYCH zdjęć wpisu
 * (zapowiedź ich nie ma) i napis „Następny wpis". Wyciek zaczyna się
 * o jedno kliknięcie dalej. `PostController::show()` dla wpisu, który jest
 * samym wskazaniem przepisu, przekierowuje na stronę przepisu — i robi to
 * ZANIM ktokolwiek zapyta o widoczność przepisu:
 *
 *     302  Location: /przepisy/bigos-z-kapusty-kiszonej
 *
 * Adres docelowy oddaje potem 403, ale SLUG JEST JUŻ ODDANY — a slug to
 * tytuł przepisu zapisany myślnikami. Dla przepisu „tylko dla obserwujących"
 * tytuł jest tym, co ustawienie prywatności ma zasłonić. Dokłada się do tego
 * samo istnienie treści („ta osoba opublikowała coś jeszcze"), widoczne także
 * dla NIEZALOGOWANEGO gościa.
 *
 * DOBÓR WIDZA JEST CZĘŚCIĄ TESTU. Ola nie obserwuje Basi — to jedyna
 * konfiguracja, w której ten wyciek widać. Gdyby obserwowała, przepis byłby
 * dla niej widoczny zgodnie z ustawieniem i test przechodziłby z niewłaściwego
 * powodu.
 *
 * KONTROLA DODATNIA JEST TU OBOWIĄZKOWA. Asercja „nie ma odnośnika do Z"
 * przechodzi także wtedy, gdy nawigacja zniknęła w całości albo gdy strona
 * oddała 404. Dlatego każdy test niżej sprawdza NAJPIERW, że nawigacja
 * dalej prowadzi tam, gdzie ma — do zwykłych wpisów Basi — i dopiero potem,
 * że nie prowadzi do zapowiedzi.
 */
class SasiedniWpisNieZdradzaCudzegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapowiedz_cudzego_przepisu_nie_jest_sasiadem_dla_obcej_osoby(): void
    {
        ['ola' => $ola, 'srodkowy' => $srodkowy, 'zapowiedz' => $zapowiedz, 'najnowszy' => $najnowszy, 'najstarszy' => $najstarszy] = $this->archiwum();

        $sasiedni = app(SasiedniWpisAutora::class);

        // KONTROLA DODATNIA — w obie strony. Bez niej asercja niżej
        // przechodziłaby także wtedy, gdyby poprawka wycięła nawigację
        // w całości.
        $this->assertSame(
            (string) $najstarszy->getKey(),
            (string) $sasiedni->poprzedni($srodkowy, $ola)?->getKey(),
            'Nawigacja wstecz przestała prowadzić do zwykłego wpisu Basi — asercja o zapowiedzi '
            .'nie mówiłaby wtedy o widoczności przepisu, tylko o martwej nawigacji.',
        );

        $nastepny = $sasiedni->nastepny($srodkowy, $ola);

        $this->assertNotNull(
            $nastepny,
            'Nawigacja w przód zniknęła mimo NOWSZEGO publicznego wpisu Basi. Poprawka odcięła '
            .'za dużo i asercja niżej przechodzi z niewłaściwego powodu.',
        );

        $this->assertNotSame(
            (string) $zapowiedz->getKey(),
            (string) $nastepny->getKey(),
            'Sąsiadem w archiwum Basi została zapowiedź przepisu „tylko dla obserwujących", '
            .'a Ola Basi nie obserwuje. Kartkowanie archiwum obeszło ustawienie prywatności.',
        );

        $this->assertSame(
            (string) $najnowszy->getKey(),
            (string) $nastepny->getKey(),
            'Nawigacja w przód pominęła zapowiedź, ale nie trafiła w następny zwykły wpis.',
        );
    }

    public function test_slug_cudzego_przepisu_nie_wycieka_przez_odnosnik_w_nawigacji(): void
    {
        ['ola' => $ola, 'srodkowy' => $srodkowy, 'zapowiedz' => $zapowiedz, 'najstarszy' => $najstarszy, 'przepis' => $przepis] = $this->archiwum();

        $html = $this->actingAs($ola)->get(route('posts.show', $srodkowy))->assertOk()->getContent();

        // KONTROLA DODATNIA: szyna nawigacji w ogóle stoi na tej stronie
        // i prowadzi do zwykłego wpisu. Bierzemy kierunek WSTECZ, bo ten
        // odnośnik ma wyglądać tak samo przed poprawką i po niej — kontrola
        // dodatnia, która zmienia się razem z poprawką, nie jest kontrolą.
        $this->assertStringContainsString(
            route('posts.show', $najstarszy),
            $html,
            'Na stronie wpisu nie ma nawet odnośnika do poprzedniego ZWYKŁEGO wpisu Basi — '
            .'asercja niżej mówiłaby o pustej stronie, nie o widoczności przepisu.',
        );

        $this->assertStringNotContainsString(
            route('posts.show', $zapowiedz),
            $html,
            'Strona wpisu podaje obcej osobie odnośnik do zapowiedzi przepisu „tylko dla '
            .'obserwujących". Kliknięcie w niego oddaje 302 z adresem przepisu w nagłówku '
            .'Location, czyli ze slugiem — a slug to tytuł.',
        );

        // I to samo z drugiej strony: gdyby odnośnik jednak był, to WŁAŚNIE
        // ta droga wynosi tytuł. Test trzyma cały łańcuch, nie tylko HTML.
        $this->assertStringNotContainsString(
            $przepis->slug,
            $html,
            'Slug przepisu „tylko dla obserwujących" stoi wprost w HTML-u strony wpisu '
            .'oglądanej przez osobę, która autora nie obserwuje.',
        );
    }

    public function test_niezalogowany_gosc_tez_nie_dostaje_odnosnika_do_zapowiedzi(): void
    {
        ['srodkowy' => $srodkowy, 'zapowiedz' => $zapowiedz, 'najstarszy' => $najstarszy] = $this->archiwum();

        // Gość to osobny przypadek, nie wariant „obcej": `widoczneDla(null)`
        // idzie inną gałęzią niż `widoczneDla($widz)`.
        $html = $this->get(route('posts.show', $srodkowy))->assertOk()->getContent();

        $this->assertStringContainsString(
            route('posts.show', $najstarszy),
            $html,
            'Gość nie dostaje nawet odnośnika do poprzedniego zwykłego wpisu — kontrola dodatnia '
            .'nie przeszła, więc asercja niżej nic nie znaczy.',
        );

        $this->assertStringNotContainsString(
            route('posts.show', $zapowiedz),
            $html,
            'Niezalogowany gość dostaje odnośnik do zapowiedzi przepisu „tylko dla obserwujących".',
        );
    }

    public function test_autor_dalej_kartkuje_wlasny_przepis(): void
    {
        ['basia' => $basia, 'srodkowy' => $srodkowy, 'zapowiedz' => $zapowiedz] = $this->archiwum();

        $this->assertSame(
            (string) $zapowiedz->getKey(),
            (string) app(SasiedniWpisAutora::class)->nastepny($srodkowy, $basia)?->getKey(),
            'Poprawka odcięła autorce jej własny przepis w nawigacji. „Poprawne dane nigdy nie '
            .'znikają" (AGENTS.md §5) — własne archiwum widać zawsze, także to prywatne.',
        );
    }

    public function test_obserwujaca_dalej_kartkuje_przepis_dla_obserwujacych(): void
    {
        ['basia' => $basia, 'srodkowy' => $srodkowy, 'zapowiedz' => $zapowiedz] = $this->archiwum();

        $kasia = $this->user('kasia');
        app(FollowUser::class)->handle($kasia, $basia);

        $this->assertSame(
            (string) $zapowiedz->getKey(),
            (string) app(SasiedniWpisAutora::class)->nastepny($srodkowy, $kasia)?->getKey(),
            'Poprawka odcięła przepis „tylko dla obserwujących" osobie, która TE obserwuje — '
            .'bramka jest za szeroka i zabiera treść uprawnionym.',
        );
    }

    public function test_zapowiedz_publicznego_przepisu_zostaje_dla_kazdego(): void
    {
        ['ola' => $ola, 'basia' => $basia, 'najnowszy' => $najnowszy] = $this->archiwum();

        $jawny = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Naleśniki z serem', 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => 'mąka']],
            steps: [['instruction' => 'Usmaż na małym ogniu.']],
            publish: true,
        );

        /** @var Post $zapowiedzJawna */
        $zapowiedzJawna = Post::query()->where('recipe_id', $jawny->getKey())->firstOrFail();
        $zapowiedzJawna->forceFill(['published_at' => now()->addHour()])->save();

        $this->assertSame(
            (string) $zapowiedzJawna->getKey(),
            (string) app(SasiedniWpisAutora::class)->nastepny($najnowszy, $ola)?->getKey(),
            'Bramka zabrała także zapowiedź przepisu PUBLICZNEGO — odcięcie jest za szerokie '
            .'i nawigacja gubi treść, którą wolno pokazać każdemu.',
        );
    }

    /**
     * Archiwum Basi, chronologicznie:
     *
     *   najstarszy (−3h)  zwykły, publiczny, ze zdjęciem
     *   srodkowy   (−2h)  zwykły, publiczny — TĘ stronę oglądamy
     *   zapowiedz  (−1h)  wskazanie przepisu „tylko dla obserwujących"
     *   najnowszy  ( 0h)  zwykły, publiczny — kontrola dodatnia „w przód"
     *
     * Ola nie obserwuje Basi.
     *
     * @return array{basia: User, ola: User, przepis: Recipe, najstarszy: Post, srodkowy: Post, zapowiedz: Post, najnowszy: Post}
     */
    private function archiwum(): array
    {
        $basia = $this->user('basia');
        $ola = $this->user('ola');

        $this->assertFalse(
            $ola->following()->whereKey($basia->getKey())->exists(),
            'Ola obserwuje Basię — wtedy przepis „tylko dla obserwujących" jest dla niej widoczny '
            .'zgodnie z ustawieniem i te testy nie mierzą wycieku.',
        );

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Bigos z kapusty kiszonej', 'visibility' => 'followers', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        $this->assertSame('followers', $przepis->fresh()->visibility);

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $zapowiedz->forceFill(['published_at' => now()->subHour()])->save();

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $zapowiedz->fresh()->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją zwykły filtr widoczności '
            .'wpisu i te testy przechodziłyby z niewłaściwego powodu.',
        );

        return [
            'basia' => $basia,
            'ola' => $ola,
            'przepis' => $przepis->fresh(),
            'najstarszy' => $this->zwykly($basia, now()->subHours(3)),
            'srodkowy' => $this->zwykly($basia, now()->subHours(2)),
            'zapowiedz' => $zapowiedz->fresh(),
            'najnowszy' => $this->zwykly($basia, now()),
        ];
    }

    private function zwykly(User $autor, Carbon $kiedy): Post
    {
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => $kiedy,
        ]);

        $wpis->media()->attach(
            Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
            ['position' => 0],
        );

        return $wpis->refresh();
    }
}
