<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\KasujZdjecie;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * PRÓBA ODTWORZENIA ZDJĘĆ NA ATRAPIE MAGAZYNU (#617, docs/infra/DR_ZDJEC_R2.md §7.1).
 *
 * Runbook każe właścicielowi wykonać próbę na koncie testowym i prawdziwym R2.
 * Ten test jest jej wersją NA SUCHO, bez żadnego połączenia z R2, Cloudflare
 * czy produkcją: trzy dyski `Storage::fake()` (oryginały, warianty, bucket
 * kopii) i PRAWDZIWY potok zdjęć (`StoreUploadedImage` + `ProcessUploadedImage`).
 *
 * PO CO OSOBNY OD `KopiaZdjecSprawdzanaTylkoOdczytemTest`
 * Tamten test kładzie do kopii klucze wpisane ręcznie z fabryki. Ten bierze
 * klucze i sumy, które wytwarza potok, więc pilnuje UMOWY między trzema
 * miejscami, które muszą mówić tym samym językiem: układem migawki z runbooka
 * (`oryginaly/<object_key>`, `warianty/<klucz wariantu>`), zapisem potoku
 * (`media.object_key`, `metadata.variants.*.key`/`bytes`, `checksum_sha256`)
 * i komendą `kuking:sprawdz-kopie-zdjec`. Zmiana układu kluczy w potoku
 * rozjechałaby kopię po cichu — ten test jest miejscem, gdzie to zobaczymy.
 *
 * CO TEN TEST ROBI, KROK PO KROKU (jak §7.1 runbooka)
 *  1. trzy zdjęcia przez potok; dwa przypięte do wpisów, trzecie „konta,
 *     które za chwilę zniknie";
 *  2. migawka: kopia obiektów pod datowanym prefiksem + jeden obiekt cudzy;
 *  3. kontrola migawki z `--sumy` musi być czysta;
 *  4. utrata: znikają pliki jednego zdjęcia i jeden wariant drugiego;
 *  5. po migawce „konto wymazane": trzecie zdjęcie znika z bazy i z żywych
 *     dysków, a w migawce zostaje (retencja techniczna);
 *  6. odtworzenie WYŁĄCZNIE według wierszy `media` z bazy, z weryfikacją SHA-256;
 *  7. wszystko wraca, a zdjęcie z pkt 5 NIE wraca do żywych dysków.
 *
 * CZEGO NIE DOWODZI
 * Niczego o prawdziwym R2: uprawnieniach tokenów, Bucket Locku, lifecycle,
 * przepustowości ani czasie odtworzenia (RPO/RTO). Te pomiary zostają
 * właścicielowi (tabela §8 runbooka). Atrapa dowodzi, że NASZ kod i NASZ
 * układ kluczy pozwalają odtworzyć zdjęcia — nie że kopia istnieje.
 *
 * ODTWORZENIE JEST TU CZYNNOŚCIĄ TESTU, nie aplikacji: w aplikacji nie ma
 * komendy zapisującej do żywych bucketów z kopii i to jest celowe (runbook §9:
 * odtwarza wyłącznie właściciel, tymczasowym tokenem).
 */
class OdtworzenieZdjecZMigawkiTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'dr_oryginaly';

    private const WARIANTY = 'dr_warianty';

    private const KOPIA = 'r2_kopia_zdjec';

    private const MIGAWKA = 'migawka-2026-09-28/';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(self::ORYGINALY);
        Storage::fake(self::WARIANTY);
        Storage::fake(self::KOPIA);

        config([
            'kuking.media.disk' => self::ORYGINALY,
            'kuking.media.public_disk' => self::WARIANTY,
            // Po `Storage::fake()` konfiguracja dysku kopii jest lokalna, więc
            // wartości, które komenda sprawdza przed startem, wpisujemy z powrotem.
            'filesystems.disks.r2_kopia_zdjec.bucket' => 'kuking-zdjecia-kopia',
            'filesystems.disks.r2_kopia_zdjec.key' => 'token-tylko-odczytu',
            'filesystems.disks.r2_kopia_zdjec.secret' => 'sekret-tylko-odczytu',
        ]);
    }

    /**
     * Zdjęcie przez prawdziwy potok: wgranie, przetworzenie, stan `ready`.
     */
    private function zdjecieZPotoku(User $wlasciciel, int $szerokosc): Media
    {
        $media = app(StoreUploadedImage::class)->handle(
            $wlasciciel,
            UploadedFile::fake()->image('obiad.jpg', $szerokosc, 600),
        );

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status, 'Potok nie doprowadził zdjęcia do `ready` — próba nie ma czego kopiować.');
        $this->assertNotEmpty($media->metadata['variants'] ?? [], 'Potok nie zapisał wariantów — kopia obejmowałaby tylko oryginał.');

        return $media;
    }

    private function wpis(User $autor, Media $media): Post
    {
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $wpis->media()->attach($media->getKey());

        return $wpis;
    }

    /**
     * Wszystkie obiekty zdjęcia: [dysk żywy, klucz żywy, klucz w migawce].
     *
     * @return list<array{dysk: string, klucz: string, wmigawce: string}>
     */
    private function obiekty(Media $media): array
    {
        $lista = [[
            'dysk' => $media->disk,
            'klucz' => $media->object_key,
            'wmigawce' => self::MIGAWKA.'oryginaly/'.$media->object_key,
        ]];

        foreach ($media->metadata['variants'] as $wariant) {
            $lista[] = [
                'dysk' => $media->variantsDisk(),
                'klucz' => $wariant['key'],
                'wmigawce' => self::MIGAWKA.'warianty/'.$wariant['key'],
            ];
        }

        return $lista;
    }

    /**
     * Migawka jak z runbooka §5: `copy`, nigdy `sync` — nic nie jest kasowane
     * w kopii, obiekty tylko przybywają.
     *
     * @param  list<Media>  $zdjecia
     */
    private function zrobMigawke(array $zdjecia): int
    {
        $liczba = 0;

        foreach ($zdjecia as $media) {
            foreach ($this->obiekty($media) as $obiekt) {
                $bajty = Storage::disk($obiekt['dysk'])->get($obiekt['klucz']);
                $this->assertNotNull($bajty, 'Źródło migawki nie istnieje: '.$obiekt['klucz']);
                Storage::disk(self::KOPIA)->put($obiekt['wmigawce'], $bajty);
                $liczba++;
            }
        }

        return $liczba;
    }

    /**
     * Odtworzenie wg §7.1 kroki 5–6: LISTA Z BAZY (wiersze `ready`), nigdy
     * z bucketu kopii. Odtwarza tylko to, czego na żywym dysku brakuje,
     * i odmawia, gdy bajty z migawki nie zgadzają się z bazą.
     *
     * @return list<string> klucze przywrócone na żywe dyski
     */
    private function odtworzZBazy(): array
    {
        $przywrocone = [];

        foreach (Media::query()->where('status', Media::STATUS_READY)->get() as $media) {
            foreach ($this->obiekty($media) as $obiekt) {
                if (Storage::disk($obiekt['dysk'])->exists($obiekt['klucz'])) {
                    continue;
                }

                $zKopii = Storage::disk(self::KOPIA)->get($obiekt['wmigawce']);

                if ($zKopii === null) {
                    throw new RuntimeException('Brak w migawce: '.$obiekt['wmigawce']);
                }

                $this->potwierdzZgodnoscZBaza($media, $obiekt['klucz'], $zKopii);

                Storage::disk($obiekt['dysk'])->put($obiekt['klucz'], $zKopii);
                $przywrocone[] = $obiekt['klucz'];
            }
        }

        return $przywrocone;
    }

    /**
     * Sama zgodność rozmiaru niczego nie dowodzi (próba z #617: podmieniony
     * bajt ma ten sam rozmiar), więc oryginał sprawdza SHA-256 z bazy.
     */
    private function potwierdzZgodnoscZBaza(Media $media, string $klucz, string $bajty): void
    {
        if ($klucz === $media->object_key) {
            if (hash('sha256', $bajty) !== $media->checksum_sha256) {
                throw new RuntimeException('NIEZGODNA_SUMA_KOPII: '.$klucz);
            }

            return;
        }

        foreach ($media->metadata['variants'] as $wariant) {
            if ($wariant['key'] === $klucz && isset($wariant['bytes']) && strlen($bajty) !== (int) $wariant['bytes']) {
                throw new RuntimeException('INNY_ROZMIAR_KOPII: '.$klucz);
            }
        }
    }

    public function test_zdjecia_wracaja_z_migawki_wedlug_bazy_a_wymazane_konto_nie_wraca(): void
    {
        $autor = $this->user('probadr');
        $pierwsze = $this->zdjecieZPotoku($autor, 800);
        $drugie = $this->zdjecieZPotoku($autor, 801);
        $this->wpis($autor, $pierwsze);
        $this->wpis($autor, $drugie);
        $wymazane = $this->zdjecieZPotoku($this->user('wymazana'), 802);

        // (2) migawka + jeden obiekt spoza bazy (zdjęcie sprzed migawki, którego wiersza już nie ma)
        $wMigawce = $this->zrobMigawke([$pierwsze, $drugie, $wymazane]);
        $this->assertGreaterThanOrEqual(6, $wMigawce, 'Migawka powinna objąć oryginały i warianty trzech zdjęć.');

        // (3) kontrola dodatnia: zanim cokolwiek zepsujemy, migawka jest czysta
        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true])
            ->expectsOutputToContain('Każdy sprawdzony wiersz ma swoje pliki w tej migawce.')
            ->assertSuccessful();
        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')->assertSuccessful();

        // (4) utrata: całe pierwsze zdjęcie i jeden wariant drugiego
        foreach ($this->obiekty($pierwsze) as $obiekt) {
            Storage::disk($obiekt['dysk'])->delete($obiekt['klucz']);
        }
        $utracony = $drugie->metadata['variants']['feed']['key'] ?? array_values($drugie->metadata['variants'])[0]['key'];
        Storage::disk(self::WARIANTY)->delete($utracony);

        // utrata jest widoczna, a nie tylko „zrobiona": komenda audytowa ją nazywa
        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')
            ->expectsOutputToContain('UTRACONE')
            ->assertFailed();

        // (5) po migawce konto zostaje wymazane: pliki i wiersz znikają z żywego serwisu
        $kluczeWymazanego = $this->obiekty($wymazane);
        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($wymazane), 'Wymazanie zdjęcia nie doszło do skutku — przeplot nie zaszedł.');
        $this->assertNull(Media::query()->find($wymazane->getKey()));

        // Migawka nadal je zawiera (retencja techniczna), a komenda nazywa je
        // NADMIAROWYMI. Utrata plików na żywych dyskach nie zmienia stanu
        // migawki, więc raport o kopii pozostaje czysty.
        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true, '--nadmiarowe' => true])
            ->expectsOutputToContain('NADMIAROWE (nie odtwarzać): '.$kluczeWymazanego[0]['wmigawce'])
            ->assertSuccessful();

        // (6) odtworzenie z bazy
        $przywrocone = $this->odtworzZBazy();

        $oczekiwane = count($this->obiekty($pierwsze)) + 1;
        $this->assertCount($oczekiwane, $przywrocone, 'Odtworzono inną liczbę obiektów niż utracono.');

        // (7) wszystko wróciło, bajt w bajt, a wymazane konto nie
        $this->artisan('kuking:sprawdz-zdjecia-po-przenosinach')->assertSuccessful();

        foreach ([$pierwsze, $drugie] as $media) {
            foreach ($this->obiekty($media) as $obiekt) {
                $zywy = Storage::disk($obiekt['dysk'])->get($obiekt['klucz']);
                $this->assertNotNull($zywy, 'Nie odtworzono: '.$obiekt['klucz']);
                $this->assertSame(
                    hash('sha256', (string) Storage::disk(self::KOPIA)->get($obiekt['wmigawce'])),
                    hash('sha256', (string) $zywy),
                    'Odtworzony obiekt różni się od migawki: '.$obiekt['klucz'],
                );
            }

            $this->assertNotFalse(getimagesizefromstring((string) Storage::disk($media->variantsDisk())->get(array_values($media->metadata['variants'])[0]['key'])), 'Odtworzony wariant nie jest obrazem.');
            $this->get($media->url('feed'))->assertRedirect();
        }

        foreach ($kluczeWymazanego as $obiekt) {
            $this->assertFalse(
                Storage::disk($obiekt['dysk'])->exists($obiekt['klucz']),
                'Odtworzono obiekt konta, które zostało wymazane: '.$obiekt['klucz'],
            );
            $this->assertTrue(
                Storage::disk(self::KOPIA)->exists($obiekt['wmigawce']),
                'Migawka jest niezmienna: nie wolno z niej nic kasować przy odtwarzaniu.',
            );
        }

        // po odtworzeniu migawka i baza znów się zgadzają (nadmiarowe nie oblewa raportu)
        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true, '--nadmiarowe' => true])
            ->expectsOutputToContain('NADMIAROWE w migawce: '.count($kluczeWymazanego).' ')
            ->assertSuccessful();
    }

    public function test_uszkodzona_kopia_oryginalu_jest_wykryta_przed_odtworzeniem_a_nie_po(): void
    {
        // Kontrola ujemna próby z #617: jeden bajt inny, rozmiar identyczny.
        $autor = $this->user('probadr2');
        $media = $this->zdjecieZPotoku($autor, 800);
        $this->wpis($autor, $media);
        $this->zrobMigawke([$media]);

        $oryginal = $this->obiekty($media)[0];
        $bajty = (string) Storage::disk(self::KOPIA)->get($oryginal['wmigawce']);
        $uszkodzone = $bajty;
        $uszkodzone[strlen($uszkodzone) - 1] = $uszkodzone[strlen($uszkodzone) - 1] === 'A' ? 'B' : 'A';
        $this->assertSame(strlen($bajty), strlen($uszkodzone));
        Storage::disk(self::KOPIA)->put($oryginal['wmigawce'], $uszkodzone);

        // sam rozmiar nie wykrywa uszkodzenia; suma tak
        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA])->assertSuccessful();
        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true])
            ->expectsOutputToContain('INNA SUMA oryginał: '.$oryginal['wmigawce'])
            ->assertFailed();

        // a odtwarzanie z takiej migawki odmawia zamiast położyć uszkodzone bajty na żywy dysk
        Storage::disk($oryginal['dysk'])->delete($oryginal['klucz']);

        try {
            $this->odtworzZBazy();
            $this->fail('Odtworzenie przyjęło uszkodzoną kopię.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NIEZGODNA_SUMA_KOPII', $e->getMessage());
        }

        $this->assertFalse(Storage::disk($oryginal['dysk'])->exists($oryginal['klucz']), 'Uszkodzone bajty trafiły na żywy dysk mimo odmowy.');
    }

    public function test_wpis_usuniety_po_migawce_nie_odzyskuje_zdjecia_tylko_dlatego_ze_pliki_wrocily(): void
    {
        // Miękko usunięty wpis: pliki mogą wrócić z kopii, ale trasa zdjęcia
        // dalej odpowiada 404 (polityka dostępu, nie obecność bajtów).
        $autor = $this->user('probadr3');
        $media = $this->zdjecieZPotoku($autor, 800);
        $wpis = $this->wpis($autor, $media);
        $this->zrobMigawke([$media]);

        // kontrola dodatnia: dopóki wpis żyje, trasa zdjęcia odsyła do pliku
        $this->get($media->url('feed'))->assertRedirect();

        $wpis->delete();

        $this->get($media->url('feed'))->assertNotFound();

        foreach ($this->obiekty($media) as $obiekt) {
            Storage::disk($obiekt['dysk'])->delete($obiekt['klucz']);
        }
        $this->odtworzZBazy();

        $this->get($media->url('feed'))->assertNotFound();
        $this->get(route('posts.show', $wpis))->assertNotFound();
    }
}
