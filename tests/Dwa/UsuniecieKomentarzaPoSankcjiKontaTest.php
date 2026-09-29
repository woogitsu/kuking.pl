<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2190: sankcja konta wykonawcy i usunięcie komentarza ustawiają się w tej
 * samej kolejce na wierszu konta. Usunięcie, które czekało na zamek konta,
 * decyduje na świeżym stanie — nie na modelu wczytanym przed sankcją.
 *
 * Wariant o najwyższym wpływie: właściciel wpisu usuwa CUDZY komentarz
 * (placeholder pod odpowiedziami + powiadomienie dla autora).
 */
#[Group('dwa-polaczenia')]
final class UsuniecieKomentarzaPoSankcjiKontaTest extends TestDwochPolaczen
{
    /** @return array<string, array{string, bool, bool}> przejście, czy wykonawca to autor komentarza, czy korzeń ma odpowiedź */
    public static function przeploty(): array
    {
        $wynik = [];
        foreach (['zawies', 'zbanuj', 'usun'] as $przejscie) {
            $wynik[$przejscie.' / właściciel usuwa cudzy / z odpowiedzią'] = [$przejscie, false, true];
            $wynik[$przejscie.' / właściciel usuwa cudzy / bez odpowiedzi'] = [$przejscie, false, false];
            $wynik[$przejscie.' / autor usuwa własny / z odpowiedzią'] = [$przejscie, true, true];
        }

        return $wynik;
    }

    #[Test]
    #[DataProvider('przeploty')]
    public function sankcja_przed_rewalidacja_zatrzymuje_usuniecie(string $przejscie, bool $wykonawcaToAutor, bool $zOdpowiedzia): void
    {
        [$wykonawca, $komentarz, $autor] = $this->rozmowa($wykonawcaToAutor, $zOdpowiedzia);
        $powiadomienPrzed = Notification::query()->count();

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $wykonawca->getKey()]);
        $sankcja = $this->wTle('status-konta', ['konto' => (string) $wykonawca->getKey(), 'przejscie' => $przejscie]);
        $this->czekajNaZablokowane(1);
        $usuniecie = $this->wTle('usun-komentarz', ['kto' => (string) $wykonawca->getKey(), 'komentarz' => (string) $komentarz->getKey()]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikSankcji = $sankcja->wynik();
        $wynikUsuniecia = $usuniecie->wynik();
        $this->assertBezZakleszczenia($wynikSankcji, 'sankcja '.$przejscie);
        $this->assertBezZakleszczenia($wynikUsuniecia, 'usunięcie '.$przejscie);
        $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
        $this->assertFalse($wynikUsuniecia['ok'], 'Usunięcie po zatwierdzonej sankcji nie może się udać.');
        $this->assertSame(AuthorizationException::class, $wynikUsuniecia['wyjatek'], $wynikUsuniecia['komunikat']);

        $this->assertFalse($wykonawca->fresh()->isActive());
        $po = Comment::withTrashed()->findOrFail($komentarz->getKey());
        $this->assertNull($po->deleted_at, 'Komentarz nie może trafić do kosza.');
        $this->assertNull($po->body_removed_at, 'Korzeń nie może dostać placeholdera.');
        $this->assertSame('Treść do zachowania', $po->body);
        $this->assertSame($powiadomienPrzed, Notification::query()->count(), 'Odmowa nie może zostawić powiadomienia.');
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    /** KONTROLA DODATNIA: usunięcie zatwierdzone przed sankcją działa, bez zakleszczenia. */
    #[Test]
    #[DataProvider('wykonawcy')]
    public function usuniecie_przed_sankcja_przechodzi_bez_zakleszczenia(bool $wykonawcaToAutor): void
    {
        [$wykonawca, $komentarz, $autor] = $this->rozmowa($wykonawcaToAutor, true);

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $wykonawca->getKey()]);
        $usuniecie = $this->wTle('usun-komentarz', ['kto' => (string) $wykonawca->getKey(), 'komentarz' => (string) $komentarz->getKey()]);
        $this->czekajNaZablokowane(1);
        $sankcja = $this->wTle('status-konta', ['konto' => (string) $wykonawca->getKey(), 'przejscie' => 'zawies']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikUsuniecia = $usuniecie->wynik();
        $wynikSankcji = $sankcja->wynik();
        $this->assertBezZakleszczenia($wynikUsuniecia, 'usunięcie');
        $this->assertBezZakleszczenia($wynikSankcji, 'sankcja');
        $this->assertTrue($wynikUsuniecia['ok'], $wynikUsuniecia['komunikat']);
        $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
        $this->assertSame('usunieto', $wynikUsuniecia['wartosc']);

        $po = Comment::withTrashed()->findOrFail($komentarz->getKey());
        $this->assertNotNull($po->body_removed_at, 'Korzeń z odpowiedzią dostaje ślad usunięcia.');
        $this->assertSame(Comment::DELETED_PLACEHOLDER, $po->body);
        $this->assertSame(
            $wykonawcaToAutor ? 0 : 1,
            Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_MODERATION)->count(),
        );
        $this->assertFalse($wykonawca->fresh()->isActive());
    }

    /** @return array<string, array{bool}> */
    public static function wykonawcy(): array
    {
        return ['właściciel usuwa cudzy' => [false], 'autor usuwa własny' => [true]];
    }

    /**
     * @return array{User, Comment, User} wykonawca, komentarz, autor komentarza
     */
    private function rozmowa(bool $wykonawcaToAutor, bool $zOdpowiedzia): array
    {
        $wlasciciel = $this->konto();
        $autor = $this->konto();
        // Właściciel wpisu usuwa cudzy komentarz albo autor usuwa własny pod cudzym wpisem.
        $wykonawca = $wykonawcaToAutor ? $autor : $wlasciciel;

        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Treść do zachowania',
        ]);

        if ($zOdpowiedzia) {
            Comment::factory()->create([
                'author_id' => $this->konto()->getKey(),
                'post_id' => $wpis->getKey(),
                'parent_id' => $komentarz->getKey(),
                'body' => 'Odpowiedź pod spodem',
            ]);
        }

        return [$wykonawca, $komentarz, $autor];
    }
}
