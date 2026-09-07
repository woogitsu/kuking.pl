<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Media\KasujZdjecie;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Visibility\WidocznoscTestCase;

/**
 * Zdjęcie chronionej treści nie otwiera się nikomu, kto nie ma prawa do
 * samej treści (audyt W7-02, P0, prywatność — blokuje publiczną betę).
 *
 * CO BYŁO ZŁE
 * Adresem zdjęcia był adres pliku w buckecie z własną domeną CDN. Taki adres
 * nikogo o nic nie pyta i nie przestaje działać: kto raz go skopiował
 * (z podglądu strony, z historii przeglądarki, z podglądu linku w komunikatorze),
 * otwierał zdjęcie także PO zablokowaniu, PO cofnięciu obserwowania i PO
 * przełączeniu przepisu na prywatny. Cała macierz widoczności z
 * `WidocznoscTestCase` obowiązywała stronę HTML i nie obowiązywała ani jednego
 * piksela.
 *
 * Najgorszy przypadek nazwał audyt wprost: `recipes.source_scan_media_id` —
 * skan odręcznej kartki z rodzinnym przepisem, a na niej nazwiska, adresy
 * i czyjeś pismo. Ma go osobny test niżej.
 *
 * DLACZEGO TEN PLIK OPIERA SIĘ O `WidocznoscTestCase`
 * Bo tam stoi KANONICZNA TABELA PRAWDY tego produktu i nie może istnieć druga.
 * Zdjęcie nie ma własnej widoczności — ma widoczność treści, do której jest
 * przypięte — więc jego macierz musi być literalnie tą samą macierzą, nie
 * podobną. Klasa bazowa daje `test_macierz_widoku` dla zdjęcia głównego
 * przepisu; wpis, skan, awatar i krok mają tu własne pętle po tej samej
 * tabeli.
 *
 * CZEGO TEN TEST NIE UDOWODNI, I TRZEBA TO POWIEDZIEĆ WPROST
 * Nie sprawdza prawdziwego R2. Że bucket wariantów naprawdę przestał mieć
 * własną domenę, rozstrzyga panel Cloudflare, a nie PHP — to jest issue #120
 * i należy do właściciela. Tu pilnujemy strony aplikacyjnej: że adres zdjęcia
 * prowadzi przez kontrolę dostępu i że ta kontrola mówi to samo co Policy.
 */
