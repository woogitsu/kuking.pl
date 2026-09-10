<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `kuking:bramka-r2` MA MÓWIĆ PRAWDĘ — także tę niewygodną.
 *
 * Komenda istnieje dlatego, że bramki z issue #120 nie da się sprawdzić
 * z PHP. Jeśli sama zacznie meldować przejście tam, gdzie nic nie
 * sprawdziła, będzie GORSZA od jej braku: właściciel wystawi
 * `cdn.kuking.pl` przed bucketem, mając na ekranie zielony napis.
 *
 * Dlatego każdy test niżej pyta o to samo z innej strony: czy komenda
 * oblewa, gdy powinna. Sukces sprawdzamy raz; porażkę siedem razy.
 *
 * PUŁAPKA, KTÓRĄ TE TESTY PILNUJĄ NA WEJŚCIU: na dysku lokalnym każde
 * sprawdzenie tej komendy wychodzi ładnie. Nie ma bucketu, nie ma
 * publicznego adresu, więc „oryginał nie jest publiczny" jest prawdą —
 * tylko o R2 nie mówi nic.
 */
class BramkaR2MowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /** Klucz oryginału użyty w testach — jeden, żeby dało się go szukać w wyjściu. */
    private const KLUCZ_ORYGINALU = 'incoming/2026/09/rosol.jpg';

    /** Endpoint z identyfikatorem konta — NIE MA prawa wyjść na ekran. */
    private const ENDPOINT = 'https://1a2b3c4d5e6f.r2.cloudflarestorage.com';

    #[Test]
    public function test_dysk_lokalny_nie_udaje_przejscia_bramki(): void
    {
        Http::fake();

        // Domyślna konfiguracja testów: dysk `public`, czyli lokalny.
        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('Serwis NIE zapisuje zdjęć do R2')
            ->assertExitCode(1);

        // Bramka na dysku lokalnym nie ma prawa nawet zapytać R2 —
        // każde żądanie oznaczałoby, że komenda sprawdza coś innego,
        // niż serwis naprawdę używa.
        Http::assertNothingSent();
    }

    #[Test]
    public function test_jeden_bucket_dla_oryginalow_i_wariantow_oblewa(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2_publiczne.bucket' => 'kuking-oryginaly']);

        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('TEN SAM bucket')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_brak_zdjecia_w_bazie_nie_jest_sukcesem(): void
    {
        $this->ustawR2();

        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('nie ma ani jednego gotowego zdjęcia')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_zamkniety_bucket_i_pelny_zestaw_wariantow_daje_przejscie(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Część serwerowa bramki PRZESZŁA')
            ->expectsOutputToContain('r2.dev` wyłączone')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_bez_proby_zapisu_bramka_nie_jest_domknieta(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        // Wszystko inne w porządku, a mimo to nie ma przejścia: dowodu na
        // `PutObject` bez ACL nikt nie przedstawił.
        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('dołóż `--zapis`')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_wariant_dostepny_bez_podpisu_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 200, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: bucket wariantów oddaje pliki BEZ podpisu')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_oryginal_do_pobrania_z_endpointu_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 200);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: oryginał z pełnym EXIF-em')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_exif_w_wariancie_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie(trescWariantu: "RIFF\x00\x00\x00\x00WEBPEXIF\x00\x00GPS 52.2297 21.0122");
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: w wariancie siedzi blok EXIF')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_oryginaly_w_publicznym_buckecie_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        Storage::disk('r2_publiczne')->put('incoming/2026/09/obce.jpg', 'oryginał, który tu nie ma prawa leżeć');
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: leży tam 1 plik')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_brak_odpowiedzi_nie_jest_dowodem_zamkniecia(): void
    {
        $this->ustawR2();
        $this->zdjecie();

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('NIE WIEMY')
            ->expectsOutputToContain('bez niej nie wolno uznać, że bucket jest zamknięty')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_plik_probny_nie_zostaje_w_buckecie(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')->assertExitCode(0);

        $this->assertSame(
            [],
            Storage::disk('r2')->files('bramka'),
            'Plik próbny został w buckecie — bramka ma po sobie sprzątać.',
        );
    }

    #[Test]
    public function test_wyjscie_nie_niesie_endpointu_ani_sygnatury(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->doesntExpectOutputToContain(self::ENDPOINT)
            ->doesntExpectOutputToContain('1a2b3c4d5e6f')
            ->doesntExpectOutputToContain('expiration=')
            ->assertExitCode(0);
    }

    /**
     * Konfiguracja udająca prawdziwe R2: dwa dyski, dwa buckety, endpoint.
     *
     * `Storage::fake()` podstawia dysk lokalny, ale NIE rusza wpisów
     * w `config`, więc komenda widzi sterownik `r2` — i to jest tu
     * potrzebne, bo inaczej oblałaby na pierwszym sprawdzeniu. Dyski
     * podstawione przez `fake()` umieją podpisywać adresy
     * (`buildTemporaryUrlsUsing` w `Storage::fake`), czyli to samo, co
     * na R2 robi `temporaryUrl()`.
     */
    private function ustawR2(): void
    {
        config([
            'filesystems.disks.r2.driver' => 'r2',
            'filesystems.disks.r2.bucket' => 'kuking-oryginaly',
            'filesystems.disks.r2.endpoint' => self::ENDPOINT,
            'filesystems.disks.r2_publiczne.driver' => 'r2',
            'filesystems.disks.r2_publiczne.bucket' => 'kuking-media',
            'filesystems.disks.r2_publiczne.endpoint' => self::ENDPOINT,
            'kuking.media.disk' => 'r2',
            'kuking.media.public_disk' => 'r2_publiczne',
        ]);

        Storage::fake('r2');
        Storage::fake('r2_publiczne');
    }

    /** Gotowe zdjęcie: oryginał w prywatnym buckecie, trzy warianty w publicznym. */
    private function zdjecie(string $trescWariantu = "RIFF\x00\x00\x00\x00WEBPVP8 czysty wariant"): Media
    {
        $wlasciciel = $this->user('gotujaca');

        Storage::disk('r2')->put(self::KLUCZ_ORYGINALU, "\xFF\xD8\xFF\xE1Exif\x00\x00GPS 52.2297 21.0122");

        $warianty = [];

        foreach (['thumb', 'feed', 'large'] as $nazwa) {
            $klucz = 'media/2026/09/rosol_'.$nazwa.'.webp';
            Storage::disk('r2_publiczne')->put($klucz, $trescWariantu);
            $warianty[$nazwa] = ['key' => $klucz, 'width' => 800, 'height' => 600];
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'r2',
            'variants_disk' => 'r2_publiczne',
            'object_key' => self::KLUCZ_ORYGINALU,
            'mime_type' => 'image/jpeg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }

    /**
     * Odpowiedzi udawanego R2, rozdzielone po tym, CZEGO dotyczy żądanie.
     *
     * Adres podpisany przez `Storage::fake()` niesie `?expiration=` —
     * po tym poznajemy podpis. Adres oryginału poznajemy po tym, że stoi
     * na endpoincie konta, a nie na adresie aplikacji.
     */
    private function odpowiedziR2(int $bezPodpisu, int $oryginal): void
    {
        Http::fake(function (Request $zadanie) use ($bezPodpisu, $oryginal) {
            $adres = $zadanie->url();

            if (Str::contains($adres, self::KLUCZ_ORYGINALU)) {
                return Http::response('bajty oryginału', $oryginal);
            }

            if (Str::contains($adres, 'expiration=')) {
                return Http::response('bajty wariantu', 200);
            }

            return Http::response('', $bezPodpisu);
        });
    }
}
