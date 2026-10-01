<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use App\Domain\Media\ZdjeciaDoPrzypiecia;
use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\TrescZabezpieczonaJakoDowod;
use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\PrzeniesPubliczneWariantyDowodu;
use App\Jobs\PurgePublicMediaCache;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sondy\SondaKolejki;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Poprawki po recenzji ścieżki CSAM (D-333, 1.10.2026).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić), wykonane ręcznie —
 * wyniki w opisie PR:
 *  - usunięcie `PrzeniesPubliczneWariantyDowodu::dispatch` z akcji → oblewa
 *    „zabezpieczenie zleca przeniesienie…”;
 *  - usunięcie kopii przed `delete()` w zadaniu → oblewa „porażka zapisu…”;
 *  - `odchodzi()` bez `STATUS_SECURED` → oblewają oba testy `ProcessUploadedImage`;
 *  - usunięcie sprawdzenia `zabezpieczona()` i `catch` w `ResolveAppeal::cofnij()` → oblewa test odwołania;
 *  - usunięcie `$chronione()` z `PrzedawnioneUsunieteTresci::bezpiecznie()` → oblewa test wyścigu retencji;
 *  - `ZdjeciaDoPrzypiecia` bez `STATUS_SECURED` → oblewa test przypinania.
 * Kolejność blokad mierzy `tests/Dwa/ZabezpieczenieDowoduNieZakleszczaSieTest.php`.
 *
 * @bez-kontroli-dodatniej Źródła są czytane tylko w dwóch testach dokumentów (migracja, polityka), a reszta mierzy zachowanie kodu na bazie i dyskach; kontrole ujemne wszystkich testów wykonano ręcznie (lista wyżej), a w teście polityki kontrolą dodatnią jest porównanie archiwum z bieżącą wersją.
 */