class ZdjeciaChronioneNieWyciekajaTest extends WidocznoscTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `Storage::fake()` SAM dokłada `buildTemporaryUrlsUsing`, więc udawany
        // dysk zachowuje się jak produkcyjne R2: `MediaController` odpowiada
        // przekierowaniem na podpisany adres, a nie bajtami. To jest dobra
        // wiadomość — testowana jest ta sama gałąź kodu co na produkcji — ale
        // trzeba o tym wiedzieć, czytając asercje: „widzi zdjęcie" znaczy tu
        // 302 z nagłówkiem `Location`, nie 200 z treścią pliku.
        // Gałąź dla dysku BEZ podpisów (praca lokalna) ma osobny test niżej.
        Storage::fake('public');
    }

    // =================================================================
    //  Macierz z klasy bazowej — zdjęcie główne przepisu
    // =================================================================

    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'hero_media_id' => $this->zdjecie()->getKey(),
            'title' => 'Tajny zurek '.$widocznosc,
            'slug' => 'tajny-zurek-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);
    }

    /**
     * ADRESEM SPRAWDZANYM PRZEZ MACIERZ JEST ADRES ZDJĘCIA, nie strony przepisu.
     *
     * Tu jest cała różnica między tym plikiem a `RecipeWidocznoscTest`: tamten
     * pilnuje strony HTML, ten — bajtów obrazka, do których dawniej dało się
     * dojść z pominięciem strony.
     */
    protected function adres(Model $tresc): string
    {
        /** @var Recipe $tresc */
        return (string) $tresc->heroMedia?->url('feed');
    }

    /**
     * Macierz z klasy bazowej, przeliczona na odpowiedzi TRASY ZDJĘCIA.
     *
     * Metody bazowej nie da się użyć wprost, bo ona liczy „dostęp" jako 200,
     * a 302 traktuje jako jedną z form odmowy (przekierowanie na logowanie).
     * Dla zdjęcia jest odwrotnie: 302 na podpisany adres w buckecie TO JEST
     * dostęp, i to jedyna jego postać na produkcji. Wspólna zostaje rzecz
     * najważniejsza — `tabelaPrawdy()` i `widzowie()` pochodzą z klasy bazowej,
     * więc nie istnieje druga tabela prawdy do rozjechania się z pierwszą.
     */
    public function test_macierz_widoku(): void
    {
        $this->sprawdzMacierz('zdjęcie główne przepisu', function (string $widocznosc): string {
            /** @var Recipe $przepis */
            $przepis = $this->utworz($widocznosc);

            return $this->adres($przepis);
        });
    }

    /**
     * Klasa bazowa oczekuje tu 403. Zdjęcie odpowiada 404 i to jest ŚWIADOME:
     * 403 potwierdza istnienie pliku, a przy skanie odręcznej kartki sama
     * odpowiedź „istnieje, ale nie dla ciebie" jest już informacją.
     */
    public function test_blokada_dziala_w_obie_strony(): void
    {
        $blokujacy = $this->user('blokujaca');
        app(BlockUser::class)->handle($blokujacy, $this->autor);

        $tresc = $this->utworz('public');

        $this->actingAs($blokujacy)
            ->get($this->adres($tresc))
            ->assertStatus(404);
    }

    // =================================================================
    //  Ta sama macierz dla wpisu
    // =================================================================

    public function test_macierz_widoku_zdjecia_wpisu(): void
    {
        $this->sprawdzMacierz('wpis', function (string $widocznosc): string {
            $zdjecie = $this->zdjecie();

            $wpis = Post::factory()->create([
                'author_id' => $this->autor->getKey(),
                'visibility' => $widocznosc,
                'body' => 'Tajny rosol '.$widocznosc,
            ]);

            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

            return $zdjecie->url('feed');
        });
    }

    // =================================================================
    //  Zdjęcie "Ugotowałem" — jedyny rodzic bez własnego testu macierzy
    // =================================================================

    /**
     * `cooked_event_media` jest w obu listach ODWOLANIA i `CookedEventPolicy`
     * ma `view()` od dawna — ale przed tym testem NIC w tym pliku nie
     * przechodziło tą trasą przez `MediaController`. Bez niego regresja
     * w `CookedEventPolicy::view()` albo literówka w nazwie klasy przy
     * odgadywaniu Policy przez Gate przeszłaby tu niezauważona: reguła w
     * `DostepDoZdjecia::rodzice()` istnieje, ale nikt nie sprawdzał, czy
     * Gate naprawdę znajduje politykę i naprawdę zwraca to, co trzeba
     * (patrz `DostepDoZdjeciaBezPolicyRodzicaTest` — dowód, że brakująca
     * Policy NIE rzuca wyjątkiem, tylko cicho odmawia).
     *
     * Widoczność wykonania idzie za widocznością PRZEPISU (delegacja
     * w `CookedEventPolicy::view`), więc macierz jest ta sama.
     */
    public function test_macierz_widoku_zdjecia_wykonania(): void
    {
        $this->sprawdzMacierz('zdjęcie „Ugotowałem"', function (string $widocznosc): string {
            $zdjecie = $this->zdjecie();

            $przepis = Recipe::factory()->create([
                'author_id' => $this->autor->getKey(),
                'visibility' => $widocznosc,
                'title' => 'Zurek do ugotowania '.$widocznosc,
                'slug' => 'zurek-ugotowany-'.$widocznosc.'-'.Str::lower(Str::random(6)),
            ]);

            $wykonanie = CookedEvent::factory()->create([
                'user_id' => $this->autor->getKey(),
                'recipe_id' => $przepis->getKey(),
            ]);

            $wykonanie->media()->attach($zdjecie->getKey(), ['position' => 0]);

            return $zdjecie->url('feed');
        });
    }

    // =================================================================
    //  Skan odręcznej kartki — najgorszy przypadek z audytu
    // =================================================================

    /**
     * `recipes.source_scan_media_id` to zdjęcie kartki z zeszytu babci:
     * nazwisko, czasem adres, zawsze czyjeś pismo. Nie pokazuje go dziś żaden
     * widok, więc nikt by nie zauważył, że jest osiągalny — a był, pod stałym
     * adresem CDN-u, dla każdego, kto go raz zobaczył w źródle strony albo
     * dostał w paczce z danymi.
     */
    public function test_macierz_widoku_skanu_kartki(): void
    {
        $this->sprawdzMacierz('skan kartki', function (string $widocznosc): string {
            $zdjecie = $this->zdjecie();

            Recipe::factory()->create([
                'author_id' => $this->autor->getKey(),
                'visibility' => $widocznosc,
                'source_scan_media_id' => $zdjecie->getKey(),
                'title' => 'Zurek z zeszytu '.$widocznosc,
                'slug' => 'zurek-z-zeszytu-'.$widocznosc.'-'.Str::lower(Str::random(6)),
            ]);

            return $zdjecie->url('feed');
        });
    }

    // =================================================================
    //  Krok przepisu — rodzic, który nie miał własnej Policy
    // =================================================================

    public function test_macierz_widoku_zdjecia_kroku_przepisu(): void
    {
        $this->sprawdzMacierz('krok przepisu', function (string $widocznosc): string {
            $zdjecie = $this->zdjecie();

            $przepis = Recipe::factory()->create([
                'author_id' => $this->autor->getKey(),
                'visibility' => $widocznosc,
                'title' => 'Zurek krok po kroku '.$widocznosc,
                'slug' => 'zurek-krok-'.$widocznosc.'-'.Str::lower(Str::random(6)),
            ]);

            RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => 0,
                'instruction' => 'Zagotuj zakwas.',
                'media_id' => $zdjecie->getKey(),
            ]);

            return $zdjecie->url('feed');
        });
    }

    // =================================================================
    //  Szkic, ukrycie moderacyjne i usunięcie (domyka też W5-05)
    // =================================================================

    /**
     * Szkic to treść, której autor jeszcze nie opublikował. Zdjęcie szkicu
     * widzi wyłącznie on i moderator — także wtedy, gdy `visibility` stoi na
     * `public`, bo widoczność opublikowanej treści nie ma tu jeszcze zastosowania.
     */
    public function test_zdjecie_szkicu_widzi_tylko_autor_i_moderator(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Szkic zurku',
            'slug' => 'szkic-zurku-'.Str::lower(Str::random(6)),
        ]);

        $adres = $zdjecie->url('feed');

        $this->actingAs($this->autor)->get($adres)->assertStatus(302);
        $this->actingAs($this->moderator())->get($adres)->assertStatus(302);

        $this->actingAs($this->obcy)->get($adres)->assertNotFound();
        $this->actingAs($this->obserwujacy)->get($adres)->assertNotFound();
        $this->actingAs($this->zablokowany)->get($adres)->assertNotFound();

        Auth::logout();
        $this->get($adres)->assertNotFound();
    }

    /**
     * Ukrycie i usunięcie przez moderatora (ustalenie W5-05).
     *
     * Decyzja moderacyjna, po której zdjęcie nadal otwiera się pod stałym
     * adresem, nie jest decyzją — jest komunikatem. Przy treści usuniętej za
     * naruszenie prawa to jest różnica między „usunęliśmy" a „ukryliśmy
     * link".
     */
    #[DataProvider('stanyPoModeracji')]
    public function test_zdjecie_tresci_ukrytej_lub_usunietej_znika_dla_widzow(string $stanPrzepisu, string $stanWpisu): void
    {
        $zdjeciePrzepisu = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => $stanPrzepisu,
            'hero_media_id' => $zdjeciePrzepisu->getKey(),
            'title' => 'Zurek po moderacji '.$stanPrzepisu,
            'slug' => 'zurek-moderacja-'.$stanPrzepisu.'-'.Str::lower(Str::random(6)),
        ]);

        $zdjecieWpisu = $this->zdjecie();

        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => $stanWpisu,
        ]);
        $wpis->media()->attach($zdjecieWpisu->getKey(), ['position' => 0]);

        foreach ([$zdjeciePrzepisu, $zdjecieWpisu] as $zdjecie) {
            $adres = $zdjecie->url('feed');

            $this->actingAs($this->obcy)->get($adres)->assertNotFound();
            $this->actingAs($this->obserwujacy)->get($adres)->assertNotFound();

            Auth::logout();
            $this->get($adres)->assertNotFound();
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function stanyPoModeracji(): array
    {
        return [
            'ukryte przez moderatora' => [Recipe::STATUS_HIDDEN, Post::STATUS_HIDDEN],
            'usunięte przez moderatora' => [Recipe::STATUS_REMOVED, Post::STATUS_REMOVED],
        ];
    }

    // =================================================================
    //  Autor zbanowany albo pending_delete — nie tylko awatar
    // =================================================================

    /**
     * `test_awatar_zbanowanego_konta_znika_razem_z_profilem` niżej sprawdza
     * TYLKO awatar. `PostPolicy::view` i `RecipePolicy::view` mają ten sam
     * warunek (`! $post->author->jestDostepnyJakoAutor()`) — ale to jest
     * DRUGA kopia tej reguły w innym pliku, a BRAMKA_BETY.md §7 wprost ostrzega,
     * że reguła bywa poprawna w jednej warstwie i inna w drugiej. Sam fakt, że
     * `/wpisy/{id}` i `/przepisy/{slug}` dają 403 dla zbanowanego autora, NIE
     * dowodzi niczego o adresie ZDJĘCIA — to jest dokładnie ta sama różnica,
     * którą całe W7-02 miało zamknąć (adres HTML vs adres pliku).
     *
     * Zawieszenie (`suspended`) NIE wchodzi tutaj celowo — to kara czasowa
     * tylko na PUBLIKOWANIE, treść zawieszonej osoby zostaje widoczna
     * (`jestDostepnyJakoAutor()`, patrz `User::mozeCzytac()`).
     */
    #[DataProvider('stanyNiedostepnegoAutora')]
    public function test_zdjecie_wpisu_i_przepisu_autora_niedostepnego_znika_dla_widzow(string $stanAutora): void
    {
        $zdjecieWpisu = $this->zdjecie();
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpis->media()->attach($zdjecieWpisu->getKey(), ['position' => 0]);

        $zdjeciePrzepisu = $this->zdjecie();
        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjeciePrzepisu->getKey(),
            'title' => 'Zurek autora '.$stanAutora,
            'slug' => 'zurek-autora-'.$stanAutora.'-'.Str::lower(Str::random(6)),
        ]);

        match ($stanAutora) {
            'banned' => $this->autor->ban(),
            'pending_delete' => $this->autor->markForDeletion(),
        };

        foreach ([$zdjecieWpisu, $zdjeciePrzepisu] as $zdjecie) {
            $adres = $zdjecie->url('feed');

            $this->actingAs($this->obcy)->get($adres)->assertNotFound();
            $this->actingAs($this->obserwujacy)->get($adres)->assertNotFound();

            Auth::logout();
            $this->get($adres)->assertNotFound();

            // Moderator dalej widzi — inaczej nie dałoby się rozpatrzyć zgłoszenia.
            $this->actingAs($this->moderator())->get($adres)->assertStatus(302);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function stanyNiedostepnegoAutora(): array
    {
        return [
            'zbanowany' => ['banned'],
            'oczekujący na usunięcie konta' => ['pending_delete'],
        ];
    }

    /**
     * KONTROLA do testu wyżej: zawieszenie NIE ukrywa zdjęć, bo to kara
     * wyłącznie na publikowanie nowej treści, nie na to, co już opublikowano.
     */
    public function test_zdjecie_wpisu_autora_zawieszonego_zostaje_widoczne(): void
    {
        $zdjecie = $this->zdjecie();
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->autor->suspend();

        $this->actingAs($this->obcy)->get($zdjecie->url('feed'))->assertStatus(302);
    }

    // =================================================================
    //  Awatar
    // =================================================================

    public function test_awatar_widac_tak_samo_jak_profil(): void
    {
        $zdjecie = $this->zdjecie();

        Profile::query()
            ->where('user_id', $this->autor->getKey())
            ->update(['avatar_media_id' => $zdjecie->getKey()]);

        $adres = $zdjecie->url('thumb');

        // Profil aktywnej osoby jest publiczny — awatar też.
        $this->actingAs($this->obcy)->get($adres)->assertStatus(302);
        Auth::logout();
        $this->get($adres)->assertStatus(302);

        // Blokada działa tu tak samo jak na profilu.
        $this->actingAs($this->zablokowany)->get($adres)->assertNotFound();
    }

    public function test_awatar_zbanowanego_konta_znika_razem_z_profilem(): void
    {
        $zdjecie = $this->zdjecie();

        Profile::query()
            ->where('user_id', $this->autor->getKey())
            ->update(['avatar_media_id' => $zdjecie->getKey()]);

        $this->autor->ban();

        $adres = $zdjecie->url('thumb');

        $this->actingAs($this->obcy)->get($adres)->assertNotFound();

        Auth::logout();
        $this->get($adres)->assertNotFound();

        // Moderator widzi — inaczej nie dałoby się rozpatrzyć odwołania.
        $this->actingAs($this->moderator())->get($adres)->assertStatus(302);
    }

    // =================================================================
    //  Dwóch rodziców o różnej widoczności — najszerszy wygrywa
    // =================================================================

    /**
     * PRZYPADEK, KTÓREGO NIE TESTUJE NIKT, A ON DECYDUJE O POPRAWNOŚCI.
     *
     * To samo zdjęcie da się przypiąć do kilku treści: człowiek dodaje
     * niedzielny rosół jako wpis, a to samo zdjęcie ustawia jako główne
     * w przepisie. Gdyby wygrywał rodzic NAJWĘŻSZY, publiczny przepis
     * pokazywałby pustą ramkę tylko dlatego, że autor wrzucił to zdjęcie
     * gdzieś jeszcze — a bajty i tak byłyby jawne przez ten przepis.
     *
     * Reguła jest więc odwrotna: przepuszcza KTÓRYKOLWIEK rodzic. To nie jest
     * poluzowanie, tylko uczciwe nazwanie stanu faktycznego.
     */
    public function test_zdjecie_o_dwoch_rodzicach_widac_przez_szerszego(): void
    {
        $zdjecie = $this->zdjecie();

        $prywatnyWpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $prywatnyWpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Rosol niedzielny',
            'slug' => 'rosol-niedzielny-'.Str::lower(Str::random(6)),
        ]);

        $adres = $zdjecie->url('feed');

        $this->actingAs($this->obcy)->get($adres)->assertStatus(302);

        Auth::logout();
        $this->get($adres)->assertStatus(302);
    }

    public function test_dwoch_waskich_rodzicow_nie_sumuje_sie_do_dostepu(): void
    {
        // Kontrola do testu wyżej: „najszerszy wygrywa" nie może znaczyć
        // „dwa razy prywatne daje publiczne".
        $zdjecie = $this->zdjecie();

        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Rosol prywatny',
            'slug' => 'rosol-prywatny-'.Str::lower(Str::random(6)),
        ]);

        $adres = $zdjecie->url('feed');

        $this->actingAs($this->obcy)->get($adres)->assertNotFound();
        $this->actingAs($this->obserwujacy)->get($adres)->assertNotFound();

        Auth::logout();
        $this->get($adres)->assertNotFound();

        $this->actingAs($this->autor)->get($adres)->assertStatus(302);
    }

    // =================================================================
    //  Zdjęcie osierocone i zdjęcie w trakcie przetwarzania
    // =================================================================

    public function test_zdjecie_bez_rodzica_widzi_tylko_wlasciciel_i_moderator(): void
    {
        // Normalny stan w trakcie wypełniania formularza: plik już wgrany,
        // treści jeszcze nie ma. Podgląd w kreatorze musi działać.
        $zdjecie = $this->zdjecie();

        $adres = $zdjecie->url('feed');

        $this->actingAs($this->autor)->get($adres)->assertStatus(302);
        $this->actingAs($this->moderator())->get($adres)->assertStatus(302);
        $this->actingAs($this->obcy)->get($adres)->assertNotFound();

        Auth::logout();
        $this->get($adres)->assertNotFound();
    }

    public function test_zdjecie_w_trakcie_przetwarzania_nie_jest_serwowane(): void
    {
        // Dopóki `ProcessUploadedImage` nie przekodował pliku, w EXIF-ie siedzi
        // pełna lokalizacja GPS kuchni (AGENTS.md §7). Wyjątku nie ma nawet dla
        // właściciela: byłby pierwszym krokiem do serwowania oryginałów tą trasą.
        $zdjecie = $this->zdjecie();
        $adres = $zdjecie->url('feed');

        $zdjecie->update(['status' => Media::STATUS_PROCESSING]);

        $this->actingAs($this->autor)->get($adres)->assertNotFound();
        $this->actingAs($this->moderator())->get($adres)->assertNotFound();
    }

    // =================================================================
    //  Odmowa wygląda jak brak zdjęcia
    // =================================================================

    public function test_odmowa_jest_nieodrozninalna_od_zdjecia_nieistniejacego(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek prywatny',
            'slug' => 'zurek-prywatny-'.Str::lower(Str::random(6)),
        ]);

        $odmowa = $this->actingAs($this->obcy)->get($zdjecie->url('feed'));

        $nieistniejace = $this->actingAs($this->obcy)->get(
            route('media.show', ['media' => Str::uuid()->toString(), 'wariant' => 'feed']),
        );

        $odmowa->assertNotFound();
        $nieistniejace->assertNotFound();

        $this->assertSame(
            $this->bezJednorazowych((string) $nieistniejace->getContent()),
            $this->bezJednorazowych((string) $odmowa->getContent()),
            'Odpowiedź na cudze zdjęcie różni się od odpowiedzi na zdjęcie, którego nie ma. '.
            'Każda różnica — treść, nagłówek, długość — potwierdza, że plik istnieje, '.
            'a przy skanie odręcznej kartki samo to jest już informacją.',
        );
    }

    public function test_identyfikator_ktory_nie_jest_uuid_konczy_sie_404_a_nie_bledem_serwera(): void
    {
        // Bez ograniczenia `whereUuid` na trasie taki adres leci wprost do
        // Postgresa jako wartość kolumny `uuid`, a ten odpowiada błędem
        // składni — czyli 500. Strona błędu serwera wygląda inaczej niż 404,
        // więc samo jej pojawienie się byłoby kanałem informacyjnym.
        $this->get('/zdjecia/nie-jestem-uuid/feed')->assertNotFound();
    }

    // =================================================================
    //  Nagłówki cache
    // =================================================================

    public function test_zdjecie_chronione_nie_trafia_do_zadnego_cache(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek do szuflady',
            'slug' => 'zurek-do-szuflady-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->actingAs($this->autor)->get($zdjecie->url('feed'));

        $odpowiedz->assertStatus(302);

        // `no-store`, nie samo `private`: `private` pozwala przeglądarce
        // zapisać plik na dysku, a to jest dokładnie ta kopia, która zostaje
        // po wylogowaniu na współdzielonym komputerze.
        // Symfony sam porządkuje dyrektywy alfabetycznie, stąd „no-store,
        // private" zamiast kolejności z kontrolera — treść jest ta sama.
        $cache = (string) $odpowiedz->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }

    public function test_zdjecie_naprawde_publiczne_wolno_trzymac_we_wspolnym_cache(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek dla wszystkich',
            'slug' => 'zurek-dla-wszystkich-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->get($zdjecie->url('feed'));

        $odpowiedz->assertStatus(302);

        $cache = (string) $odpowiedz->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cache);

        preg_match('/max-age=(\d+)/', $cache, $dopasowanie);
        $maxAge = (int) ($dopasowanie[1] ?? -1);
        $waznoscPodpisu = 60 * (int) config('kuking.media.signed_url_minutes');

        $this->assertGreaterThan(
            0,
            $maxAge,
            'Zdjęcie treści publicznej ma dostać cache — bez niego każde '.
            'wyświetlenie feedu to komplet żądań do aplikacji.',
        );

        $this->assertLessThan(
            $waznoscPodpisu,
            $maxAge,
            'Cache przekierowania musi wygasać WCZEŚNIEJ niż podpis w adresie, '.
            'na który ono prowadzi. Przy równych wartościach klient, który '.
            'dostał 302 w pierwszej sekundzie okna, może użyć go ponownie '.
            'w ostatniej i pójść po adres już wygasły — pusta ramka znikająca '.
            'po odświeżeniu, czyli usterka nie do powtórzenia na żądanie. '.
            'Osobno: krótki cache jest też górnym ograniczeniem na to, jak '.
            'długo przełączenie przepisu na prywatny może nie odciąć dostępu.',
        );
    }

    /**
     * Zdjęcie widoczne dla wszystkich, ale oglądane przez osobę ZABLOKOWANĄ,
     * nie może wyjść z nagłówkiem pozwalającym na wspólny cache — bo odpowiedź
     * jest wtedy odmową, a odmowy nie wolno nikomu przechować.
     */
    public function test_odmowa_nie_dostaje_publicznego_cache(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek widoczny',
            'slug' => 'zurek-widoczny-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->actingAs($this->zablokowany)->get($zdjecie->url('feed'));

        $odpowiedz->assertNotFound();

        $this->assertStringNotContainsString(
            'public',
            (string) $odpowiedz->headers->get('Cache-Control'),
        );
    }

    // =================================================================
    //  Kształt rozwiązania: adres to trasa, bajty idą z bucketu
    // =================================================================

    public function test_adres_zdjecia_prowadzi_przez_aplikacje_a_nie_do_bucketu(): void
    {
        // TO JEST WARUNEK DZIAŁANIA CAŁEJ RESZTY TEGO PLIKU. Gdyby `Media::url()`
        // wróciło do `Storage::url()`, każdy test wyżej sprawdzałby adres,
        // którego aplikacja w ogóle nie obsługuje — i nadal byłby zielony przy
        // asercjach na odmowę.
        $zdjecie = $this->zdjecie();

        $this->assertSame(
            route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => 'feed']),
            $zdjecie->url('feed'),
        );

        foreach (array_keys((array) config('kuking.media.variants')) as $nazwa) {
            $this->assertStringNotContainsString(
                '.webp',
                $zdjecie->url((string) $nazwa),
                'Adres zdjęcia zawiera nazwę pliku w buckecie. Taki adres nie przechodzi '.
                'przez żadną Policy i działa wiecznie.',
            );
        }
    }

    public function test_dysk_z_podpisami_dostaje_przekierowanie_zamiast_bajtow_przez_php(): void
    {
        // Na produkcji warianty leżą na `r2_publiczne` (sterownik s3), który
        // podpisy umie. Bajty NIE MAJĄ iść przez PHP: jedno zdjęcie to kilkaset
        // kilobajtów, a jedna strona feedu potrafi ich mieć kilkadziesiąt.
        // `X-Accel-Redirect` nie wchodzi w grę, bo przed PHP stoi Caddy,
        // nie nginx — stąd 302.
        Storage::disk('public')->buildTemporaryUrlsUsing(
            fn (string $sciezka, \DateTimeInterface $wygasa): string => 'https://bucket.example/'.$sciezka.'?podpis=abc',
        );

        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek z bucketu',
            'slug' => 'zurek-z-bucketu-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->get($zdjecie->url('feed'));

        $odpowiedz->assertStatus(302);

        $cel = (string) $odpowiedz->headers->get('Location');

        $this->assertStringStartsWith('https://bucket.example/', $cel);
        $this->assertStringContainsString('podpis=abc', $cel);
    }

    /**
     * Dysk BEZ podpisów — tak wygląda praca lokalna i tylko tam bajty idą
     * przez PHP. Gałąź istnieje po to, żeby środowisko deweloperskie nie
     * różniło się od produkcji akurat w miejscu, którego nikt by wtedy
     * nie testował.
     */
    public function test_dysk_bez_podpisow_oddaje_plik_ale_dopiero_po_sprawdzeniu_dostepu(): void
    {
        // Osobny dysk, a nie `Storage::fake()`: fake ZAWSZE dokłada
        // podpisywanie adresów, więc na nim tej gałęzi nie da się dosięgnąć.
        config(['filesystems.disks.bez_podpisow' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/bez_podpisow'),
            'throw' => false,
        ]]);

        $zdjecie = $this->zdjecie(dysk: 'bez_podpisow');

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek lokalny',
            'slug' => 'zurek-lokalny-'.Str::lower(Str::random(6)),
        ]);

        $adres = $zdjecie->url('feed');

        $odpowiedz = $this->actingAs($this->autor)->get($adres);

        $odpowiedz->assertOk();

        // `streamedContent()`, nie `assertSee()`: kontroler oddaje tu
        // `StreamedResponse`, którego `getContent()` jest pusty, dopóki
        // strumień nie zostanie odczytany.
        $this->assertSame('udawane-bajty-feed', $odpowiedz->streamedContent());

        $this->actingAs($this->obcy)->get($adres)->assertNotFound();

        Auth::logout();
        $this->get($adres)->assertNotFound();
    }

    public function test_odmowa_nie_zdradza_adresu_pliku_w_buckecie(): void
    {
        Storage::disk('public')->buildTemporaryUrlsUsing(
            fn (string $sciezka, \DateTimeInterface $wygasa): string => 'https://bucket.example/'.$sciezka.'?podpis=abc',
        );

        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek ukryty',
            'slug' => 'zurek-ukryty-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->actingAs($this->obcy)->get($zdjecie->url('feed'));

        $odpowiedz->assertNotFound();

        $this->assertNull($odpowiedz->headers->get('Location'));
        $this->assertStringNotContainsString(
            'bucket.example',
            (string) $odpowiedz->getContent(),
        );
    }

    // =================================================================
    //  Lista rodziców nie może się rozjechać z listą odwołań
    // =================================================================

    /**
     * `KasujZdjecie::ODWOLANIA` jest pilnowaną listą wszystkiego, co może
     * wskazywać na `media` (osobny test czyta ją z kluczy obcych w schemacie).
     * `DostepDoZdjecia` musi znać dokładnie tę samą listę: tabela, o której
     * ta klasa nie wie, znaczy zdjęcie bez rodzica, czyli niewidoczne dla
     * wszystkich poza właścicielem — awarię po bezpiecznej stronie, ale nadal
     * awarię, i lepiej, żeby wyszła stąd niż ze zgłoszenia użytkownika.
     */
    public function test_kazde_odwolanie_do_zdjecia_ma_tu_swojego_rodzica(): void
    {
        $odwolania = KasujZdjecie::ODWOLANIA;
        $rodzice = DostepDoZdjecia::ODWOLANIA;

        sort($odwolania);
        sort($rodzice);

        $this->assertSame(
            $odwolania,
            $rodzice,
            'Lista rodziców w DostepDoZdjecia rozjechała się z KasujZdjecie::ODWOLANIA. '.
            'Nowa tabela wskazująca na `media` musi trafić w OBA miejsca.',
        );
    }

    // =================================================================
    //  Pomocnicze
    // =================================================================

    /**
     * Gotowe zdjęcie z trzema wariantami leżącymi na udawanym dysku.
     *
     * Pliki muszą naprawdę istnieć: na dysku bez podpisów kontroler oddaje je
     * sam i sprawdza wcześniej `exists()`, więc bez nich każdy test dostawałby
     * 404 — i cały ten plik byłby zielony z najgorszego możliwego powodu.
     */
    private function zdjecie(?User $wlasciciel = null, string $dysk = 'public'): Media
    {
        $identyfikator = Str::uuid()->toString();

        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';

            $warianty[$nazwa] = [
                'key' => $klucz,
                'width' => $krawedz,
                'height' => $krawedz,
            ];

            Storage::disk($dysk)->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => ($wlasciciel ?? $this->autor)->getKey(),
            'disk' => $dysk,
            'variants_disk' => $dysk,
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }

    /**
     * Kanoniczna tabela prawdy z `WidocznoscTestCase`, zastosowana do adresu
     * ZDJĘCIA zamiast do adresu strony.
     *
     * @param  callable(string): string  $utworzZdjecie  dostaje widoczność,
     *                                                   oddaje adres zdjęcia
     */
    private function sprawdzMacierz(string $opisTresci, callable $utworzZdjecie): void
    {
        foreach ($this->widocznosci() as $widocznosc) {
            $adres = $utworzZdjecie($widocznosc);

            foreach ($this->widzowie() as $ktoNazwa => $kto) {
                $powinienWidziec = $this->tabelaPrawdy()[$ktoNazwa][$widocznosc];

                // Jawne wylogowanie: `actingAs()` trzyma się kolejnych żądań
                // w tym samym teście, więc bez tego przypadek „niezalogowany"
                // dziedziczyłby użytkownika z poprzedniego obrotu pętli.
                if ($kto === null) {
                    Auth::logout();
                    $zadanie = $this->get($adres);
                } else {
                    $zadanie = $this->actingAs($kto)->get($adres);
                }

                $opis = sprintf(
                    'zdjęcie: %s / widoczność „%s" / widz „%s" — oczekiwano %s, dostano HTTP %d.',
                    $opisTresci,
                    $widocznosc,
                    $ktoNazwa,
                    $powinienWidziec ? 'DOSTĘPU' : 'ODMOWY',
                    $zadanie->getStatusCode(),
                );

                if ($powinienWidziec) {
                    $this->assertSame(302, $zadanie->getStatusCode(), $opis);
                } else {
                    $this->assertSame(404, $zadanie->getStatusCode(), $opis);
                }
            }
        }
    }

    /**
     * Usuwa z HTML-a to, co i tak różni się między dwoma żądaniami (nonce CSP,
     * token CSRF). Bez tego porównanie dwóch stron 404 nigdy by się nie udało,
     * a różnica, o którą tu chodzi, jest zupełnie inna.
     */
    private function bezJednorazowych(string $html): string
    {
        return (string) preg_replace(
            [
                '/nonce="[^"]*"/',
                '/content="[A-Za-z0-9]{20,}"/',
                // Identyfikator zdjęcia wraca w `og:url` i w `canonical` —
                // to jest echo ADRESU, o który pytano, a nie informacja o tym,
                // czy plik istnieje. Bez tej normalizacji porównanie dwóch
                // stron 404 nie udałoby się nigdy, bo adresy z definicji różne.
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            ],
            ['nonce="X"', 'content="X"', 'UUID'],
            $html,
        );
    }
}
