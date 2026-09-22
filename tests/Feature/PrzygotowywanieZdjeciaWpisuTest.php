<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\PublishPost;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Zdjęcie wpisu tuż po publikacji (audyt A2, potem issue #430).
 *
 * CO SIĘ DZIAŁO — I DLACZEGO PIERWSZA NAPRAWA BYŁA ZA SŁABA
 *
 * `StoreUploadedImage` wrzuca przetwarzanie do kolejki i wraca natychmiast,
 * a `PostController::store()` od razu przekierowuje na stronę wpisu. Między
 * publikacją a końcem zadania w tle jest okno, w którym `Media` ma status
 * `pending`. Audyt A2 kazał wypełnić to okno KOMUNIKATEM, żeby w miejscu
 * zdjęcia nie było pustki — i tak też było zrobione.
 *
 * Właściciel serwisu odrzucił to rozwiązanie (#430) i miał rację:
 *
 *   „trzeba jakoś od razu im pokazywać zdjęcie które dodali, a nie napis że
 *    jest przetwarzane. (…) Starzy ludzie nie czytają i będzie panika co się
 *    stało..."
 *
 * Okno nie jest tu bowiem przypadkiem ani skutkiem obciążenia — wgranie
 * zdjęcia i publikacja wpisu to JEDNO żądanie, więc w chwili pierwszego
 * renderu strony zadanie w tle nie mogło było policzyć niczego. Komunikat nie
 * był więc rzadkim widokiem na wypadek opóźnienia, tylko TYM, co po
 * opublikowaniu wpisu widziała każda osoba, zawsze.
 *
 * DZIŚ: `StoreUploadedImage` robi wariant `podglad` synchronicznie
 * (`App\Domain\Media\PodgladOdRazu`), więc pierwszy render ma już co pokazać.
 * Komunikat zostaje dla sytuacji, w których wariantu naprawdę nie ma —
 * i te też mają tu swoje testy, bo inaczej ten plik byłby zielony nad
 * usterką, którą opisuje #432.
 *
 * DLACZEGO TE TESTY NIE UFAJĄ KOLEJCE
 * `phpunit.xml` ustawia `QUEUE_CONNECTION=sync` — zadanie skończyłoby się,
 * zanim kontroler zdąży odpowiedzieć, więc okno „warianty jeszcze nie
 * policzone" w ogóle by nie istniało. Każdy test niżej używa `Queue::fake()`,
 * żeby zamrozić `Media` dokładnie w stanie z produkcji.
 */
class PrzygotowywanieZdjeciaWpisuTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * Jedyny sposób odróżnienia „prawdziwe zdjęcie" od „miejsce na zdjęcie".
     *
     * Strona ZAWSZE zawiera jakiś `<img>` — choćby pusty podgląd w nakładce
     * powiększenia (`layout.blade.php`, `#powiekszenie`) — a blok zastępczy
     * też dostaje klasę `post-photo`, żeby zajmować to samo miejsce.
     */
    private const ZNACZNIK_ZDJECIA = '<img class="post-photo"';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_autor_widzi_swoje_zdjecie_od_razu_po_publikacji_a_nie_napis_o_nim(): void
    {
        // To jest issue #430 zapisane jako test.
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 1200, 900)],
            'visibility' => 'public',
        ]);

        $post = Post::firstOrFail();
        $zdjecie = $post->media->first();

        // Stan odtworzony NAPRAWDĘ, nie udawany: zadanie w tle nie ruszyło,
        // więc wariantów z `kuking.media.variants` nie ma ani jednego.
        $this->assertSame(Media::STATUS_PENDING, $zdjecie->status, 'Test nie odtwarza stanu, który miał sprawdzić: zdjęcie jest już gotowe.');
        $this->assertFalse($zdjecie->maWariant('feed'), 'Test nie odtwarza stanu, który miał sprawdzić: zadanie w tle zdążyło policzyć warianty.');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            self::ZNACZNIK_ZDJECIA,
            $tresc,
            'Autorka nie widzi swojego zdjęcia zaraz po publikacji — dokładnie usterka #430.',
        );
        $this->assertStringNotContainsString('przygotowuje', $tresc);
    }

    public function test_zdjecie_widoczne_od_razu_jest_wariantem_a_nie_oryginalem(): void
    {
        // WARUNEK TWARDSZY NIŻ SAMA WIDOCZNOŚĆ ZDJĘCIA. Oryginał niesie EXIF
        // (aparat, data, a w wierszach sprzed D-023 także GPS kuchni).
        // „Pokazujemy od razu" nie ma prawa znaczyć „pokazujemy plik
        // przysłany przez użytkownika".
        Queue::fake();

        $basia = $this->user('basia');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 1200, 900),
        );

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(self::ZNACZNIK_ZDJECIA, $tresc);

        // Klucz oryginału nie pada na stronie ani razu — ani jako adres, ani
        // w `srcset`. Adresem jest trasa aplikacji, a ta zna wyłącznie nazwy
        // wariantów (biała lista w `routes/web.php`).
        $this->assertStringNotContainsString(
            $zdjecie->object_key,
            $tresc,
            'Na stronie wpisu pojawił się klucz ORYGINAŁU — pliku z nietkniętym EXIF-em.',
        );

        // A to, co widać, przeszło przez nasz koder: `podglad` jest jedynym
        // wariantem, jaki na tym etapie istnieje.
        $zdjecie->refresh();
        $this->assertTrue($zdjecie->maWariant('podglad'));
        $this->assertSame('image/webp', Storage::disk('public')->mimeType(
            (string) $zdjecie->wariant('podglad')['key'],
        ));
    }

    public function test_inni_widzowie_tez_widza_zdjecie_a_nie_komunikat(): void
    {
        // Podgląd nie jest prywatnym udogodnieniem autorki: to zwykły wariant,
        // więc wpis od pierwszej sekundy wygląda tak samo dla wszystkich,
        // którzy mają prawo go widzieć.
        Queue::fake();

        $basia = $this->user('basia');
        $ktos = $this->user('ktos');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 1200, 900)],
            'visibility' => 'public',
        ]);
        $post = Post::firstOrFail();

        $tresc = $this->trescEkranu(
            $this->actingAs($ktos)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(self::ZNACZNIK_ZDJECIA, $tresc);
        $this->assertStringNotContainsString('przygotowuje', $tresc);
    }

    public function test_odswiezenie_strony_podmienia_podglad_na_pelne_warianty_bez_javascriptu(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 1200, 900),
        );

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $przed = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );
        $this->assertStringContainsString(self::ZNACZNIK_ZDJECIA, $przed);
        $this->assertStringNotContainsString('_feed.webp', $przed, 'Test nie odtwarza stanu: wariant `feed` już istnieje przed przetworzeniem.');

        // To, co na produkcji robi zadanie w tle — wołane wprost. Bez linijki
        // JavaScriptu po stronie klienta: samo odświeżenie strony (kolejny GET).
        (new ProcessUploadedImage($zdjecie->getKey()))->handle();

        $po = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(self::ZNACZNIK_ZDJECIA, $po);
        $this->assertStringContainsString('feed', $po, 'Po przetworzeniu strona ma sięgać po prawdziwe warianty, nie zostać przy podglądzie.');
        $this->assertStringNotContainsString('przygotowuje', $po);
    }

    public function test_podglad_zostaje_w_metadanych_po_przetworzeniu_zeby_jego_plik_dalo_sie_skasowac(): void
    {
        // `KasujZdjecie` chodzi po `metadata.variants` i nie ma innego sposobu,
        // żeby dowiedzieć się o pliku w buckecie. Gdyby zadanie w tle
        // nadpisało warianty w całości, plik podglądu zostałby tam na zawsze —
        // sierota, której nie usuwa ani skasowanie wpisu, ani wymazanie konta.
        Queue::fake();

        $basia = $this->user('basia');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 1200, 900),
        );

        $kluczPodgladu = (string) $zdjecie->wariant('podglad')['key'];

        (new ProcessUploadedImage($zdjecie->getKey()))->handle();

        $zdjecie->refresh();

        $this->assertSame(Media::STATUS_READY, $zdjecie->status);
        $this->assertSame(
            $kluczPodgladu,
            $zdjecie->wariant('podglad')['key'] ?? null,
            'Zadanie w tle zgubiło wariant `podglad` — jego plik został w buckecie bez żadnego uchwytu w bazie.',
        );
        $this->assertTrue($zdjecie->maWariant('feed'));
    }

    public function test_gdy_podgladu_nie_da_sie_zrobic_autor_dostaje_czytelny_komunikat_zamiast_pustki(): void
    {
        // STAN „WARIANTU NAPRAWDĘ NIE MA" — zbudowany naprawdę, nie udawany:
        // próg megapikseli ustawiony poniżej rozmiaru zdjęcia, czyli dokładnie
        // ta gałąź `PodgladOdRazu`, która chroni pamięć kontenera przed
        // dekodowaniem wielkiego pliku w żądaniu webowym.
        //
        // Bez tego testu cały ten plik byłby zielony nad usterką #432, bo
        // komunikat zastępczy nie renderowałby się już nigdzie.
        Queue::fake();
        config(['kuking.media.podglad.max_megapixels' => 1]);

        $basia = $this->user('basia');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('wielkie.jpg', 2000, 1500),
        );

        $this->assertFalse($zdjecie->maWariant('podglad'), 'Test nie odtwarza stanu, który miał sprawdzić: podgląd jednak powstał.');

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringNotContainsString(self::ZNACZNIK_ZDJECIA, $tresc);
        $this->assertStringContainsString('przygotowuje', $tresc);
        $this->assertStringContainsString('Nic nie zginęło', $tresc);
    }

    public function test_obcy_widz_dostaje_komunikat_bez_zwracania_sie_do_niego_jak_do_autora(): void
    {
        Queue::fake();
        config(['kuking.media.podglad.max_megapixels' => 1]);

        $basia = $this->user('basia');
        $ktos = $this->user('ktos');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('wielkie.jpg', 2000, 1500),
        );
        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($ktos)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString('przygotowuje', $tresc);
        $this->assertStringNotContainsString('Twoje zdjęcie', $tresc);
    }

    public function test_wymiary_znacznika_opisuja_wariant_ktory_idzie_do_przegladarki_a_nie_oryginal(): void
    {
        // SKOK UKŁADU, NIE DROBIAZG. Kolumny `width`/`height` opisują plik
        // PRZED obrotem z EXIF-u, a wariant jest już obrócony. Zdjęcie
        // z telefonu trzymanego pionowo (`Orientation` 6 albo 8) miałoby więc
        // w atrybutach 4032×3024, będąc w rzeczywistości 3024×4032 — karta
        // skakałaby o pół ekranu w chwili, w której zdjęcie się wczyta.
        // Przy powiększonym tekście na telefonie to jest skok na cały ekran.
        $basia = $this->user('basia');

        $zdjecie = Media::factory()->zSamymPodgladem()->create([
            'owner_id' => $basia->getKey(),
            // Oryginał POZIOMY, wariant PIONOWY — dokładnie to, co robi obrót.
            'width' => 4032,
            'height' => 3024,
        ]);

        $zdjecie->update(['metadata' => ['variants' => [
            'podglad' => ['key' => (string) $zdjecie->wariant('podglad')['key'], 'width' => 480, 'height' => 640],
        ]]]);
        $zdjecie->refresh();

        // Żądamy `feed`, którego nie ma — odpowiedź ma opisywać to, co
        // NAPRAWDĘ pójdzie do przeglądarki, czyli podgląd.
        $this->assertSame(480, $zdjecie->width('feed'));
        $this->assertSame(640, $zdjecie->height('feed'));

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString('width="480"', $tresc);
        $this->assertStringContainsString('height="640"', $tresc);
        $this->assertStringNotContainsString('width="4032"', $tresc, 'Znacznik opisuje wymiary ORYGINAŁU, nie serwowanego wariantu — karta skoczy przy wczytaniu zdjęcia.');
    }

    public function test_blok_zastepczy_nie_ma_wlasnego_wezszego_ograniczenia_niz_zdjecie(): void
    {
        // Kryterium z #432: „blok zastępczy jest dzieckiem tego samego
        // kontenera co zdjęcie i nie ma własnego, węższego ograniczenia".
        // Pilnujemy tego na ZNACZNIKACH, bo szerokość mierzy się w
        // przeglądarce, a tu można sprawdzić warunek, który ją umożliwia:
        // ten sam kontener (klasa `post-photo`) i żadnej klasy kształtu,
        // która narzucałaby proporcje zdjęcia, którego nie ma.
        Queue::fake();
        config(['kuking.media.podglad.max_megapixels' => 1]);

        $basia = $this->user('basia');

        $zdjecie = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('wielkie.jpg', 2000, 1500),
        );
        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString('class="post-photo photo-placeholder"', $tresc);

        foreach (['photo-placeholder-kwadrat', 'photo-placeholder-pion', 'photo-placeholder-panorama'] as $klasaKsztaltu) {
            $this->assertStringNotContainsString(
                $klasaKsztaltu,
                $tresc,
                'Blok zastępczy znów udaje wysokość zdjęcia, którego nie ma (#432).',
            );
        }

        // KAŻDE ZDANIE OSOBNO — to jest naprawa osieroconej kropki. Znak
        // interpunkcyjny nie ma jak zostać sam na początku wiersza, jeśli
        // kończy zdanie, które mieści się w jednym wierszu.
        preg_match_all('/<p class="photo-placeholder-zdanie">\s*(.+?)\s*<\/p>/s', $tresc, $trafienia);

        $this->assertCount(2, $trafienia[1], 'Komunikat autora ma być rozbity na dwa krótkie zdania.');

        foreach ($trafienia[1] as $zdanie) {
            $this->assertStringEndsWith('.', trim($zdanie), "Zdanie „{$zdanie}” nie kończy się kropką — kropka została w innym wierszu.");
        }
    }

    public function test_trwale_nieudane_przetworzenie_mowi_o_tym_zamiast_wiecznej_pustki(): void
    {
        $basia = $this->user('basia');

        $zdjecie = Media::factory()->pending()->create([
            'owner_id' => $basia->getKey(),
            'status' => Media::STATUS_REJECTED,
            'metadata' => ['variants' => [], 'failure_reason' => 'processing_failed'],
        ]);

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$zdjecie->getKey()], visibility: 'public');

        $tresc = $this->trescEkranu(
            $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent(),
        );

        $this->assertStringContainsString('Nie udało się przygotować', $tresc);
        $this->assertStringNotContainsString('przygotowuje.', $tresc, 'Zdjęcie, które padło na dobre, nie powinno dalej udawać, że „się przygotowuje".');
    }
}
