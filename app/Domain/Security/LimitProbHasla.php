<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use App\Support\KluczeLimitow;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Trzy koszyki prób hasła — dla WSZYSTKICH formularzy, które sprawdzają
 * hasło przed zalogowaniem, nie tylko dla `/login`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE JAKO OSOBNA KLASA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo koszyki żyły wewnątrz `LoginController`, a wyrocznia hasła — nie.
 * W serwisie są TRZY publiczne formularze, które robią `Hash::check()` albo
 * `Auth::validate()` na dowolnym koncie znalezionym przez
 * `User::findByLogin()`:
 *
 *   1. `/login`                    — LoginController
 *   2. `/odwolanie`                — AppealController::guestStore (#10)
 *   3. `/cofnij-usuniecie-konta`   — AccountDeletionController::cancel (A8)
 *
 * Do tej pory tylko PIERWSZY miał licznik po koncie. Dwa pozostałe miały
 * wyłącznie `throttle:5,60` liczony po adresie IP — czyli dokładnie ten
 * kształt ochrony, który `config/kuking.php` (`login_limits`) nazywa wprost
 * niewystarczającym: „licznik przywiązany do ADRESU strukturalnie nie widzi
 * ataku rozproszonego po wielu adresach na jedno konto".
 *
 * ZMIERZONE NA `61686213`, zanim ta klasa powstała: 60 prób hasła do JEDNEGO
 * konta z 60 różnych adresów, na każdym z tych dwóch formularzy — ZERO
 * odmów. Ten sam pomiar na `/login` blokuje przy piętnastej próbie. Napastnik
 * nie musiał więc łamać `/login`: miał obok dwie trasy bez licznika konta,
 * a przy `/odwolanie` także bez Turnstile.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TE SAME KLUCZE CO `/login`, NIE WŁASNE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `KluczeLimitow` liczy klucz z loginu i adresu, bez prefiksu trasy — i to
 * jest tu celowe, wbrew regule „jeden prefiks to jedna trasa"
 * (`LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`). Tamta reguła chroni
 * przed tym, żeby PRZEGLĄDANIE nie zjadało budżetu PUBLIKOWANIA — czyli
 * przed mieszaniem dwóch RÓŻNYCH czynności. Tutaj czynność jest jedna:
 * zgadywanie tego samego hasła do tego samego konta. Trzy osobne wiadra po
 * 15 prób dałyby napastnikowi 45 prób na kwadrans zamiast 15 i byłyby
 * ochroną tylko z nazwy.
 *
 * To samo rozstrzygnięcie stoi już w `config/kuking.php` przy
 * `confirm_password`: „to wciąż zgadywanie cudzego hasła (...) i nie ma
 * prawa mieć luźniejszego limitu".
 *
 * ŻADEN PRÓG SIĘ TU NIE ZMIENIA. Liczby są te same, z `login_limits`;
 * zmienia się wyłącznie to, ILE DRZWI ich pilnuje.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CENA, WPROST: TO POZWALA OBCEMU ZABLOKOWAĆ CUDZE KONTO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Koszyk konta (15 prób / 15 min) z definicji nie odróżnia właściciela od
 * napastnika — ktoś, kto zna cudzy login, wyczerpie go cudzym loginem
 * i właścicielka z POPRAWNYM hasłem dostanie odmowę. Zmierzone: po 15
 * próbach napastnika z 15 adresów właścicielka z właściwym hasłem zostaje
 * odrzucona na 15 minut.
 *
 * Tej ceny nie da się nie zapłacić — alternatywą jest brak ochrony konta
 * przed atakiem rozproszonym. Można natomiast zapłacić ją UCZCIWIE, i o to
 * dbają dwie rzeczy obok:
 *
 *  - komunikat NAZYWA drogę wyjścia (niżej, `komunikat()`): logowanie
 *    linkiem e-mail nie przechodzi przez te koszyki w ogóle, więc działa
 *    także wtedy, gdy koszyk konta jest pełny;
 *  - udany reset hasła CZYŚCI koszyk konta (`PasswordResetController`) —
 *    po ustawieniu nowego hasła próby napastnika dotyczyły już hasła,
 *    którego nie ma, więc nie ma czego dalej trzymać zamkniętego.
 *
 * Tego, że dopisanie tej klasy do dwóch formularzy NIE psuje odwołania
 * ani cofnięcia usunięcia zwykłemu człowiekowi, pilnuje
 * `LimitHaselPrzedLogowaniemTest`: pięć pomyłek pod rząd przechodzi bez
 * odmowy, bo koszyk pary ma 5 prób na minutę, a prawdziwe odwołanie składa
 * się raz.
 */
final class LimitProbHasla
{
    public function __construct(private readonly KluczeLimitow $klucze) {}

    /**
     * Czy któryś koszyk jest pełny — jeśli tak, dalej nie idziemy.
     *
     * Rzuca `ValidationException` pod wskazanym polem, bo wszystkie trzy
     * formularze pokazują błąd przy polu `login`, a nie stroną 429: strona
     * 429 nie zachowuje wpisanego tekstu odwołania (`errors/429.blade.php`
     * odzyskuje tylko trasy z `App\Support\OdzyskiwalneDane`).
     *
     * JEDEN KOMUNIKAT DLA WSZYSTKICH TRZECH KOSZYKÓW I DLA KONTA, KTÓREGO
     * NIE MA — rozróżnienie powiedziałoby, który licznik trafił, czyli czy
     * konto o tym loginie istnieje.
     */
    public function zatrzymajJesliZaDuzo(string $login, string $adres, string $pole = 'login'): void
    {
        foreach ($this->koszyki($login, $adres) as $koszyk) {
            if (! RateLimiter::tooManyAttempts($koszyk['klucz'], $koszyk['proby'])) {
                continue;
            }

            $minuty = max(1, (int) ceil(RateLimiter::availableIn($koszyk['klucz']) / 60));

            throw ValidationException::withMessages([$pole => $this->komunikat($minuty)]);
        }
    }

    /** Nieudana próba hasła — liczy się do wszystkich trzech koszyków. */
    public function zapiszNieudanaProbe(string $login, string $adres): void
    {
        foreach ($this->koszyki($login, $adres) as $koszyk) {
            RateLimiter::hit($koszyk['klucz'], decaySeconds: $koszyk['sekundy']);
        }
    }

    /**
     * Udana próba czyści PARĘ I KONTO, NIGDY ADRES.
     *
     * Gdyby czyściła adres, napastnik zalogowałby się na własne, jednorazowe
     * konto z tego samego adresu, żeby wyzerować licznik adresowy i wrócić
     * do rozpylania po cudzych kontach (`KluczeLimitow`).
     */
    public function wyczyscPoUdanej(string $login, string $adres): void
    {
        $koszyki = $this->koszyki($login, $adres);

        RateLimiter::clear($koszyki['para']['klucz']);
        RateLimiter::clear($koszyki['konto']['klucz']);
    }

    /**
     * Zdjęcie blokady KONTA po udanym resecie hasła.
     *
     * Koszyk konta chodzi po TYM, CO CZŁOWIEK WPISAŁ, a nie po
     * identyfikatorze konta (`KluczeLimitow`, sekcja „czego ta klasa nadal
     * nie robi"), więc jedno konto ma dwa klucze: po adresie e-mail i po
     * nazwie użytkownika. Czyścimy OBA — wyczyszczenie jednego zostawiałoby
     * człowieka zablokowanego na tym zapisie, którego akurat używa, i to
     * bez żadnej widocznej przyczyny.
     *
     * Koszyka ADRESU nie ruszamy tu z tego samego powodu co wyżej.
     */
    public function zdejmijBlokadeKonta(User $user): void
    {
        $zapisy = array_filter([
            $user->email,
            $user->profile?->username,
        ]);

        foreach ($zapisy as $zapis) {
            RateLimiter::clear($this->klucze->konto((string) $zapis));
        }
    }

    /**
     * Komunikat mówi DWIE rzeczy: kiedy i co zrobić w międzyczasie.
     *
     * Samo „za dużo prób, spróbuj za 15 min" zostawia osobę, której konto
     * zablokował ktoś obcy, przed ścianą bez drzwi — a drzwi są: logowanie
     * linkiem e-mail nie przechodzi przez te koszyki w ogóle
     * (`LoginLinkController`), więc działa OD RAZU, bez czekania.
     *
     * Zdanie jest STAŁE — nie zależy od tego, czy konto o podanym loginie
     * istnieje, ani od tego, który koszyk trafił. Inaczej komunikat stałby
     * się wyrocznią, przed którą broni się reszta tej klasy.
     *
     * Przy wyłączonym logowaniu linkiem (`login_link.wlaczone` = false)
     * zostaje „Nie pamiętam hasła": ustawienie nowego hasła zdejmuje blokadę
     * konta (`zdejmijBlokadeKonta()` wołane z `PasswordResetController`),
     * więc to też jest droga wyjścia, tylko dłuższa. Nie wymieniamy drogi,
     * której w danej chwili nie ma — martwa podpowiedź jest gorsza niż jej
     * brak (AGENTS.md §5).
     */
    private function komunikat(int $minuty): string
    {
        $wyjscie = (bool) config('kuking.login_link.wlaczone')
            ? 'Nie czekaj — wejdź na konto linkiem: na ekranie logowania kliknij „Wyślij mi link”, '
                .'a wpuścimy Cię wiadomością e-mail. To działa od razu.'
            : 'W międzyczasie kliknij „Nie pamiętam hasła” — po ustawieniu nowego hasła '
                .'będzie można zalogować się od razu, bez czekania.';

        return "Za dużo prób logowania. Spróbuj ponownie za {$minuty} min. ".$wyjscie;
    }

    /**
     * Trzy koszyki z liczbami z `config/kuking.php` → `login_limits`.
     *
     * @return array{para: array{klucz: string, proby: int, sekundy: int}, konto: array{klucz: string, proby: int, sekundy: int}, adres: array{klucz: string, proby: int, sekundy: int}}
     */
    private function koszyki(string $login, string $adres): array
    {
        $limity = (array) config('kuking.login_limits');

        $koszyk = fn (string $nazwa, string $klucz): array => [
            'klucz' => $klucz,
            'proby' => (int) ($limity[$nazwa]['proby'] ?? 5),
            'sekundy' => (int) ($limity[$nazwa]['sekundy'] ?? 60),
        ];

        return [
            'para' => $koszyk('para', $this->klucze->para($login, $adres)),
            'konto' => $koszyk('konto', $this->klucze->konto($login)),
            'adres' => $koszyk('adres', $this->klucze->adres($adres)),
        ];
    }
}
