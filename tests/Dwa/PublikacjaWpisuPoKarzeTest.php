<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Posts\KontoNieMozePublikowac;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Rzeczywiste dwa połączenia: kara i publikacja konkurują o ten sam wiersz autora. */
#[Group('dwa-polaczenia')]
final class PublikacjaWpisuPoKarzeTest extends TestDwochPolaczen
{
    /** @return array<string, array{string}> */
    public static function kary(): array
    {
        return [
            'zawieszenie' => [User::STATUS_SUSPENDED],
            'ban' => [User::STATUS_BANNED],
            'oczekiwanie na usunięcie' => [User::STATUS_PENDING_DELETE],
            'wymazanie' => [User::STATUS_ERASED],
        ];
    }

    #[Test]
    #[DataProvider('kary')]
    public function kara_zatwierdzona_przed_blokada_autora_cofa_cala_publikacje(string $status): void
    {
        $autor = $this->konto();
        $gospodarz = $this->konto();
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $tag = 'Wyscig'.bin2hex(random_bytes(5));
        $klucz = (string) Str::uuid();
        $pivotsPrzed = DB::table('post_tags')->count();
        $jobsPrzed = DB::table('jobs')->count();

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [$autor->getKey()]);
        try {
            // Proces ładuje aktywnego autora PRZED zatwierdzeniem kary, po czym
            // ustawia się w prawdziwej kolejce PostgreSQL na blokadzie konta.
            $proces = $this->wTle('opublikuj-wpis-2088', [
                'konto' => (string) $autor->getKey(), 'gospodarz' => (string) $gospodarz->getKey(),
                'zdjecie' => (string) $zdjecie->getKey(), 'tag' => $tag, 'klucz' => $klucz,
            ]);
            $this->czekajNaZablokowane(1);

            $zmiana = $bariera->prepare('UPDATE users SET status = ?, delete_scope = ?, '
                .'delete_requested_at = ?, data_erased_at = ? WHERE id = ?');
            $usuniete = in_array($status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true);
            $zmiana->execute([
                $status,
                $usuniete ? User::DELETE_SCOPE_MINIMUM : null,
                $usuniete ? now()->toDateTimeString() : null,
                $status === User::STATUS_ERASED ? now()->toDateTimeString() : null,
                $autor->getKey(),
            ]);
            $this->assertSame(1, $zmiana->rowCount());
            $bariera->commit();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $proces->wynik();
        $this->assertFalse($wynik['ok'], 'Publikacja po zatwierdzonej karze nie może się udać.');
        $this->assertSame(KontoNieMozePublikowac::class, $wynik['wyjatek'], $wynik['komunikat']);
        $this->assertNull($wynik['sqlstate'], 'Odmowa powinna być regułą domenową, nie timeoutem bazy.');
        $this->assertSame($status, $autor->fresh()->status);
        $this->assertSame(0, Post::where('author_id', $autor->getKey())->count());
        $this->assertSame(0, DB::table('post_media')->where('media_id', $zdjecie->getKey())->count());
        $this->assertSame($pivotsPrzed, DB::table('post_tags')->count());
        $this->assertSame(0, Tag::where('name', $tag)->count(), 'Nowy tag z odrzuconego wpisu musi się wycofać.');
        $this->assertSame(0, DB::table('audit_log')->where('actor_id', $autor->getKey())->where('action', 'post.published')->count());
        $this->assertSame(0, DB::table('first_post_events')->where('author_id', $autor->getKey())->count());
        $this->assertSame(0, Notification::where('actor_id', $autor->getKey())->where('type', Notification::TYPE_FIRST_POST)->count());
        $this->assertSame($jobsPrzed, DB::table('jobs')->count());
    }

    #[Test]
    public function publikacja_pierwsza_konczy_sie_przed_kara(): void
    {
        $autor = $this->konto();
        $gospodarz = $this->konto();
        $barieraId = random_int(1, 2_000_000_000);
        $bariera = $this->nowePolaczenie();
        $bariera->beginTransaction();
        $zapytanie = $bariera->prepare('SELECT pg_advisory_xact_lock(2088, ?)');
        $zapytanie->execute([$barieraId]);

        try {
            $publikacja = $this->wTle('opublikuj-wpis-2088', [
                'konto' => (string) $autor->getKey(), 'gospodarz' => (string) $gospodarz->getKey(),
                'klucz' => (string) Str::uuid(), 'bariera' => (string) $barieraId,
            ]);
            // Publikacja trzyma już FOR NO KEY UPDATE i czeka na naszą
            // barierę. Dopiero teraz kara wchodzi do kolejki po ten wiersz.
            $this->czekajNaZablokowane(1);
            $kara = $this->wTle('status-konta', [
                'konto' => (string) $autor->getKey(), 'przejscie' => 'zawies',
            ]);
            $this->czekajNaZablokowane(2);
        } finally {
            $bariera->rollBack();
        }

        $wynikPublikacji = $publikacja->wynik();
        $wynikKary = $kara->wynik();
        $this->assertTrue($wynikPublikacji['ok'], $wynikPublikacji['komunikat']);
        $this->assertTrue($wynikKary['ok'], $wynikKary['komunikat']);
        $this->assertSame(User::STATUS_SUSPENDED, $wynikKary['wartosc']);
        $this->assertSame(1, Post::where('author_id', $autor->getKey())->count());
        $this->assertSame(1, DB::table('audit_log')->where('actor_id', $autor->getKey())->where('action', 'post.published')->count());
        $this->assertSame(User::STATUS_SUSPENDED, $autor->fresh()->status);
    }
}
