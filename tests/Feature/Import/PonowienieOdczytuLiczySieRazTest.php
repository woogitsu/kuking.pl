<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\KlientLuna;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #2064, kontrola dodatnia: pojedyncze „Spróbuj jeszcze raz” działa, a to
 * samo kliknięcie wysłane drugi raz nie zjada drugiej próby z limitu osoby
 * (D-297) ani drugiego płatnego odczytu. Wyścig tych samych żądań na dwóch
 * połączeniach: `Tests\Dwa\PonowienieImportuPrzezHttpNaDwochPolaczeniachTest`.
 *
 * Kolejka jest `sync`, więc odczyt dzieje się w tym samym żądaniu.
 */
final class PonowienieOdczytuLiczySieRazTest extends TestCase
{
    use RefreshDatabase;

    private const ODPOWIEDZ = [
        'nieczytelne' => false,
        'tytul' => 'Sernik babci Hani',
        'porcje' => '8',
        'skladniki' => [['tekst' => '1 kg twarogu', 'grupa' => null]],
        'kroki' => [['tekst' => 'Piec godzinę w 170 stopniach.']],
        'uwagi' => null,
    ];

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
            // Stary odczyt + JEDNO ponowienie + jedna nowa kartka.
            'kuking.import.limity.na_osobe_dzien' => 3,
        ]);

        $this->osoba = $this->user('ponowienie');
        app(PrzestawZgodeNaOdczytAi::class)->handle($this->osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
    }

    public function test_ponowienie_odczytuje_szkic_a_drugie_klikniecie_nie_zjada_limitu_ani_budzetu(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(self::ODPOWIEDZ)]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ])]);
        $poprzednie = $this->nieudanyOdczyt();

        $this->actingAs($this->osoba)->post(route('import.ponow', $poprzednie))->assertRedirect();
        $ponowienie = ImportPrzepisu::query()->whereKeyNot($poprzednie->getKey())->sole();

        // Pojedyncze ponowienie działa: szkic dostał przepis z kartki.
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $ponowienie->status);
        $this->assertSame('Sernik babci Hani', $poprzednie->recipe()->first()?->title);
        Http::assertSentCount(1);

        // To samo kliknięcie jeszcze raz (np. stara karta przeglądarki).
        $this->actingAs($this->osoba)->post(route('import.ponow', $poprzednie))
            ->assertRedirect(route('import.show', $ponowienie));

        $this->assertSame(2, ImportPrzepisu::query()->count());
        Http::assertSentCount(1);

        // Limit policzył ponowienie raz: trzecia próba dnia wciąż przechodzi.
        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => UploadedFile::fake()->image('kartka.jpg', 1200, 1600)]);
        $nowa = ImportPrzepisu::query()->whereNotIn('id', [$poprzednie->getKey(), $ponowienie->getKey()])->sole();
        $this->assertNotSame(ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM, $nowa->status, 'Drugie kliknięcie ponowienia zjadło próbę z dziennego limitu.');
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $nowa->status);
        Http::assertSentCount(2);
    }

    private function nieudanyOdczyt(): ImportPrzepisu
    {
        $media = app(StoreUploadedImage::class)->handle($this->osoba, UploadedFile::fake()->image('kartka.jpg', 1200, 1600));
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $this->osoba->getKey(),
            'visibility' => 'private',
            'source_scan_media_id' => $media->getKey(),
        ]);

        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $this->osoba->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY,
        ])->save();

        return $zlecenie;
    }
}
