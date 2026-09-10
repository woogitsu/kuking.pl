<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * NA JAK DŁUGO ZAWIESZAMY KONTO — zamknięta lista wyborów i jeden przelicznik
 * na datę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DWIE USTERKI, KTÓRE TO ZAMYKA (zgłoszenie właściciela)
 * ────────────────────────────────────────────────────────────────────────
 *
 * 1. GRUPA RADIO BEZ POZYCJI ZEROWEJ BYŁA PUŁAPKĄ BEZ WYJŚCIA.
 *    Formularz dawał „Na 1 dzień / Na 7 dni / Na 30 dni / Bezterminowo"
 *    i ani jednej pozycji znaczącej „nie zawieszam". Raz zaznaczonego
 *    `<input type="radio">` nie da się odkliknąć — jedyną drogą powrotu
 *    było odświeżenie strony, a razem z nim ginęło uzasadnienie, notatka
 *    i wiadomość do autora. To wprost łamie regułę z `AGENTS.md` §5:
 *    poprawnie wpisane dane nigdy nie znikają.
 *
 * 2. BRAK WYBORU ZNACZYŁ NAJSUROWSZĄ KARĘ — I TO JEST GROŹNIEJSZE.
 *    Podpis pod grupą mówił: „Bez wyboru zawieszenie jest bezterminowe".
 *    Czyli pomyłka przez zaniechanie dawała blokadę do decyzji człowieka,
 *    a przy zespole 1-2 osób (D-012) nikt takiej kary nie odklikuje sam.
 *    Domyślne zachowanie ma być odwrotne: pomyłka w stronę ŁAGODNIEJSZĄ
 *    jest odwracalna jednym kliknięciem, pomyłka w stronę bezterminowej
 *    blokady kosztuje zaufanie i wymaga odwołania (DSA art. 20).
 *
 * Dlatego `BRAK` jest pierwszą i domyślnie zaznaczoną pozycją, a
 * `BEZTERMINOWO` wymaga jawnego wyboru. Zawieszenie z wyborem „Bez
 * zawieszenia" nie jest cicho wykonywane jako bezterminowe ani cicho
 * pomijane — kontroler odmawia takiej decyzji i mówi, czego brakuje.
 * Cisza byłaby tu najgorsza: log moderacji mówiłby „zawieszono", a konto
 * działałoby dalej.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WŁASNY TERMIN, SKORO SĄ 1, 7 I 30 DNI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo trzy liczby nie są wszystkimi sensownymi wyborami, a `status_expires_at`
 * to zwykły `timestamptz` — nic w bazie nie wymagało tej trójki. Podręcznik
 * moderacji przewiduje eskalację („2. wystąpienie → 7 dni, 3. → trwała"),
 * a między 30 dniami i bezterminowością była dziura, którą moderator
 * zamykał jedynym dostępnym narzędziem: karą bez terminu.
 *
 * ZAKRES 1-365 DNI:
 *  - dolna granica: krócej niż dzień to nie kara, tylko ostrzeżenie —
 *    a ostrzeżenie jest osobną decyzją (`warn`);
 *  - górna granica: rok to najdłuższa kara, która jeszcze czyta się jako
 *    CZASOWA. Powyżej mówilibyśmy „na 3 lata", a człowiek i tak nie wróci —
 *    czyli byłaby to blokada bezterminowa udająca termin. Na to jest
 *    osobna, jawna pozycja i osobna decyzja (`ban`).
 */
final class DlugoscZawieszenia
{
    /** Nie zawieszam — pozycja pierwsza i domyślna. */
    public const BRAK = 'brak';

    /** Liczbę dni podaje moderator w polu obok. */
    public const WLASNY = 'wlasny';

    /** Do decyzji człowieka. Wyłącznie z jawnego wyboru. */
    public const BEZTERMINOWO = 'bezterminowo';

    /** Terminy pod jednym kliknięciem — z podręcznika moderacji. */
    public const GOTOWE = [1, 7, 30];

    public const MIN_DNI = 1;

    public const MAX_DNI = 365;

    /**
     * Pozycje grupy w kolejności, w jakiej stoją na ekranie.
     *
     * Kolejność nie jest ozdobą: „Bez zawieszenia" jest PIERWSZE, żeby
     * czytało się jako stan wyjściowy, a nie jako furtka dopisana na końcu.
     * „Bezterminowo" jest ostatnie, bo jest najsurowsze.
     *
     * @return array<string, string>
     */
    public static function dlaFormularza(): array
    {
        $pozycje = [self::BRAK => 'Bez zawieszenia'];

        foreach (self::GOTOWE as $dni) {
            $pozycje[(string) $dni] = $dni === 1 ? 'Na 1 dzień' : 'Na '.$dni.' dni';
        }

        $pozycje[self::WLASNY] = 'Własny termin — liczba dni z pola niżej';
        $pozycje[self::BEZTERMINOWO] = 'Bezterminowo, do mojej decyzji';

        return $pozycje;
    }

    /**
     * Dozwolone wartości pola `suspend_days` — do reguły `in:`.
     *
     * @return list<string>
     */
    public static function wartosci(): array
    {
        return array_map('strval', array_keys(self::dlaFormularza()));
    }

    /** Czy ten wybór w ogóle zawiesza konto. */
    public static function zawiesza(?string $wybor): bool
    {
        return $wybor !== null && $wybor !== self::BRAK && in_array($wybor, self::wartosci(), true);
    }

    /** Czy ten wybór wymaga liczby dni z osobnego pola. */
    public static function wymagaLiczby(?string $wybor): bool
    {
        return $wybor === self::WLASNY;
    }

    /**
     * Wybór z formularza → konkretna data albo `null`.
     *
     * `null` znaczy „bezterminowo, do decyzji człowieka" i tak ma zostać:
     * `User::suspend(null)` zapisuje zawieszenie bez terminu, którego
     * `kuking:zdejmij-wygasle-kary` świadomie nie rusza.
     *
     * `BRAK` też oddaje `null`, ale tu nigdy nie dojdzie — kontroler
     * odrzuca „Zawieś konto" bez wybranego terminu, zanim cokolwiek zapisze.
     *
     * WŁASNY TERMIN BEZ POPRAWNEJ LICZBY WYWALA WYJĄTEK, a nie oddaje `null`.
     * Wygląda to surowo, ale `null` znaczy tu „bezterminowo" — czyli cichy
     * błąd w liczbie zamieniłby się w NAJSUROWSZĄ karę. Dokładnie ten
     * mechanizm naprawia ta zmiana, więc nie wolno go tu odtworzyć. Przez
     * formularz to nie przejdzie (walidacja pilnuje zakresu), a wyjątek
     * pilnuje drugiego wejścia, gdyby kiedyś powstało.
     */
    public static function termin(?string $wybor, ?int $dni): ?CarbonInterface
    {
        if (! self::zawiesza($wybor) || $wybor === self::BEZTERMINOWO) {
            return null;
        }

        if (self::wymagaLiczby($wybor)) {
            if ($dni === null || $dni < self::MIN_DNI || $dni > self::MAX_DNI) {
                throw new InvalidArgumentException(
                    'Własny termin zawieszenia wymaga liczby dni od '.self::MIN_DNI.' do '.self::MAX_DNI.'.',
                );
            }

            return Carbon::now()->addDays($dni);
        }

        return Carbon::now()->addDays((int) $wybor);
    }
}
