<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audyt zewnętrzny, punkt N02: `Cache-Control: no-store` stał na PRZEKIEROWANIU
 * (302), nie na odpowiedzi z samą treścią zdjęcia.
 *
 * CO BYŁO ZŁE
 * `MediaController::show()` liczy właściwy nagłówek `Cache-Control` i wkłada
 * go w odpowiedź 302 — ale `Storage::disk(...)->temporaryUrl()` woła się BEZ
 * trzeciego argumentu. Adres w `Location` jest więc podpisany tak samo dla
 * zdjęcia prywatnego i publicznego: TA SAMA sygnatura S3, żadnego narzuconego
 * nagłówka. Odpowiedź, którą naprawdę odda R2 na ten podpisany adres, nie ma
 * ŻADNEGO zakazu cache'owania — a to jest odpowiedź z bajtami zdjęcia, którą
 * może zapisać pośrednik (proxy, CDN, cache przeglądarki), bo jego własna
 * odpowiedź nic takiego nie zabrania. `Cache-Control` na 302 chroni tylko
 * SAM ADRES, nie treść, do której on prowadzi.
 *
 * DLACZEGO TEN TEST NIE UŻYWA `Storage::fake()`
 * `Illuminate\Support\Facades\Storage::fake()` podmienia dysk na
 * `LocalFilesystemAdapter` i sam dokłada `buildTemporaryUrlsUsing(fn ($path,
 * $expiration) => ...)` — zamyka DWA argumenty, więc trzeci (`$options`,
 * czyli właśnie `ResponseCacheControl`) jest przez PHP po cichu odrzucany.
 * Test na fałszywym dysku byłby zielony niezależnie od tego, czy kontroler
 * w ogóle przekazuje opcje dalej — czyli byłby zielony na zamkniętych
 * drzwiach. Dlatego dysk tutaj ma prawdziwy sterownik `s3`
 * (`Illuminate\Filesystem\AwsS3V3Adapter`), z fałszywymi kluczami i adresem
 * `endpoint`, którego nikt nie odpytuje: `createPresignedRequest()` tylko
 * PODPISUJE żądanie lokalnie (HMAC-SHA256), nie wysyła go — więc test działa
 * bez sieci i bez prawdziwego R2.
 *
 * CZEGO TEN TEST NIE UDOWADNIA, I TRZEBA TO POWIEDZIEĆ WPROST
 * Że AWS SDK umie ZBUDOWAĆ podpisany adres z `response-cache-control` w
 * zapytaniu — tak, to jest właśnie to, co ten test mierzy. Że Cloudflare R2
 * NAPRAWDĘ uwzględni ten parametr w swojej odpowiedzi — nie, tego z tego
 * kontenera nie da się rozstrzygnąć: R2 deklaruje zgodność z S3 API, ale to
 * pomiar wymagający prawdziwego bucketu R2 (patrz raport, sekcja „Decyzje dla
 * właściciela").
 */
class ZdjecieObiektuDostajeTenSamNoStoreCoPrzekierowanieTest extends TestCase
{
    use RefreshDatabase;

    private const DYSK = 'r2_testowy_bez_sieci';

    protected function setUp(): void
    {
        parent::setUp();

        // Prawdziwy sterownik `s3`, fałszywe dane uwierzytelniające. Presigning
        // jest operacją lokalną (podpis HMAC), więc żaden z poniższych testów
        // nie łączy się z siecią.
        config(['filesystems.disks.'.self::DYSK => [
            'driver' => 's3',
            'key' => 'klucz-testowy',
            'secret' => 'sekret-testowy',
            'region' => 'auto',
            'bucket' => 'kuking-warianty-testowe',
            'endpoint' => 'https://przyklad.invalid',
            'use_path_style_endpoint' => false,
            'throw' => true,
        ]]);
    }

    /**
     * POMIAR PRZED NAPRAWĄ (i test regresji po niej): treść chroniona.
     *
     * Nagłówek `Cache-Control` na samym przekierowaniu 302 jest już dobry
     * (`ZdjeciaChronioneNieWyciekajaTest::test_zdjecie_chronione_nie_trafia_do_zadnego_cache`
     * pilnuje tego od dawna). Ten test sprawdza DRUGĄ połowę: czy ten sam
     * zakaz dotarł też do adresu, pod który przekierowanie prowadzi —
     * czyli do odpowiedzi, która naprawdę niesie bajty zdjęcia.
     */
    public function test_prywatne_zdjecie_narzuca_no_store_takze_na_odpowiedzi_z_bucketu(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $zdjecie->owner_id,
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek prywatny do naglowkow',
            'slug' => 'zurek-prywatny-naglowki-'.Str::lower(Str::random(6)),
        ]);

        $wlasciciel = User::query()->findOrFail($zdjecie->owner_id);

        $odpowiedz = $this->actingAs($wlasciciel)->get($zdjecie->url('feed'));

        $odpowiedz->assertStatus(302);

        // Nagłówek NA PRZEKIEROWANIU — to jest już zamknięte, kontrola, że
        // naprawa nie zepsuła tego, co działało.
        $naPrzekierowaniu = (string) $odpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $naPrzekierowaniu);

        // Nagłówek NARZUCONY NA ODPOWIEDŹ Z BAJTAMI: musi być zaszyty w samym
        // podpisanym adresie (`response-cache-control` w query stringu GetObject),
        // bo to jedyny sposób, żeby R2 sam dołożył `Cache-Control: no-store`
        // do odpowiedzi, którą odda na ten adres. Bez tego adres w `Location`
        // nie różni się niczym od adresu do zdjęcia publicznego — a to jest
        // dokładnie luka z audytu (N02).
        $cel = (string) $odpowiedz->headers->get('Location');
        parse_str((string) parse_url($cel, PHP_URL_QUERY), $parametry);

        $this->assertArrayHasKey(
            'response-cache-control',
            $parametry,
            'Podpisany adres w Location nie niesie żadnego wymuszonego '.
            'Cache-Control — odpowiedź z bajtami zdjęcia (ta, którą naprawdę '.
            'odda R2) wraca bez zakazu cache\'owania, mimo że sam 302 go ma.',
        );

        $this->assertStringContainsString('no-store', $parametry['response-cache-control']);
        $this->assertStringContainsString('private', $parametry['response-cache-control']);

        // I musi to być TA SAMA reguła co na przekierowaniu — nie osobna
        // liczba do rozjechania się przy następnej zmianie. Porównanie
        // ZBIORU dyrektyw, nie ciągu znaków: Symfony porządkuje dyrektywy
        // na nagłówku HTTP alfabetycznie (`no-store, private`), a parametr
        // `response-cache-control` w adresie niesie surową wartość, którą
        // kontroler wysłał do S3 — ten sam zestaw słów, inna kolejność.
        $this->assertSame(
            $this->dyrektywy($naPrzekierowaniu),
            $this->dyrektywy($parametry['response-cache-control']),
        );
    }

    /**
     * KONTROLA: zdjęcie naprawdę publiczne wolno oddać ze wspólnym cache —
     * także na bajtach, nie tylko na przekierowaniu. Bez tego testu naprawa
     * mogłaby „naprawić" N02 przez wymuszenie `no-store` wszędzie, co
     * przywróciłoby problem z `docs/MEDIA_PIPELINE.md`: każde wejście na
     * publiczny feed biłoby prosto w aplikację.
     */
    public function test_zdjecie_publiczne_dostaje_publiczny_cache_takze_na_bajtach(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $zdjecie->owner_id,
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek publiczny do naglowkow',
            'slug' => 'zurek-publiczny-naglowki-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->get($zdjecie->url('feed'));

        $odpowiedz->assertStatus(302);

        $naPrzekierowaniu = (string) $odpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $naPrzekierowaniu);

        $cel = (string) $odpowiedz->headers->get('Location');
        parse_str((string) parse_url($cel, PHP_URL_QUERY), $parametry);

        $this->assertArrayHasKey('response-cache-control', $parametry);
        $this->assertStringContainsString('public', $parametry['response-cache-control']);
        $this->assertSame(
            $this->dyrektywy($naPrzekierowaniu),
            $this->dyrektywy($parametry['response-cache-control']),
        );
    }

    /**
     * ASERCJA KONTROLNA (obowiązkowa przed każdym pomiarem z tego pliku):
     * bez niej cały powyższy pomiar mógłby przechodzić na ZAMKNIĘTYCH
     * drzwiach — np. gdyby macierz widoczności przestała działać i KAŻDY
     * dostawał 302, łącznie z obcym na prywatnej treści, powyższe testy
     * i tak zmierzyłyby nagłówki (na błędnie wydanym przekierowaniu) i byłyby
     * zielone z najgorszego możliwego powodu. Ten test pilnuje, że obcy na
     * prywatnej treści dostaje odmowę, zanim w ogóle dojdzie do pytania
     * o nagłówki.
     */
    public function test_kontrola_obcy_nie_dostaje_zadnego_przekierowania_do_prywatnego_zdjecia(): void
    {
        $zdjecie = $this->zdjecie();

        Recipe::factory()->create([
            'author_id' => $zdjecie->owner_id,
            'visibility' => 'private',
            'hero_media_id' => $zdjecie->getKey(),
            'title' => 'Zurek prywatny kontrola',
            'slug' => 'zurek-prywatny-kontrola-'.Str::lower(Str::random(6)),
        ]);

        $obcy = $this->user('obca_kontrola');

        $odpowiedz = $this->actingAs($obcy)->get($zdjecie->url('feed'));

        // `MediaController` celowo odpowiada 404, nie 403 (patrz komentarz
        // klasy) — ale 403 też byłoby poprawną odmową, gdyby to zachowanie
        // się kiedyś zmieniło; nie wolno dostać 302.
        $this->assertContains($odpowiedz->getStatusCode(), [403, 404]);
        $this->assertNull($odpowiedz->headers->get('Location'));
    }

    /**
     * Rozbija wartość `Cache-Control` na zbiór dyrektyw, żeby porównanie nie
     * zależało od ich kolejności (Symfony porządkuje je alfabetycznie na
     * prawdziwym nagłówku HTTP, ale nie na wartości wpisanej ręcznie
     * w parametr `response-cache-control` adresu).
     *
     * @return list<string>
     */
    private function dyrektywy(string $cacheControl): array
    {
        $dyrektywy = array_map('trim', explode(',', $cacheControl));
        sort($dyrektywy);

        return $dyrektywy;
    }

    /**
     * Gotowe zdjęcie z wariantem `feed` na prawdziwym sterowniku `s3`
     * (fałszywe poświadczenia, żadnego ruchu sieciowego).
     */
    private function zdjecie(): Media
    {
        $wlasciciel = $this->user('autorka_naglowki_'.Str::lower(Str::random(6)));
        $identyfikator = Str::uuid()->toString();
        $klucz = 'media/'.$identyfikator.'_feed.webp';

        // Świadomie BEZ `Storage::disk(...)->put()`: na tym dysku wysłałoby
        // to prawdziwe zapytanie do (nieistniejącego) endpointu. Nie jest
        // nam to potrzebne — `MediaController` na dysku z podpisami (czyli
        // takim jak ten) nigdy nie czyta samego pliku, tylko podpisuje adres
        // do klucza, który już zna z metadanych.
        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => self::DYSK,
            'variants_disk' => self::DYSK,
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => [
                'feed' => ['key' => $klucz, 'width' => 960, 'height' => 960],
            ]],
        ]);
    }
}
