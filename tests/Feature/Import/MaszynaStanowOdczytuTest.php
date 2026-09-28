<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\Rezerwacja;
use App\Domain\Import\ZlecImportPrzepisu;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Jobs\OdczytajPrzepis;
use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Models\WpisZgody;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Maszyna stanów odczytu kartki: rezerwacja → wysłanie → zapis odpowiedzi →
 * rozliczenie (D-298, „Maszyna stanów płatnego wywołania”).
 *
 * Każda sekcja to jedno zgłoszenie z audytu i awaria WSTRZYKNIĘTA dokładnie
 * w oknie, które zgłoszenie opisuje (`DB::beforeExecuting()` — ten sam
 * sposób co `EksportNieUtykaMiedzyCommitemAWyslaniemTest`):
 *
 *   #1973 — awaria po rezerwacji, przed zapisem zlecenia,
 *   #1974 — awaria po rozliczeniu budżetu, przed zapisem zlecenia,
 *   #1977 — awaria między zapisem zlecenia a wysłaniem zadania,
 *   #1980 — awaria po zapisaniu odpowiedzi modelu, przed zapisem szkicu.
 *
 * KONTROLA UJEMNA (zmierzona 26.09.2026 na kodzie gałęzi sprzed poprawki,
 * wpisy w `scripts/kontrole-negatywne-alfa08.py`): każdy z czterech testów
 * `test_1973_…`, `test_1974_…`, `test_1977_…`, `test_1980_…` padał —
 * odpowiednio: 76 000 mikro-USD zarezerwowane na zawsze; wydatek 82 000
 * zamiast 76 000 (rozliczenie dwa razy); zlecenie `oczekuje` bez zadania
 * i to samo po ponowieniu z tym samym kluczem; DWA żądania do modelu.
 *
 * ŻADNYCH PRAWDZIWYCH WYWOŁAŃ: `Http::fake()` + `preventStrayRequests()`.
 */
final class MaszynaStanowOdczytuTest extends TestCase
{
    use RefreshDatabase;

    /** Najgorszy przypadek z cennika testu: 6000 × 2 + 8000 × 8. */
    private const REZERWACJA = 76_000;

    /** Koszt z `usage` atrapy: 1000 × 2 + 500 × 8. */
    private const KOSZT_Z_USAGE = 6_000;

