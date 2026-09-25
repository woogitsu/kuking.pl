<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Jobs\GenerateUserExport;
use App\Models\Block;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Jedna granica cudzych danych w paczce RODO (audyt B2-05, B2-06, B5 pkt 12,
 * issue #1245).
 *
 * Scena z audytu: Basia komentowała wpis Zenka i ugotowała jego przepis.
 * Zenek przełączył jedno i drugie na „Tylko ja”, a potem poprosił o usunięcie
 * konta. Paczka Basi niosła `pod_czym: "wpis: <pierwsze 60 znaków Zenka>"`,
 * jego nazwę, tytuł prywatnego przepisu i Zenka na liście obserwowanych.
 * Każda z tych rzeczy ma teraz tę samą granicę co ekran — i każda ma obok
 * kontrolę dodatnią: widoczna treść dalej jest w paczce.
 */
class EksportJednaGranicaCudzychDanychTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);
    }

    public function test_listy_osob_pomijaja_konta_zamkniete_i_blokady_i_mowia_ile(): void
    {
        $basia = $this->user('basia');
        $dora = $this->user('dora', ['display_name' => 'Dora Widoczna']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Odchodzacy']);
        $zenek->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $adam = $this->user('adam', ['display_name' => 'Adam Zbanowany']);
        $adam->forceFill(['status' => User::STATUS_BANNED])->save();
        // Blokady tu nie ma: trigger `follows_blokada_ma_pierwszenstwo` nie
        // dopuszcza obserwowania przy blokadzie, więc taki wiersz nie istnieje.

        foreach ([$dora, $zenek, $adam] as $osoba) {
            DB::table('follows')->insert(['follower_id' => $basia->getKey(), 'followed_id' => $osoba->getKey(), 'created_at' => now()]);
            DB::table('follows')->insert(['follower_id' => $osoba->getKey(), 'followed_id' => $basia->getKey(), 'created_at' => now()]);
        }

        $dane = $this->dane($basia);

        foreach (['obserwuje', 'obserwuja_mnie'] as $lista) {
            $this->assertSame(['dora'], array_column($dane[$lista], 'nazwa_uzytkownika'), $lista);
            $this->assertSame(2, $dane[$lista.'_niewidocznych'], $lista);
        }

        $calosc = json_encode($dane, JSON_UNESCAPED_UNICODE);
        foreach (['Zenek Odchodzacy', 'Adam Zbanowany'] as $nazwa) {
            $this->assertStringNotContainsString($nazwa, (string) $calosc);
        }
    }

    public function test_moj_komentarz_i_ugotowalem_nie_wynosza_cudzej_niewidocznej_tresci(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Autor']);

        $wpis = Post::factory()->create(['author_id' => $zenek->getKey(), 'body' => 'Sekretny wpis Zenka o rodzinnej kolacji']);
        $przepis = Recipe::factory()->for($zenek, 'author')->create(['title' => 'Tajny bigos Zenka']);
        Comment::create(['author_id' => $basia->getKey(), 'post_id' => $wpis->getKey(), 'body' => 'Komentarz Basi pod wpisem', 'status' => Comment::STATUS_PUBLISHED]);
        CookedEvent::factory()->create(['user_id' => $basia->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Moja notatka o bigosie']);

        // Kontrola dodatnia: dopóki treść jest publiczna, paczka ją nazywa.
        $przed = $this->dane($basia);
        $this->assertSame('wpis: Sekretny wpis Zenka o rodzinnej kolacji', $przed['moje_komentarze'][0]['pod_czym']);
        $this->assertSame('Zenek Autor', $przed['moje_komentarze'][0]['czyje_to_bylo']);
        $this->assertSame('Tajny bigos Zenka', $przed['ugotowalem'][0]['przepis']);
        $this->assertSame('Zenek Autor', $przed['ugotowalem'][0]['autor_przepisu']);

        $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();
        $przepis->forceFill(['visibility' => 'private'])->save();
        DataExport::query()->delete();

        $po = $this->dane($basia);

        $this->assertSame('wpis: '.CollectUserExportData::TRESC_NIEDOSTEPNA, $po['moje_komentarze'][0]['pod_czym']);
        $this->assertNull($po['moje_komentarze'][0]['czyje_to_bylo']);
        $this->assertSame('Komentarz Basi pod wpisem', $po['moje_komentarze'][0]['tresc']);
        $this->assertSame(CollectUserExportData::TRESC_NIEDOSTEPNA, $po['ugotowalem'][0]['przepis']);
        $this->assertNull($po['ugotowalem'][0]['autor_przepisu']);
        $this->assertSame('Moja notatka o bigosie', $po['ugotowalem'][0]['notatka']);

        $calosc = (string) json_encode($po, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Sekretny wpis', $calosc);
        $this->assertStringNotContainsString('Tajny bigos', $calosc);
        $this->assertStringNotContainsString('Zenek Autor', $calosc);
    }

    public function test_cudze_komentarze_pod_moja_trescia_przechodza_przez_widoczne_dla(): void
    {
        $basia = $this->user('basia');
        $dora = $this->user('dora');
        $blokujaca = $this->user('blokujaca');
        Block::create(['blocker_id' => $blokujaca->getKey(), 'blocked_id' => $basia->getKey(), 'created_at' => now()]);

        $wpis = Post::factory()->create(['author_id' => $basia->getKey()]);
        $korzen = Comment::create(['author_id' => $dora->getKey(), 'post_id' => $wpis->getKey(), 'body' => 'Widoczny komentarz Dory', 'status' => Comment::STATUS_PUBLISHED]);
        Comment::create(['author_id' => $blokujaca->getKey(), 'post_id' => $wpis->getKey(), 'body' => 'Komentarz osoby blokujacej', 'status' => Comment::STATUS_PUBLISHED]);
        Comment::create(['author_id' => $blokujaca->getKey(), 'post_id' => $wpis->getKey(), 'parent_id' => $korzen->getKey(), 'body' => 'Odpowiedz osoby blokujacej', 'status' => Comment::STATUS_PUBLISHED]);

        $calosc = (string) json_encode($this->dane($basia)['wpisy'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Widoczny komentarz Dory', $calosc);
        $this->assertStringNotContainsString('osoby blokujacej', $calosc);
    }

    /** @return array<string, mixed> */
    private function dane(User $user): array
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        (new GenerateUserExport((string) $export->getKey()))->handle();
        $export->refresh();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true);
        $json = $zip->getFromName('dane.json');
        $zip->close();

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
