<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * OCENA MODELEM: BUDŻET CZASU (#829) I ZDJĘCIA GOTOWE PO PUBLIKACJI (#830).
 *
 * #829 — zadanie ma 30 s, każde żądanie do modelu do 8 s, a żądań jest
 * tekst + `zdjec_na_wpis`. Mierzymy WYNIK dla moderatora przy wolnym
 * dostawcy, którego każda odpowiedź mieści się we własnym limicie. Zegar
 * przesuwa atrapa (`Carbon::setTestNow`) — zgodnie z limitem, który klient
 * NAPRAWDĘ nadał żądaniu (`$opcje['timeout']`): dłuższa odpowiedź to
 * timeout, jak w Guzzle.
 *
 * #830 — zdjęcie przypięte do wpisu przed gotowością było pomijane i nikt
 * do niego nie wracał. Przepływ: wpis ze zdjęciem w kolejce → analiza →
 * prawdziwe `ProcessUploadedImage` → zlecona analiza → zdjęcie ocenione.
 *
 * Każde żądanie idzie w atrapę; `TestCase` ma `preventStrayRequests()`.
 */
class ModeracjaBudzetIZdjeciaPoGotowosciTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'oryginal830';

    private const WARIANTY = 'wariant830';

    /** Ile sekund „trwa" jedna odpowiedź dostawcy. */
    private int $odpowiedzTrwa = 0;

    /** Żądania, które naprawdę wyszły — także te zakończone timeoutem (`Http::recorded()` ich nie liczy). */
    private int $wyslane = 0;

    /** Automat w bazie w chwili PIERWSZEGO żądania do modelu. */
    private ?int $oznaczenPrzedModelem = null;

    /** @var array<string, float> */
    private array $wynikTekstu = ['hate' => 0.95];

    /** @var array<string, float> */
    private array $wynikZdjecia = ['hate' => 0.95];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00'));

        config([
            'kuking.moderation.sygnaly.wlaczone' => true,
            'kuking.moderation.model.klucz' => 'atrapa-klucza',
            'kuking.moderation.model.limit_czasu' => 8,
            'kuking.moderation.model.ocenia_zdjecia' => true,
            'kuking.moderation.model.zdjec_na_wpis' => 2,
        ]);

        Http::fake(function (Request $zadanie, array $opcje) {
            $this->wyslane++;
            $this->oznaczenPrzedModelem ??= Report::query()->where('source', Report::SOURCE_AUTOMAT)->count();

            $limit = (int) ($opcje['timeout'] ?? 0);
            $this->assertGreaterThan(0, $limit, 'Żądanie do modelu wyszło bez limitu czasu.');

            if ($this->odpowiedzTrwa > $limit) {
                Carbon::setTestNow(Carbon::now()->addSeconds($limit));

                throw new ConnectionException('Przekroczony limit czasu (atrapa).');
            }

            Carbon::setTestNow(Carbon::now()->addSeconds($this->odpowiedzTrwa));

            $obraz = ($zadanie['input'][0]['type'] ?? null) === 'image_url';

            return Http::response(['results' => [['category_scores' => $obraz ? $this->wynikZdjecia : $this->wynikTekstu]]]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // #829 — SYGNAŁY LOKALNE NIE CZEKAJĄ NA MODEL
    // ---------------------------------------------------------------

    public function test_lokalny_sygnal_jest_zapisany_zanim_ruszy_model_i_model_dopisuje_sie_do_niego(): void
    {
        $wpis = $this->wpis($this->user('spamer'), 'Zarabiaj z domu, tel. 600 100 200');

        $this->analizuj($wpis);

        $this->assertSame(1, $this->oznaczenPrzedModelem, 'Sygnał lokalny czekał na koniec oceny modelem.');

        $oznaczenia = $this->oznaczenia($wpis);
        $this->assertCount(1, $oznaczenia, 'Jedno oznaczenie na treść (D-052).');
        $this->assertStringContainsString('Model ocenił tekst', (string) $oznaczenia[0]->details);
        $this->assertSame('automat_model', $oznaczenia[0]->reason, 'Cięższy sygnał modelu nie przesunął sprawy w kolejce.');
        $this->assertGreaterThanOrEqual(2, substr_count((string) $oznaczenia[0]->details, "\n— "), 'W oznaczeniu brakuje sygnału lokalnego albo modelu.');
    }

    /**
     * Pilny sygnał modelu dołożony do istniejącego oznaczenia alarmuje raz;
     * powtórna analiza (np. po gotowości zdjęcia) nie wysyła drugiego listu.
     */
    public function test_pilny_sygnal_dolozony_alarmuje_raz(): void
    {
        config(['kuking.moderation.model.alarm_email' => 'moderacja@example.test']);
        $this->wynikTekstu = ['sexual/minors' => 0.9];
        $wpis = $this->wpis($this->user('pilny'), 'Zarabiaj z domu, tel. 600 100 200');

        $this->analizuj($wpis);
        $this->analizuj($wpis);

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertCount(1, $this->oznaczenia($wpis));
        $this->assertSame(1, substr_count((string) $this->oznaczenia($wpis)[0]->details, 'Model ocenił tekst'));
    }

    /** @return array<string, array{int, int, int, int}> */
    public static function konfiguracje(): array
    {
        // [zdjęć na wpis, sekund na odpowiedź, oczekiwanych żądań, pominiętych]
        return [
            // Kontrola dodatnia: szybki dostawca — budżet niczego nie ucina.
            'szybko, 6 zdjęć' => [6, 1, 7, 0],
            // Domyślne 2 zdjęcia przy wolnym dostawcy: 3 × 6 s = 18 s < 22 s.
            'wolno, 2 zdjęcia' => [2, 6, 3, 0],
            // Stary rachunek: 7 × 6 s = 42 s przy zadaniu 30 s. Teraz:
            // tekst + 2 zdjęcia po 6 s, trzecie z limitem 4 s, reszta pominięta.
            'wolno, 6 zdjęć' => [6, 6, 4, 3],
        ];
    }

    #[DataProvider('konfiguracje')]
    public function test_ocena_miesci_sie_w_czasie_zadania_i_mowi_ze_jest_niepelna(int $zdjec, int $sekund, int $zadan, int $pominietych): void
    {
        config(['kuking.moderation.model.zdjec_na_wpis' => $zdjec]);
        $this->odpowiedzTrwa = $sekund;
        $log = Log::spy();

        $autor = $this->user('wolny');
        $wpis = $this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 200');

        foreach (range(1, 6) as $pozycja) {
            $wpis->media()->attach($this->gotoweZdjecie($autor), ['position' => $pozycja]);
        }

        $start = Carbon::now();
        $this->analizuj($wpis);
        $trwalo = (int) $start->diffInSeconds(Carbon::now());

        $this->assertSame($zadan, $this->wyslane);
        $this->assertLessThanOrEqual(
            (new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, 'x'))->timeout - PrzeanalizujTresc::ZAPAS_SEKUND,
            $trwalo,
            'Ocena modelem wyszła poza budżet zadania.',
        );

        $oznaczenia = $this->oznaczenia($wpis);
        $this->assertCount(1, $oznaczenia);
        $opis = (string) $oznaczenia[0]->details;
        $this->assertStringContainsString('Model ocenił tekst', $opis, 'Wynik modelu uzyskany przed końcem budżetu przepadł.');

        if ($pominietych === 0) {
            $this->assertStringNotContainsString('NIEPEŁNA', $opis);
            $log->shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(fn ($k): bool => ($k['stage'] ?? null) === 'model_budzet')]);

            return;
        }

        $this->assertStringContainsString("NIEPEŁNA: zabrakło czasu na {$pominietych} z ocen", $opis);
        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $w, array $k = []): bool => ($k['stage'] ?? null) === 'model_budzet' && ($k['pominiete'] ?? null) === $pominietych,
        )->once();
    }

    public function test_wylaczona_ocena_zdjec_i_brak_klucza_nie_zaliczaja_sie_do_niepelnej(): void
    {
        config(['kuking.moderation.model.ocenia_zdjecia' => false, 'kuking.moderation.model.zdjec_na_wpis' => 6]);
        $autor = $this->user('bezzdjec');
        $wpis = $this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 200');
        $wpis->media()->attach($this->gotoweZdjecie($autor), ['position' => 1]);

        $this->analizuj($wpis);
        Http::assertSentCount(1);

        config(['kuking.moderation.model.klucz' => null]);
        $drugi = $this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 201');
        $this->analizuj($drugi);

        Http::assertSentCount(1);
        $this->assertCount(1, $this->oznaczenia($drugi), 'Bez klucza przestały działać sygnały lokalne.');

        foreach ([$wpis, $drugi] as $tresc) {
            $this->assertStringNotContainsString('NIEPEŁNA', (string) $this->oznaczenia($tresc)[0]->details);
        }
    }

    // ---------------------------------------------------------------
    // #830 — ZDJĘCIE GOTOWE PO PUBLIKACJI
    // ---------------------------------------------------------------

    public function test_zdjecie_przygotowane_po_analizie_zostaje_ocenione(): void
    {
        $this->wynikTekstu = ['hate' => 0.01];
        $this->wynikZdjecia = ['violence/graphic' => 0.9];
        [$wpis, $zdjecie] = $this->wpisZeZdjeciemWKolejce();

        // Analiza z publikacji rusza, zanim zdjęcie ma warianty.
        $this->analizuj($wpis);
        Http::assertSentCount(1);
        $this->assertCount(0, $this->oznaczenia($wpis));

        Queue::fake([PrzeanalizujTresc::class]);
        (new ProcessUploadedImage($zdjecie->getKey()))->handle();
        $this->assertSame(Media::STATUS_READY, $zdjecie->refresh()->status);

        Queue::assertPushed(PrzeanalizujTresc::class, 1);
        Queue::assertPushed(PrzeanalizujTresc::class, fn (PrzeanalizujTresc $z): bool => $z->typ === PrzeanalizujTresc::TYP_WPIS && $z->id === (string) $wpis->getKey());

        // Ponowne dostarczenie zadania zdjęcia nie zleca drugiej analizy.
        (new ProcessUploadedImage($zdjecie->getKey()))->handle();
        Queue::assertPushed(PrzeanalizujTresc::class, 1);

        $this->analizuj($wpis);
        $this->analizuj($wpis);

        $oznaczenia = $this->oznaczenia($wpis);
        $this->assertCount(1, $oznaczenia, 'Ponowna analiza postawiła drugie oznaczenie.');
        $this->assertSame(1, substr_count((string) $oznaczenia[0]->details, 'Model ocenił zdjęcie'), 'Powód zdjęcia dopisany dwa razy.');
    }

    public function test_zdjecie_gotowe_dopisuje_sie_do_istniejacego_oznaczenia(): void
    {
        $this->wynikTekstu = ['hate' => 0.01];
        $this->wynikZdjecia = ['violence/graphic' => 0.9];
        [$wpis, $zdjecie] = $this->wpisZeZdjeciemWKolejce('Zarabiaj z domu, tel. 600 100 200');

        $this->analizuj($wpis);
        $this->assertCount(1, $this->oznaczenia($wpis));
        $this->assertStringNotContainsString('zdjęcie', (string) $this->oznaczenia($wpis)[0]->details);

        (new ProcessUploadedImage($zdjecie->getKey()))->handle();

        $oznaczenia = $this->oznaczenia($wpis);
        $this->assertCount(1, $oznaczenia);
        $this->assertStringContainsString('Model ocenił zdjęcie', (string) $oznaczenia[0]->details, 'Sygnał zdjęcia przepadł na deduplikacji.');
        $this->assertSame('automat_model', $oznaczenia[0]->reason);
    }

    public function test_zamknietej_sprawy_nie_otwiera_ale_zostawia_slad(): void
    {
        $this->wynikTekstu = ['hate' => 0.01];
        $this->wynikZdjecia = ['violence/graphic' => 0.9];
        [$wpis, $zdjecie] = $this->wpisZeZdjeciemWKolejce('Zarabiaj z domu, tel. 600 100 200');

        $this->analizuj($wpis);
        $sprawa = $this->oznaczenia($wpis)[0];
        $sprawa->forceFill([
            'status' => Report::STATUS_REJECTED,
            'resolved_by' => $this->user('moderator')->getKey(),
            'resolved_at' => now(),
            'resolution_note' => 'To nic takiego.',
        ])->save();
        $przed = (string) $sprawa->details;
        $log = Log::spy();

        (new ProcessUploadedImage($zdjecie->getKey()))->handle();

        $sprawa->refresh();
        $this->assertSame(Report::STATUS_REJECTED, $sprawa->status, '„To nic takiego" wróciło do kolejki.');
        $this->assertSame($przed, (string) $sprawa->details);
        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $w, array $k = []): bool => ($k['stage'] ?? null) === 'automat_sprawa_zamknieta',
        )->once();
    }

    /** @return array<string, array{string}> */
    public static function zdjeciaBezOceny(): array
    {
        return [
            'wpis prywatny przed gotowością' => ['prywatny_przed'],
            'wpis prywatny po zleceniu' => ['prywatny_po'],
            'zdjęcie odpięte' => ['odpiete'],
            'zdjęcie poza limitem' => ['poza_limitem'],
            'przetwarzanie nieudane' => ['odrzucone'],
        ];
    }

    #[DataProvider('zdjeciaBezOceny')]
    public function test_zdjecie_bez_prawa_do_oceny_nie_wychodzi(string $przypadek): void
    {
        [$wpis, $zdjecie] = $this->wpisZeZdjeciemWKolejce();
        $this->analizuj($wpis);
        Http::assertSentCount(1);

        match ($przypadek) {
            'prywatny_przed' => app(EditPost::class)->handle($wpis->author, $wpis, $wpis->body, Post::VISIBILITY_PRIVATE),
            'odpiete' => $wpis->media()->detach($zdjecie->getKey()),
            'poza_limitem' => $this->dopnijPrzed($wpis, $zdjecie),
            'odrzucone' => Storage::disk(self::ORYGINALY)->delete($zdjecie->object_key),
            default => null,
        };

        Queue::fake([PrzeanalizujTresc::class]);

        try {
            (new ProcessUploadedImage($zdjecie->getKey()))->handle();
        } catch (\RuntimeException) {
            // `odrzucone`: zadanie zdjęcia rzuca, żeby kolejka je ponowiła.
        }

        if ($przypadek === 'prywatny_po') {
            Queue::assertPushed(PrzeanalizujTresc::class, 1);
            app(EditPost::class)->handle($wpis->author, $wpis, $wpis->body, Post::VISIBILITY_PRIVATE);
            $this->analizuj($wpis);
        } else {
            Queue::assertNotPushed(PrzeanalizujTresc::class);
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r): bool => ($r['input'][0]['type'] ?? null) === 'image_url');
    }

    // ---------------------------------------------------------------
    // POMOCNICZE
    // ---------------------------------------------------------------

    private function wpis(User $autor, string $tekst): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function analizuj(Post $wpis): void
    {
        $this->app->call([new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()), 'handle']);
    }

    /** @return list<Report> */
    private function oznaczenia(Post $wpis): array
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_id', $wpis->getKey())
            ->get()
            ->all();
    }

    private function gotoweZdjecie(User $wlasciciel): Media
    {
        $klucz = 'media/test/'.Str::uuid()->toString().'_thumb.webp';
        Storage::disk('public')->put($klucz, (string) ImageManager::gd()->create(320, 240)->fill('cc4400')->toWebp());

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => null,
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['thumb' => ['key' => $klucz, 'width' => 320, 'height' => 240]]],
        ]);
    }

    /** @return array{Post, Media} */
    private function wpisZeZdjeciemWKolejce(string $tekst = 'Zupa pomidorowa jak u mamy.'): array
    {
        Storage::fake(self::ORYGINALY);
        Storage::fake(self::WARIANTY);

        $autor = $this->user('czeka');
        $klucz = 'incoming/'.Str::uuid()->toString().'.jpg';
        Storage::disk(self::ORYGINALY)->put($klucz, (string) ImageManager::gd()->create(1400, 900)->fill('0a1e5a')->toJpeg());

        $zdjecie = Media::create([
            'owner_id' => $autor->getKey(),
            'disk' => self::ORYGINALY,
            'variants_disk' => self::WARIANTY,
            'object_key' => $klucz,
            'status' => Media::STATUS_PROCESSING,
            'metadata' => [],
        ]);

        $wpis = $this->wpis($autor, $tekst);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 1]);

        return [$wpis, $zdjecie];
    }

    /** Dwa gotowe zdjęcia przed badanym — przy limicie 2 badane wypada poza ocenę. */
    private function dopnijPrzed(Post $wpis, Media $zdjecie): void
    {
        $wpis->media()->updateExistingPivot($zdjecie->getKey(), ['position' => 3]);
        $wpis->media()->attach($this->gotoweZdjecie($wpis->author), ['position' => 1]);
        $wpis->media()->attach($this->gotoweZdjecie($wpis->author), ['position' => 2]);
    }
}
