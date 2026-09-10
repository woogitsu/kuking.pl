<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Support\Facades\Cache;

/**
 * Ile listów z linkiem do logowania wolno jeszcze wysłać DZISIAJ (issue #25).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE, SKORO SĄ JUŻ LIMITY ZAPYTAŃ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo tamte chronią przed czym innym. `limits.login_link` liczy po adresie IP,
 * `login_link.limit_na_adres` — po adresie e-mail. Oba pilnują POJEDYNCZEGO
 * nadużycia i oba są w porządku wtedy, gdy pięciuset ludzi zachowuje się
 * zupełnie normalnie: każdy prosi o swój jeden list, żaden limit nie zostaje
 * przekroczony, a serwis wysyła pięćset listów z puli trzystu.
 *
 * EmailLabs na planie darmowym daje **300 listów na dobę na CAŁY serwis**
 * (D-047). Z tego samego wiadra idą potwierdzenia rejestracji, przypomnienia
 * hasła, powiadomienia o „Ugotowałem" i decyzje moderacyjne. Właściciel
 * spodziewa się fali migracyjnej z Garnek.pl — setek kont zakładanych w kilka
 * dni, przez ludzi, dla których logowanie linkiem jest drogą PODSTAWOWĄ.
 * Bez tego licznika pierwszą rzeczą, która przestaje działać w takim dniu,
 * jest POTWIERDZENIE REJESTRACJI: nowi ludzie nie wchodzą w ogóle, a przyczyna
 * siedzi kilka warstw dalej i nie ma jak jej zobaczyć.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO DZIEŃ KALENDARZOWY, A NIE OKNO 24 GODZIN LIMITERA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo dostawca liczy dobę, a nie okno przesuwne od pierwszego listu.
 * `RateLimiter` z opóźnieniem 24 h zaczynałby odliczać od pierwszej wysyłki
 * i po tygodniu byłby przesunięty względem licznika, którego naprawdę
 * pilnujemy. Klucz niesie więc datę i wygasa sam.
 *
 * CZEGO TA KLASA NIE WIE: ile listów wysłały INNE części serwisu. Nie ma
 * jednego licznika całej poczty i celowo go tu nie budujemy — to byłby drugi
 * pomiar tej samej rzeczy, obok tego, który prowadzi dostawca. Ten licznik
 * pilnuje wyłącznie WŁASNEGO sufitu tej jednej funkcji, żeby nie zjadła
 * cudzego kawałka wiadra. Prawdziwy stan puli pokazuje panel EmailLabs.
 *
 * CACHE, NIE BAZA: to jest licznik, nie dowód. Jego zgubienie (restart
 * kontenera z pamięciowym sterownikiem cache) kosztuje najwyżej tyle, że
 * budżet zaczyna się liczyć od nowa — czyli awaria wychodzi w stronę
 * „wyślemy więcej listów", nie „zamkniemy komuś drzwi". Przy `CACHE_STORE`
 * ustawionym na `database` (tak chodzi produkcja) licznik przeżywa restart.
 */
final class DziennyBudzetListow
{
    private const PREFIKS = 'poczta:budzet:link-logowania:';

    /**
     * Czy zostało jeszcze miejsce w dzisiejszym budżecie.
     *
     * Budżet ustawiony na zero albo mniej znaczy „ta funkcja nie wysyła dziś
     * nic" i jest poprawną, świadomą konfiguracją (awaryjne odcięcie poczty
     * bez wyłączania całej drogi — ludzie z ważnym linkiem w skrzynce nadal
     * się nim zalogują).
     */
    public function zostalo(): int
    {
        return max(0, $this->budzet() - $this->zuzyte());
    }

    public function jestMiejsce(): bool
    {
        return $this->zostalo() > 0;
    }

    /**
     * Zajmij jedno miejsce w budżecie — wołane DOPIERO wtedy, gdy list
     * naprawdę poszedł.
     *
     * Kolejność ma znaczenie i jest tu odwrotna niż przy zwykłym limicie
     * zapytań: gdyby licznik ruszał przy każdym WYSŁANIU FORMULARZA, byle
     * automat wpisujący nieistniejące adresy wyczerpałby dobowy budżet
     * w kilka minut i zamknął drogę wszystkim prawdziwym ludziom, nie
     * wysławszy ani jednego listu. Przed samym zalewaniem formularza broni
     * `limits.login_link` i `login_link.limit_na_adres`.
     */
    public function zajmij(): void
    {
        $klucz = $this->klucz();

        // `add` zakłada klucz z terminem ważności tylko wtedy, gdy go
        // jeszcze nie ma — bez tego `increment` na nieistniejącym kluczu
        // zakłada wpis BEZ terminu i licznik zostaje na zawsze.
        Cache::add($klucz, 0, now()->addDays(2));
        Cache::increment($klucz);
    }

    public function zuzyte(): int
    {
        return (int) Cache::get($this->klucz(), 0);
    }

    public function budzet(): int
    {
        return (int) config('kuking.login_link.dzienny_budzet', 0);
    }

    private function klucz(): string
    {
        return self::PREFIKS.now()->format('Y-m-d');
    }
}
