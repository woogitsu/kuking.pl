<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\PodstawaDecyzji;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POMIAR, NIE OPIS: kiedy plik zdjęcia przestaje być dostępny.
 *
 * Kartka `_wspolne/CSAM_JEDNA_KARTKA.md` przeszła sześć wersji bez ani jednego
 * uruchomienia aplikacji. Ten test jest tym uruchomieniem.
 *
 * MIERZY NA DYSKU ZE STEROWNIKIEM `r2` WSKAZANYM NA LOKALNE MinIO. To nie jest
 * ozdobnik: dysk lokalny nie ma podpisów (`providesTemporaryUrls() === false`),
 * więc `MediaController` idzie na nim gałęzią „oddaj plik przez PHP" — czyli
 * NIE tą, którą idzie produkcja. Pytanie „czy wcześniej wydany podpis działa
 * dalej" na dysku lokalnym nie daje się nawet zadać.
 *
 * MATERIAŁ JEST WYGENEROWANY: jednolity prostokąt z `imagecreatetruecolor`,
 * konta testowe, własna baza. Żadnych prawdziwych danych, żadnej produkcji.
 *
 * ZDJĘCIE O DWÓCH RODZICACH ZBUDOWANE WPROST W BAZIE (`attach()` na pivocie
 * `post_media`), bo zwykłą ścieżką użytkownika nie da się go zbudować:
 * `PostController::zebranZdjecia()` filtruje `->whereDoesntHave('posts')`.
 * To jest osobny wynik pomiaru, nie szczegół techniczny.
 *
 * Wymaga MinIO pod adresem z `POMIAR_S3_ENDPOINT`. Bez tej zmiennej test się
 * POMIJA — cicha zamiana pomiaru w „zielony bez pomiaru" jest tu gorsza niż
 * brak testu.
 */
class PomiarOdcieciaDostepuDoPlikuTest extends TestCase
{
    use RefreshDatabase;

    private const DYSK = 'pomiar_r2';

    /** @var list<array{czas: string, stan: string, kto: string, droga: string, kod: int, cache: string, cel: string}> */
    private array $pomiary = [];

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = env('POMIAR_S3_ENDPOINT');

        if (! is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('Brak POMIAR_S3_ENDPOINT — pomiar wymaga żywego magazynu S3.');
        }

        config()->set('filesystems.disks.'.self::DYSK, [
            // Ten sam sterownik co produkcja (`App\Support\Storage\DyskR2`).
            'driver' => 'r2',
            'key' => env('POMIAR_S3_KEY'),
            'secret' => env('POMIAR_S3_SECRET'),
            'region' => 'auto',
            'bucket' => env('POMIAR_S3_BUCKET', 'pomiar'),
            'endpoint' => $endpoint,
            // JEDYNA RÓŻNICA WOBEC PRODUKCJI: MinIO adresuje bucket ścieżką,
            // R2 hostem. Nie dotyka to ani podpisywania, ani `GetObject`.
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);
    }

