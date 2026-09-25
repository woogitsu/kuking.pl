<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as TrasaFrameworku;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * „UUID W ADRESIE NIE JEST AUTORYZACJĄ" (AGENTS.md §7) — STRAŻNIK REGUŁY.
 *
 * Reguła jest w `AGENTS.md` od początku, ale do 12.09.2026 nikt jej nie
 * PRZELICZYŁ: nie było ani jednego miejsca, które pyta „czy KAŻDA trasa
 * biorąca model z adresu rzeczywiście przechodzi przez bramkę". Audyt
 * przeszedł wszystkie takie trasy ręcznie i nie znalazł dziury — ten plik
 * pilnuje, żeby NASTĘPNA trasa nie weszła bez bramki, bo ręczny przegląd
 * chroni tylko ten jeden dzień, w którym go zrobiono.
 *
 * DWIE WARSTWY, CELOWO RÓŻNE
 *
 *  1. `test_kazda_trasa_...` — SKAN po tablicy tras. Odpowiada na pytanie
 *     „czy bramka w ogóle istnieje", czyta kod metody kontrolera. Jest
 *     tani i łapie nową trasę dopisaną bez `authorize()`.
 *  2. Testy behawioralne niżej — prawdziwe żądania HTTP obcej osoby na
 *     cudzy zasób. Odpowiadają na pytanie „czy bramka DZIAŁA", bo skan
 *     widzi tylko napis `authorize(` i nie wie, czego on pilnuje.
 *
 * Żadna z tych warstw nie zastępuje drugiej: skan bez testów behawioralnych
 * przepuściłby Policy zwracającą `true` dla każdego zalogowanego, a testy
 * behawioralne bez skanu nie wiedzą nic o trasie dopisanej jutro.
 *
 * CZEGO TEN PLIK NIE PILNUJE (napisane wprost, żeby nie obiecywał więcej,
 * niż mierzy): skan obejmuje trasy, które biorą model z adresu — przez
 * wiązanie trasy (parametr typowany klasą modelu) albo przez jawne
 * `findOrFail()`/`firstOrFail()` w ciele metody. Trasa, która wczyta model
 * jakąś trzecią drogą (np. `->first()` po ręcznie sklejonym zapytaniu),
 * w skan nie wejdzie i zostaje na odpowiedzialności przeglądu kodu.
 */
