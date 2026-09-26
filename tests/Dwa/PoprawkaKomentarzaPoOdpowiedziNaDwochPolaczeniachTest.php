<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * ODPOWIEDŹ I POPRAWKA TEGO SAMEGO KOMENTARZA NA DWÓCH POŁĄCZENIACH (#1337).
 *
 * `CommentPolicy::update()` odmawia po pierwszej odpowiedzi, ale samo pytanie
 * Policy przed zapisem zostawia okno: odpowiedź zatwierdzona między
 * sprawdzeniem a `UPDATE` dawała stan „odpowiedź pod zmienioną treścią".
 * `EditComment` bierze ten sam zamek wiersza korzenia co `LockCommentContext`
 * przy publikacji odpowiedzi i dopiero pod nim pyta Policy ponownie.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wiersz korzenia pod `FOR UPDATE`. Odpowiedź staje w kolejce
 * pierwsza (zamek korzenia w `LockCommentContext`), poprawka druga. Po
 * zwolnieniu bariery odpowiedź zatwierdza, a poprawka — pod zamkiem — widzi
 * ją i odmawia. Bez zamka w `EditComment` Policy przepuszcza poprawkę przed
 * zatwierdzeniem odpowiedzi, a `UPDATE` czeka na wiersz i nadpisuje treść.
 *
 * Kontrola dodatnia: w odwrotnej kolejności (poprawka przed odpowiedzią)
 * obie operacje przechodzą — zamek nie blokuje zwykłej poprawki.
 */
#[Group('dwa-polaczenia')]
final class PoprawkaKomentarzaPoOdpowiedziNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private const PYTANIE = 'Czy dodać sól?';

    private const POPRAWKA = 'Czy pominąć sól?';

    public function test_odpowiedz_przed_poprawka_zamyka_poprawke(): void
    {
        [$autorka, $odpowiadajacy, $wpis, $pytanie] = $this->rozmowa();

        $bariera = $this->bariera('SELECT 1 FROM comments WHERE id = ? FOR UPDATE', [(string) $pytanie->getKey()]);

        $odpowiedz = $this->wTle('odpowiedz', $this->argumentyOdpowiedzi($odpowiadajacy, $wpis, $pytanie));
        $this->czekajNaZablokowane(1);
        $poprawka = $this->wTle('popraw-komentarz', $this->argumentyPoprawki($autorka, $pytanie));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikOdpowiedzi = $odpowiedz->wynik();
        $wynikPoprawki = $poprawka->wynik();
        $this->assertBezZakleszczenia($wynikOdpowiedzi, 'odpowiedź');
        $this->assertBezZakleszczenia($wynikPoprawki, 'poprawka');
        $this->assertTrue($wynikOdpowiedzi['ok'], 'Odpowiedź padła: '.$wynikOdpowiedzi['komunikat']);
        $this->assertTrue($wynikPoprawki['ok'], 'Poprawka padła: '.$wynikPoprawki['komunikat']);

        $this->assertTrue(Comment::query()->whereKey($wynikOdpowiedzi['wartosc'])->where('parent_id', $pytanie->getKey())->exists());
        $this->assertSame('odmowa', $wynikPoprawki['wartosc']);
        $this->assertSame(self::PYTANIE, $pytanie->fresh()->body, 'Odpowiedź stoi pod zmienioną treścią pytania.');
    }

    public function test_poprawka_przed_odpowiedzia_przechodzi(): void
    {
        [$autorka, $odpowiadajacy, $wpis, $pytanie] = $this->rozmowa();

        $bariera = $this->bariera('SELECT 1 FROM comments WHERE id = ? FOR UPDATE', [(string) $pytanie->getKey()]);

        $poprawka = $this->wTle('popraw-komentarz', $this->argumentyPoprawki($autorka, $pytanie));
        $this->czekajNaZablokowane(1);
        $odpowiedz = $this->wTle('odpowiedz', $this->argumentyOdpowiedzi($odpowiadajacy, $wpis, $pytanie));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPoprawki = $poprawka->wynik();
        $wynikOdpowiedzi = $odpowiedz->wynik();
        $this->assertBezZakleszczenia($wynikPoprawki, 'poprawka');
        $this->assertBezZakleszczenia($wynikOdpowiedzi, 'odpowiedź');
        $this->assertTrue($wynikPoprawki['ok'], 'Poprawka padła: '.$wynikPoprawki['komunikat']);
        $this->assertTrue($wynikOdpowiedzi['ok'], 'Odpowiedź padła: '.$wynikOdpowiedzi['komunikat']);

        $this->assertSame('zapisano', $wynikPoprawki['wartosc']);
        $this->assertSame(self::POPRAWKA, $pytanie->fresh()->body);
        $this->assertTrue(Comment::query()->whereKey($wynikOdpowiedzi['wartosc'])->exists());
    }

    /** @return array{User, User, Post, Comment} */
    private function rozmowa(): array
    {
        $wlasciciel = $this->konto();
        $autorka = $this->konto();
        $odpowiadajacy = $this->konto();
        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $pytanie = Comment::factory()->create([
            'author_id' => $autorka->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => self::PYTANIE,
        ]);

        return [$autorka, $odpowiadajacy, $wpis, $pytanie];
    }

    /** @return array<string, string> */
    private function argumentyOdpowiedzi(User $kto, Post $wpis, Comment $rodzic): array
    {
        return ['kto' => (string) $kto->getKey(), 'wpis' => (string) $wpis->getKey(), 'rodzic' => (string) $rodzic->getKey(), 'tresc' => 'Tak.'];
    }

    /** @return array<string, string> */
    private function argumentyPoprawki(User $kto, Comment $komentarz): array
    {
        return ['kto' => (string) $kto->getKey(), 'komentarz' => (string) $komentarz->getKey(), 'tresc' => self::POPRAWKA];
    }
}
