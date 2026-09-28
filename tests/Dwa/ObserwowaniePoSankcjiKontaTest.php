<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2091: świeży status właściciela relacji po kolejce na blokadzie konta. */
#[Group('dwa-polaczenia')]
final class ObserwowaniePoSankcjiKontaTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tagi = [];

    protected function tearDown(): void
    {
        if ($this->tagi !== []) {
            $sprzataczka = $this->nowePolaczenie();
            $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[])')
                ->execute(['{'.implode(',', $this->tagi).'}']);
        }

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function operacjePoSankcji(): array
    {
        $przypadki = [];
        foreach ([User::STATUS_SUSPENDED, User::STATUS_BANNED, User::STATUS_PENDING_DELETE, User::STATUS_ERASED] as $status) {
            foreach (['obserwuj', 'obserwuj-tag', 'zapisz-obserwowane-tagi'] as $scenariusz) {
                $przypadki[$status.'_'.$scenariusz] = [$status, $scenariusz];
            }
        }

        return $przypadki;
    }

    #[DataProvider('operacjePoSankcji')]
    public function test_sankcja_pierwsza_odcina_obserwowanie_osoby_i_tagu(string $status, string $scenariusz): void
    {
        [$follower, $target, $tag] = $this->dane();
        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [(string) $follower->getKey()]);

        try {
            $proces = $this->wTle($scenariusz, $this->argumenty($scenariusz, $follower, $target, $tag));
            $this->czekajNaZablokowane(1);

            $zmiana = $bariera->prepare('UPDATE users SET status = ?, delete_scope = ?, '
                .'delete_requested_at = ?, data_erased_at = ? WHERE id = ?');
            $usuniete = in_array($status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true);
            $zmiana->execute([
                $status,
                $usuniete ? User::DELETE_SCOPE_MINIMUM : null,
                $usuniete ? now()->toDateTimeString() : null,
                $status === User::STATUS_ERASED ? now()->toDateTimeString() : null,
                $follower->getKey(),
            ]);
            $this->assertSame(1, $zmiana->rowCount());
            $bariera->commit();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $proces->wynik();
        $this->assertBezZakleszczenia($wynik, $scenariusz.' po '.$status);
        $this->assertFalse($wynik['ok'], $scenariusz.' przeszedł po '.$status);
        $this->assertSame(BladDlaCzlowieka::class, $wynik['wyjatek'], $wynik['komunikat']);
        $this->assertSame(0, DB::table('follows')->where('follower_id', $follower->getKey())->count());
        $this->assertSame(0, DB::table('tag_follows')->where('user_id', $follower->getKey())->count());
        $this->assertSame(0, Notification::where('actor_id', $follower->getKey())
            ->where('type', Notification::TYPE_FOLLOW)->count());
    }

    /** @return array<string, array{string}> */
    public static function operacje(): array
    {
        return [
            'osoba' => ['obserwuj'],
            'tag' => ['obserwuj-tag'],
            'formularz tagow' => ['zapisz-obserwowane-tagi'],
        ];
    }

    #[DataProvider('operacje')]
    public function test_obserwowanie_pierwsze_konczy_sie_przed_sankcja(string $scenariusz): void
    {
        [$follower, $target, $tag] = $this->dane();
        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [(string) $follower->getKey()]);
        $obserwuj = $this->wTle($scenariusz, $this->argumenty($scenariusz, $follower, $target, $tag));
        $this->czekajNaZablokowane(1);
        $sankcja = $this->wTle('status-konta', [
            'konto' => (string) $follower->getKey(), 'przejscie' => 'zawies',
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikObserwowania = $obserwuj->wynik();
        $wynikSankcji = $sankcja->wynik();
        $this->assertBezZakleszczenia($wynikObserwowania, $scenariusz);
        $this->assertBezZakleszczenia($wynikSankcji, 'sankcja');
        $this->assertTrue($wynikObserwowania['ok'], $wynikObserwowania['komunikat']);
        $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
        $this->assertSame(User::STATUS_SUSPENDED, $follower->fresh()->status);
        $this->assertSame($scenariusz === 'obserwuj' ? 1 : 0,
            DB::table('follows')->where('follower_id', $follower->getKey())->count());
        $this->assertSame($scenariusz === 'obserwuj' ? 0 : 1,
            DB::table('tag_follows')->where('user_id', $follower->getKey())->count());
    }

    /** @return array{User, User, Tag} */
    private function dane(): array
    {
        [$follower, $target] = $this->paraPosortowana();
        $tag = Tag::factory()->create();
        $this->tagi[] = (string) $tag->getKey();

        return [$follower, $target, $tag];
    }

    /** @return array<string, string> */
    private function argumenty(string $scenariusz, User $follower, User $target, Tag $tag): array
    {
        return $scenariusz === 'obserwuj'
            ? ['kto' => (string) $follower->getKey(), 'kogo' => (string) $target->getKey()]
            : ['kto' => (string) $follower->getKey(), 'tag' => (string) $tag->getKey()];
    }
}