class AutoryzacjaTrasZWiazaniemModeluTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ŚWIADOME WYJĄTKI — trasy biorące model z adresu, które NIE MAJĄ
     * `authorize()` i mieć go nie muszą. Każdy wpis ma powód, a powód ma
     * być czytelny bez wchodzenia do kontrolera.
     *
     * Lista jest CELOWO jawna i krótka. Dopisanie tu nazwy to decyzja
     * produktowa („ta treść jest publiczna" albo „ta akcja dotyczy wyłącznie
     * własnych danych"), a nie sposób na uciszenie testu.
     *
     * @var array<string, string>
     */
    private const SWIADOME_WYJATKI = [
        // Zdjęcie ma WŁASNĄ bramkę, tylko nie nazywa się `authorize()`:
        // `DostepDoZdjecia::rozstrzygnij()` pyta `Gate` o Policy RODZICA
        // zdjęcia (wpisu, przepisu, wykonania, profilu) i `MediaController`
        // odmawia przez `abort_unless($decyzja->dlaWidza, 404)`.
        'media.show' => 'Bramka w App\Domain\Media\DostepDoZdjecia (Gate na Policy rodzica).',

        // Odwołanie od decyzji moderacyjnej: własnościowa bramka stoi
        // w prywatnej metodzie `AppealController::sprawdzWlascicielaSprawy()`
        // — trzy `abort_*`, w tym „moderator też nie ogląda tu cudzych spraw".
        'appeals.show' => 'Własnościowa bramka: AppealController::sprawdzWlascicielaSprawy().',
        'appeals.store' => 'Własnościowa bramka: AppealController::sprawdzWlascicielaSprawy().',

        // Wyjęcie rzeczy z WŁASNEGO zeszytu. Akcja chodzi po kolekcjach
        // osoby zalogowanej, więc cudzy identyfikator nie ma czego usunąć.
        // Co więcej: treść, której już nie wolno OGLĄDAĆ, tym bardziej musi
        // dać się wyjąć z zeszytu — `authorize('view')` zamknęłoby ją tam
        // na zawsze.
        'collections.unsave' => 'Usuwanie z WŁASNEGO zeszytu — zapytanie chodzi po kolekcjach zalogowanego.',
        'collections.unsave-post' => 'Usuwanie z WŁASNEGO zeszytu — zapytanie chodzi po kolekcjach zalogowanego.',

        // Tag jest treścią publiczną z założenia (`docs/FEATURES.md` — tagi
        // to wspólna nawigacja serwisu, nie czyjaś własność). Obserwowanie
        // zapisuje się w relacji osoby zalogowanej.
        'tags.show' => 'Tag jest publiczny — zamknięcie go byłoby regresją produktową.',
        'questions.index' => 'Publiczna lista: firstOrFail wybiera wyłącznie aktywny tag filtra; pytania filtruje QuestionList przez widoczneDla. Bramka działu pozostaje obowiązkowa.',
        'tags.follow' => 'Tag publiczny; zapis idzie przez relację zalogowanego (followedTags).',
        'tags.unfollow' => 'Tag publiczny; zapis idzie przez relację zalogowanego (followedTags).',

        // Otwarcie powiadomienia: właścicielstwo egzekwuje ZAPYTANIE
        // (`$request->user()->notifications()->whereKey(...)->firstOrFail()`),
        // więc cudzy identyfikator nie wybiera żadnego wiersza.
        'notifications.open' => 'Właścicielstwo w zapytaniu: relacja notifications() zalogowanego.',
    ];

    /**
     * SKAN: każda trasa biorąca model z adresu ma bramkę autoryzacji.
     *
     * Bramką jest tutaj JEDNO z trzech:
     *  - wywołanie `authorize(...)` albo `Gate::` w ciele metody kontrolera,
     *  - middleware `can:` na trasie,
     *  - middleware podpisu (`signed` / `ValidateSignature`) — wtedy
     *    autoryzacją jest sam, wygasający link z listu (tak działa pobranie
     *    paczki RODO i odwołanie zgłaszającego).
     *
     * ASERCJA NA LICZBĘ PRZESKANOWANYCH TRAS JEST OBOWIĄZKOWA
     * (`docs/PULAPKI_TESTOW.md` pułapka 2): bez niej zła ścieżka, zmiana
     * nazwy klasy albo przeniesienie kontrolerów wyłącza ten test w ciszy,
     * a zero trafień jest dla skanu sukcesem.
     */
    public function test_kazda_trasa_z_wiazaniem_modelu_ma_bramke_autoryzacji(): void
    {
        $przeskanowane = 0;
        $bezBramki = [];

        foreach (Route::getRoutes() as $trasa) {
            $metoda = $this->metodaKontrolera($trasa);

            if ($metoda === null) {
                continue;
            }

            $zrodlo = $this->zrodloMetody($metoda);

            if (! $this->bierzeModelZAdresu($metoda, $zrodlo)) {
                continue;
            }

            $przeskanowane++;

            $nazwa = $trasa->getName() ?? $trasa->uri();

            if (array_key_exists($nazwa, self::SWIADOME_WYJATKI)) {
                continue;
            }

            if (! $this->maBramke($trasa, $zrodlo)) {
                $bezBramki[] = $nazwa.' ('.$trasa->getActionName().')';
            }
        }

        $this->assertGreaterThanOrEqual(45, $przeskanowane,
            'Skan nie czyta tras — zmieniła się przestrzeń nazw kontrolerów albo sposób wiązania modeli? '
            .'Przeskanowano: '.$przeskanowane);

        $this->assertSame([], $bezBramki,
            "Trasa bierze model z adresu i nie pyta o autoryzację.\n"
            ."AGENTS.md §7: UUID w adresie NIE JEST autoryzacją — każde wejście na cudzą treść przechodzi przez Policy.\n"
            ."Jeśli ta treść jest publiczna ŚWIADOMIE, dopisz trasę do AutoryzacjaTrasZWiazaniemModeluTest::SWIADOME_WYJATKI razem z powodem.\n"
            .'Trasy bez bramki: '.implode(', ', $bezBramki));
    }

    /**
     * Lista wyjątków nie może gnić.
     *
     * Nazwa trasy, która przestała istnieć (zmiana nazwy, usunięcie ekranu),
     * zostaje na liście jako martwa zgoda — i w dniu, w którym ktoś użyje tej
     * samej nazwy do czegoś innego, wyjątek otworzy się sam, bez jednego
     * czerwonego przebiegu.
     */
    public function test_lista_swiadomych_wyjatkow_nie_zawiera_martwych_nazw(): void
    {
        $istniejace = [];

        foreach (Route::getRoutes() as $trasa) {
            if ($trasa->getName() !== null) {
                $istniejace[$trasa->getName()] = true;
            }
        }

        $martwe = array_values(array_filter(
            array_keys(self::SWIADOME_WYJATKI),
            fn (string $nazwa): bool => ! isset($istniejace[$nazwa]),
        ));

        // KONTROLA DODATNIA (pułapka 4): sama asercja „nic nie zostało"
        // przeszłaby też wtedy, gdyby tablica tras była pusta.
        $this->assertArrayHasKey('posts.show', $istniejace,
            'Tablica tras nie została wczytana — ten test nie mierzy niczego.');

        $this->assertSame([], $martwe,
            'Na liście świadomych wyjątków są nazwy tras, których już nie ma: '.implode(', ', $martwe));
    }

    /**
     * BEHAWIORALNIE: obca zalogowana osoba na cudzym zasobie dostaje odmowę.
     *
     * Każda pozycja ma KONTROLĘ DODATNIĄ w tym samym teście — po odmowie dla
     * obcego to samo żądanie robi właściciel i musi przejść
     * (`docs/PULAPKI_TESTOW.md` pułapka 4). Bez tej pary test przechodziłby
     * także wtedy, gdyby trasa była zepsuta i odmawiała WSZYSTKIM.
     *
     * Kolejność „najpierw obcy, potem właściciel" jest wymuszona: część tych
     * żądań kasuje zasób.
     */
    public function test_obca_osoba_nie_wchodzi_na_cudzy_zasob_przez_sam_identyfikator(): void
    {
        $wlasciciel = $this->user('wlascicielka');
        $obcy = $this->user('obcaosoba');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Prywatny zeszyt',
            'visibility' => 'private',
        ]);

        $wpis = Post::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);

        $przepis = Recipe::factory()->create(['author_id' => $wlasciciel->getKey()]);

        $komentarz = Comment::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'post_id' => Post::factory()->create(['author_id' => $wlasciciel->getKey()])->getKey(),
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $wlasciciel->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $zgloszenie = Report::create([
            'reporter_id' => $wlasciciel->getKey(),
            'target_type' => 'post',
            'target_id' => (string) $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) $wpis->getKey(),
            'subject_user_id' => $wlasciciel->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        // trasa => [metoda HTTP, parametry trasy, dane formularza]
        $przypadki = [
            'collections.show' => ['get', $zeszyt, []],
            'collections.destroy' => ['delete', $zeszyt, []],
            'posts.edit' => ['get', $wpis, []],
            'posts.media.edit' => ['get', $wpis, []],
            'wspomnienia.ukryj' => ['post', $wpis, []],
            'recipes.edit' => ['get', $przepis, []],
            'recipes.details' => ['get', $przepis, []],
            'comments.update' => ['put', $komentarz, ['body' => 'Poprawiona treść komentarza.']],
            'cooked.celebrate' => ['get', $wykonanie, []],
            'cooked.destroy' => ['delete', $wykonanie, []],
            'reports.mine.show' => ['get', $zgloszenie, []],
            'appeals.show' => ['get', $decyzja, []],
        ];

        foreach ($przypadki as $trasa => [$metodaHttp, $model, $dane]) {
            $odpowiedzObcego = $this->actingAs($obcy)
                ->from(route('home'))
                ->{$metodaHttp}(route($trasa, $model), $dane);

            $this->assertContains($odpowiedzObcego->getStatusCode(), [403, 404],
                "Obca osoba weszła na cudzy zasób przez sam identyfikator: {$trasa} "
                ."(kod {$odpowiedzObcego->getStatusCode()}). AGENTS.md §7.");
        }

        // KONTROLA DODATNIA — te same żądania u właściciela MUSZĄ przejść.
        // Właściciel wykonania nie jest autorem przepisu, więc `cooked.celebrate`
        // należy do autora przepisu; tu obie role ma ta sama osoba.
        foreach ($przypadki as $trasa => [$metodaHttp, $model, $dane]) {
            $odpowiedzWlasciciela = $this->actingAs($wlasciciel)
                ->from(route('home'))
                ->{$metodaHttp}(route($trasa, $model), $dane);

            $this->assertNotContains($odpowiedzWlasciciela->getStatusCode(), [403, 404],
                "Właściciel dostał odmowę na WŁASNYM zasobie: {$trasa} "
                ."(kod {$odpowiedzWlasciciela->getStatusCode()}). "
                .'Ta kontrola dodatnia pilnuje, żeby test wyżej nie przechodził dlatego, że trasa odmawia wszystkim.');
        }
    }

    /**
     * Panel moderacji: samo zalogowanie nie wystarcza.
     *
     * Policy, która wpuszcza każdego zalogowanego, jest dziurą, nawet gdy
     * formalnie istnieje — tutaj mierzymy to wprost, na trasie z wiązaniem
     * modelu `User`.
     */
    public function test_zwykla_osoba_nie_oglada_cudzej_karty_w_panelu_moderacji(): void
    {
        $ogladany = $this->user('ogladanykucharz');
        $zwykly = $this->user('zwyklaosoba');

        // 404, nie 403 — `EnsureUserIsModerator` celowo nie potwierdza
        // nikomu, że panel moderacji w ogóle istnieje.
        $this->actingAs($zwykly)
            ->get(route('admin.users.show', $ogladany))
            ->assertNotFound();

        // KONTROLA DODATNIA: ta sama karta otwiera się moderatorowi, więc
        // powyższe 403 nie bierze się z tego, że ekran jest po prostu zepsuty.
        $this->actingAs($this->moderator())
            ->get(route('admin.users.show', $ogladany))
            ->assertOk();
    }

    /**
     * Metoda kontrolera stojąca za trasą — albo `null`, gdy trasa nie jest
     * naszym kontrolerem (domknięcia, trasy Livewire'a, trasy frameworku).
     */
    private function metodaKontrolera(TrasaFrameworku $trasa): ?ReflectionMethod
    {
        $akcja = $trasa->getActionName();

        if (! str_contains($akcja, '@') || ! str_starts_with($akcja, 'App\\')) {
            return null;
        }

        [$klasa, $nazwaMetody] = explode('@', $akcja, 2);

        if (! method_exists($klasa, $nazwaMetody)) {
            return null;
        }

        return new ReflectionMethod($klasa, $nazwaMetody);
    }

    /** Kod źródłowy ciała metody kontrolera. */
    private function zrodloMetody(ReflectionMethod $metoda): string
    {
        $plik = $metoda->getFileName();

        if ($plik === false) {
            return '';
        }

        $linie = file($plik);

        if ($linie === false) {
            return '';
        }

        return implode('', array_slice(
            $linie,
            $metoda->getStartLine() - 1,
            $metoda->getEndLine() - $metoda->getStartLine() + 1,
        ));
    }

    /**
     * Czy ta metoda w ogóle bierze model z adresu.
     *
     * Dwie drogi, obie realnie używane w tym repozytorium:
     *  - wiązanie trasy: parametr typowany klasą dziedziczącą po `Model`,
     *  - ręczne wczytanie: `findOrFail()` / `firstOrFail()` w ciele metody
     *    (tak robią kontrolery przepisów, profilu i powiadomień, bo szukają
     *    po slugu albo po nazwie użytkownika).
     */
    private function bierzeModelZAdresu(ReflectionMethod $metoda, string $zrodlo): bool
    {
        foreach ($metoda->getParameters() as $parametr) {
            $typ = $parametr->getType();

            if ($typ instanceof \ReflectionNamedType
                && ! $typ->isBuiltin()
                && is_subclass_of($typ->getName(), Model::class)) {
                return true;
            }
        }

        return preg_match('/\b(findOrFail|firstOrFail)\s*\(/', $zrodlo) === 1;
    }

    /**
     * Czy trasa ma jakąkolwiek bramkę autoryzacji (patrz docblock testu).
     *
     * Napis `authorize(` liczy się tylko w KODZIE (audyt B7-17): wcześniej
     * wystarczał zakomentowany `// $this->authorize(...)` albo to słowo
     * w napisie, żeby trasa bez bramki przeszła skan. Czy bramka pilnuje
     * WŁAŚCIWEJ zdolności na WŁAŚCIWYM modelu, skan nadal nie wie — to mierzy
     * żądaniami `KazdaTrasaZIdentyfikatoremPodPolicyTest`.
     */
    private function maBramke(TrasaFrameworku $trasa, string $zrodlo): bool
    {
        if (preg_match('/authorize\s*\(|Gate::/', $this->samKod($zrodlo)) === 1) {
            return true;
        }

        foreach ($trasa->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'can:')) {
                return true;
            }

            if ($middleware === 'signed' || str_contains($middleware, 'ValidateSignature')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Źródło metody bez komentarzy i bez literałów napisowych — to, co PHP
     * faktycznie wykona. Tokenizer, nie wyrażenie regularne: komentarz
     * blokowy w środku linii albo `//` w napisie zmyliłyby każde wyrażenie.
     */
    private function samKod(string $zrodlo): string
    {
        $kod = '';

        foreach (token_get_all('<?php '.$zrodlo) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_OPEN_TAG], true)) {
                    $kod .= ' ';

                    continue;
                }

                $kod .= $token[1];

                continue;
            }

            $kod .= $token;
        }

        return $kod;
    }
}
