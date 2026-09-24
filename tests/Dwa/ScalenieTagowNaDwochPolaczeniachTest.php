<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #996 NA DWÓCH POŁĄCZENIACH: dwa równoległe scalenia nie zbudują łańcucha
 * `A → B → C` z pominięciem wyzwalacza `tags_scalenie_jednym_skokiem_trg`.
 *
 * Przy `READ COMMITTED` sam odczyt w wyzwalaczu nie widzi niezatwierdzonej
 * zmiany drugiej transakcji — bez `FOR SHARE` na celu obie połowy łańcucha
 * weszłyby naraz. Test ustawia oba przeploty:
 *
 *   najpierw źródło (A → B), równolegle cel (B → C)  →  B → C czeka i odmawia
 *   najpierw cel (B → C), równolegle źródło (A → B)  →  A → B czeka i odmawia
 *
 * a w każdym także kontrolę dodatnią: gdy pierwsza transakcja się wycofa,
 * druga — po odczekaniu — przechodzi. Bez niej „odmowa” mogłaby znaczyć
 * cokolwiek (literówka w kolumnie, brak wiersza, zła baza).
 *
 * Oba zapisy idą SUROWYM `UPDATE` (scenariusz `scal-tag-surowo`), bo
 * mierzymy barierę bazy; `MergeTags` serializuje się własną blokadą.
 *
 * ── KONTROLA UJEMNA (wykonana) ──
 *
 * Usunięcie `FOR SHARE` z funkcji wyzwalacza w bazie wyścigów (24.09.2026)
 * oblewa WSZYSTKIE cztery przeploty komunikatem „Po 15 s w kolejce po
 * blokadę stoi 0 uczestników”: drugi `UPDATE` nie staje w kolejce, bo klucz
 * obcy bierze tylko `FOR KEY SHARE`, a ten nie koliduje ze zmianą statusu —
 * czyli obie połowy łańcucha przeszłyby równolegle. Po przywróceniu funkcji
 * 4/4 zielone.
 */
#[Group('dwa-polaczenia')]
final class ScalenieTagowNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tagi = [];

    private ?PDO $zapisujacy = null;

    protected function tearDown(): void
    {
        if ($this->tagi !== []) {
            $sprzataczka = $this->nowePolaczenie();
            $ids = '{'.implode(',', $this->tagi).'}';
            // Najpierw scalone (źródła), potem cele — klucz obcy bez
            // `ON DELETE` odmówiłby skasowania celu z przypiętym źródłem.
            $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[]) AND merged_into_tag_id IS NOT NULL')->execute([$ids]);
            $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[])')->execute([$ids]);
            $this->tagi = [];
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function przeploty(): iterable
    {
        foreach ([true => 'najpierw-zrodlo', false => 'najpierw-cel'] as $najpierwZrodlo => $nazwa) {
            yield "$nazwa/zatwierdzone" => [(bool) $najpierwZrodlo, true];
            yield "$nazwa/wycofane" => [(bool) $najpierwZrodlo, false];
        }
    }

    #[DataProvider('przeploty')]
    public function test_rownolegle_scalenia_nie_buduja_lancucha(bool $najpierwZrodlo, bool $zatwierdz): void
    {
        $a = $this->nowyTag();
        $b = $this->nowyTag();
        $c = $this->nowyTag();

        $pierwsza = $this->nowePolaczenie();
        $pierwsza->beginTransaction();

        [$pierwszeZrodlo, $pierwszyCel, $drugieZrodlo, $drugiCel] = $najpierwZrodlo
            ? [$a, $b, $b, $c]
            : [$b, $c, $a, $b];

        $this->scal($pierwsza, $pierwszeZrodlo, $pierwszyCel);

        $druga = $this->wTle('scal-tag-surowo', ['zrodlo' => $drugieZrodlo, 'cel' => $drugiCel]);
        $this->czekajNaZablokowane(1);

        $zatwierdz ? $pierwsza->commit() : $pierwsza->rollBack();

        $wynik = $druga->wynik();
        $this->assertBezZakleszczenia($wynik, 'drugie scalenie');

        if (! $zatwierdz) {
            $this->assertTrue($wynik['ok'], 'Po wycofaniu pierwszej transakcji poprawne scalenie nie przeszło: '.$wynik['komunikat']);
            $this->assertSame(1, $wynik['wartosc'], 'Drugie scalenie nie trafiło w wiersz.');
        } else {
            $this->assertFalse($wynik['ok'], 'Równoległe scalenia zbudowały łańcuch A → B → C.');
            $this->assertStringContainsString(
                $najpierwZrodlo ? 'jest celem innych scalen' : 'mozna scalic tylko w aktywny tag',
                $wynik['komunikat'],
            );
        }

        $lancuchy = $this->obserwator->prepare(
            'SELECT count(*) FROM tags AS z JOIN tags AS c ON c.id = z.merged_into_tag_id '
            ."WHERE z.id = ANY(?::uuid[]) AND c.status <> 'active'",
        );
        $lancuchy->execute(['{'.implode(',', $this->tagi).'}']);
        $this->assertSame(0, (int) $lancuchy->fetchColumn(), 'W bazie został łańcuch scaleń.');
    }

    private function nowyTag(): string
    {
        $id = (string) Str::uuid();
        $nazwa = 'w996 '.bin2hex(random_bytes(5));

        // Autocommit na osobnym połączeniu — obserwator nigdy nie pisze.
        $this->zapisujacy ??= $this->nowePolaczenie();
        $this->zapisujacy->prepare(
            'INSERT INTO tags (id, name, normalized_name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())',
        )->execute([$id, $nazwa, $nazwa, str_replace(' ', '-', $nazwa)]);

        $this->tagi[] = $id;

        return $id;
    }

    private function scal(PDO $polaczenie, string $zrodlo, string $cel): void
    {
        $zapytanie = $polaczenie->prepare("UPDATE tags SET status = 'merged', merged_into_tag_id = ? WHERE id = ?");
        $zapytanie->execute([$cel, $zrodlo]);
        $this->assertSame(1, $zapytanie->rowCount(), 'Pierwsze scalenie nie trafiło w wiersz.');
    }
}
