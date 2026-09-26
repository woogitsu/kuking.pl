<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\Block;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #1245: cudze komentarze pod własną treścią przechodzą w paczce
 * przez tę samą granicę co na ekranie — `Comment::widoczneDla()` na
 * korzeniach I odpowiedziach. Wcześniej relacje `comments()`/`replies()`
 * filtrowały tylko status, więc do ZIP-a trafiał tekst osób wzajemnie
 * zablokowanych oraz kont `banned` i `pending_delete`.
 */
class EksportKomentarzyTaSamaGranicaCoEkranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    public function test_paczka_nie_niesie_komentarzy_ktorych_wlasciciel_nie_widzi_na_ekranie(): void
    {
        [$basia, $tresci] = $this->scena();

        $cala = $this->calaPaczka($this->eksport($basia));

        foreach ($tresci as $nazwa => $tresc) {
            // Kontrola dodatnia: widoczny komentarz i widoczna odpowiedź są
            // w paczce — asercja „nie ma" niżej nie przechodzi więc na pustej.
            if (str_starts_with($nazwa, 'widoczny')) {
                $this->assertStringContainsString($tresc, $cala, "Brak w paczce: {$nazwa}.");
            } else {
                $this->assertStringNotContainsString($tresc, $cala, "Paczka wyniosła: {$nazwa}.");
            }
        }

        // Ekran mówi to samo co paczka — dla przepisu, wpisu i wykonania.
        $this->actingAs($basia);
        foreach ($tresci as $nazwa => $tresc) {
            [, $gdzie] = explode('|', $nazwa);
            $adres = $this->adresy[$gdzie];
            $odpowiedz = $this->get($adres)->assertOk();
            str_starts_with($nazwa, 'widoczny')
                ? $odpowiedz->assertSee($tresc)
                : $odpowiedz->assertDontSee($tresc);
        }
    }

    public function test_po_zdjeciu_blokady_nowa_paczka_znow_niesie_komentarz(): void
    {
        [$basia, $tresci] = $this->scena();

        Block::query()->delete();

        $cala = $this->calaPaczka($this->eksport($basia));

        foreach (['zablokowany_przez_basie|przepis', 'blokujacy_basie|wpis', 'odp_zablokowany_przez_basie|wykonanie'] as $nazwa) {
            $this->assertStringContainsString($tresci[$nazwa], $cala, "Po zdjęciu blokady brak: {$nazwa}.");
        }
    }

    public function test_wlasne_komentarze_zostaja_w_moich_komentarzach_a_ukryte_cudze_nie_wracaja(): void
    {
        [$basia, $tresci] = $this->scena();

        $wlasny = Comment::create([
            'author_id' => $basia->getKey(),
            'post_id' => $this->wpis->getKey(),
            'body' => 'Mój własny komentarz ukryty przez moderację',
            'status' => Comment::STATUS_HIDDEN,
        ]);

        $dane = json_decode($this->zArchiwum($this->eksport($basia), 'dane.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains($wlasny->body, array_column($dane['moje_komentarze'], 'tresc'));
        $this->assertStringNotContainsString($tresci['ukryty|wpis'], json_encode($dane['wpisy'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($wlasny->body, json_encode($dane['wpisy'], JSON_UNESCAPED_UNICODE));
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private Post $wpis;

    /** @var array<string, string> */
    private array $adresy = [];

    /** @return array{0: User, 1: array<string, string>} */
    private function scena(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zwykla = $this->user('zwykla', ['display_name' => 'Zwykła']);
        $zablokowana = $this->user('zablokowana');
        $blokujaca = $this->user('blokujaca');
        $zbanowana = $this->user('zbanowana');
        $zbanowana->forceFill(['status' => User::STATUS_BANNED])->save();
        $odchodzaca = $this->user('odchodzaca');
        $odchodzaca->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        Block::create(['blocker_id' => $basia->getKey(), 'blocked_id' => $zablokowana->getKey(), 'created_at' => now()]);
        Block::create(['blocker_id' => $blokujaca->getKey(), 'blocked_id' => $basia->getKey(), 'created_at' => now()]);

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Pierogi Basi']);
        $this->wpis = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Mój wpis']);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $basia->getKey(),
            'recipe_id' => Recipe::factory()->create()->getKey(),
        ]);

        $this->adresy = ['przepis' => $przepis->url(), 'wpis' => $this->wpis->url(), 'wykonanie' => $wykonanie->url()];

        $tresci = [];
        foreach (['przepis' => ['recipe_id', $przepis], 'wpis' => ['post_id', $this->wpis], 'wykonanie' => ['cooked_event_id', $wykonanie]] as $gdzie => [$kolumna, $obiekt]) {
            $korzen = $this->komentarz($zwykla, $kolumna, $obiekt, null, "widoczny korzen {$gdzie}");
            $tresci["widoczny|{$gdzie}"] = $korzen->body;
            $tresci["widoczny_odp|{$gdzie}"] = $this->komentarz($zwykla, $kolumna, $obiekt, $korzen, "widoczna odpowiedz {$gdzie}")->body;

            foreach (['zablokowany_przez_basie' => $zablokowana, 'blokujacy_basie' => $blokujaca, 'zbanowany' => $zbanowana, 'odchodzacy' => $odchodzaca] as $kto => $autor) {
                $tresci["{$kto}|{$gdzie}"] = $this->komentarz($autor, $kolumna, $obiekt, null, "{$kto} korzen {$gdzie}")->body;
                $tresci["odp_{$kto}|{$gdzie}"] = $this->komentarz($autor, $kolumna, $obiekt, $korzen, "{$kto} odpowiedz {$gdzie}")->body;
            }

            $tresci["ukryty|{$gdzie}"] = $this->komentarz($zwykla, $kolumna, $obiekt, null, "ukryty moderacja {$gdzie}", Comment::STATUS_HIDDEN)->body;
        }

        return [$basia, $tresci];
    }

    private function komentarz(User $autor, string $kolumna, object $obiekt, ?Comment $rodzic, string $tresc, string $status = Comment::STATUS_PUBLISHED): Comment
    {
        return Comment::create([
            'author_id' => $autor->getKey(),
            $kolumna => $obiekt->getKey(),
            'parent_id' => $rodzic?->getKey(),
            'body' => 'Komentarz: '.$tresc,
            'status' => $status,
        ]);
    }

    private function eksport(User $user): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    /** Pełne bajty wszystkich plików tekstowych archiwum (JSON, HTML, TXT). */
    private function calaPaczka(DataExport $export): string
    {
        $zip = $this->otworz($export);
        $out = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = (string) $zip->getNameIndex($i);
            if (preg_match('/\.(json|html|txt|md)$/', $nazwa) === 1) {
                $out .= "\n".$zip->getFromIndex($i);
            }
        }

        $zip->close();

        // JSON koduje polskie litery jako \uXXXX — nasze treści są ASCII,
        // więc porównanie bajtów jest wprost.
        return $out;
    }

    private function zArchiwum(DataExport $export, string $plik): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$plik}.");

        return $tresc;
    }

    private function otworz(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;

        $this->assertTrue(
            $zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true,
            'Nie udało się otworzyć paczki ZIP.',
        );

        return $zip;
    }
}
