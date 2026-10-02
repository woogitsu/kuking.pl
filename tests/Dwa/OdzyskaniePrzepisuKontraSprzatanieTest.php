<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odzyskanie własnego przepisu (#2620) kontra nocne sprzątanie usuniętych
 * treści: dwa połączenia, jedno rozstrzygnięcie.
 *
 * ── CO MIERZYMY ──
 *
 * `PrzedawnioneUsunieteTresci` wczytuje kandydatów BEZ blokady, a dopiero
 * potem w transakcji kasuje przepis. Odzyskanie (`OdzyskajUsunietyPrzepis`)
 * bierze ten sam wiersz `recipes` pod `FOR UPDATE`. Bez ponownego odczytu
 * pod blokadą po stronie sprzątania odzyskany szkic znikałby na stałe tej
 * samej nocy — bez śladu i bez szansy na kolejny ruch autora.
 *
 * Jedyny sposób, w jaki kandydat sprzątania może jeszcze zostać odzyskany,
 * to rozjazd progów (zegary web/worker, `--dni` operatora). Test odtwarza go
 * wprost: przepis usunięty 29,5 dnia temu jest w oknie odzyskania (30 dni),
 * a sprzątanie biegnie z progiem 29 dni.
 *
 * Bariera trzyma wiersz przepisu; uczestnicy ustawiają się w kolejce w znanej
 * kolejności, a kolejność obsługi blokad w PostgreSQL to kolejność zgłoszeń.
 */
#[Group('dwa-polaczenia')]
final class OdzyskaniePrzepisuKontraSprzatanieTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            try {
                DB::table('recipe_ingredients')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać przepisów testu: ".$e->getMessage()."\n");
            }

            $this->przepisy = [];
        }

        parent::tearDown();
    }

    private function usunietyPrzepis(User $autor): Recipe
    {
        $znacznik = bin2hex(random_bytes(5));

        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Sernik wyścigowy '.$znacznik,
            'slug' => 'sernik-wyscigowy-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDays(40),
        ]);

        $this->przepisy[] = (string) $przepis->getKey();

        // Usunięty 29,5 dnia temu: w oknie odzyskania (30), za progiem sprzątania (29).
        $przepis->forceFill(['deleted_at' => now()->subDays(29)->subHours(12)])->save();

        return $przepis;
    }

    private function barieraNaPrzepisie(Recipe $przepis): \PDO
    {
        return $this->bariera('SELECT 1 FROM recipes WHERE id = ? FOR UPDATE', [(string) $przepis->getKey()]);
    }

    public function test_odzyskanie_ktore_wygrywa_chroni_przepis_przed_sprzataniem(): void
    {
        $autor = $this->konto();
        $przepis = $this->usunietyPrzepis($autor);

        $bariera = $this->barieraNaPrzepisie($przepis);

        $odzyskanie = $this->wTle('odzyskaj-przepis-2620', ['konto' => (string) $autor->getKey(), 'przepis' => (string) $przepis->getKey()]);
        $this->czekajNaZablokowane(1);

        $sprzatanie = $this->wTle('sprzataj-usuniete-2620', ['dni' => '29']);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikOdzyskania = $odzyskanie->wynik();
        $wynikSprzatania = $sprzatanie->wynik();

        $this->assertBezZakleszczenia($wynikOdzyskania, 'odzyskanie przepisu obok sprzątania');
        $this->assertBezZakleszczenia($wynikSprzatania, 'sprzątanie obok odzyskania przepisu');

        // Kontrole dodatnie: oba procesy naprawdę zadziałały.
        $this->assertTrue($wynikOdzyskania['ok'], 'Odzyskanie nie przeszło: '.$wynikOdzyskania['wyjatek'].' '.$wynikOdzyskania['komunikat']);
        $this->assertFalse($wynikOdzyskania['wartosc']['juz']);
        $this->assertTrue($wynikSprzatania['ok'], 'Sprzątanie nie przeszło: '.$wynikSprzatania['wyjatek'].' '.$wynikSprzatania['komunikat']);

        // TO JEST CAŁE ZNALEZISKO: odzyskany szkic przeżył sprzątanie.
        $this->assertSame(0, $wynikSprzatania['wartosc']['przepisy'], 'Sprzątanie skasowało przepis, który autor właśnie odzyskał.');
        $this->assertSame(0, $wynikSprzatania['wartosc']['nagrobki']);

        $zapisany = Recipe::query()->whereKey($przepis->getKey())->first();
        $this->assertNotNull($zapisany, 'Odzyskany przepis zniknął z bazy.');
        $this->assertNull($zapisany->deleted_at);
        $this->assertSame(Recipe::STATUS_DRAFT, $zapisany->status);
    }

    public function test_sprzatanie_ktore_wygrywa_odbiera_odzyskanie_zdaniem_po_polsku(): void
    {
        $autor = $this->konto();
        $przepis = $this->usunietyPrzepis($autor);

        $bariera = $this->barieraNaPrzepisie($przepis);

        $sprzatanie = $this->wTle('sprzataj-usuniete-2620', ['dni' => '29']);
        $this->czekajNaZablokowane(1);

        $odzyskanie = $this->wTle('odzyskaj-przepis-2620', ['konto' => (string) $autor->getKey(), 'przepis' => (string) $przepis->getKey()]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikSprzatania = $sprzatanie->wynik();
        $wynikOdzyskania = $odzyskanie->wynik();

        $this->assertBezZakleszczenia($wynikSprzatania, 'sprzątanie obok odzyskania przepisu');
        $this->assertBezZakleszczenia($wynikOdzyskania, 'odzyskanie przepisu obok sprzątania');

        $this->assertTrue($wynikSprzatania['ok'], 'Sprzątanie nie przeszło: '.$wynikSprzatania['wyjatek'].' '.$wynikSprzatania['komunikat']);
        $this->assertSame(1, $wynikSprzatania['wartosc']['przepisy'], 'Sprzątanie nie skasowało kandydata — przeplot był inny niż opisany.');

        // Odzyskanie przegrywa uczciwie: zdanie dla człowieka, nie zakleszczenie ani 500.
        $this->assertFalse($wynikOdzyskania['ok'], 'Odzyskanie przeszło na przepisie skasowanym na stałe.');
        $this->assertStringContainsString('nie da się już odzyskać', (string) $wynikOdzyskania['komunikat']);

        $this->assertSame(0, Recipe::withTrashed()->whereKey($przepis->getKey())->count());
    }

    public function test_dwa_rownolegle_odzyskania_daja_jeden_rezultat(): void
    {
        $autor = $this->konto();
        $przepis = $this->usunietyPrzepis($autor);

        $bariera = $this->barieraNaPrzepisie($przepis);

        $pierwsze = $this->wTle('odzyskaj-przepis-2620', ['konto' => (string) $autor->getKey(), 'przepis' => (string) $przepis->getKey()]);
        $this->czekajNaZablokowane(1);
        $drugie = $this->wTle('odzyskaj-przepis-2620', ['konto' => (string) $autor->getKey(), 'przepis' => (string) $przepis->getKey()]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $a = $pierwsze->wynik();
        $b = $drugie->wynik();

        $this->assertBezZakleszczenia($a, 'pierwsze odzyskanie');
        $this->assertBezZakleszczenia($b, 'drugie odzyskanie');
        $this->assertTrue($a['ok'], 'Pierwsze odzyskanie nie przeszło: '.$a['wyjatek'].' '.$a['komunikat']);
        $this->assertTrue($b['ok'], 'Drugie odzyskanie nie przeszło: '.$b['wyjatek'].' '.$b['komunikat']);

        // Dokładnie jedno z nich coś zrobiło; drugie zastało przepis odzyskany.
        $this->assertFalse($a['wartosc']['juz'], 'Pierwsze w kolejce powinno odzyskać.');
        $this->assertTrue($b['wartosc']['juz'], 'Drugie w kolejce powinno zastać przepis już odzyskany.');

        $this->assertSame(1, Recipe::query()->where('author_id', $autor->getKey())->count());
    }
}
