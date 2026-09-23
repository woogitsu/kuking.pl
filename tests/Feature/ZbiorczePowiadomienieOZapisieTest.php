<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DECYZJA WŁAŚCICIELA z 20.09.2026 (domyka issue #906, rozszerza go).
 *
 * Dwie części naraz:
 *  1. Jedna osoba zapisująca ten sam przepis w kilku SWOICH zeszytach liczy
 *     się jako JEDEN zapis — autor dostaje jedno powiadomienie (#906).
 *  2. Zapisy od RÓŻNYCH osób zlewają się w jedno powiadomienie zbiorcze.
 *     Pierwsza osoba powiadamia NATYCHMIAST (osobne powiadomienie),
 *     kolejne osoby dokładają się do TEJ SAMEJ, jeszcze nieprzeczytanej
 *     wiadomości — bez mnożenia wierszy w bazie.
 */
class ZbiorczePowiadomienieOZapisieTest extends TestCase
{
    use RefreshDatabase;

    public function test_jedna_osoba_trzy_zeszyty_daje_jedno_powiadomienie(): void
    {
        $autor = $this->user('autor_3_zeszyty');
        $osoba = $this->user('kolekcjonerka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $zeszytA = $osoba->defaultCollection();
        $zeszytB = $osoba->collections()->create(['name' => 'Na obiad', 'visibility' => 'private']);
        $zeszytC = $osoba->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);

        /** @var SaveRecipeToCollection $save */
        $save = app(SaveRecipeToCollection::class);

        $save->handle($osoba, $przepis, $zeszytA);
        $save->handle($osoba, $przepis, $zeszytB);
        $save->handle($osoba, $przepis, $zeszytC);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->count(),
            'Jedna osoba w trzech swoich zeszytach to jeden zapis, nie trzy powiadomienia.',
        );
    }

    public function test_pierwsza_osoba_powiadamia_natychmiast(): void
    {
        $autor = $this->user('autor_natychmiast');
        $pierwsza = $this->user('pierwsza_osoba');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(SaveRecipeToCollection::class)->handle($pierwsza, $przepis);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $this->assertTrue($powiadomienie->isUnread());
        $this->assertSame($pierwsza->getKey(), $powiadomienie->actor_id);
    }

    public function test_trzy_rozne_osoby_dają_jedno_zbiorcze_powiadomienie(): void
    {
        $autor = $this->user('autor_zbiorczy');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $osoby = [
            $this->user('zapisuje_1'),
            $this->user('zapisuje_2'),
            $this->user('zapisuje_3'),
        ];

        $save = app(SaveRecipeToCollection::class);

        foreach ($osoby as $osoba) {
            $save->handle($osoba, $przepis);
        }

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->count(),
            'Trzy różne osoby — jedno zbiorcze powiadomienie, nie trzy osobne.',
        );

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $this->assertSame($osoby[0]->getKey(), $powiadomienie->actor_id);
        $this->assertSame(2, $powiadomienie->data['others_count'] ?? null);
    }

    public function test_odmiana_dla_dokladnie_dwoch_osob(): void
    {
        $autor = $this->user('autor_dwie_osoby');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);

        $save = app(SaveRecipeToCollection::class);
        $save->handle($this->user('duo_1'), $przepis);
        $save->handle($this->user('duo_2'), $przepis);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $tresc = $powiadomienie->tresc();

        $this->assertStringNotContainsString('oraz 1 innych osób', $tresc, 'Błąd odmiany: dla dwóch osób to jest osoba, nie "innych osób".');
        $this->assertStringContainsString('1 inna osoba', $tresc);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function odmianyProvider(): array
    {
        return [
            'dwie osoby razem (1 inna)' => [1, '1 inna osoba'],
            'trzy osoby razem (2 inne)' => [2, '2 inne osoby'],
            'cztery osoby razem (3 inne)' => [3, '3 inne osoby'],
            'pięć osób razem (4 inne)' => [4, '4 inne osoby'],
            'sześć osób razem (5 innych, dopełniacz)' => [5, '5 innych osób'],
            'jedenaście osób razem (10 innych, dopełniacz)' => [10, '10 innych osób'],
        ];
    }

    #[DataProvider('odmianyProvider')]
    public function test_poprawna_polska_odmiana_liczebnika(int $inni, string $oczekiwanyFragment): void
    {
        $autor = $this->user('autor_odmiana_'.$inni);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Bigos']);

        $save = app(SaveRecipeToCollection::class);
        for ($i = 0; $i <= $inni; $i++) {
            $save->handle($this->user('odmiana_'.$inni.'_'.$i), $przepis);
        }

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $this->assertStringContainsString($oczekiwanyFragment, $powiadomienie->tresc());
    }

    public function test_cofniecie_zapisu_przed_odczytaniem_usuwa_z_partii(): void
    {
        $autor = $this->user('autor_cofniecie');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $pierwsza = $this->user('cofajaca_pierwsza');
        $druga = $this->user('cofajaca_druga');

        $save = app(SaveRecipeToCollection::class);
        $save->handle($pierwsza, $przepis);
        $save->handle($druga, $przepis);

        // Pierwsza osoba się rozmyśla i wyjmuje przepis ze swojego zeszytu,
        // ZANIM autor przeczytał powiadomienie.
        $save->remove($pierwsza, $przepis);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $this->assertSame($druga->getKey(), $powiadomienie->actor_id, 'Druga osoba przejmuje miejsce pierwszej po jej wycofaniu się.');
        $this->assertSame(0, $powiadomienie->data['others_count'] ?? null);
    }

    public function test_cofniecie_jedynego_zapisu_przed_odczytaniem_kasuje_powiadomienie(): void
    {
        $autor = $this->user('autor_cofniecie_jedynego');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $osoba = $this->user('cofajaca_jedyna');

        $save = app(SaveRecipeToCollection::class);
        $save->handle($osoba, $przepis);
        $save->remove($osoba, $przepis);

        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->count(),
            'Cofnięcie jedynego zapisu przed przeczytaniem nie zostawia po sobie powiadomienia.',
        );
    }

    public function test_wyjecie_z_jednego_z_dwoch_zeszytow_nie_cofa_powiadomienia(): void
    {
        // Main (#775, D-231) pozwala wyjąć przepis z JEDNEGO zeszytu. Zapis tej
        // osoby trwa wtedy w drugim, więc jej udział w partii zostaje; znika
        // dopiero, gdy przepisu nie ma w żadnym jej zeszycie.
        $autor = $this->user('autor_dwa_zeszyty');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $osoba = $this->user('osoba_dwa_zeszyty');

        $zeszytA = $osoba->defaultCollection();
        $zeszytB = $osoba->collections()->create(['name' => 'Na obiad', 'visibility' => 'private']);

        $save = app(SaveRecipeToCollection::class);
        $save->handle($osoba, $przepis, $zeszytA);
        $save->handle($osoba, $przepis, $zeszytB);

        $this->assertSame(1, $save->remove($osoba, $przepis, $zeszytA));

        $zapytanie = fn () => Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED);

        $this->assertSame(1, $zapytanie()->count(), 'Przepis leży jeszcze w drugim zeszycie — powiadomienie zostaje.');

        $this->assertSame(1, $save->remove($osoba, $przepis, $zeszytB));

        $this->assertSame(0, $zapytanie()->count(), 'Po wyjęciu z ostatniego zeszytu zapis jest wycofany przed przeczytaniem.');
    }

    public function test_po_przeczytaniu_kolejny_zapis_zaczyna_nowa_partie(): void
    {
        $autor = $this->user('autor_nowa_partia');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $save = app(SaveRecipeToCollection::class);
        $save->handle($this->user('partia_1'), $przepis);

        $pierwsze = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();
        $pierwsze->forceFill(['read_at' => now()])->save();

        $save->handle($this->user('partia_2'), $przepis);

        $this->assertSame(
            2,
            Notification::query()
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->count(),
            'Po przeczytaniu poprzedniej partii kolejny zapis zaczyna nową, osobną wiadomość.',
        );
    }

    public function test_zapis_wlasnego_przepisu_nie_liczy_sie_do_partii(): void
    {
        $autor = $this->user('autor_wlasny');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $ktos = $this->user('obcy_wlasny');

        $save = app(SaveRecipeToCollection::class);
        $save->handle($autor, $przepis);
        $save->handle($ktos, $przepis);

        $powiadomienie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();

        $this->assertSame($ktos->getKey(), $powiadomienie->actor_id);
    }
}
