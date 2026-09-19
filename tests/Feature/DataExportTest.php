<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
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

    public function test_paczka_zawiera_decyzje_o_instalacji_wlasnego_konta(): void
    {
        $basia = $this->user('basia', ['pwa_prompt_state' => 'dismissed']);
        $this->user('marek', ['pwa_prompt_state' => 'installed']);

        $data = $this->jsonFromArchive($this->runExportFor($basia));

        $this->assertSame('dismissed', $data['konto']['stan_zachety_instalacji']);
    }

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
     * REGRESJA: zapisany WPIS w ogóle nie trafiał do paczki.
     *
     * Od migracji `2026_09_06_150000_collection_items_accept_posts` zeszyt
     * przyjmuje dwie rzeczy — przepisy ORAZ wpisy. `CollectUserExportData::
     * collections()` czytało wyłącznie `->recipes`, więc druga połowa zeszytu
     * znikała: bez pozycji, bez liczby i bez zdania o tym, że jej nie ma.
     *
     * Żaden dotychczasowy test tego nie widział, bo wszystkie budowały
     * eksport z zeszytem pustym albo z samych przepisów — dokładnie ta sama
     * klasa błędu co przy paczce bez zdjęć niżej.
     */
    public function test_paczka_zawiera_wpis_zapisany_do_zeszytu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek', 'email' => 'zenek@przyklad.test']);

        $wpis = Post::factory()->for($zenek, 'author')->create(['body' => 'Zakwas na żurek po dwóch dniach']);
        $przepis = Recipe::factory()->for($zenek, 'author')->create(['title' => 'Żurek na zakwasie']);

        $zeszyt = $basia->defaultCollection();
        // `app(...)`, nie `new`: `SaveRecipeToCollection` bierze w konstruktorze
        // `NotifyUser` (powiadamia autora przepisu o zapisaniu). Kontener
        // składa akcję tak samo jak w produkcji, więc test przechodzi tą samą
        // drogą co kontroler — a nie własną, uproszczoną.
        app(SaveRecipeToCollection::class)->handle($basia, $przepis, $zeszyt);
        app(SavePostToCollection::class)->handle($basia, $wpis, $zeszyt, 'Spróbować przed Wielkanocą');

        $export = $this->runExportFor($basia);
        $data = $this->jsonFromArchive($export);

        $this->assertCount(1, $data['kolekcje']);
        $kolekcja = $data['kolekcje'][0];

        // Przepis był w paczce od początku — sprawdzamy, że nic mu nie ubyło.
        $this->assertSame(['Żurek na zakwasie'], array_column($kolekcja['przepisy'], 'tytul'));

        // ...a wpis był tym, czego brakowało.
        $this->assertCount(1, $kolekcja['wpisy'], 'Zapisany wpis nie trafił do paczki.');
        $this->assertSame('Zakwas na żurek po dwóch dniach', $kolekcja['wpisy'][0]['tresc']);
        $this->assertSame('Zenek', $kolekcja['wpisy'][0]['autor']);
        $this->assertSame('Spróbować przed Wielkanocą', $kolekcja['wpisy'][0]['moja_notatka']);
        $this->assertNotNull($kolekcja['wpisy'][0]['zapisano']);

        // Klucz z liczbą jest ZAWSZE, także gdy nic się nie schowało (issue #113).
        $this->assertSame(0, $kolekcja['wpisow_juz_niewidocznych']);

        // Zapisany wpis to CUDZA treść. Wolno przepisać tekst, datę i podpis —
        // nigdy adresu e-mail autora.
        $this->assertStringNotContainsString(
            'zenek@przyklad.test',
            $this->readFromArchive($export, 'dane.json'),
        );
    }

    /**
     * Wpis, którego w serwisie już nie widać, wchodzi do paczki jako LICZBA,
     * nie jako treść.
     *
     * Zeszyt jest pojemnikiem na cudze treści. Wpis zapisany wtedy, gdy autor
     * pokazywał go obserwującym, przestaje być widoczny po zaprzestaniu
     * obserwowania — ekran zeszytu mówi wtedy „ile pozycji, nie jakich".
     * Paczka ZIP zostaje na dysku na zawsze i da się ją komuś wysłać, więc
     * musi trzymać tę samą granicę — tak samo jak przy powiadomieniach
     * (`Notification::visibleTo`).
     */
    public function test_zapisany_wpis_juz_niewidoczny_wchodzi_do_paczki_jako_liczba(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek']);

        $wpis = Post::factory()->for($zenek, 'author')->followersOnly()->create([
            'body' => 'Tylko dla obserwujących: rosół babci',
        ]);

        // Basia obserwuje Zenka, odkłada wpis „na potem"...
        $basia->following()->attach($zenek->getKey(), ['created_at' => now()]);
        app(SavePostToCollection::class)->handle($basia, $wpis, $basia->defaultCollection());

        // ...a potem przestaje obserwować. Wpis zostaje w zeszycie, ale
        // przestaje być dla niej widoczny.
        $basia->following()->detach($zenek->getKey());

        $export = $this->runExportFor($basia);
        $kolekcja = $this->jsonFromArchive($export)['kolekcje'][0];

        $this->assertSame([], $kolekcja['wpisy']);
        $this->assertSame(1, $kolekcja['wpisow_juz_niewidocznych']);

        $this->assertStringNotContainsString(
            'Tylko dla obserwujących: rosół babci',
            $this->readFromArchive($export, 'dane.json'),
            'Paczka wyjęła cudzy tekst poza ustawienie widoczności, które wybrał jego autor.',
        );
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

    /**
     * Paczka RODO ma pokazywać to, co człowiek WIDZI w serwisie — nie
     * wszystko, co o nim leży w bazie.
     *
     * `$user->notifications()` w eksporcie nie miało `visibleTo()`, którym
     * filtruje się lista powiadomień na ekranie. Skutek: powiadomienie
     * ukryte w serwisie (bo jego sprawca został zbanowany albo poprosił
     * o usunięcie konta) i tak trafiało do paczki — RAZEM z polem
     * `excerpt`, czyli 120 znakami CUDZEGO tekstu.
     *
     * To jest ten sam nawracający wzorzec, o którym mówi
     * `docs/HANDOVER.md`: reguła istnieje poprawnie w jednej warstwie
     * (`Notification::scopeVisibleTo`), a druga jej nie woła.
     */
    public function test_paczka_nie_niesie_powiadomien_ukrytych_w_serwisie(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek']);
        $widmo = $this->user('widmo', ['display_name' => 'Widmo']);

        // KONTROLA: zwykłe powiadomienie od aktywnego konta ma zostać.
        Notification::create([
            'user_id' => $basia->getKey(),
            'actor_id' => $zenek->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => ['excerpt' => 'zaczął Cię obserwować'],
        ]);

        Notification::create([
            'user_id' => $basia->getKey(),
            'actor_id' => $widmo->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => ['excerpt' => 'TEKST KTORY NIE MA PRAWA WYJSC'],
        ]);

        // Bez tego kroku oba powiadomienia są widoczne i test przechodziłby
        // niezależnie od naprawy.
        $widmo->forceFill(['status' => User::STATUS_BANNED])->save();

        $data = $this->jsonFromArchive($this->runExportFor($basia));

        // `JSON_UNESCAPED_UNICODE`, bo bez niego polskie znaki uciekają do
        // `\u0105` i asercja kontrolna nie znajduje własnego tekstu.
        $wszystko = json_encode($data['powiadomienia'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString(
            'zaczął Cię obserwować',
            $wszystko,
            'Zniknęło także zwykłe powiadomienie — filtr jest za szeroki.',
        );

        $this->assertStringNotContainsString(
            'TEKST KTORY NIE MA PRAWA WYJSC',
            $wszystko,
            'Fragment cudzego tekstu z powiadomienia ukrytego w serwisie trafił do paczki RODO.',
        );

        $this->assertCount(1, $data['powiadomienia']);
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
        // Kod, nie zdanie (audyt W7-07) — patrz reasonFor() w GenerateUserExport.
        $this->assertSame(DataExport::REASON_STORAGE, $export->failure_reason);
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
        $this->assertSame(DataExport::REASON_TIMEOUT, $export->failure_reason);
    }

    /**
     * Powtórzenie scenariusza wyżej, ale z naciskiem na to, co NIE ma prawa
     * trafić do kolumny: komentarz nad starym `reasonFor()` ostrzegał wprost
     * przed „SQLSTATE[42P01]" na ekranie, a kod robił dokładnie to (audyt W7-07).
     */
    public function test_powod_niepowodzenia_eksportu_nigdy_nie_zawiera_surowego_komunikatu_wyjatku(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        // Dysk, którego nie ma w konfiguracji — realny odpowiednik awarii storage.
        // Bez tej zmiany w reasonFor() SQLSTATE albo nazwa dysku z tej awarii
        // wylądowałyby wprost w failure_reason i na ekranie ustawień.
        config(['kuking.exports.disk' => 'dysk-ktorego-nie-ma']);

        try {
            (new GenerateUserExport((string) $export->getKey()))->handle();
            $this->fail('Job powinien rzucić wyjątek, żeby kolejka zapisała porażkę.');
        } catch (\Throwable) {
            // Wyjątek jest pożądany — kolejka musi wiedzieć o porażce.
        }

        $export->refresh();

        $this->assertContains($export->failure_reason, array_keys(DataExport::REASONS));

        $powod = (string) $export->failure_reason;

        $this->assertStringNotContainsString('SQLSTATE', $powod);
        $this->assertStringNotContainsString('Exception', $powod);
        $this->assertStringNotContainsString('dysk-ktorego-nie-ma', $powod);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $powod);
    }

    public function test_widok_pokazuje_ludzki_tekst_powodu_a_nie_kod(): void
    {
        $basia = $this->user('basia');

        DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => DataExport::REASON_STORAGE,
        ]);

        $response = $this->actingAs($basia)->get(route('settings.data'));

        $response->assertOk();
        $response->assertSee('Nie udało się zapisać paczki w naszym magazynie plików.');
        $response->assertSee((string) config('kuking.community.contact_email'));
        $response->assertDontSee(DataExport::REASON_STORAGE, false);
    }

    /**
     * Nieznany/stary kod (albo wiersz sprzed migracji W7-07, jeszcze z wolnym
     * tekstem) ma dać sensowny tekst, nie pusty ekran i nie surowy ciąg
     * z bazy — patrz DataExport::failureReasonLabel().
     */
    public function test_widok_pokazuje_bezpieczny_tekst_dla_nieznanego_kodu(): void
    {
        $basia = $this->user('basia');

        DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => 'Nie udało się przygotować paczki: SQLSTATE[42P01]: Undefined table',
        ]);

        $response = $this->actingAs($basia)->get(route('settings.data'));

        $response->assertOk();
        $response->assertSee('Nie udało się przygotować paczki z Twoimi danymi.');
        $response->assertDontSee('SQLSTATE', false);
        $response->assertDontSee('42P01', false);
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
        //
        // Zdanie opisuje PACZKĘ, nie konto: ta sama gałąź obsługuje konto
        // z samymi zdjęciami odrzuconymi, któremu „nie masz żadnego zdjęcia"
        // mówiłoby nieprawdę (`EksportMowiOZdjeciachWDrodzeTest`).
        $this->assertStringContainsString('W tej paczce nie ma żadnego zdjęcia', $index);

        $zip->close();
    }
}
