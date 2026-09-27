<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2090: sankcja konta i poprawka komentarza muszą ustawiać się w tej samej
 * kolejce na wierszu konta, zanim poprawka zablokuje komentarz.
 */
#[Group('dwa-polaczenia')]
final class PoprawkaKomentarzaPoSankcjiKontaTest extends TestDwochPolaczen
{
    public function test_sankcja_przed_poprawka_odmawia_zapisu(): void
    {
        foreach (['zawies', 'zbanuj', 'usun'] as $przejscie) {
            [$autorka, $komentarz] = $this->rozmowa();

            $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $autorka->getKey()]);
            $sankcja = $this->wTle('status-konta', ['konto' => (string) $autorka->getKey(), 'przejscie' => $przejscie]);
            $this->czekajNaZablokowane(1);
            $poprawka = $this->wTle('popraw-komentarz', $this->argumentyPoprawki($autorka, $komentarz));
            $this->czekajNaZablokowane(2);
            $this->zwolnijBariere($bariera);

            $wynikSankcji = $sankcja->wynik();
            $wynikPoprawki = $poprawka->wynik();
            $this->assertBezZakleszczenia($wynikSankcji, 'sankcja '.$przejscie);
            $this->assertBezZakleszczenia($wynikPoprawki, 'poprawka '.$przejscie);
            $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
            $this->assertTrue($wynikPoprawki['ok'], $wynikPoprawki['komunikat']);
            $this->assertSame('odmowa', $wynikPoprawki['wartosc']);
            $this->assertSame('Przed poprawką', $komentarz->fresh()->body);
            $this->assertFalse($autorka->fresh()->isActive());
        }
    }

    public function test_poprawka_przed_sankcja_przechodzi_bez_zakleszczenia(): void
    {
        [$autorka, $komentarz] = $this->rozmowa();

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $autorka->getKey()]);
        $poprawka = $this->wTle('popraw-komentarz', $this->argumentyPoprawki($autorka, $komentarz));
        $this->czekajNaZablokowane(1);
        $sankcja = $this->wTle('status-konta', ['konto' => (string) $autorka->getKey(), 'przejscie' => 'zawies']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikPoprawki = $poprawka->wynik();
        $wynikSankcji = $sankcja->wynik();
        $this->assertBezZakleszczenia($wynikPoprawki, 'poprawka');
        $this->assertBezZakleszczenia($wynikSankcji, 'sankcja');
        $this->assertTrue($wynikPoprawki['ok'], $wynikPoprawki['komunikat']);
        $this->assertTrue($wynikSankcji['ok'], $wynikSankcji['komunikat']);
        $this->assertSame('zapisano', $wynikPoprawki['wartosc']);
        $this->assertSame('Po poprawce', $komentarz->fresh()->body);
        $this->assertFalse($autorka->fresh()->isActive());
    }

    /** @return array{User, Comment} */
    private function rozmowa(): array
    {
        $wlasciciel = $this->konto();
        $autorka = $this->konto();
        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $komentarz = Comment::factory()->create([
            'author_id' => $autorka->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Przed poprawką',
        ]);

        return [$autorka, $komentarz];
    }

    /** @return array<string, string> */
    private function argumentyPoprawki(User $autorka, Comment $komentarz): array
    {
        return ['kto' => (string) $autorka->getKey(), 'komentarz' => (string) $komentarz->getKey(), 'tresc' => 'Po poprawce'];
    }
}
