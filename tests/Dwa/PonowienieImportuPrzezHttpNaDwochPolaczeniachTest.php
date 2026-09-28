<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Import\KlientLuna;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Jobs\OdczytajPrzepis;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\WpisZgody;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regresja #2064: dwa równoległe POST-y „Spróbuj jeszcze raz” dla tego
 * samego szkicu kosztują najwyżej JEDNO płatne wywołanie modelu.
 *
 * `PonowienieImportuNaDwochPolaczeniachTest` woła samą akcję. Ten test idzie
 * przez prawdziwe żądania HTTP (dwa procesy, dwa połączenia), a potem
 * wykonuje w tym procesie KAŻDE zadanie `OdczytajPrzepis`, które zostało
 * w kolejce — z atrapą modelu, która liczy żądania. Model odpowiada 503,
 * więc szkic zostaje pusty: gdyby w kolejce stały dwa zadania, oba przeszłyby
 * kontrolę „szkic nietknięty” i oba zapłaciłyby za odczyt (opis w issue).
 */
#[Group('dwa-polaczenia')]
final class PonowienieImportuPrzezHttpNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private ?string $szkicId = null;

    /** @var list<ProcesRownolegly> */
    private array $uczestnicy = [];

    protected function tearDown(): void
    {
        foreach ($this->uczestnicy as $uczestnik) {
            $uczestnik->zabij();
        }

        if ($this->szkicId !== null) {
            $importy = DB::table('importy_przepisow')->where('recipe_id', $this->szkicId)->pluck('id');
            foreach ($importy as $importId) {
                DB::table('jobs')->where('payload', 'like', '%'.$importId.'%')->delete();
                DB::table('ai_rezerwacje')->where('import_id', $importId)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_dwa_rownolegle_posty_ponowienia_to_jedno_zlecenie_jedno_zadanie_i_jedno_platne_wywolanie(): void
    {
        $this->konfiguracjaOdczytu();
        Storage::fake('public');

        $osoba = $this->konto(['email' => 'import-'.bin2hex(random_bytes(10)).'@example.invalid']);
        app(PrzestawZgodeNaOdczytAi::class)->handle($osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
        $media = app(StoreUploadedImage::class)->handle($osoba, UploadedFile::fake()->image('kartka.jpg', 1200, 1600));
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $osoba->getKey(),
            'visibility' => 'private',
            'source_scan_media_id' => $media->getKey(),
        ]);
        $this->szkicId = (string) $szkic->getKey();
        $poprzednie = new ImportPrzepisu;
        $poprzednie->forceFill([
            'user_id' => $osoba->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY,
        ])->save();

        // Oba żądania stają na tej samej blokadzie importów osoby, której
        // używa akcja — dopiero wtedy naprawdę czekają JEDNOCZEŚNIE.
        $bariera = $this->bariera(
            "SELECT pg_advisory_xact_lock(hashtext('kuking:import:' || ?))",
            [(string) $osoba->getKey()],
        );
        $argumenty = ['kto' => (string) $osoba->getKey(), 'poprzednie' => (string) $poprzednie->getKey()];
        $pierwszy = $this->uczestnik($argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->uczestnik($argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $a = $pierwszy->wynik();
        $b = $drugi->wynik();
        $this->assertBezZakleszczenia($a, 'pierwszy POST');
        $this->assertBezZakleszczenia($b, 'drugi POST');
        $this->assertTrue($a['ok'], $a['komunikat']);
        $this->assertTrue($b['ok'], $b['komunikat']);

        $nowe = ImportPrzepisu::query()->where('recipe_id', $szkic->getKey())
            ->whereKeyNot($poprzednie->getKey())->pluck('id')->map(fn ($id): string => (string) $id)->all();

        // Jedno zlecenie, a oba żądania prowadzą człowieka do niego, bez błędu.
        $this->assertCount(1, $nowe, 'Dwa POST-y ponowienia zapisały więcej niż jedno nowe zlecenie.');
        $this->assertSame(302, $a['wartosc']['status']);
        $this->assertSame(302, $b['wartosc']['status']);
        $this->assertNull($a['wartosc']['blad']);
        $this->assertNull($b['wartosc']['blad']);
        $this->assertStringEndsWith('/import/'.$nowe[0], (string) $a['wartosc']['dokad']);
        $this->assertStringEndsWith('/import/'.$nowe[0], (string) $b['wartosc']['dokad']);

        // Limit osoby (D-297) widzi stary odczyt i JEDNO ponowienie.
        $this->assertSame(2, DB::table('importy_przepisow')->where('user_id', $osoba->getKey())
            ->where('status', '!=', ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM)->count());

        $zadania = DB::table('jobs')->where('payload', 'like', '%OdczytajPrzepis%')->get()
            ->filter(fn (object $z): bool => array_filter($nowe, fn (string $id): bool => str_contains((string) $z->payload, $id)) !== []);
        $this->assertCount(1, $zadania, 'Dwa POST-y ponowienia wstawiły do kolejki więcej niż jedno zadanie odczytu.');

        // Każde zlecenie, które czeka, dostaje swój odczyt — tak jak zrobiłby
        // worker. Model odpowiada 503, więc szkic zostaje pusty.
        $wywolania = 0;
        Http::fake(function () use (&$wywolania) {
            $wywolania++;

            return Http::response([], 503);
        });
        $czekajace = ImportPrzepisu::query()->where('recipe_id', $szkic->getKey())
            ->where('status', ImportPrzepisu::STATUS_OCZEKUJE)->pluck('id');
        foreach ($czekajace as $id) {
            $this->app->call([new OdczytajPrzepis((string) $id), 'handle']);
        }

        $this->assertSame(1, $wywolania, 'Za jeden szkic zapłacono więcej niż jeden odczyt modelu.');
    }

    private function konfiguracjaOdczytu(): void
    {
        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.zrodla.zdjecie' => true,
            // Baza wyścigów zachowuje wydatki poprzednich przebiegów.
            'kuking.import.budzet.dzienny_usd' => 1000.0,
            'kuking.import.budzet.miesieczny_usd' => 10000.0,
        ]);
    }

    /** @param array<string, string> $argumenty */
    private function uczestnik(array $argumenty): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/ponowienieImportu.php', 'ponow-http', $argumenty, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->uczestnicy[] = $proces;

        return $proces;
    }
}
