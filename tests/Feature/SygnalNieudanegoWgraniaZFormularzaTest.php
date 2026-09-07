<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audyt zewnętrzny, punkt N05: hipoteza do zmierzenia — `photo_upload_failed`
 * (issue #115) zapisuje się TYLKO wtedy, gdy zawiedzie przetwarzanie
 * WEWNĄTRZ `StoreUploadedImage`, a NIE wtedy, gdy żądanie odpada wcześniej:
 * na walidacji formularza (zły typ, za duży plik) albo na limicie żądań
 * (429). `StoreUploadedImage::handle()` w ogóle nie jest wołane, gdy
 * `$request->validate()` w `PostController::store()` rzuci
 * `ValidationException`, ani gdy throttle odetnie żądanie, zanim kontroler
 * w ogóle się uruchomi — a te dwie drogi są dokładnie tak samo „nieudanym
 * wgraniem zdjęcia" z punktu widzenia człowieka przed ekranem.
 *
 * Ten plik sprawdza WYNIK, nie mechanizm — wysyła żądania na prawdziwy
 * endpoint `posts.store`, tak jak zrobiłaby to przeglądarka, i patrzy, czy
 * w `product_signals` powstał wiersz. Uzupełnia `SygnalyProduktoweTest`,
 * który sprawdza to samo zdarzenie, ale wywołując `StoreUploadedImage`
 * bezpośrednio — czyli drogę, która już działa.
 *
 * CZWARTA DROGA Z AUDYTU („żądanie bez pliku") NIE MA TU TESTU-REGRESJI
 * PO STRONIE „musi zapisać sygnał", CELOWO. `photos` jest `nullable`
 * w KAŻDYM z czterech miejsc, które przyjmują zdjęcie (post, „Ugotowałem",
 * przepis, avatar) — wysyłka bez zdjęcia jest tu POPRAWNYM zachowaniem
 * (wpis tekstowy bez zdjęcia), nie odrzuceniem. Test niżej to potwierdza
 * jako kontrolę, a raport wyjaśnia, dlaczego to nie jest piąta droga do
 * naprawienia.
 */
class SygnalNieudanegoWgraniaZFormularzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    // -----------------------------------------------------------------
    // (a) zły typ pliku — odrzucone przez ObslugiwaneZdjecie W WALIDACJI,
    // StoreUploadedImage::handle() nigdy nie zostaje wywołane.
    // -----------------------------------------------------------------

    public function test_zly_typ_pliku_odrzucony_w_walidacji_formularza_zapisuje_sygnal(): void
    {
        $basia = $this->user('basia');

        $plikTekstowy = UploadedFile::fake()->createWithContent('notatka.jpg', 'to nie jest obraz');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$plikTekstowy],
            'visibility' => 'public',
        ]);

        // Kontrola, że w ogóle trafiliśmy na ścieżkę błędu walidacji, a nie
        // np. na 500 — inaczej test przechodziłby na próżno.
        $odpowiedz->assertSessionHasErrors();

        $this->assertSame(
            1,
            ProductSignal::query()->count(),
            'Zły typ pliku odrzucony PRZED `StoreUploadedImage::handle()` (walidacja formularza w '
            .'PostController::store) nie zapisał żadnego sygnału `photo_upload_failed`.',
        );
    }

    // -----------------------------------------------------------------
    // (b) za duży plik — j.w., odrzucone regułą `max:` / ObslugiwaneZdjecie
    // w walidacji, zanim StoreUploadedImage zdąży policzyć bajty sam.
    // -----------------------------------------------------------------

    public function test_za_duzy_plik_odrzucony_w_walidacji_formularza_zapisuje_sygnal(): void
    {
        $basia = $this->user('basia');
        config(['kuking.media.max_bytes' => 1024]);

        $zaDuzy = UploadedFile::fake()->image('obiad.jpg', 800, 600)->size(5000);

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$zaDuzy],
            'visibility' => 'public',
        ]);

        $odpowiedz->assertSessionHasErrors();

        $this->assertSame(
            1,
            ProductSignal::query()->count(),
            'Za duży plik odrzucony w walidacji formularza (`max:` kilobajtów) nie zapisał żadnego '
            .'sygnału `photo_upload_failed`.',
        );
    }

    // -----------------------------------------------------------------
    // (c) żądanie BEZ pliku — kontrola: `photos` jest nullable wszędzie,
    // więc to jest POPRAWNA wysyłka (wpis bez zdjęcia), nie odrzucenie.
    // Sygnał NIE POWINIEN powstać — to jest test kontrolny, nie regresyjny.
    // -----------------------------------------------------------------

    public function test_zadanie_bez_pliku_to_poprawny_wpis_tekstowy_bez_sygnalu(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Dziś tylko rosół, bez zdjęcia.',
            'visibility' => 'public',
        ]);

        $odpowiedz->assertSessionDoesntHaveErrors();
        $odpowiedz->assertRedirect();

        $this->assertSame(
            0,
            ProductSignal::query()->count(),
            'Wysyłka bez zdjęcia jest poprawnym wpisem tekstowym — nie powinna tworzyć sygnału '
            .'`photo_upload_failed`.',
        );
    }

    // -----------------------------------------------------------------
    // (d) 429 — limit żądań na trasie `posts.store` (config('kuking.limits.post')).
    // -----------------------------------------------------------------

    public function test_429_na_trasie_publikacji_ze_zdjeciem_zapisuje_sygnal(): void
    {
        Queue::fake();
        $basia = $this->user('basia');
        $limit = (int) explode(',', config('kuking.limits.post'))[0];

        // Wyczerpujemy limit żądaniami BEZ zdjęcia — throttle liczy PRZED
        // kontrolerem, więc treść żądania nie ma znaczenia dla samego
        // wyczerpania limitu (świadomie różne od tego, co wysyłamy jako
        // ostatnie, przekraczające żądanie).
        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($basia)->post(route('posts.store'), [
                'body' => "próba {$i}",
                'visibility' => 'public',
            ]);
        }

        $this->assertSame(
            0,
            ProductSignal::query()->count(),
            'Żądania W GRANICACH limitu nie powinny tworzyć żadnego sygnału o nieudanym wgraniu — '
            .'żadne z nich nie niosło zdjęcia.',
        );

        $zdjecie = UploadedFile::fake()->image('obiad.jpg', 800, 600);

        $przekraczajace = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$zdjecie],
            'visibility' => 'public',
        ]);

        $przekraczajace->assertStatus(429);

        $sygnal = ProductSignal::query()->where('signal_name', ZapiszSygnal::PHOTO_UPLOAD_FAILED)->first();

        $this->assertNotNull(
            $sygnal,
            'Żądanie ze zdjęciem odrzucone limitem żądań (429) nie zapisało żadnego sygnału '
            .'`photo_upload_failed` — z punktu widzenia osoby przed ekranem to jest dokładnie '
            .'tak samo nieudane wgranie zdjęcia jak każde inne.',
        );
    }

    // -----------------------------------------------------------------
    // Kontrola pozytywna: poprawne wgranie przez ten sam endpoint nie
    // tworzy żadnego sygnału błędu — bez tego powyższe asercje liczące
    // wiersze na 0 przechodziłyby także wtedy, gdyby POST w ogóle nie
    // dotarł do endpointu.
    // -----------------------------------------------------------------

    public function test_poprawne_wgranie_przez_endpoint_nie_zapisuje_sygnalu_bledu(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
            'visibility' => 'public',
        ]);

        $odpowiedz->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('product_signals', 0);
    }
}
