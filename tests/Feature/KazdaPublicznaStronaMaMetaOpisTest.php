<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KAŻDA STRONA PUBLICZNA MA META DESCRIPTION (regresja issue #191).
 *
 * ZGŁOSZENIE
 * `resources/views/components/layout.blade.php` renderuje
 * `<meta name="description">` TYLKO wtedy, gdy `$description` zostało
 * przekazane. `/tag/{slug}` i strony prawne (`/regulamin`, `/prywatnosc`,
 * `/zasady`) tego nie robiły — Lighthouse mierzył tam SEO 92/100 zamiast
 * 100, wyłącznie z tego powodu.
 *
 * DLACZEGO TEN TEST PRZECHODZI PO WSZYSTKICH TRASACH, NIE PO DWÓCH
 * Test, który sprawdza tylko `/tag/zupy` i `/regulamin`, łapie dokładnie te
 * dwie strony i ani jednej więcej. Trzecia strona bez opisu — a jest taka,
 * patrz niżej — przeszłaby niezauważona, i czwarta, którą ktoś doda za
 * pół roku, też. Zamiast tego pętla idzie po WSZYSTKICH zarejestrowanych
 * trasach GET i każdą kwalifikuje regułą, nie nazwą z góry:
 *
 *   1. trasa wymaga zalogowania (middleware `Authenticate`)?
 *      → NIE jest to strona publiczna w sensie SEO — Google nigdy nie
 *      zobaczy jej jako zalogowany użytkownik, więc opis jej nie dotyczy.
 *      (To samo rozróżnienie, którego używa `scripts/wydajnosc.mjs`.)
 *   2. trasa jest techniczna — nie renderuje strony HTML w ogóle (plik XML,
 *      plik tekstowy, obraz, pakiet Livewire)? → pomijamy jawnie, z powodem.
 *   3. odpowiedź niesie `noindex` (meta `robots` ALBO nagłówek
 *      `X-Robots-Tag`, bo obie drogi naprawdę istnieją w tym kodzie —
 *      patrz `ApplySecurityHeaders::shouldNotIndex()` i
 *      `SocialController::followers()/following()`)? → strona ŚWIADOMIE
 *      poza indeksem, opis jej nie dotyczy. Test i tak wymusza, że
 *      `noindex` NAPRAWDĘ tam jest — więc cichej pomyłki (strona bez opisu
 *      i bez noindex, która akurat nie trafiła na żadną listę) nie da się
 *      przez to przemycić.
 *   4. w pozostałych przypadkach: `<meta name="description">` MUSI być
 *      i MUSI mieć niepustą treść.
 *
 * Garść tras jest zbyt kosztowna albo zbyt kruche do zbudowania w izolacji
 * (token podpisany, drugi krok logowania w trakcie, zdarzenie chronione
 * Policy) — te mają WŁASNY, jawny wyjątek z uzasadnieniem
 * ($trudneDoZbudowania niżej), bo są opisane w kodzie źródłowym jako
 * `noindex` i sprawdzanie ich tutaj dublowałoby inny test bez dodatkowej
 * pewności.
 *
 * ZNALEZISKO PRZY OKAZJI: `/zglos-nielegalna-tresc` (zgłoszenie treści
 * niezgodnej z prawem, DSA art. 16) też nie miało opisu — i, w odróżnieniu
 * od reszty formularzy, CELOWO nie jest `noindex` (musi być łatwo
 * znajdywalna, bo to obowiązek z przepisu). Naprawione tym samym PR-em,
 * bo dokładnie to jest cel tego testu: złapać trzecią stronę, nie tylko
 * dwie z opisu zgłoszenia.
 */
class KazdaPublicznaStronaMaMetaOpisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trasy techniczne — nie renderują strony HTML, więc `<meta
     * name="description">` nie ma tu znaczenia i test ich nie pobiera.
     */
    private const TRASY_TECHNICZNE = [
        'health' => 'sonda zdrowia — zwraca zwykłą odpowiedź, nie stronę HTML',
        'sitemap' => 'plik XML — Google nie czyta z niego meta description',
        'robots' => 'plik tekstowy robots.txt, nie strona HTML',
        'storage.local' => 'serwuje surowy plik z dysku (dev), nie renderuje layoutu',
        'media.show' => 'serwuje binarny wariant zdjęcia, nie stronę HTML',
    ];

    /**
     * Trasy publiczne (bez `Authenticate`), które NIE renderują HTML-a
     * możliwego do łatwego, niekruchego zbudowania w izolacji — każda ma
     * w komentarzu dowód, że i tak jest `noindex` w źródle, żeby wpis na
     * tej liście nie był aktem wiary.
     */
    private const TRUDNE_DO_ZBUDOWANIA = [
        // Wymaga sesji „logowanie w toku" (klucz `2fa_login_user_id` albo
        // podobny) — bez niej `TwoFactorChallengeController::show()`
        // przekierowuje na `/login`, więc nie da się tu dostać czystym
        // GET-em. `resources/views/auth/two_factor_challenge.blade.php`
        // ma `:noindex="true"` na trasę wprost.
        'login.two_factor' => 'wymaga sesji drugiego kroku logowania w toku; noindex w auth/two_factor_challenge.blade.php',

        // Wymaga PODPISANEGO adresu i realnego `CookedEvent` chronionego
        // Policy (nie każdy widz może go zobaczyć) — dublowanie tej
        // machinerii tutaj nie dodaje pewności, którą już nie ma inny test.
        // `resources/views/pages/cooked/show.blade.php` ma `:noindex="true"`
        // bezwarunkowo.
        'cooked.show' => 'wymaga CookedEvent chronionego Policy; noindex bezwarunkowo w pages/cooked/show.blade.php',

        // Wymaga PODPISANEGO adresu (`ValidateSignature`) i realnego
        // zgłoszenia (`Report`) powiązanego z autorem zgłoszenia.
        // `resources/views/pages/appeals/reporter.blade.php` ma
        // `:noindex="true"` bezwarunkowo.
        'appeals.reporter' => 'wymaga podpisanego adresu i zgłoszenia; noindex bezwarunkowo w pages/appeals/reporter.blade.php',
    ];

    public function test_kazda_indeksowalna_strona_publiczna_ma_niepusty_meta_description(): void
    {
        [$autor, $tag, $recipe, $post] = $this->zbudujTresc();

        $adresyDlaTras = [
            'landing' => route('landing'),
            'discover' => route('discover'),
            'about' => route('about'),
            'help' => route('help'),
            'register' => route('register'),
            'login' => route('login'),
            'terms' => route('terms'),
            'privacy' => route('privacy'),
            'rules' => route('rules'),
            'kontakt' => route('kontakt'),
            'kontakt.potwierdzenie' => route('kontakt.potwierdzenie'),
            'zglos.nielegalna' => route('zglos.nielegalna'),
            'zglos.nielegalna.potwierdzenie' => route('zglos.nielegalna.potwierdzenie'),
            'appeals.guest' => route('appeals.guest'),
            'account.delete.cancel' => route('account.delete.cancel'),
            'password.request' => route('password.request'),
            // Logowanie linkiem e-mail (issue #25, D-056). Oba ekrany są
            // `noindex` jak reszta rodziny logowania, więc pętla niżej
            // sprawdzi tylko, że oddają 200 — ale wpis MUSI tu być, żeby
            // test nie przestał widzieć nowej strony publicznej.
            //
            // `login.link.confirm` z byle tokenem renderuje ekran „ten link
            // już nie działa" i to jest poprawne 200: odpowiedź dla tokenu
            // nieistniejącego ma wyglądać tak samo jak dla wygasłego
            // i zużytego (D-056).
            'login.link' => route('login.link'),
            'login.link.confirm' => route('login.link.confirm', ['token' => 'token-testowy']),
            // Zaproszenie do założenia konta (D-085) — ta sama rodzina i ta
            // sama zasada: `noindex`, a byle token renderuje ekran „to
            // zaproszenie już nie działa" i to jest poprawne 200, bo token
            // nieistniejący ma wyglądać tak samo jak wygasły i zużyty.
            'zaproszenie.pokaz' => route('zaproszenie.pokaz', ['token' => 'token-testowy']),
            'password.reset' => route('password.reset', ['token' => 'token-testowy']),
            'search' => route('search'),
            'tags.show' => route('tags.show', $tag->slug),
            'profile.show' => route('profile.show', $autor->profile->username),
            'social.following' => route('social.following', $autor->profile->username),
            'social.followers' => route('social.followers', $autor->profile->username),
            'recipes.show' => route('recipes.show', $recipe->slug),
            'cooking.show' => route('cooking.show', $recipe->slug),
            'posts.show' => route('posts.show', $post),

            // Wypisanie z tygodniowego podsumowania i droga powrotna
            // (issue #11, D-057). Adresy są PODPISANE, bo trasy stoją za
            // `middleware('signed')` — stąd pomocnik zamiast `route()`.
            //
            // `GET` na tych trasach naprawdę przestawia zgodę na koncie
            // z fikstury i to jest w porządku: fikstura żyje tylko w tym
            // teście, a strony są tu sprawdzane pod kątem `noindex`
            // i obecności opisu, nie skutku ubocznego. Dlaczego wypisanie
            // działa na `GET`: `App\Http\Controllers\
            // PodsumowanieTygodniaController` — wyjście musi być jednym
            // kliknięciem.
            'podsumowanie.wypisz' => OdnosnikWypisania::dla($autor),
            'podsumowanie.wracam' => OdnosnikWypisania::powrotDla($autor),
        ];

        $zbadanych = 0;
        $bezOpisu = [];

        foreach (Route::getRoutes() as $trasa) {
            if (! in_array('GET', $trasa->methods(), true)) {
                continue;
            }

            $nazwa = $trasa->getName();

            // Trasy bez nazwy: `/up` (sonda frameworka) i zasoby pakietu
            // Livewire (CSS/JS komponentów) — nie są naszymi stronami.
            if ($nazwa === null || str_starts_with($trasa->uri(), 'livewire')) {
                continue;
            }

            if (array_key_exists($nazwa, self::TRASY_TECHNICZNE)) {
                continue;
            }

            if (array_key_exists($nazwa, self::TRUDNE_DO_ZBUDOWANIA)) {
                continue;
            }

            // Trasa wymaga zalogowania → nie jest stroną publiczną w sensie
            // SEO (ta sama granica, której używa `scripts/wydajnosc.mjs`).
            if ($this->wymagaLogowania($trasa)) {
                continue;
            }

            $this->assertArrayHasKey(
                $nazwa,
                $adresyDlaTras,
                "Nowa trasa publiczna „{$nazwa}” (/{$trasa->uri()}) nie ma wpisu "
                .'w $adresyDlaTras tego testu, więc nie da się jej pobrać i sprawdzić. '
                .'Dopisz adres do mapy (z fikstury, jeśli trasa ma parametr) ALBO, '
                .'jeśli strona świadomie nie ma mieć opisu, dopisz ją do '
                .'TRASY_TECHNICZNE / TRUDNE_DO_ZBUDOWANIA z uzasadnieniem — to jest '
                .'dokładnie ten test, który ma złapać nową stronę bez opisu.',
            );

            $zbadanych++;

            $odpowiedz = $this->get($adresyDlaTras[$nazwa]);

            $this->assertSame(
                200,
                $odpowiedz->getStatusCode(),
                "Trasa „{$nazwa}” nie odpowiedziała 200 (dostała "
                .$odpowiedz->getStatusCode().'). Test liczy na gotową stronę HTML, '
                .'żeby sprawdzić jej meta description.',
            );

            $html = $odpowiedz->getContent();

            $maNoindex = str_contains((string) $html, 'name="robots" content="noindex')
                || str_contains((string) $odpowiedz->headers->get('X-Robots-Tag'), 'noindex');

            if ($maNoindex) {
                // Świadomie poza indeksem — opis jej nie dotyczy (dokładnie
                // tak jak `/login` w pomiarze z tego zgłoszenia).
                continue;
            }

            if (! preg_match('/<meta name="description" content="([^"]*)">/', (string) $html, $dopasowanie)
                || trim($dopasowanie[1]) === '') {
                $bezOpisu[] = "{$nazwa} (/{$trasa->uri()})";
            }
        }

        // ASERCJA KONTROLNA — bez niej pusta lista `$bezOpisu` może znaczyć
        // „wszystko ma opis" ALBO „pętla nic nie zbadała" (np. ktoś
        // przypadkiem dopisał `Authenticate` do każdej trasy w `web.php`).
        // 20 września 2026 trasa publicznych GET-ów bez auth było ponad 20.
        $this->assertGreaterThan(
            20,
            $zbadanych,
            'Pętla zbadała mniej niż 20 tras publicznych — to nie jest stan, '
            .'w którym pusta lista `$bezOpisu` cokolwiek znaczy. Sprawdź warunki '
            .'pomijające (wymagaLogowania, TRASY_TECHNICZNE) zanim uwierzysz '
            .'w zielony wynik.',
        );

        $this->assertSame(
            [],
            $bezOpisu,
            "Strona publiczna bez meta description:\n".implode("\n", $bezOpisu)
            ."\n\nissue #191: `<x-layout>` renderuje <meta name=\"description\"> "
            .'TYLKO, gdy dostanie `description`. Dodaj ten atrybut do widoku, '
            .'z sensowną polską treścią (docs/brand/COPY_STYLE.md) — albo, jeśli '
            .'strona ma świadomie zostać bez opisu, oznacz ją `:noindex="true"` '
            .'(albo dopisz do wyjątków w tym teście, z powodem).',
        );
    }

    /** Middleware `Authenticate` gdziekolwiek w stosie trasy. */
    private function wymagaLogowania(RoutingRoute $trasa): bool
    {
        foreach ($trasa->gatherMiddleware() as $warstwa) {
            // `gatherMiddleware()` zwraca alias tak, jak został przypięty
            // w `routes/web.php` ("auth", ewentualnie "auth:web") — NIE
            // rozwiniętą klasę. `Authenticate::class` zostaje w imporcie
            // i tak: to on jest źródłem prawdy o tym, JAKI alias sprawdzamy
            // (`config('auth')`/`bootstrap/app.php` mapuje "auth" na tę
            // klasę), więc reguła nie rozjeżdża się cicho, gdy ktoś kiedyś
            // zmieni nazwę aliasu.
            if (is_string($warstwa) && ($warstwa === 'auth' || str_starts_with($warstwa, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treść potrzebna, żeby trasy sparametryzowane (przepis, wpis, tag,
     * profil) w ogóle dało się otworzyć jako gość.
     *
     * @return array{0: User, 1: Tag, 2: Recipe, 3: Post}
     */
    private function zbudujTresc(): array
    {
        $autor = $this->user('autorka_seo');

        $tag = Tag::factory()->create();

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'slug' => 'przepis-seo-'.Str::lower(Str::random(6)),
        ]);

        // `cooking.show` (tryb gotowania) przekierowuje na sam przepis, gdy
        // nie ma kroków — potrzebny co najmniej jeden, żeby trasa dała 200.
        RecipeStep::create([
            'recipe_id' => $recipe->getKey(),
            'position' => 0,
            'instruction' => 'Wymieszaj składniki.',
        ]);

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        // Wpis oznaczony tym samym tagiem: strona tagu ma niezerową liczbę
        // wpisów, więc test przechodzi przez GAŁĄŹ OPISU Z LICZBĄ, nie tylko
        // przez wariant „tag jeszcze pusty".
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return [$autor, $tag, $recipe, $post];
    }
}