class ZabezpieczenieDowoduCsamPoRecenzjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.usuniete_tresci.retention_days' => 30,
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.connections.database.after_commit' => false,
        ]);
    }

    // ───────────── 1. warianty zdjęcia dowodu przestają być publiczne ─────────────

    public function test_zabezpieczenie_zleca_przeniesienie_wariantow_i_zadanie_je_przenosi_czysci_cdn_i_zachowuje_oryginal(): void
    {
        Queue::fake();
        $prywatny = Storage::fake('oryginal_dowodu');
        $publiczny = Storage::fake('warianty_dowodu');

        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor, $prywatny, $publiczny);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $warianty = array_column($zdjecie->metadata['variants'], 'key');

        // KONTROLA DODATNIA: przed zabezpieczeniem warianty leżą w publicznym buckecie.
        foreach ($warianty as $klucz) {
            $this->assertTrue($publiczny->exists($klucz));
        }

        $this->actingAs($this->moderator())->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $wpis->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirect();

        Queue::assertPushed(
            PrzeniesPubliczneWariantyDowodu::class,
            static fn (PrzeniesPubliczneWariantyDowodu $zadanie): bool => $zadanie->mediaIds === [$zdjecie->getKey()],
        );

        (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();

        $zdjecie->refresh();
        $this->assertSame(Media::STATUS_SECURED, $zdjecie->status);
        $this->assertNotEmpty($zdjecie->metadata[Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT] ?? null);

        $poPrzeniesieniu = [];
        foreach ($warianty as $klucz) {
            $nowy = 'zabezpieczone/'.$zdjecie->getKey().'/'.basename($klucz);
            $poPrzeniesieniu[$klucz] = [
                'publiczny' => $publiczny->exists($klucz),
                'prywatny' => $prywatny->exists($nowy),
                'mapa' => $zdjecie->metadata[Media::METADANE_WARIANTY_ZABEZPIECZONE][$klucz] ?? null,
            ];
        }

        $oczekiwane = [];
        foreach ($warianty as $klucz) {
            $oczekiwane[$klucz] = [
                'publiczny' => false,
                'prywatny' => true,
                'mapa' => 'zabezpieczone/'.$zdjecie->getKey().'/'.basename($klucz),
            ];
        }
        $this->assertSame($oczekiwane, $poPrzeniesieniu);

        // Dowód: oryginał nietknięty, a `variants` zostają (da się odtworzyć).
        $this->assertTrue($prywatny->exists($zdjecie->object_key));
        $zostaly = array_column($zdjecie->metadata['variants'], 'key');
        sort($zostaly);
        sort($warianty);
        $this->assertSame($warianty, $zostaly);

        // CDN: adres trasy KAŻDEGO wariantu idzie do czyszczenia.
        Queue::assertPushed(PurgePublicMediaCache::class, function (PurgePublicMediaCache $zadanie) use ($zdjecie): bool {
            foreach (array_keys($zdjecie->metadata['variants']) as $nazwa) {
                if (! in_array(route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => $nazwa]), $zadanie->adresy, true)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_przeniesienie_jest_idempotentne_i_ponowienie_nie_psuje_dowodu(): void
    {
        Queue::fake();
        $prywatny = Storage::fake('oryginal_dowodu');
        $publiczny = Storage::fake('warianty_dowodu');
        $autor = $this->user('autor');
        $zdjecie = $this->zdjecie($autor, $prywatny, $publiczny);
        $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();

        (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();
        $this->assertNotEmpty($zdjecie->fresh()->metadata[Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT] ?? null);
        $mapaPierwsza = $zdjecie->fresh()->metadata[Media::METADANE_WARIANTY_ZABEZPIECZONE];
        (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();

        $this->assertSame($mapaPierwsza, $zdjecie->fresh()->metadata[Media::METADANE_WARIANTY_ZABEZPIECZONE]);
        foreach ($mapaPierwsza as $nowy) {
            $this->assertTrue($prywatny->exists($nowy));
        }
    }

    public function test_porazka_zapisu_w_prywatnym_magazynie_nie_kasuje_wariantu_z_publicznego_bo_to_dowod(): void
    {
        Queue::fake();
        $prywatny = Storage::fake('oryginal_dowodu');
        $publiczny = Storage::fake('warianty_dowodu');
        $autor = $this->user('autor');
        $zdjecie = $this->zdjecie($autor, $prywatny, $publiczny);
        $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();
        $warianty = array_column($zdjecie->metadata['variants'], 'key');

        // Prywatny dysk odmawia zapisu kopii (zwraca false).
        Storage::set('oryginal_dowodu', new class($prywatny)
        {
            public function __construct(private Filesystem $prawdziwy) {}

            public function put($sciezka, $zawartosc, $opcje = []): bool
            {
                return false;
            }

            public function __call(string $metoda, array $argumenty): mixed
            {
                return $this->prawdziwy->{$metoda}(...$argumenty);
            }
        });

        try {
            (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();
            $this->fail('Zadanie miało zgłosić porażkę, żeby kolejka je ponowiła.');
        } catch (RuntimeException) {
            // Oczekiwane.
        }

        $this->assertArrayNotHasKey(
            Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT,
            $zdjecie->fresh()->metadata,
            'Porażka kopii nie może wyciszyć sondy zaległego dowodu.',
        );
        foreach ($warianty as $klucz) {
            $this->assertTrue($publiczny->exists($klucz), 'Wariant zniknął z publicznego dysku bez kopii — dowód stracony.');
        }
        // Adresy i tak idą do czyszczenia (porażka jednego dysku nie blokuje CDN).
        Queue::assertPushed(PurgePublicMediaCache::class);
    }

    public function test_stary_wspolny_publiczny_bucket_nie_udaje_zakonczonego_przeniesienia(): void
    {
        Queue::fake();
        $legacy = Storage::fake('r2_legacy');
        config(['filesystems.disks.r2_legacy.bucket' => 'syntetyczny-legacy']);
        $autor = $this->user('autor_legacy');
        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'disk' => 'r2_legacy',
            'variants_disk' => 'r2_legacy',
        ]);
        $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();
        $legacy->put($zdjecie->object_key, 'syntetyczny oryginał');
        $warianty = array_column($zdjecie->metadata['variants'], 'key');
        foreach ($warianty as $klucz) {
            $legacy->put($klucz, 'syntetyczny wariant');
        }

        $dowod = new ZabezpieczenieDowodu;
        $dowod->forceFill([
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'subject_user_id' => $autor->getKey(),
            'previous_media_status' => Media::STATUS_READY,
        ])->save();

        try {
            (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();
            $this->fail('Publiczny wariant w starym buckecie nie może być uznany za przeniesiony.');
        } catch (RuntimeException) {
            // Job zostaje do retry i ostatecznie do failed_jobs.
        }

        $this->assertTrue($legacy->exists($zdjecie->object_key), 'Oryginał dowodu ma zostać nietknięty.');
        foreach ($warianty as $klucz) {
            $this->assertTrue($legacy->exists($klucz), 'Wariant bez prywatnej kopii nie może zniknąć.');
        }
        $this->assertArrayNotHasKey(Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT, $zdjecie->fresh()->metadata);

        $this->travel(16)->minutes();
        try {
            app(SondaKolejki::class)->sprawdz();
            $this->fail('Zaległy publiczny wariant powinien zapalić sondę.');
        } catch (KontrolaZdrowiaNieprzeszla $e) {
            $this->assertSame(Powody::POWOD_WARIANTY_DOWODU_ZALEGLE, $e->kod);
        }
    }

    public function test_wspolny_prywatny_dysk_moze_zakonczyc_prace_bez_kasowania_wariantu(): void
    {
        Queue::fake();
        config(['filesystems.disks.r2_legacy.bucket' => null]);
        $prywatny = Storage::fake('prywatny_dowod_testowy');
        $zdjecie = Media::factory()->create([
            'owner_id' => $this->user('autor_prywatny')->getKey(),
            'disk' => 'prywatny_dowod_testowy',
            'variants_disk' => 'prywatny_dowod_testowy',
        ]);
        $zdjecie->forceFill(['status' => Media::STATUS_SECURED])->save();
        $prywatny->put($zdjecie->object_key, 'syntetyczny oryginał');
        $warianty = array_column($zdjecie->metadata['variants'], 'key');
        foreach ($warianty as $klucz) {
            $prywatny->put($klucz, 'syntetyczny wariant');
        }

        (new PrzeniesPubliczneWariantyDowodu([$zdjecie->getKey()]))->handle();

        $this->assertNotEmpty($zdjecie->fresh()->metadata[Media::METADANE_WARIANTY_DOWODU_PRZENIESIONE_AT] ?? null);
        $this->assertTrue($prywatny->exists($zdjecie->object_key));
        foreach ($warianty as $klucz) {
            $this->assertTrue($prywatny->exists($klucz));
        }
    }

    // ───────────── ProcessUploadedImage nie publikuje wariantów dla zdjęcia secured ─────────────

    public function test_przetwarzanie_zdjecia_zabezpieczonego_w_trakcie_nie_publikuje_wariantow_i_zostawia_status(): void
    {
        $oryginaly = Storage::fake('oryginal_dowodu');
        $warianty = Storage::fake('warianty_dowodu');
        $media = $this->zdjecieWKolejce($oryginaly);

        // Moderator zabezpiecza zdjęcie, gdy zadanie jest po pierwszym wariancie.
        $this->dyskZHakiem($warianty, naZapisie: 2, hak: fn () => Media::query()->whereKey($media->getKey())
            ->update(['status' => Media::STATUS_SECURED]));

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();
        $this->assertSame(Media::STATUS_SECURED, $media->status);
        $this->assertSame([], $media->metadata['variants'] ?? [], 'Zadanie opublikowało warianty zdjęcia dowodu.');

        $pliki = $warianty->allFiles();
        $this->assertSame([], $pliki, 'Warianty położone przez zadanie zostały w publicznym buckecie.');
    }

    public function test_ostatnia_proba_przetwarzania_nie_zmienia_secured_w_rejected(): void
    {
        $oryginaly = Storage::fake('oryginal_dowodu');
        $warianty = Storage::fake('warianty_dowodu');
        $media = $this->zdjecieWKolejce($oryginaly);

        // Zdjęcie zostaje zabezpieczone, a zapis kolejnego wariantu pada.
        $this->dyskZHakiem($warianty, naZapisie: 2, hak: function (): void {
            Media::query()->update(['status' => Media::STATUS_SECURED]);

            throw new RuntimeException('Bucket odmówił zapisu.');
        });

        // `handle()` bez zadania kolejki: porażka jest od razu ostateczna.
        (new ProcessUploadedImage($media->getKey()))->handle();

        $this->assertSame(Media::STATUS_SECURED, $media->fresh()->status);
        $this->assertSame([], $warianty->allFiles());
    }

    // ───────────── 2. uznanie odwołania od decyzji CSAM ─────────────

    public function test_odwolania_od_decyzji_csam_nie_da_sie_uznac_i_zostaje_otwarte(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->actingAs($this->moderator())->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $wpis->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirect();
        auth()->logout();

        $decyzja = ModerationAction::query()->where('target_id', $wpis->getKey())
            ->where('action', ModerationAction::ACTION_REMOVE)->sole();
        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To pomyłka.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        try {
            app(ResolveAppeal::class)->handle(
                moderator: $this->admin(),
                odwolanie: $odwolanie,
                wynik: Appeal::STATUS_OVERTURNED,
                uzasadnienie: 'Cofam decyzję.',
            );
            $this->fail('Uznano odwołanie od decyzji CSAM, a treść dalej jest ukryta.');
        } catch (TrescZabezpieczonaJakoDowod $odmowa) {
            $this->assertStringContainsString('przekaż ją właścicielowi serwisu albo prawnikowi', $odmowa->getMessage());
        }

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->refresh()->status);
        $this->assertSoftDeleted($wpis);
        $this->assertSame(0, AuditLogEntry::where('action', 'appeal.resolved')->count());
    }

    public function test_kontrola_dodatnia_odwolanie_od_zwyklego_usuniecia_jest_uznawane_i_tresc_wraca(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey(), 'status' => Post::STATUS_HIDDEN]);
        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'previous_status' => 'published',
            'reason_code' => 'spam-reklama',
            'user_message' => 'Treść wygląda na reklamę.',
        ]);
        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie reklama.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        app(ResolveAppeal::class)->handle(
            moderator: $this->admin(),
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_OVERTURNED,
            uzasadnienie: 'Cofam decyzję.',
        );

        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->refresh()->status);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->refresh()->status);
    }

    // ───────────── 4. retencja: ponowne sprawdzenie pod blokadą ─────────────

    /** @return array<string, array{0: string}> */
    public static function typyRetencji(): array
    {
        return ['wpis' => ['post'], 'przepis' => ['recipe'], 'komentarz' => ['comment']];
    }

    #[DataProvider('typyRetencji')]
    public function test_zabezpieczenie_w_oknie_miedzy_sprawdzeniem_a_usunieciem_chroni_tresc_przed_forcedelete(string $typ): void
    {
        $autor = $this->user('autor');
        $tresc = match ($typ) {
            'post' => Post::factory()->create(['author_id' => $autor->getKey()]),
            'recipe' => Recipe::factory()->create(['author_id' => $autor->getKey()]),
            default => Comment::factory()->create([
                'author_id' => $autor->getKey(),
                'post_id' => Post::factory()->create()->getKey(),
            ]),
        };
        $tresc->delete();
        DB::table($tresc->getTable())->where('id', $tresc->getKey())->update(['deleted_at' => now()->subDays(40)]);

        $moderatorId = (string) $this->moderator()->getKey();

        // Okno wyścigu: sprawdzenie w pętli (bez blokady) już powiedziało
        // „nie chronione”; zaraz po zajęciu wiersza treści `FOR UPDATE`
        // moderator zabezpiecza ją (rejestr + decyzja), a dopiero potem
        // idzie `forceDelete()`. Ponowne sprawdzenie pod blokadą ma to zauważyć.
        $wstawiono = false;
        DB::listen(function ($zapytanie) use (&$wstawiono, $typ, $tresc, $autor, $moderatorId): void {
            if ($wstawiono || ! str_contains(strtolower($zapytanie->sql), 'for update')) {
                return;
            }
            $wstawiono = true;

            $nowaDecyzja = ModerationAction::create([
                'moderator_id' => $moderatorId,
                'target_type' => $typ,
                'target_id' => $tresc->getKey(),
                'subject_user_id' => $autor->getKey(),
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'krzywdzenie-dzieci',
            ]);
            (new ZabezpieczenieDowodu)->forceFill([
                'target_type' => $typ,
                'target_id' => $tresc->getKey(),
                'subject_user_id' => $autor->getKey(),
                'moderation_action_id' => $nowaDecyzja->getKey(),
                'secured_by' => $moderatorId,
            ])->save();
        });

        $wynik = app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);

        $this->assertTrue($wstawiono, 'Okno wyścigu nie zostało odtworzone — test niczego nie mierzy.');
        $this->assertSame(0, $wynik['wpisy'] + $wynik['przepisy'] + $wynik['komentarze'] + $wynik['nagrobki']);
        $this->assertDatabaseHas($tresc->getTable(), ['id' => $tresc->getKey()]);
    }

    // ───────────── 7. zdjęcie secured nie wraca przez przypięcie ─────────────

    public function test_zdjecie_secured_nie_da_sie_przypiac_ponownie(): void
    {
        $autor = $this->user('autor');
        $gotowe = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $zabezpieczone = Media::factory()->create(['owner_id' => $autor->getKey(), 'status' => Media::STATUS_SECURED]);

        $zablokowane = DB::transaction(static fn (): array => ZdjeciaDoPrzypiecia::zablokuj(
            (string) $autor->getKey(),
            [(string) $gotowe->getKey(), (string) $zabezpieczone->getKey()],
        ));

        $this->assertSame([(string) $gotowe->getKey()], $zablokowane);
    }

    // ───────────── 8. migracja: DROP i ADD w jednym ALTER TABLE ─────────────

    public function test_migracja_statusu_secured_zmienia_ograniczenie_jedna_instrukcja_i_jest_zwalidowana(): void
    {
        $plik = (string) file_get_contents(base_path('database/migrations/2026_10_01_150100_allow_secured_media_status.php'));

        $this->assertSame(2, preg_match_all('/ALTER TABLE media DROP CONSTRAINT IF EXISTS .*, ADD CONSTRAINT/', $plik));
        $this->assertDoesNotMatchRegularExpression('/ALTER TABLE media DROP CONSTRAINT IF EXISTS \'\.self::OGRANICZENIE\);/', $plik);

        $ograniczenie = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def, convalidated FROM pg_constraint WHERE conname = 'media_status_check'");
        $this->assertNotNull($ograniczenie);
        $this->assertStringContainsString("'secured'", $ograniczenie->def);
        $this->assertTrue($ograniczenie->convalidated);
    }

    // ───────────── 9. polityka prywatności ─────────────

    public function test_polityka_ma_wyjatek_art_17_ust_3_a_archiwum_jest_identyczne_z_biezaca(): void
    {
        $biezaca = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $archiwum = (string) file_get_contents(resource_path('legal/archiwum/polityka-prywatnosci-2026-09-30.md'));

        $this->assertStringContainsString('art. 17 ust. 3', $biezaca);
        $this->assertSame($biezaca, $archiwum, 'Archiwum bieżącej wersji różni się od bieżącej polityki.');
    }

    // ───────────── pomocnicze ─────────────

    private function zdjecie(User $wlasciciel, Filesystem $prywatny, Filesystem $publiczny): Media
    {
        $zdjecie = Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'oryginal_dowodu',
            'variants_disk' => 'warianty_dowodu',
        ]);

        $prywatny->put($zdjecie->object_key, 'oryginal');
        foreach ($zdjecie->metadata['variants'] as $wariant) {
            $publiczny->put($wariant['key'], 'wariant');
        }

        return $zdjecie;
    }

    private function zdjecieWKolejce(Filesystem $oryginaly): Media
    {
        $klucz = 'incoming/dowod-csam.jpg';
        $oryginaly->put($klucz, $this->obrazek());

        return Media::create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'oryginal_dowodu',
            'variants_disk' => 'warianty_dowodu',
            'object_key' => $klucz,
            'status' => Media::STATUS_PENDING,
            'metadata' => [],
        ]);
    }

    /**
     * Dysk wariantów, który na N-tym zapisie najpierw wykonuje `$hak`
     * (np. zabezpieczenie zdjęcia przez moderatora albo awaria bucketu).
     */
    private function dyskZHakiem(Filesystem $prawdziwy, int $naZapisie, \Closure $hak): void
    {
        Storage::set('warianty_dowodu', new class($prawdziwy, $naZapisie, $hak)
        {
            private int $zapisy = 0;

            public function __construct(
                private Filesystem $prawdziwy,
                private int $naZapisie,
                private \Closure $hak,
            ) {}

            public function put($sciezka, $zawartosc, $opcje = [])
            {
                if (++$this->zapisy === $this->naZapisie) {
                    ($this->hak)();
                }

                return $this->prawdziwy->put($sciezka, $zawartosc, $opcje);
            }

            public function __call(string $metoda, array $argumenty): mixed
            {
                return $this->prawdziwy->{$metoda}(...$argumenty);
            }
        });
    }

    private function obrazek(): string
    {
        $image = imagecreatetruecolor(1400, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 30, 90));

        ob_start();
        try {
            imagejpeg($image, null, 93);
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        return $bytes;
    }
}