    private User $osoba;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.zrodla.zdjecie' => true,
        ]);

        $this->osoba = $this->user('maszyna');
        app(PrzestawZgodeNaOdczytAi::class)->handle($this->osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
    }

    /**
     * KONTROLA DODATNIA PLIKU (`docs/PULAPKI_TESTOW.md` §4): bez awarii
     * zwykła droga kończy się szkicem, jednym żądaniem i jednym rozliczeniem.
     * Testy niżej sprawdzają, czego po awarii NIE MA — przeszłyby też na
     * kodzie, który nie robi nic.
     */
    public function test_bez_awarii_jedno_zadanie_jedno_rozliczenie_gotowy_szkic(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();

        $this->uruchom($zlecenie);

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertSame(self::KOSZT_Z_USAGE, $zlecenie->koszt_mikrousd);
        $this->assertSame(['zarezerwowano' => 0, 'wydano' => self::KOSZT_Z_USAGE], $this->budzet());
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('ai_rezerwacje')->where('import_id', $zlecenie->getKey())->where('stan', 'rozliczona')->count());
    }

    // ------------------------------------------------------------------
    // #1973 — awaria po rezerwacji, przed zapisem zlecenia
    // ------------------------------------------------------------------

    public function test_1973_awaria_po_rezerwacji_nie_blokuje_budzetu_po_failed(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();

        // Pierwszy zapis zlecenia PO udanej rezerwacji pada.
        $zarezerwowano = false;
        $padlo = false;
        DB::beforeExecuting(function (string $sql) use (&$zarezerwowano, &$padlo): void {
            if (str_contains($sql, 'zarezerwowano_mikrousd = zarezerwowano_mikrousd +')) {
                $zarezerwowano = true;

                return;
            }

            if ($zarezerwowano && ! $padlo && str_starts_with($sql, 'update "importy_przepisow"')) {
                $padlo = true;

                throw new RuntimeException('zapis zlecenia padł (awaria wymuszona testem)');
            }
        });

        $blad = $this->uruchomZAwaria($zlecenie);

        $this->assertTrue($padlo, 'Sabotaż nie trafił w okno między rezerwacją a zapisem zlecenia.');
        (new OdczytajPrzepis((string) $zlecenie->getKey()))->failed($blad);

        Http::assertNothingSent();
        $this->assertSame(
            ['zarezerwowano' => 0, 'wydano' => 0],
            $this->budzet(),
            'Rezerwacja bez śladu w zleceniu została w budżecie — kolejne importy odbiją się od limitu, którego nikt nie wydał.',
        );
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->fresh()->status);
    }

    /**
     * Proces zabity po rezerwacji (`failed()` nigdy nie ruszy): rezerwację
     * wygasza sprzątanie po czasie, nie człowiek.
     */
    public function test_1973_rezerwacja_osierocona_bez_failed_wygasa_po_czasie(): void
    {
        $zlecenie = $this->zlecenieBezWysylki();
        $budzet = app(BudzetAi::class);
        $rezerwacja = $budzet->zarezerwuj(self::REZERWACJA, (string) $zlecenie->getKey(), 1);
        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);

        $this->artisan('kuking:odzyskaj-importy')->assertSuccessful();
        $this->assertSame(self::REZERWACJA, $this->budzet()['zarezerwowano'], 'Świeża rezerwacja trwającego odczytu nie może zniknąć.');

        $this->travel((int) config('kuking.import.odzyskiwanie.rezerwacja_minut') + 1)->minutes();
        $this->artisan('kuking:odzyskaj-importy')->assertSuccessful();

        // Nie wysłana = zwolniona bez wydatku.
        $this->assertSame(['zarezerwowano' => 0, 'wydano' => 0], $this->budzet());
        $this->assertSame('zwolniona', DB::table('ai_rezerwacje')->value('stan'));
    }

    // ------------------------------------------------------------------
    // #1974 — rozliczenie idempotentne
    // ------------------------------------------------------------------

    public function test_1974_awaria_po_rozliczeniu_budzetu_nie_liczy_kosztu_drugi_raz(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();

        $padlo = false;
        DB::beforeExecuting(function (string $sql) use (&$padlo): void {
            if (! $padlo && str_starts_with($sql, 'update "importy_przepisow"') && str_contains($sql, '"koszt_mikrousd"')) {
                $padlo = true;

                throw new RuntimeException('zapis rozliczenia zlecenia padł (awaria wymuszona testem)');
            }
        });

        $blad = $this->uruchomZAwaria($zlecenie);

        $this->assertTrue($padlo, 'Sabotaż nie trafił w zapis rozliczenia zlecenia.');
        (new OdczytajPrzepis((string) $zlecenie->getKey()))->failed($blad);
        (new OdczytajPrzepis((string) $zlecenie->getKey()))->failed($blad);

        $budzet = $this->budzet();
        $this->assertSame(0, $budzet['zarezerwowano']);
        // Jedno żądanie = jedno rozliczenie. Rozliczenie zapisane w zleceniu
        // cofnęło się razem z budżetem, więc idzie najgorszy przypadek (D-297:
        // lepiej zawyżyć) — ale RAZ, a nie `usage` + rezerwacja.
        $this->assertSame(self::REZERWACJA, $budzet['wydano'], 'Jedno wywołanie modelu zostało policzone w budżecie więcej niż raz.');
        $this->assertSame($budzet['wydano'], $zlecenie->fresh()->koszt_mikrousd, 'Budżet i zlecenie rozjechały się — rachunku nie da się uzgodnić.');
    }

    public function test_1974_ponowne_rozliczenie_tej_samej_proby_nic_nie_zmienia(): void
    {
        $budzet = app(BudzetAi::class);
        $importId = (string) Str::uuid();
        $rezerwacja = $budzet->zarezerwuj(self::REZERWACJA, $importId, 1);
        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);

        $this->assertSame(self::KOSZT_Z_USAGE, $budzet->rozlicz($rezerwacja, self::KOSZT_Z_USAGE));
        $this->assertNull($budzet->rozlicz($rezerwacja, self::KOSZT_Z_USAGE), 'Drugie rozliczenie tej samej próby musi być niczym.');
        $this->assertNull($budzet->rozlicz($rezerwacja, null));
        $budzet->zwolnij($rezerwacja);

        $this->assertSame(['zarezerwowano' => 0, 'wydano' => self::KOSZT_Z_USAGE], $this->budzet());

        // Klucz (import, próba) jest unikalny także w bazie.
        $this->assertSame(BudzetAi::ODMOWA_POWTORZONA, $budzet->zarezerwuj(self::REZERWACJA, $importId, 1));
        $this->assertSame(['zarezerwowano' => 0, 'wydano' => self::KOSZT_Z_USAGE], $this->budzet());
    }

    // ------------------------------------------------------------------
    // #1977 — zlecenie i zadanie razem albo wcale
    // ------------------------------------------------------------------

    public function test_1977_awaria_kolejki_nie_zostawia_zlecenia_bez_zadania_a_ponowienie_je_wysyla(): void
    {
        config(['queue.default' => 'database']);
        $klucz = (string) Str::uuid();

        $padlo = false;
        DB::beforeExecuting(function (string $sql, array $wiazania) use (&$padlo): void {
            if (! $padlo && str_contains($sql, 'insert into "jobs"') && str_contains(implode(' ', array_map('strval', $wiazania)), 'OdczytajPrzepis')) {
                $padlo = true;

                throw new RuntimeException('kolejka nie przyjmuje zadań (awaria wymuszona testem)');
            }
        });

        try {
            app(ZlecImportPrzepisu::class)->handle($this->osoba, $this->kartka(), $klucz);
            $this->fail('Awaria kolejki nie przerwała zlecenia — test nie zmierzył tego, co miał.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kolejka nie przyjmuje', $e->getMessage());
        }

        $this->assertTrue($padlo);
        $this->assertSame(
            $this->zadaniaOdczytu(),
            ImportPrzepisu::query()->where('status', ImportPrzepisu::STATUS_OCZEKUJE)->count(),
            'Zlecenie `oczekuje` zostało zatwierdzone bez zadania w kolejce — nikt go nie wykona.',
        );

        // Ponowienie TYM SAMYM kluczem formularza dochodzi do skutku.
        $zlecenie = app(ZlecImportPrzepisu::class)->handle($this->osoba, $this->kartka(), $klucz);

        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $zlecenie->status);
        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertSame(1, $this->zadaniaOdczytu(), 'Ponowienie z tym samym kluczem zwróciło zlecenie bez zadania.');
    }

    /**
     * Zadanie zgubione inną drogą (wyczyszczona tabela `jobs`, zlecenie
     * sprzed poprawki): zlecenie nie wisi w „trwa” do retencji — dostaje
     * jawny błąd z „Spróbuj jeszcze raz”.
     */
    public function test_1977_porzucone_zlecenie_konczy_sie_jawnym_bledem_z_ponowieniem(): void
    {
        $zlecenie = $this->zlecenieBezWysylki();

        $this->artisan('kuking:odzyskaj-importy')->assertSuccessful();
        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $zlecenie->fresh()->status, 'Świeże zlecenie w kolejce nie jest porzucone.');

        $this->travel((int) config('kuking.import.odzyskiwanie.zlecenie_minut') + 1)->minutes();
        $this->artisan('kuking:odzyskaj-importy')->assertSuccessful();

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $zlecenie->kod_bledu);
        $this->assertTrue($zlecenie->moznaPonowic());
    }

    // ------------------------------------------------------------------
    // #1980 — ponowienie nie płaci drugi raz
    // ------------------------------------------------------------------

    public function test_1980_ponowienie_po_zapisanej_odpowiedzi_nie_wola_modelu_drugi_raz(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();

        $padlo = false;
        DB::beforeExecuting(function (string $sql) use (&$padlo): void {
            if (! $padlo && str_starts_with($sql, 'insert into "recipe_ingredients"')) {
                $padlo = true;

                throw new RuntimeException('zapis szkicu padł (awaria wymuszona testem)');
            }
        });

        $this->uruchomZAwaria($zlecenie);
        $this->assertTrue($padlo, 'Sabotaż nie trafił w zapis szkicu.');
        $this->assertNotNull($zlecenie->fresh()->odpowiedz_modelu, 'Odpowiedź modelu musi być zapisana, zanim ruszy szkic.');

        // Kolejka ponawia zadanie.
        $this->uruchom($zlecenie);

        Http::assertSentCount(1);
        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertSame(['zarezerwowano' => 0, 'wydano' => self::KOSZT_Z_USAGE], $this->budzet(), 'Ponowienie naliczyło drugie wywołanie.');
        $this->assertSame(self::KOSZT_Z_USAGE, $zlecenie->koszt_mikrousd);
        $this->assertSame(2, $zlecenie->recipe->ingredients()->count());
    }

    /**
     * Zapis szkicu i znacznik `gotowy` idą w jednej transakcji — inaczej
     * ponowienie po awarii tuż za szkicem wzięłoby własny tekst za tekst
     * człowieka i skończyło „szkic_zmieniony”.
     */
    public function test_1980_awaria_tuz_za_szkicem_nie_konczy_sie_szkic_zmieniony(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();

        $padlo = false;
        DB::beforeExecuting(function (string $sql, array $wiazania) use (&$padlo): void {
            if (! $padlo && str_starts_with($sql, 'update "importy_przepisow"') && in_array(ImportPrzepisu::STATUS_GOTOWY, $wiazania, true)) {
                $padlo = true;

                throw new RuntimeException('znacznik gotowy padł (awaria wymuszona testem)');
            }
        });

        $this->uruchomZAwaria($zlecenie);
        $this->assertTrue($padlo);
        $this->uruchom($zlecenie);

        Http::assertSentCount(1);
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->fresh()->status);
        $this->assertSame(2, $zlecenie->recipe->ingredients()->count());
    }

    /**
     * Zwykły wyjątek (nie awaria modelu) ponawia kolejka do `$tries` razy.
     * Płatnych żądań na zlecenie i tak jest najwyżej `PROBY_MODELU`.
     */
    public function test_1980_sufit_platnych_zadan_obejmuje_kazdy_rodzaj_ponowienia(): void
    {
        $this->modelOdpowiada();
        $zlecenie = $this->zlecenieBezWysylki();
        $zlecenie->forceFill(['proby' => OdczytajPrzepis::PROBY_MODELU])->save();

        $this->uruchom($zlecenie);

        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY, $zlecenie->fresh()->kod_bledu);
        $this->assertSame(['zarezerwowano' => 0, 'wydano' => 0], $this->budzet());
    }

    // ------------------------------------------------------------------

    private function uruchom(ImportPrzepisu $zlecenie): void
    {
        $this->app->call([new OdczytajPrzepis((string) $zlecenie->getKey()), 'handle']);
    }

    private function uruchomZAwaria(ImportPrzepisu $zlecenie): Throwable
    {
        try {
            $this->uruchom($zlecenie);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Zadanie przeszło bez wstrzykniętej awarii — test nie zmierzył tego, co miał.');
    }

    private function modelOdpowiada(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'nieczytelne' => false,
                'tytul' => 'Sernik babci Hani',
                'porcje' => '8',
                'skladniki' => [['tekst' => '1 kg twarogu', 'grupa' => null], ['tekst' => '1 szkl. cukru', 'grupa' => null]],
                'kroki' => [['tekst' => 'Twaróg zmielić dwa razy.']],
                'uwagi' => null,
            ])]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ])]);
    }

    private function kartka(): UploadedFile
    {
        return UploadedFile::fake()->image('kartka.jpg', 1200, 1600);
    }

    private function zlecenieBezWysylki(): ImportPrzepisu
    {
        $media = app(StoreUploadedImage::class)->handle($this->osoba, $this->kartka());
        $szkic = app(PublishRecipe::class)->handle($this->osoba, [
            'title' => 'Przepis z kartki', 'visibility' => 'private', 'source_scan_media_id' => $media->getKey(),
        ], [], [], false);

        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $this->osoba->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_OCZEKUJE,
        ])->save();

        return $zlecenie;
    }

    /** @return array{zarezerwowano: int, wydano: int} */
    private function budzet(): array
    {
        $wiersz = DB::table('ai_budzet_dzienny')->where('dzien', Czas::dzisiajData())->first();

        return [
            'zarezerwowano' => $wiersz === null ? 0 : (int) $wiersz->zarezerwowano_mikrousd,
            'wydano' => $wiersz === null ? 0 : (int) $wiersz->wydano_mikrousd,
        ];
    }

    private function zadaniaOdczytu(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%OdczytajPrzepis%')->count();
    }
}
