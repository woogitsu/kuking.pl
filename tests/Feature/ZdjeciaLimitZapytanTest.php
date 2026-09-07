<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audyt W7-02, punkt 8: trasa `media.show` MA limit zapytań i limit ten
 * MUSI mieścić normalne przeglądanie galerii (`config/kuking.php`
 * uzasadnia „600,1" właśnie tym — jedna strona feedu to grubo ponad sto
 * żądań o zdjęcia naraz).
 *
 * DLACZEGO ADRES ZDJĘCIA NIEISTNIEJĄCEGO
 * Middleware `throttle` liczy zapytanie PRZED wejściem do kontrolera —
 * odmowa 404 na nieistniejącym UUID i tak przechodzi przez licznik, a jest
 * dużo tańsza niż prawdziwe zdjęcie z prawdziwym rodzicem (bez zapytań
 * o Policy). Do zmierzenia SAMEGO limitu nie potrzeba prawdziwego pliku.
 *
 * ZNANY, OSOBNO ZGŁOSZONY PROBLEM (poza zakresem plików tego zadania):
 * ten test mierzy limit trasy `media.show` W IZOLACJI, jednym ciągiem
 * zapytań o TĘ SAMĄ trasę. Nie dowodzi on, że 600 zapytań o zdjęcia zostaje
 * w budżecie NIENARUSZONYM przez inne trasy — bo nie zostaje: `throttle:N,M`
 * bez trzeciego parametru (prefiksu) w `routes/web.php` dzieli jeden
 * licznik na adres IP z KAŻDĄ inną taką trasą (`ThrottleRequests::
 * resolveRequestSignature()` liczy `domain|ip`, bez nazwy trasy). Zmierzone
 * osobno i opisane w raporcie audytu — naprawa siedzi w `routes/web.php`,
 * pliku spoza zakresu tego zadania.
 */
class ZdjeciaLimitZapytanTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_z_configu_dziala_i_miesci_przegladanie_galerii(): void
    {
        $limit = (int) explode(',', (string) config('kuking.limits.zdjecie'))[0];

        $this->assertGreaterThanOrEqual(
            200,
            $limit,
            'Limit trasy zdjęcia spadł poniżej sensownego budżetu jednej strony feedu — '.
            'to jest zmiana w config/kuking.php, nie w kodzie tego testu, i wymaga '.
            'świadomej decyzji, nie przypadku.',
        );

        $adres = fn (): string => route('media.show', ['media' => Str::uuid()->toString(), 'wariant' => 'feed']);

        // Symulacja jednej strony feedu z zapasem: mniej niż połowa
        // deklarowanego limitu, a i tak grubo więcej niż realna liczba
        // zdjęć na jednym ekranie. Żadne z tych zapytań nie może dostać 429 —
        // inaczej zwykłe przewijanie wyglądałoby jak awaria serwisu.
        for ($i = 0; $i < 150; $i++) {
            $status = $this->get($adres())->getStatusCode();

            $this->assertNotSame(
                429,
                $status,
                "Zapytanie numer {$i} o zdjęcie (ze 150, czyli ćwierć budżetu {$limit}/min) ".
                'dostało 429 — normalne przeglądanie galerii zostałoby odbite.',
            );
        }

        // Reszta budżetu — do granicy włącznie. Zapytanie numer `limit` (czyli
        // ($limit + 1)-sze policzone od 1, tu: `$limit - 150`-te w tej pętli
        // licząc od zera) MUSI jeszcze przejść: limit to liczba dozwolonych
        // prób, nie próg, po którym zaczyna działać.
        for ($i = 150; $i < $limit; $i++) {
            $status = $this->get($adres())->getStatusCode();

            $this->assertNotSame(
                429,
                $status,
                "Zapytanie numer {$i} (w granicach zadeklarowanego limitu {$limit}/min) dostało 429.",
            );
        }

        // I dopiero TERAZ, PO wyczerpaniu całego zadeklarowanego budżetu,
        // limit ma się odezwać — kontrola, że w ogóle działa i że powyższa
        // pętla nie przeszła tylko dlatego, że limitu nie ma wcale.
        $odbicie = $this->get($adres());

        $this->assertSame(
            429,
            $odbicie->getStatusCode(),
            'Po wyczerpaniu całego limitu z config/kuking.php trasa zdjęcia powinna '.
            'odpowiedzieć 429 — jeśli nie odpowiada, limit nie działa wcale, '.
            'a trasa jest bez żadnej ochrony przed zalaniem.',
        );
    }
}