    public function test_kiedy_plik_przestaje_byc_dostepny(): void
    {
        $minuty = max(1, (int) config('kuking.media.signed_url_minutes'));

        // ---------- materiał: wygenerowany jednolity prostokąt ----------
        $obraz = imagecreatetruecolor(960, 720);
        imagefill($obraz, 0, 0, (int) imagecolorallocate($obraz, 200, 200, 200));
        ob_start();
        imagewebp($obraz, null, 82);
        $bajty = (string) ob_get_clean();
        imagedestroy($obraz);

        $autor = $this->user('autorpomiaru');
        $moderator = $this->moderator();

        $klucz = 'media/pomiar/'.Str::uuid().'_feed.webp';
        Storage::disk(self::DYSK)->put($klucz, $bajty);

        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'disk' => self::DYSK,
            'variants_disk' => self::DYSK,
            'object_key' => $klucz,
            'metadata' => ['variants' => ['feed' => [
                'key' => $klucz, 'width' => 960, 'height' => 720, 'bytes' => strlen($bajty),
            ]]],
        ]);

        // ---------- dwie treści, jedno zdjęcie ----------
        $wpisA = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Treść A pomiaru',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $wpisB = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Treść B pomiaru',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // WPROST W BAZIE — patrz komentarz klasy.
        $wpisA->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $wpisB->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $adres = route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => 'feed']);

        // ---------- STAN 1: kontrola dodatnia ----------
        $podpis = $this->stan('1-przed-czymkolwiek', $adres, $autor, $moderator);

        $this->assertNotNull(
            $podpis,
            'KONTROLA DODATNIA PADŁA: gość nie dostał adresu przed żadną decyzją — pomiar nieważny.',
        );

        $this->zmierzPodpis('1-przed-czymkolwiek', $podpis);

        // ---------- STAN 2: `Usuń treść` na wpisie A ----------
        $this->decyzja($moderator, 'post', (string) $wpisA->getKey(), 'remove');
        $this->stan('2-po-usunieciu-A', $adres, $autor, $moderator);
        $this->zmierzPodpis('2-po-usunieciu-A', $podpis);

        // ---------- STAN 3: ban konta autora ----------
        $this->decyzja($moderator, 'user', (string) $autor->getKey(), 'ban');
        $this->assertSame(User::STATUS_BANNED, $autor->fresh()?->status);
        $this->stan('3-po-banie-autora', $adres, $autor, $moderator);
        $this->zmierzPodpis('3-po-banie-autora', $podpis);

        // ---------- STAN 4: usunięcie wpisu B ----------
        $this->decyzja($moderator, 'post', (string) $wpisB->getKey(), 'remove');
        $this->stan('4-po-usunieciu-B', $adres, $autor, $moderator);
        $this->zmierzPodpis('4-po-usunieciu-B', $podpis);

        // ---------- STAN 5: po wygaśnięciu podpisu ----------
        // Czekamy PRAWDZIWE tyle, ile mówi konfiguracja, plus 20 s zapasu.
        sleep($minuty * 60 + 20);
        $this->zmierzPodpis('5-po-wygasnieciu-podpisu', $podpis);

        $this->zapisz($minuty);

        // Jedyna asercja utrwalająca WYNIK, a nie życzenie: podpis wydany
        // przed decyzjami przeżywa je wszystkie, a gaśnie dopiero z zegarem.
        $this->assertSame(
            200,
            $this->kod('4-po-usunieciu-B', 'gość (stary podpis)'),
            'Stary podpis przestał działać po usunięciu obu treści i banie — ZMIANA wobec pomiaru z 21.09.2026.',
        );

        $this->assertSame(
            403,
            $this->kod('5-po-wygasnieciu-podpisu', 'gość (stary podpis)'),
            'Podpis nie wygasł po '.$minuty.' min — ZMIANA wobec pomiaru z 21.09.2026.',
        );
    }

    /**
     * Jedno przejście trasy aplikacji trzema pytającymi. Zwraca podpisany
     * adres z nagłówka `Location` dla gościa, o ile go dostał.
     */
    private function stan(string $stan, string $adres, User $autor, User $moderator): ?string
    {
        $podpis = null;

        foreach ([
            'gość' => null,
            'właściciel zdjęcia' => $autor,
            'moderator' => $moderator,
        ] as $kto => $widz) {
            $this->wyloguj();

            if ($widz !== null) {
                $this->actingAs($widz->fresh());
            }

            $odpowiedz = $this->get($adres);

            // DOKĄD prowadzi przekierowanie. Bez tego 302 na `/login`
            // (sesja unieważniona banem) policzyłoby się jako „widzi
            // zdjęcie" — dokładnie ten rodzaj fałszywego potwierdzenia,
            // przed którym ostrzega `CZYTAJ-TO-NAJPIERW.md`.
            $cel = (string) $odpowiedz->headers->get('Location');
            $magazyn = (string) env('POMIAR_S3_ENDPOINT');

            $this->zapiszPomiar(
                $stan,
                $kto,
                'trasa aplikacji',
                $odpowiedz->getStatusCode(),
                (string) $odpowiedz->headers->get('Cache-Control'),
                $cel === '' ? '(bez przekierowania)'
                    : (str_starts_with($cel, $magazyn) ? 'magazyn (podpisany adres)' : $cel),
            );

            if ($kto === 'gość' && $odpowiedz->getStatusCode() === 302) {
                $podpis = (string) $odpowiedz->headers->get('Location');
            }
        }

        return $podpis;
    }

    /** Ten sam podpisany adres magazynu, sprawdzany PRAWDZIWYM żądaniem HTTP. */
    private function zmierzPodpis(string $stan, ?string $podpis): void
    {
        if ($podpis === null) {
            $this->zapiszPomiar($stan, 'gość (stary podpis)', 'podpisany adres magazynu', 0, 'BRAK ADRESU');

            return;
        }

        $uchwyt = curl_init($podpis);
        curl_setopt_array($uchwyt, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $wynik = (string) curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        curl_close($uchwyt);

        preg_match('/^cache-control:\s*(.+)$/mi', $wynik, $dopasowanie);

        $this->zapiszPomiar(
            $stan,
            'gość (stary podpis)',
            'podpisany adres magazynu',
            $kod,
            trim($dopasowanie[1] ?? '(brak)'),
        );
    }

    private function decyzja(User $moderator, string $typ, string $id, string $akcja): void
    {
        $zgloszenie = Report::create([
            'target_type' => $typ,
            'target_id' => $id,
            'reason' => 'other',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->wyloguj();

        $this->actingAs($moderator->fresh())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => PodstawaDecyzji::KRZYWDZENIE_DZIECI,
                'note' => 'Pomiar odcięcia dostępu do pliku.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()?->status);
    }

    /** Powrót do stanu „nikt nie jest zalogowany" między pomiarami. */
    private function wyloguj(): void
    {
        Auth::forgetGuards();
    }

    private function zapiszPomiar(string $stan, string $kto, string $droga, int $kod, string $cache, string $cel = '-'): void
    {
        $this->pomiary[] = [
            'czas' => (new \DateTimeImmutable)->format('H:i:s.v'),
            'stan' => $stan,
            'kto' => $kto,
            'droga' => $droga,
            'kod' => $kod,
            'cache' => $cache === '' ? '(brak)' : $cache,
            'cel' => $cel,
        ];
    }

    private function kod(string $stan, string $kto): ?int
    {
        foreach ($this->pomiary as $pomiar) {
            if ($pomiar['stan'] === $stan && $pomiar['kto'] === $kto) {
                return $pomiar['kod'];
            }
        }

        return null;
    }

    private function zapisz(int $minuty): void
    {
        $wiersze = ['czas;stan;kto;droga;kod_http;cache_control;cel'];

        foreach ($this->pomiary as $pomiar) {
            $wiersze[] = implode(';', [
                $pomiar['czas'],
                $pomiar['stan'],
                $pomiar['kto'],
                $pomiar['droga'],
                (string) $pomiar['kod'],
                $pomiar['cache'],
                $pomiar['cel'],
            ]);
        }

        $naglowek = '# signed_url_minutes='.$minuty.'; dysk='.self::DYSK
            .'; sterownik=r2 (MinIO, path-style)';

        file_put_contents(
            (string) env('POMIAR_WYNIK', storage_path('pomiar-odciecia.csv')),
            $naglowek."\n".implode("\n", $wiersze)."\n",
        );
    }
}
