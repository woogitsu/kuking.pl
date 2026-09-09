<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use App\Turnstile\WynikTurnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reguła Turnstile. Nazwa jest dokładna i celowa: sprawdzamy, czy token NIE
 * JEST PODROBIONY — a nie, czy token W OGÓLE JEST.
 *
 * ┌───────────────────────────────────────────────────────────────────────────┐
 * │ NIGDY NIE DOKŁADAJ TU `required`. TO NIE JEST PRZEOCZENIE.                │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * `AGENTS.md` §5: rejestracja, logowanie, publikacja wpisu, przepis, komentarz
 * i „Ugotowałem" MUSZĄ działać bez JavaScriptu. Turnstile jest widgetem JS
 * i wersji bez JS NIE MA — nie da się tego obejść sprytniejszym kodem.
 * Wniosek jest jeden:
 *
 *   brak tokenu      → PRZEPUSZCZAMY (ochroną są limity zapytań z
 *                      `config/kuking.php`, `klucz_wyslania` i weryfikacja
 *                      adresu e-mail)
 *   token nieprawdziwy → ODRZUCAMY z komunikatem mówiącym, co zrobić
 *   Cloudflare milczy  → PRZEPUSZCZAMY i zapisujemy ostrzeżenie
 *
 * Turnstile jest więc FILTREM TANIEGO RUCHU AUTOMATYCZNEGO, a nie warunkiem
 * dostępu. Skrypt masowo zakładający konta zwykle nie wykonuje JavaScriptu
 * wcale — a jeśli już wykonuje, to Turnstile ma szansę go poznać. Człowiek
 * z wyłączonym skryptem, czytnikiem ekranu, starą przeglądarką albo słabym
 * zasięgiem (skrypt się nie dociągnął) traci przy `required` DOSTĘP DO
 * SERWISU. To jest różnica między niewygodą dla napastnika i zamkniętymi
 * drzwiami dla użytkownika.
 *
 * Kto to kiedyś „dokręci" jednym `required`, wyłączy rejestrację i odzyskanie
 * hasła dokładnie tym osobom, dla których ten serwis w ogóle powstał
 * (docs/UX_50_PLUS.md). Decyzja: `docs/DECISIONS.md` D-050.
 *
 * DLACZEGO REGUŁA, A NIE MIDDLEWARE
 * Bo człowiek ma zobaczyć błąd PRZY POLU i w podsumowaniu na górze formularza,
 * bez utraty tego, co wpisał (`old()`) — a middleware oddające 403 wyrzuca
 * wpisany tekst do kosza. To ten sam powód, dla którego `ObslugiwaneZdjecie`
 * jest regułą, choć prawdziwą granicą jest kod domenowy.
 */
final class TurnstileNieJestPodrobiony implements ValidationRule
{
    /**
     * @param  string  $miejsce  klucz z `kuking.turnstile.miejsca` — ten sam,
     *                           którym widok włącza widget. Jedno źródło
     *                           prawdy: nie da się mieć widgetu bez walidacji
     *                           ani walidacji bez widgetu.
     */
    public function __construct(
        private readonly string $miejsce,
        private readonly KlientTurnstile $klient = new KlientTurnstile,
    ) {}

    /**
     * Reguły dla pola z tokenem — gotowe do wstawienia w tablicę `validate()`.
     *
     * Bez `required` (patrz komentarz klasy) i bez `max`, bo długość
     * rozstrzygamy niżej sami: laravelowe `max` oddałoby komunikat
     * o „polu cf-turnstile-response", którego nikt na ekranie nie zrozumie.
     *
     * @return list<mixed>
     */
    public static function reguly(string $miejsce): array
    {
        return ['nullable', new self($miejsce)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Turnstile::dziala($this->miejsce)) {
            // Brak kluczy albo miejsce wyłączone w konfiguracji. Nie pytamy
            // Cloudflare i nie zatrzymujemy nikogo — także wtedy, gdy ktoś
            // podstawił token ręcznie. Inaczej dałoby się wymusić na nas
            // ruch wychodzący na formularzu, którego Turnstile nie dotyczy.
            return;
        }

        if ($value === null || $value === '' || $value === []) {
            /*
             * DRUGI ZAMEK, NIE PIERWSZY — i trzeba wiedzieć, który jest który.
             *
             * Przez ścieżkę formularza ta gałąź SIĘ NIE WYKONUJE i nie ona
             * jest gwarancją działania bez JavaScriptu. Ta reguła nie jest
             * „implicit", więc Laravel w ogóle jej nie woła dla wartości
             * pustej albo nieobecnej — a tak wygląda każde wysłanie bez
             * skryptu. Zmierzone: podmiana treści tej gałęzi na `$fail()`
             * NIE psuje ani jednego testu w tej paczce.
             *
             * PRAWDZIWĄ gwarancją jest BRAK `required` (i brak innej reguły
             * wymuszającej obecność) przy tym polu w sześciu kontrolerach —
             * `RegisterController`, `LoginController`,
             * `PasswordResetController`, `AccountDeletionController`,
             * `NapiszDoNasController` i `ZgloszenieNielegalnejTresciController`.
             * Pilnują tego testy `test_*_bez_tokenu_*` w
             * `tests/Feature/TurnstileNieZamykaDrzwiTest.php`, po jednym na
             * każdy z tych formularzy. Jeśli szukasz miejsca, w którym można
             * przypadkiem zamknąć drzwi osobie bez JS-u — jest tam, nie tu.
             *
             * Gałąź zostaje mimo to, bo reguła jest klasą publiczną i da się
             * ją wywołać poza formularzem: przez `Validator::make()` bez
             * `nullable`, przez `sometimes()`, przez własny test albo przez
             * przyszły kod, który zechce sprawdzić token wprost. W każdym
             * z tych wywołań pusta wartość ma znaczyć „nie ma czego
             * sprawdzać", a nie „odrzuć". Domyka to
             * `test_regula_wywolana_wprost_z_pustym_tokenem_nie_odrzuca`,
             * więc od teraz ta gałąź jest przetestowana, a nie martwa.
             */
            return;
        }

        if (! is_string($value) || strlen($value) > Turnstile::MAKSYMALNA_DLUGOSC_TOKENU) {
            // Tablica zamiast łańcucha albo token dłuższy od wszystkiego, co
            // Turnstile wystawia. Nie ma po co pytać Cloudflare — to nie
            // przyszło z widgetu.
            $fail(Turnstile::komunikatOdrzucenia());

            return;
        }

        $wynik = $this->klient->sprawdz($value, request()->ip());

        if ($wynik === WynikTurnstile::Odrzucony) {
            $fail(Turnstile::komunikatOdrzucenia());
        }

        // `Przeszedl` i `Nierozstrzygniety` znaczą tu to samo: puszczamy dalej.
        // Różnica jest w dzienniku, nie na ekranie — `KlientTurnstile` zapisał
        // ostrzeżenie, a człowiek nie ma nic do zrobienia z awarią Cloudflare.
    }
}
