<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #778: dwa procesy wykonują prawdziwe akcje zapisu. Blokada SHARE tabeli
 * przepuszcza SELECT, ale zatrzymuje INSERT do collections. Dopiero po
 * potwierdzeniu w pg_stat_activity, że pierwszy czeka na INSERT, a drugi
 * w kolejce za nim (zamek konta z #1022), puszczamy barierę. To wymuszony przeplot, nie dwa przypadkowo równoległe wywołania.
 * Nie mierzymy tutaj wyścigu z ręcznym zakładaniem ani usuwaniem zeszytu.
 */
#[Group('dwa-polaczenia')]
final class PierwszyZapisDoZeszytuTest extends TestDwochPolaczen
{
    /** @return iterable<string, array{string, string, bool, bool, bool}> */
    public static function saves(): iterable
    {
        foreach ([false, true] as $transaction) {
            foreach ([['recipe', 'recipe'], ['post', 'post'], ['recipe', 'post']] as [$first, $second]) {
                yield $first.'-'.$second.($transaction ? '-transakcja' : '') => [$first, $second, $transaction, false, false];
            }
        }

        yield 'ten-sam-przepis' => ['recipe', 'recipe', false, true, false];
        yield 'ten-sam-wpis' => ['post', 'post', false, true, false];
        yield 'publiczne-zapisane' => ['recipe', 'post', true, false, true];
    }

    #[DataProvider('saves')]
    public function test_pierwsze_zapisy_trafiaja_do_jednego_prywatnego_zeszytu(
        string $firstType,
        string $secondType,
        bool $transaction,
        bool $sameContent,
        bool $publicNotebook,
    ): void {
        $owner = $this->konto();
        $author = $this->konto();
        $manual = $publicNotebook ? Collection::query()->create([
            'owner_id' => $owner->getKey(), 'name' => 'Zapisane',
            'visibility' => 'public', 'is_default' => false,
        ]) : null;
        $make = fn (string $type) => ($type === 'recipe' ? Recipe::factory() : Post::factory())
            ->create(['author_id' => $author->getKey(), 'visibility' => 'public']);
        $first = $make($firstType);
        $second = $sameContent ? $first : $make($secondType);
        $this->assertFalse($owner->collections()->where('is_default', true)->exists());

        $barrier = $this->nowePolaczenie();
        $barrier->beginTransaction();
        $barrier->exec('LOCK TABLE collections IN SHARE MODE');
        $arguments = ['kto' => (string) $owner->getKey(), 'transakcja' => $transaction ? '1' : '0'];
        $a = $this->wTle('zapis-do-zeszytu', $arguments + ['typ' => $firstType, 'tresc' => (string) $first->getKey()]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('zapis-do-zeszytu', $arguments + ['typ' => $secondType, 'tresc' => (string) $second->getKey()]);
        $this->czekajNaZablokowane(2);

        // Pierwszy backend stoi konkretnie na INSERT do zeszytów — odczytał
        // brak domyślnego zeszytu. Od #1022 zapis bierze zamek konta
        // (`ZamekZapisuDoZeszytu`), więc drugi zapis tej samej osoby czeka
        // w kolejce ZA pierwszym, zanim w ogóle zapyta o zeszyt. Sprawdzamy
        // to wprost: drugi czeka i blokuje go właśnie pierwszy. Ścieżkę
        // złapanego 23505 w `defaultCollection()` mierzy
        // `DomyslnyZeszytNieMaskujeInnejUnikalnosciTest`.
        $waiting = $this->obserwator->query("SELECT pid FROM pg_stat_activity
            WHERE datname = current_database() AND wait_event_type = 'Lock'
            AND query ILIKE 'insert into \"collections\"%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, array_unique($waiting));
        $queued = $this->obserwator->query("SELECT pg_blocking_pids(pid)::text FROM pg_stat_activity
            WHERE datname = current_database() AND wait_event_type = 'Lock'
            AND pid <> ".(int) $waiting[0])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $queued);
        $this->assertStringContainsString((string) $waiting[0], $queued[0]);
        $this->zwolnijBariere($barrier);

        $results = [$a->wynik(), $b->wynik()];
        foreach ($results as $result) {
            $this->assertBezZakleszczenia($result, 'pierwszy zapis do zeszytu');
            $this->assertTrue($result['ok'], 'Zapis nie doszedł do końca: '.$result['komunikat']);
        }
        $this->assertSame($results[0]['wartosc'], $results[1]['wartosc']);
        $this->assertSame(1, $owner->collections()->where('is_default', true)->count());
        $collection = $owner->collections()->where('is_default', true)->sole();
        $this->assertSame('private', $collection->visibility);
        $this->assertSame($sameContent ? 1 : 2, DB::table('collection_items')->where('collection_id', $collection->getKey())->count());
        foreach ([[$firstType, $first], [$secondType, $second]] as [$type, $content]) {
            $this->assertDatabaseHas('collection_items', [
                'collection_id' => $collection->getKey(), $type.'_id' => $content->getKey(),
            ]);
        }
        if ($sameContent && $firstType === 'recipe') {
            $this->assertSame(1, Notification::query()->where('user_id', $author->getKey())
                ->where('type', Notification::TYPE_SAVED)->count());
        }
        if ($manual !== null) {
            $manual->refresh();
            $this->assertFalse($manual->is_default);
            $this->assertSame('public', $manual->visibility);
            $this->assertSame('Zapisane', $manual->name);
            $this->assertSame('Zapisane 2', $collection->name);
            $this->assertSame(0, DB::table('collection_items')->where('collection_id', $manual->getKey())->count());
        }
    }
}
