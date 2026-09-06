<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportFileNames;
use App\Jobs\GenerateUserExport;
use App\Mail\DataExportReady;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

/**
 * Paczka ZIP z danymi użytkownika — RODO art. 15 i 20.
 *
 * Ten plik pilnuje trzech rzeczy naraz:
 *  - że paczka POWSTAJE i naprawdę zawiera zdjęcia (archiwum Garnek.pl jest
 *    niepełne dokładnie dlatego, że zdjęć nie pobrano),
 *  - że pobierze ją TYLKO właściciel i tylko w terminie,
 *  - że NIE MA w niej cudzych danych osobowych — to najważniejszy test tutaj.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dysk ze zdjęciami i dysk z paczkami — oba udawane, żeby test nie
        // zostawiał plików w storage/ projektu.
        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    // -----------------------------------------------------------------
    // Budowanie paczki
    // -----------------------------------------------------------------

    public function test_job_tworzy_plik_i_ustawia_status_rozmiar_i_termin_waznosci(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);

        $export = $this->runExportFor($basia);

        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertSame('local', $export->disk);
        $this->assertNotNull($export->object_key);
        $this->assertGreaterThan(0, (int) $export->bytes);
        $this->assertNotNull($export->completed_at);
        $this->assertNotNull($export->expires_at);
        $this->assertTrue($export->expires_at->isFuture());
        $this->assertSame(7, (int) $export->completed_at->diffInDays($export->expires_at, absolute: true));

        Storage::disk('local')->assertExists($export->object_key);
        $this->assertTrue($export->isDownloadable());
    }

    public function test_paczka_zawiera_index_czytelny_html_zdjecia_i_dane_json(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        $recipe = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        $photo = $this->photoFor($basia, 'zdjecie-rosolu');
        $recipe->update(['hero_media_id' => $photo->getKey()]);

        $export = $this->runExportFor($basia);

        // Świadomie prawdziwy ZIP, nie atrapa: chcemy wiedzieć, że plik
        // faktycznie da się otworzyć, a nie tylko że coś zapisaliśmy.
        $files = $this->filesInArchive($export);

        $this->assertContains('index.html', $files);
        $this->assertContains('dane.json', $files);
        $this->assertContains('wpisy.html', $files);
        $this->assertContains('CZYTAJ-TO-NAJPIERW.txt', $files);

        $recipePages = array_values(array_filter($files, fn (string $f): bool => str_starts_with($f, 'przepisy/')));
        $this->assertCount(1, $recipePages);

        $photos = array_values(array_filter($files, fn (string $f): bool => str_starts_with($f, 'zdjecia/')));
        $this->assertCount(1, $photos, 'Paczka bez zdjęć jest atrapą eksportu.');
        $this->assertMatchesRegularExpression('/^zdjecia\/\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.webp$/', $photos[0]);

        $index = $this->readFromArchive($export, 'index.html');
        $this->assertStringContainsString('Rosół z kury', $index);
        $this->assertStringContainsString(basename($recipePages[0]), $index, 'Spis treści musi linkować do pliku przepisu.');

        // Strona przepisu musi działać BEZ internetu: zero adresów http(s)
        // do naszych zasobów, zdjęcia po ścieżce relatywnej.
        $page = $this->readFromArchive($export, $recipePages[0]);
        $this->assertStringContainsString('../zdjecia/', $page);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $page);
        $this->assertStringContainsString('<style>', $page);
        $this->assertStringNotContainsString('http://localhost', $page);

        $readme = $this->readFromArchive($export, 'CZYTAJ-TO-NAJPIERW.txt');
        $this->assertStringContainsString('index.html', $readme);
        $this->assertStringContainsString('Kliknij dwa razy', $readme);
    }

    public function test_eksport_zawiera_prywatne_wpisy_i_szkice_przepisow(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        Post::factory()->for($basia, 'author')->private()->create(['body' => 'Prywatna notatka o zakalcu']);
        Post::factory()->for($basia, 'author')->draft()->create(['body' => 'Szkic wpisu o pierogach']);
        $draft = Recipe::factory()->for($basia, 'author')->draft()->create(['title' => 'Niedokonczony sernik']);

        $export = $this->runExportFor($basia);
        $data = $this->jsonFromArchive($export);

        $bodies = array_column($data['wpisy'], 'tresc');
        $this->assertContains('Prywatna notatka o zakalcu', $bodies);
        $this->assertContains('Szkic wpisu o pierogach', $bodies);

        $titles = array_column($data['przepisy'], 'tytul');
        $this->assertContains('Niedokonczony sernik', $titles);

        // Szkic dostaje własny plik HTML — inaczej „wszystkie Twoje przepisy”
        // byłoby kłamstwem.
        $files = $this->filesInArchive($export);
        $this->assertContains('przepisy/'.ExportFileNames::recipeFile($draft), $files);
    }

    /**
     * Regresja: paczka obiecuje „wszystko po kolei, od najstarszego”, więc
     * kolejność musi wynikać z DATY WIDOCZNEJ w paczce (publikacji), a nie
     * z `created_at`, który przy imporcie danych bywa taki sam dla wszystkiego.
     */
    public function test_wpisy_sa_uporzadkowane_od_najstarszego_po_dacie_publikacji(): void
    {
        $basia = $this->user('basia');

        Post::factory()->for($basia, 'author')->create([
            'body' => 'Nowszy wpis',
            'published_at' => now()->subDay(),
        ]);

        Post::factory()->for($basia, 'author')->create([
            'body' => 'Starszy wpis',
            'published_at' => now()->subMonth(),
        ]);

        $data = $this->jsonFromArchive($this->runExportFor($basia));

        $this->assertSame(['Starszy wpis', 'Nowszy wpis'], array_column($data['wpisy'], 'tresc'));
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Komentarz innej osoby pod wpisem Basi jest danymi TEJ osoby. Basia ma
     * prawo dostać treść i podpis — nie ma prawa dostać adresu e-mail Zenka.
     */
    public function test_eksport_nie_zawiera_adresow_email_innych_uzytkownikow(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek', 'email' => 'zenek@przyklad.test']);

        $post = Post::factory()->for($basia, 'author')->create(['body' => 'Dzisiejszy rosół']);
        Comment::factory()->create([
            'author_id' => $zenek->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Wygląda przepysznie!',
        ]);

        $recipe = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        Comment::factory()->create([
            'author_id' => $zenek->getKey(),
            'post_id' => null,
            'recipe_id' => $recipe->getKey(),
            'body' => 'Zrobiłem i wyszło.',
        ]);

        CookedEvent::factory()->create([
            'user_id' => $zenek->getKey(),
            'recipe_id' => $recipe->getKey(),
        ]);

        $basia->following()->attach($zenek->getKey());
        $zenek->following()->attach($basia->getKey());

        $export = $this->runExportFor($basia);

        // Przeszukujemy CAŁE archiwum, nie tylko dane.json — e-mail nie może
        // wyciec ani przez HTML, ani przez plik tekstowy.
        foreach ($this->filesInArchive($export) as $file) {
            if (str_starts_with($file, 'zdjecia/')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'zenek@przyklad.test',
                $this->readFromArchive($export, $file),
                "Adres e-mail innego użytkownika trafił do pliku {$file}.",
            );
        }

        // Własny e-mail Basi w paczce być MUSI — to jej dane (art. 15).
        $data = $this->jsonFromArchive($export);
        $this->assertSame($basia->email, $data['konto']['email']);

        // Treść i podpis cudzego komentarza zostają — bez nich eksport
        // nie odzwierciedla tego, co Basia widziała w serwisie.
        $comments = $data['wpisy'][0]['komentarze'];
        $this->assertSame('Wygląda przepysznie!', $comments[0]['tresc']);
        $this->assertSame('Zenek', $comments[0]['autor']);
        $this->assertArrayNotHasKey('email', $comments[0]);
        $this->assertArrayNotHasKey('id', $comments[0]);
        $this->assertArrayNotHasKey('autor_id', $comments[0]);
    }

    public function test_powiadomienia_nie_przenosza_cudzych_identyfikatorow(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek', 'email' => 'zenek@przyklad.test']);

        Notification::create([
            'user_id' => $basia->getKey(),
            'actor_id' => $zenek->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => ['username' => 'zenek', 'excerpt' => 'zaczął Cię obserwować'],
        ]);

        $export = $this->runExportFor($basia);
        $data = $this->jsonFromArchive($export);

        $notification = $data['powiadomienia'][0];

        $this->assertSame('Zenek', $notification['od_kogo']);
        $this->assertSame('zaczął Cię obserwować', $notification['szczegoly']['excerpt']);

        // `username` i wszystkie identyfikatory są odfiltrowane listą
        // dozwolonych kluczy — nowy klucz w kodzie nie wycieknie tu sam.
        $this->assertArrayNotHasKey('username', $notification['szczegoly']);
        $this->assertArrayNotHasKey('actor_id', $notification);
    }

    // -----------------------------------------------------------------
    // Pobieranie
    // -----------------------------------------------------------------

    public function test_wlasciciel_pobiera_swoja_paczke(): void
    {
        $basia = $this->user('basia');
        $export = $this->runExportFor($basia);

        $this->actingAs($basia)
            ->get($this->downloadUrl($export))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=kuking-moje-dane-'.$export->created_at->format('Y-m-d').'.zip');
    }

    public function test_inny_uzytkownik_nie_pobierze_cudzej_paczki(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');

        $export = $this->runExportFor($basia);

        // Zenek ma PRAWIDŁOWO podpisany link (np. przekazany mu przez pomyłkę).
        // Podpis to nie autoryzacja — właściciela sprawdza kontroler.
        $this->actingAs($zenek)
            ->get($this->downloadUrl($export))
            ->assertNotFound();
    }

    public function test_gosc_bez_zalogowania_nie_pobierze_paczki(): void
    {
        $basia = $this->user('basia');
        $export = $this->runExportFor($basia);

        $this->get($this->downloadUrl($export))->assertRedirect(route('login'));
    }

    public function test_niepodpisany_adres_nie_dziala(): void
    {
        $basia = $this->user('basia');
        $export = $this->runExportFor($basia);

        $this->actingAs($basia)
            ->get(route('settings.data.download', ['export' => $export->getKey()]))
            ->assertForbidden();
    }

    public function test_paczka_po_terminie_waznosci_nie_jest_do_pobrania(): void
    {
        $basia = $this->user('basia');
        $export = $this->runExportFor($basia);

        $export->update(['expires_at' => now()->subDay()]);
        $export->refresh();

        // Warstwa pierwsza: podpis wystawiony na termin paczki jest już przedawniony.
        $this->actingAs($basia)
            ->get($this->downloadUrl($export))
            ->assertForbidden();

        // Warstwa druga: nawet ze świeżym, prawidłowym podpisem paczka
        // po terminie nie wychodzi z serwera.
        $freshLink = URL::temporarySignedRoute(
            'settings.data.download',
            now()->addHour(),
            ['export' => $export->getKey()],
        );

        $this->actingAs($basia)->get($freshLink)->assertNotFound();
    }

    // -----------------------------------------------------------------
    // E-mail
    // -----------------------------------------------------------------

    public function test_wysylamy_email_z_linkiem_podpisanym_czasowo(): void
    {
        Mail::fake();

        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $export = $this->runExportFor($basia);

        Mail::assertSent(DataExportReady::class, function (DataExportReady $mail) use ($basia, $export): bool {
            $rendered = $mail->render();

            return $mail->hasTo($basia->email)
                && str_contains($rendered, 'signature=')
                && str_contains($rendered, 'expires=')
                && str_contains($rendered, (string) $export->getKey());
        });
    }

    // -----------------------------------------------------------------
    // Błędy
    // -----------------------------------------------------------------

    public function test_niepowodzenie_ustawia_status_failed_z_powodem(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        // Dysk, którego nie ma w konfiguracji — realny odpowiednik awarii storage.
        config(['kuking.exports.disk' => 'dysk-ktorego-nie-ma']);

        try {
            (new GenerateUserExport((string) $export->getKey()))->handle();
            $this->fail('Job powinien rzucić wyjątek, żeby kolejka zapisała porażkę.');
        } catch (\Throwable) {
            // Wyjątek jest pożądany — kolejka musi wiedzieć o porażce.
        }

        $export->refresh();

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertNotNull($export->failure_reason);
        $this->assertStringContainsString('Nie udało się przygotować paczki', $export->failure_reason);
    }

    public function test_job_nie_zostawia_rekordu_w_stanie_przygotowywania(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_PROCESSING,
        ]);

        // Tak wygląda timeout: proces ginie, wyjątku nie ma, zostaje hook failed().
        (new GenerateUserExport((string) $export->getKey()))->failed(null);

        $export->refresh();

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertStringContainsString('limit czasu', (string) $export->failure_reason);
    }

    public function test_prosba_o_eksport_kolejkuje_job(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        Queue::assertPushed(GenerateUserExport::class);
    }

    // -----------------------------------------------------------------
    // Sprzątanie
    // -----------------------------------------------------------------

    public function test_komenda_sprzatajaca_usuwa_wygasle_paczki(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');

        $stara = $this->runExportFor($basia);
        $stara->update(['expires_at' => now()->subDay()]);

        $swieza = $this->runExportFor($zenek);

        $staryKlucz = (string) $stara->object_key;
        $swiezyKlucz = (string) $swieza->object_key;

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        Storage::disk('local')->assertMissing($staryKlucz);
        Storage::disk('local')->assertExists($swiezyKlucz);

        $stara->refresh();
        $this->assertSame(DataExport::STATUS_EXPIRED, $stara->status);
        $this->assertNull($stara->object_key);
        $this->assertNull($stara->bytes);

        // Rekord zostaje — historia „poprosiłam o eksport” to też dane
        // użytkownika. Znika tylko plik.
        $this->assertDatabaseHas('data_exports', ['id' => $stara->getKey()]);

        $this->assertSame(DataExport::STATUS_READY, $swieza->refresh()->status);
    }

    public function test_tryb_podgladu_nic_nie_kasuje(): void
    {
        $basia = $this->user('basia');
        $export = $this->runExportFor($basia);
        $export->update(['expires_at' => now()->subDay()]);

        $this->artisan('kuking:sprzataj-eksporty', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('local')->assertExists((string) $export->object_key);
        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function runExportFor(User $user): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    /** Zdjęcie z prawdziwym plikiem na udawanym dysku. */
    private function photoFor(User $owner, string $name): Media
    {
        $key = "media/{$owner->getKey()}/{$name}.webp";

        Storage::disk('public')->put($key, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $owner->getKey(),
            'disk' => 'public',
            'object_key' => $key,
            'status' => Media::STATUS_READY,
        ]);
    }

    private function downloadUrl(DataExport $export): string
    {
        return URL::temporarySignedRoute(
            'settings.data.download',
            $export->expires_at,
            ['export' => $export->getKey()],
        );
    }

    /** @return list<string> */
    private function filesInArchive(DataExport $export): array
    {
        $zip = $this->openArchive($export);

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (is_string($name)) {
                $files[] = $name;
            }
        }

        $zip->close();

        return $files;
    }

    private function readFromArchive(DataExport $export, string $file): string
    {
        $zip = $this->openArchive($export);
        $content = $zip->getFromName($file);
        $zip->close();

        $this->assertIsString($content, "W archiwum nie ma pliku {$file}.");

        return $content;
    }

    /** @return array<string, mixed> */
    private function jsonFromArchive(DataExport $export): array
    {
        return json_decode($this->readFromArchive($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function openArchive(DataExport $export): ZipArchive
    {
        $path = Storage::disk((string) $export->disk)->path((string) $export->object_key);

        $zip = new ZipArchive;

        $this->assertTrue($zip->open($path) === true, 'Nie udało się otworzyć paczki ZIP.');

        return $zip;
    }

    public function test_paczka_bez_zdjec_nie_obiecuje_katalogu_ktorego_nie_ma(): void
    {
        // ZipArchive nie tworzy pustych katalogów, więc `zdjecia/` powstaje
        // w paczce TYLKO wtedy, gdy jest do niego co włożyć. Spis treści
        // pokazywał mimo to link „Otwórz katalog ze zdjęciami" — u kogoś bez
        // ani jednego zdjęcia prowadził w przeglądarce do „nie znaleziono pliku".
        //
        // Usterka dotyka WYŁĄCZNIE osoby najświeższej w serwisie i to w pliku,
        // który ma być dowodem, że jej dane są bezpieczne. Wszystkie pozostałe
        // testy budują eksport z gotowymi zdjęciami, więc żaden jej nie widział.
        $user = $this->user('bezzdjec');

        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport($export->getKey()))->handle();

        $zip = new ZipArchive;
        $zip->open(Storage::disk($export->refresh()->disk)->path($export->object_key));

        $index = $zip->getFromName('index.html');

        // Katalogu naprawdę nie ma...
        $this->assertFalse($zip->locateName('zdjecia/'));

        // ...więc spis treści nie ma prawa do niego zapraszać.
        $this->assertStringNotContainsString('href="zdjecia/"', $index);

        // I mówi wprost, dlaczego go nie ma — pusta sekcja bez wyjaśnienia
        // wygląda jak brakująca część paczki.
        $this->assertStringContainsString('Nie masz jeszcze w Kuking żadnego zdjęcia', $index);

        $zip->close();
    }
}
