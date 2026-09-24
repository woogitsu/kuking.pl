<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `/health` opisuje awarię KODEM, nie komunikatem wyjątku.
 *
 * PO CO TO JEST
 * `/health` nie ma `auth` i mieć nie może — Railway odpytuje go z zewnątrz
 * przy każdym wdrożeniu, a zewnętrzny monitoring co kilka minut
 * (`docs/infra/INFRA_DECISION.md` §11). Odpowiedź czyta więc dowolna osoba
 * w internecie. Do niedawna wkładaliśmy do niej `$e->getMessage()` wprost,
 * czyli przy awarii bazy pokazywaliśmy adres hosta, port i nazwę bazy
 * z komunikatu PDO, a przy awarii dysku — ścieżkę na serwerze.
 *
 * To jest ta sama usterka, którą audyt W7-07 znalazł w `failure_reason`
 * eksportu RODO, tyle że na trasie bez logowania i bez limitu zapytań.
 *
 * DLACZEGO TO JEST TEST, A NIE „PRZECIEŻ POPRAWILIŚMY KONTROLER"
 * Wyciek przez `/health` nie boli od razu i nie widać go na żadnym ekranie:
 * pokazuje się dopiero wtedy, gdy coś jest zepsute, czyli w najgorszym
 * możliwym momencie. Ktoś jutro dopisze czwarte sprawdzenie, wrzuci do niego
 * `$e->getMessage()`, zobaczy zielone testy i pójdzie dalej. Ten plik pilnuje
 * REGUŁY: cokolwiek wyjdzie w polu `error`, musi być kodem z
 * `HealthController::POWODY`.
 *
 * DRUGA POŁOWA REGUŁY
 * Zamilknięcie nie jest rozwiązaniem. Każdy test sprawdza też, że szczegół
 * techniczny NADAL gdzieś jest — w logu, gdzie ma dostęp do niego wyłącznie
 * właściciel. Inaczej „naprawa" polegałaby na oślepieniu monitoringu.
 */
class HealthNieZdradzaSzczegolowTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $zapisaneWLogu = [];

    public function test_awaria_bazy_nie_pokazuje_hosta_portu_ani_nazwy_bazy(): void
    {
        $this->nasluchujLogu();

        $domyslna = (string) config('database.default');

        // Port 1 nikogo nie słucha, więc PDO odbija się natychmiast — bez
        // czekania na timeout. Nazwy są celowo rozpoznawalne: jeśli
        // którakolwiek trafi do odpowiedzi, asercja niżej to pokaże.
        config([
            'database.connections.zepsuta' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'sekretna-nazwa-bazy',
                'username' => 'sekretny-uzytkownik',
                'password' => 'sekretne-haslo',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => 'zepsuta',
        ]);

        try {
            $odpowiedz = $this->get('/health');
        } finally {
            // Przywracamy PRZED czymkolwiek innym: `RefreshDatabase` wycofuje
            // transakcję na połączeniu domyślnym dopiero w `tearDown()`,
            // a domyślne rozwiązuje wtedy jeszcze raz z konfiguracji.
            config(['database.default' => $domyslna]);
            DB::purge('zepsuta');
        }

        $odpowiedz->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.ok', false);

        $tresc = $odpowiedz->getContent();
        $this->assertIsString($tresc);

        foreach (['127.0.0.1', 'sekretna-nazwa-bazy', 'sekretny-uzytkownik', 'SQLSTATE', 'select 1'] as $tajemnica) {
            $this->assertStringNotContainsString(
                $tajemnica,
                $tresc,
                "Publiczna odpowiedź /health zawiera „{$tajemnica}\". Tę stronę czyta "
                .'dowolna osoba w internecie, także taka, która właśnie szuka, po czym uderzyć.',
            );
        }

        $this->assertContains($odpowiedz->json('checks.database.error'), HealthController::POWODY);

        // ...a szczegół nie zniknął, tylko przeniósł się tam, gdzie widzi go
        // wyłącznie właściciel.
        $wLogu = $this->wpisyOZdrowiu();
        $this->assertNotEmpty($wLogu, 'Awaria /health nie zostawiła w logu ani jednej linijki.');
        $this->assertStringContainsString('127.0.0.1', json_encode($wLogu, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function test_awaria_dysku_nie_pokazuje_sciezki_na_serwerze(): void
    {
        $this->nasluchujLogu();

        config([
            'kuking.media.disk' => 'zepsuty',
            'filesystems.disks.zepsuty' => [
                'driver' => 'local',
                'root' => '/proc/nie-ma-takiego-katalogu',
                'throw' => true,
            ],
        ]);

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()->assertJsonPath('checks.media.ok', false);

        $tresc = $odpowiedz->getContent();
        $this->assertIsString($tresc);

        $this->assertStringNotContainsString('/proc/nie-ma-takiego-katalogu', $tresc);
        $this->assertSame('zapis_niemozliwy', $odpowiedz->json('checks.media.error'));
        $this->assertContains($odpowiedz->json('checks.media.error'), HealthController::POWODY);

        $this->assertStringContainsString(
            '/proc/nie-ma-takiego-katalogu',
            json_encode($this->wpisyOZdrowiu(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_martwa_droga_publiczna_nie_pokazuje_katalogu_dysku(): void
    {
        // `public/storage` ma istnieć i wskazywać na domyślny dysk — dopiero
        // wtedy dysk `bez_linku` jest „gdzie indziej”. Bez tego wynik zależał od
        // tego, czy wcześniej w tym samym procesie inny test wywołał
        // `storage:link` (po podziale testów na części — nie wywołał).
        Artisan::call('storage:link');

        $katalog = storage_path('framework/testing/zdjecia-bez-linku-kody');
        File::ensureDirectoryExists($katalog);

        config([
            'kuking.media.disk' => 'bez_linku',
            'filesystems.disks.bez_linku' => [
                'driver' => 'local',
                'root' => $katalog,
                'throw' => true,
            ],
        ]);

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()->assertJsonPath('checks.media.ok', false);

        $tresc = $odpowiedz->getContent();
        $this->assertIsString($tresc);

        // Ani katalogu dysku, ani ścieżki do `public/storage`: jedno i drugie
        // opisuje układ plików na serwerze.
        $this->assertStringNotContainsString($katalog, $tresc);
        $this->assertStringNotContainsString(public_path('storage'), $tresc);

        $this->assertSame('droga_publiczna_gdzie_indziej', $odpowiedz->json('checks.media.error'));
        $this->assertContains($odpowiedz->json('checks.media.error'), HealthController::POWODY);

        File::deleteDirectory($katalog);
    }

    public function test_zdrowa_instalacja_nie_ma_w_odpowiedzi_zadnego_pola_error(): void
    {
        Artisan::call('storage:link');

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()->assertJsonPath('status', 'ok');

        // Dopóki wszystko działa, `error` nie ma prawa istnieć — także puste.
        // Gdyby istniało, test na „kod z zamkniętego zbioru" można by przejść
        // pustym stringiem, czyli niczego by nie sprawdzał.
        foreach (array_keys((array) $odpowiedz->json('checks')) as $nazwa) {
            $this->assertNull($odpowiedz->json("checks.{$nazwa}.error"));
        }
    }

    private function nasluchujLogu(): void
    {
        $this->zapisaneWLogu = [];

        Log::listen(function ($wiadomosc): void {
            $this->zapisaneWLogu[] = $wiadomosc;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wpisyOZdrowiu(): array
    {
        return array_values(array_map(
            fn ($w) => $w->context,
            array_filter(
                $this->zapisaneWLogu,
                fn ($w) => $w->message === 'Kontrola /health nie przeszła.',
            ),
        ));
    }
}
