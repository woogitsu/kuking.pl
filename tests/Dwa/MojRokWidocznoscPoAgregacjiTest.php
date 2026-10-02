<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2418: A grupuje widoczne wykonania; B zatwierdza utratę widoczności;
 * A dopiero potem pobiera metadane przepisu i renderuje prawdziwy HTTP.
 * Bez RefreshDatabase: B musi widzieć dane A i zatwierdzić zmianę.
 */
#[Group('dwa-polaczenia')]
final class MojRokWidocznoscPoAgregacjiTest extends TestDwochPolaczen
{
    /** @return array<string, array{string}> */
    public static function powody(): array
    {
        return [
            'prywatny' => ['private'],
            'usunięty' => ['removed'],
            'ukryty moderacyjnie' => ['hidden'],
            'blokada' => ['block'],
            'zamknięty autor' => ['banned'],
        ];
    }

    #[DataProvider('powody')]
    public function test_zmiana_zatwierdzona_po_agregacji_nie_ujawnia_tytulu_ani_slugu(string $powod): void
    {
        [$widz, $autor, $przepis] = $this->dane();
        $drugi = $this->nowePolaczenie();
        $glowny = DB::selectOne('SELECT pg_backend_pid() AS pid');
        $this->assertNotNull($glowny);
        $this->assertNotSame(
            (int) $glowny->pid,
            (int) $this->odczytaj($drugi, 'SELECT pg_backend_pid()'),
            'Zmiana stanu musi przejść przez naprawdę drugie połączenie.',
        );
        $zmieniono = false;

        DB::listen(function (QueryExecuted $query) use (&$zmieniono, $drugi, $powod, $widz, $autor, $przepis): void {
            if ($zmieniono || ! $this->poAgregacji($query)) {
                return;
            }

            $zmieniono = true;
            $this->assertSame(0, DB::transactionLevel(), 'A nie może trzymać starej migawki transakcji.');
            $this->zmienWidocznosc($drugi, $powod, $widz, $autor, $przepis);
            $this->assertFalse($drugi->inTransaction(), 'Zmiana widoczności nie została zatwierdzona przed pobraniem metadanych.');
        });

        $main = $this->strona($widz);

        $this->assertTrue($zmieniono, 'MOJ_ROK_2418_BRAK_BARIERY: nie było zapytania agregującego.');
        $this->assertStringNotContainsString('Sekretny bigos', $main, 'MOJ_ROK_2418_TYTUL_PO_UTRACIE_WIDOCZNOSCI');
        $this->assertStringNotContainsString($przepis->slug, $main, 'MOJ_ROK_2418_SLUG_PO_UTRACIE_WIDOCZNOSCI');
    }

    public function test_bez_zmiany_widocznosci_ten_sam_przepis_pozostaje_na_ekranie(): void
    {
        [$widz, , $przepis] = $this->dane();

        $main = $this->strona($widz);

        $this->assertStringContainsString('Sekretny bigos', $main);
        $this->assertStringContainsString($przepis->slug, $main);
    }

    /** @return array{User, User, Recipe} */
    private function dane(): array
    {
        $widz = $this->konto();
        $autor = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Sekretny bigos']);

        foreach (['2026-03-10 12:00:00', '2026-03-11 12:00:00'] as $kiedy) {
            CookedEvent::factory()->create([
                'user_id' => $widz->getKey(),
                'recipe_id' => $przepis->getKey(),
                'cooked_at' => Carbon::parse($kiedy, 'Europe/Warsaw')->utc(),
            ]);
        }

        return [$widz, $autor, $przepis];
    }

    private function poAgregacji(QueryExecuted $query): bool
    {
        $sql = strtolower($query->sql);

        return str_contains($sql, 'cooked_events')
            && str_contains($sql, 'group by')
            && str_contains($sql, 'recipe_id');
    }

    private function zmienWidocznosc(PDO $drugi, string $powod, User $widz, User $autor, Recipe $przepis): void
    {
        if ($powod === 'block') {
            $sql = 'INSERT INTO blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, now())';
            $drugi->prepare($sql)->execute([(string) $autor->getKey(), (string) $widz->getKey()]);

            return;
        }

        if ($powod === 'banned') {
            $drugi->prepare('UPDATE users SET status = ? WHERE id = ?')
                ->execute([User::STATUS_BANNED, (string) $autor->getKey()]);

            return;
        }

        if ($powod === 'hidden') {
            $drugi->prepare('UPDATE recipes SET status = ? WHERE id = ?')
                ->execute([Recipe::STATUS_HIDDEN, (string) $przepis->getKey()]);

            return;
        }

        if ($powod === 'removed') {
            $drugi->prepare('UPDATE recipes SET deleted_at = now() WHERE id = ?')
                ->execute([(string) $przepis->getKey()]);

            return;
        }

        $drugi->prepare('UPDATE recipes SET visibility = ? WHERE id = ?')
            ->execute(['private', (string) $przepis->getKey()]);
    }

    private function strona(User $widz): string
    {
        $html = (string) $this->actingAs($widz)->get('/moj-rok/2026')->assertOk()->getContent();
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $main = (new DOMXPath($dokument))->query('//main')->item(0);
        $this->assertNotNull($main, 'Brak <main> w odpowiedzi HTTP.');

        return (string) $dokument->saveHTML($main);
    }
}
