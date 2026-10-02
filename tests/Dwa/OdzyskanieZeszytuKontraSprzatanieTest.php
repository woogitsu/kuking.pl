<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Odzyskiwanie\UsunZeszyt;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odzyskanie własnego, usuniętego zeszytu (#2567) kontra nocne sprzątanie kopii
 * odzyskania: dwa połączenia, jedno rozstrzygnięcie.
 *
 * ── CO MIERZYMY ──
 *
 * `PrzedawnioneUsunieteZeszyty` wczytuje kandydatów BEZ blokady, a dopiero
 * potem kasuje każdą kopię w transakcji. Odzyskanie (`OdzyskajUsunietyZeszyt`)
 * bierze ten sam wiersz `deleted_collections` pod `FOR UPDATE`. Dwie szkody,
 * które ten test wyłapuje:
 *  - odzyskanie bez blokady odtworzyłoby zeszyt z kopii, którą sprzątanie
 *    właśnie skasowało (dane po terminie wracają do życia);
 *  - sprzątanie bez ponownego odczytu pod blokadą kasowałoby kopię, z której
 *    zeszyt właśnie wrócił, albo wywracało się na pustym wierszu.
 *
 * Jedyny sposób, w jaki kandydat sprzątania może jeszcze zostać odzyskany, to
 * rozjazd progów (zegary web/worker, `--dni` operatora). Test odtwarza go
 * wprost: kopia sprzed 29,5 dnia jest w oknie odzyskania (30 dni), a
 * sprzątanie biegnie z progiem 29 dni.
 *
 * Bariera trzyma wiersz kopii; uczestnicy ustawiają się w kolejce w znanej
 * kolejności, a kolejność obsługi blokad w PostgreSQL to kolejność zgłoszeń.
 */
#[Group('dwa-polaczenia')]
final class OdzyskanieZeszytuKontraSprzatanieTest extends TestDwochPolaczen
{
    /** @return array{0: string, 1: string} identyfikator zeszytu i wiersza kopii */
    private function usunietyZeszyt(User $wlasciciel): array
    {
        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt wyścigowy '.bin2hex(random_bytes(4)),
            'visibility' => 'private',
        ]);

        app(UsunZeszyt::class)->handle($wlasciciel, $zeszyt);

        // Usunięty 29,5 dnia temu: w oknie odzyskania (30), za progiem sprzątania (29).
        DB::table('deleted_collections')->where('collection_id', $zeszyt->getKey())
            ->update(['deleted_at' => now()->subDays(29)->subHours(12)]);

        $kopiaId = (string) DB::table('deleted_collections')->where('collection_id', $zeszyt->getKey())->value('id');
        $this->assertNotSame('', $kopiaId, 'Kontrola dodatnia: kopia odzyskania musi istnieć.');

        return [(string) $zeszyt->getKey(), $kopiaId];
    }

    private function barieraNaKopii(string $kopiaId): \PDO
    {
        return $this->bariera('SELECT 1 FROM deleted_collections WHERE id = ? FOR UPDATE', [$kopiaId]);
    }

    public function test_odzyskanie_ktore_wygrywa_chroni_zeszyt_przed_sprzataniem(): void
    {
        $wlasciciel = $this->konto();
        [$zeszytId, $kopiaId] = $this->usunietyZeszyt($wlasciciel);

        $bariera = $this->barieraNaKopii($kopiaId);

        $odzyskanie = $this->wTle('odzyskaj-zeszyt-2567', ['konto' => (string) $wlasciciel->getKey(), 'zeszyt' => $zeszytId]);
        $this->czekajNaZablokowane(1);

        $sprzatanie = $this->wTle('sprzataj-zeszyty-2567', ['dni' => '29']);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikOdzyskania = $odzyskanie->wynik();
        $wynikSprzatania = $sprzatanie->wynik();

        $this->assertBezZakleszczenia($wynikOdzyskania, 'odzyskanie zeszytu obok sprzątania');
        $this->assertBezZakleszczenia($wynikSprzatania, 'sprzątanie obok odzyskania zeszytu');

        // Kontrole dodatnie: oba procesy naprawdę zadziałały.
        $this->assertTrue($wynikOdzyskania['ok'], 'Odzyskanie nie przeszło: '.$wynikOdzyskania['wyjatek'].' '.$wynikOdzyskania['komunikat']);
        $this->assertFalse($wynikOdzyskania['wartosc']['juz']);
        $this->assertTrue($wynikSprzatania['ok'], 'Sprzątanie nie przeszło: '.$wynikSprzatania['wyjatek'].' '.$wynikSprzatania['komunikat']);

        // TO JEST CAŁE ZNALEZISKO: odzyskany zeszyt przeżył sprzątanie, a kopia nie została skasowana „drugi raz”.
        $this->assertSame(0, $wynikSprzatania['wartosc'], 'Sprzątanie skasowało kopię, z której zeszyt właśnie wrócił.');
        $this->assertSame(1, Collection::query()->whereKey($zeszytId)->count(), 'Odzyskany zeszyt zniknął z bazy.');
        $this->assertSame(0, DB::table('deleted_collections')->where('id', $kopiaId)->count());
    }

    public function test_sprzatanie_ktore_wygrywa_odbiera_odzyskanie_zdaniem_po_polsku(): void
    {
        $wlasciciel = $this->konto();
        [$zeszytId, $kopiaId] = $this->usunietyZeszyt($wlasciciel);

        $bariera = $this->barieraNaKopii($kopiaId);

        $sprzatanie = $this->wTle('sprzataj-zeszyty-2567', ['dni' => '29']);
        $this->czekajNaZablokowane(1);

        $odzyskanie = $this->wTle('odzyskaj-zeszyt-2567', ['konto' => (string) $wlasciciel->getKey(), 'zeszyt' => $zeszytId]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikSprzatania = $sprzatanie->wynik();
        $wynikOdzyskania = $odzyskanie->wynik();

        $this->assertBezZakleszczenia($wynikSprzatania, 'sprzątanie obok odzyskania zeszytu');
        $this->assertBezZakleszczenia($wynikOdzyskania, 'odzyskanie zeszytu obok sprzątania');

        $this->assertTrue($wynikSprzatania['ok'], 'Sprzątanie nie przeszło: '.$wynikSprzatania['wyjatek'].' '.$wynikSprzatania['komunikat']);
        $this->assertSame(1, $wynikSprzatania['wartosc'], 'Sprzątanie nie skasowało kandydata — przeplot był inny niż opisany.');

        // Odzyskanie przegrywa uczciwie: zdanie dla człowieka, nie zakleszczenie ani 500,
        // i NIC nie zostaje odtworzone z wygasłej kopii.
        $this->assertFalse($wynikOdzyskania['ok'], 'Odzyskanie przeszło na kopii skasowanej na stałe.');
        $this->assertStringContainsString('nie da się już odzyskać', (string) $wynikOdzyskania['komunikat']);
        $this->assertSame(0, Collection::query()->whereKey($zeszytId)->count(), 'Zeszyt odtworzony z kopii, którą skasowano po terminie.');
    }

    public function test_dwa_rownolegle_odzyskania_daja_jeden_rezultat(): void
    {
        $wlasciciel = $this->konto();
        [$zeszytId, $kopiaId] = $this->usunietyZeszyt($wlasciciel);

        $bariera = $this->barieraNaKopii($kopiaId);

        $pierwsze = $this->wTle('odzyskaj-zeszyt-2567', ['konto' => (string) $wlasciciel->getKey(), 'zeszyt' => $zeszytId]);
        $this->czekajNaZablokowane(1);
        $drugie = $this->wTle('odzyskaj-zeszyt-2567', ['konto' => (string) $wlasciciel->getKey(), 'zeszyt' => $zeszytId]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $a = $pierwsze->wynik();
        $b = $drugie->wynik();

        $this->assertBezZakleszczenia($a, 'pierwsze odzyskanie');
        $this->assertBezZakleszczenia($b, 'drugie odzyskanie');
        $this->assertTrue($a['ok'], 'Pierwsze odzyskanie nie przeszło: '.$a['wyjatek'].' '.$a['komunikat']);
        $this->assertTrue($b['ok'], 'Drugie odzyskanie nie przeszło: '.$b['wyjatek'].' '.$b['komunikat']);

        // Dokładnie jedno z nich coś zrobiło; drugie zastało zeszyt odzyskany.
        $this->assertFalse($a['wartosc']['juz'], 'Pierwsze w kolejce powinno odzyskać.');
        $this->assertTrue($b['wartosc']['juz'], 'Drugie w kolejce powinno zastać zeszyt już odzyskany.');

        $this->assertSame(1, Collection::query()->where('owner_id', $wlasciciel->getKey())->count());
    }
}
