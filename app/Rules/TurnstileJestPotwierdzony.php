<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use App\Turnstile\WynikTurnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * Reguła Turnstile. Nazwa jest dokładna i celowa: sprawdzamy, czy sprawdzenie
 * Turnstile ZOSTAŁO POTWIERDZONE — czyli czy token W OGÓLE PRZYSZEDŁ i czy nie
 * jest podrobiony.
 *
 * ┌───────────────────────────────────────────────────────────────────────────┐
 * │ BRAK TOKENU ODRZUCA WYSŁANIE. To jest zmiana z 9 września 2026 i była     │
 * │ świadoma — poprzednia wersja tej klasy nazywała się                       │
 * │ `TurnstileNieJestPodrobiony` i brak tokenu PRZEPUSZCZAŁA.                 │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * CO SIĘ ZMIENIŁO I DLACZEGO
 * Pierwotna wersja (D-050, PR #218) opierała się na zasadzie „ważne funkcje
 * działają bez JavaScriptu": Turnstile jest widgetem JS, wersji bez JS nie ma,
 * więc brak tokenu musiał przechodzić. **Właściciel tę zasadę zmienił dla tych
 * siedmiu formularzy**: „w tych newralgicznych miejscach niech JS będzie
 * obowiązkowo jak ta rejestracja itp, tam gdzie można się obejść to spoko, ale
 * lepiej żeby był z wygody". Uzasadnienie faktyczne: nasi ludzie wchodzą
 * z nowoczesnych telefonów albo z komputera i JavaScript mają. Turnstile
 * przestał więc być filtrem, a stał się warunkiem wysłania. Pełny zapis:
 * `docs/DECISIONS.md` D-050.
 *
 *   brak tokenu        → ODRZUCAMY z komunikatem `komunikatBrakuTokenu()`
 *                        i wpisem w dzienniku (żeby dało się policzyć,
 *                        ilu ludzi to dotknęło)
 *   token nieprawdziwy → ODRZUCAMY z komunikatem `komunikatOdrzucenia()`
 *   Cloudflare milczy  → PRZEPUSZCZAMY i zapisujemy ostrzeżenie
 *   brak kluczy        → PRZEPUSZCZAMY, nikogo nie pytamy
 *
 * DWA ODRZUCENIA, DWA RÓŻNE KOMUNIKATY — I TO NIE JEST OZDOBA
 * „Nie ma tokenu" i „token jest zły" to dla człowieka przed ekranem dwie
 * zupełnie różne sytuacje. W pierwszej nie widzi NICZEGO, czego brakuje
 * (skrypt się nie dociągnął przy słabym zasięgu, blokada reklam zjadła adres
 * Cloudflare), więc komunikat musi powiedzieć, że to sprawdzenie się nie
 * wczytało, i co z tym zrobić. W drugiej sprawdzenie było widoczne i wygasło,
 * więc wystarczy wysłać formularz jeszcze raz. Wspólny tekst kazałby połowie
 * osób szukać usterki, której u nich nie ma.
 *
 * DLACZEGO `$implicit = true`
 * Bez tego Laravel NIE WOŁA tej reguły dla pola pustego albo nieobecnego
 * (`Validator::presentOrRuleIsImplicit()`) — czyli dokładnie dla każdego
 * wysłania bez tokenu, a więc dla jedynego przypadku, o który w tej zmianie
 * chodzi. To jedno pole `public bool $implicit` jest tu całym mechanizmem
 * zaciśnięcia; `required` w siedmiu kontrolerach dałoby ten sam skutek, ale
 * z laravelowym komunikatem o „polu cf-turnstile-response", którego nikt
 * na ekranie nie zrozumie.
 *
 * CZEGO TA ZMIANA NIE DOTKNĘŁA (i nie wolno tego „przy okazji" dokręcić)
 * Awaria Cloudflare — timeout, 5xx, zły sekret po naszej stronie — dalej
 * PRZEPUSZCZA formularz. To jest nasza albo cudza usterka, nie wina człowieka
 * przed ekranem; zamykanie z tego powodu rejestracji byłoby absurdem, bo
 * jedna literówka w panelu Railway wyłączałaby wejście do serwisu, a z zewnątrz
 * wyglądałoby to na działający serwis. Rozstrzyga o tym `KlientTurnstile`,
 * oddając `Nierozstrzygniety`.
 *
 * CO MUSI IŚĆ RAZEM Z TĄ REGUŁĄ
 * Zaciśnięcie bez drogi wyjścia zamienia rzadką awarię w cichą utratę
 * użytkownika. Dlatego `resources/views/components/turnstile.blade.php` ma
 * `<noscript>` z osobnym zdaniem dla każdego z siedmiu formularzy i adresem
 * e-mail, pod którym siedzi człowiek. Kto zdejmie `<noscript>`, zostawi ludzi
 * przed martwym przyciskiem — pilnuje tego
 * `tests/Feature/TurnstileWymagaPotwierdzeniaTest.php`.
 *
 * DLACZEGO REGUŁA, A NIE MIDDLEWARE
 * Bo człowiek ma zobaczyć błąd PRZY POLU i w podsumowaniu na górze formularza,
 * bez utraty tego, co wpisał (`old()`) — a middleware oddające 403 wyrzuca
 * wpisany tekst do kosza. To ten sam powód, dla którego `ObslugiwaneZdjecie`
 * jest regułą, choć prawdziwą granicą jest kod domenowy.
 */
final class TurnstileJestPotwierdzony implements ValidationRule
{
    /**
     * Reguła ma być wołana TAKŻE dla pola pustego i nieobecnego — patrz
     * komentarz klasy. Bez tego brak tokenu przechodziłby dalej w ciszy.
     */
    public bool $implicit = true;

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
     * Bez `required` i bez `max`, choć jedno i drugie brzmi tu naturalnie:
     * obecność pilnuje `$implicit` wyżej, a długość rozstrzygamy niżej sami.
     * Laravelowe `required` i `max` oddałyby komunikat o „polu
     * cf-turnstile-response", którego nikt na ekranie nie zrozumie.
     *
     * @return list<mixed>
     */
    public static function reguly(string $miejsce): array
    {
        return [new self($miejsce)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Turnstile::dziala($this->miejsce)) {
            // Brak kluczy albo miejsce wyłączone w konfiguracji. Nie pytamy
            // Cloudflare i nie zatrzymujemy nikogo — także wtedy, gdy ktoś
            // podstawił token ręcznie. Inaczej dałoby się wymusić na nas
            // ruch wychodzący na formularzu, którego Turnstile nie dotyczy,
            // a CI i praca lokalna (obie bez kluczy) stanęłyby na każdym
            // z siedmiu formularzy naraz.
            return;
        }

        if ($value === null || $value === '' || $value === []) {
            // Widget nie odłożył tokenu: JavaScript wyłączony albo skrypt
            // Turnstile się nie dociągnął. Odrzucamy — ale komunikatem, który
            // mówi, co zrobić, i z innym tekstem niż przy tokenie podrobionym.
            $this->zapiszWDzienniku();

            $fail(Turnstile::komunikatBrakuTokenu($this->miejsce));

            return;
        }

        if (! is_string($value) || strlen($value) > Turnstile::MAKSYMALNA_DLUGOSC_TOKENU) {
            // Tablica zamiast łańcucha albo token dłuższy od wszystkiego, co
            // Turnstile wystawia. Nie ma po co pytać Cloudflare — to nie
            // przyszło z widgetu.
            $fail(Turnstile::komunikatOdrzucenia($this->miejsce));

            return;
        }

        $wynik = $this->klient->sprawdz($value, request()->ip());

        if ($wynik === WynikTurnstile::Odrzucony) {
            $fail(Turnstile::komunikatOdrzucenia($this->miejsce));
        }

        // `Przeszedl` i `Nierozstrzygniety` znaczą tu to samo: puszczamy dalej.
        // Różnica jest w dzienniku, nie na ekranie — `KlientTurnstile` zapisał
        // ostrzeżenie, a człowiek nie ma nic do zrobienia z awarią Cloudflare.
    }

    /**
     * ŚLAD, ŻEBY DAŁO SIĘ ODPOWIEDZIEĆ NA PYTANIE „CZY ZAMKNĘLIŚMY KOMUŚ DRZWI".
     *
     * Zaciśnięcie z 9 września jest zakładem: twierdzimy, że nasi ludzie mają
     * JavaScript. Zakład bez licznika jest wiarą, a nie decyzją — po tygodniu
     * musi dać się policzyć, ilu ludzi odbiło się od tego formularza i od
     * którego. `Log::warning`, a nie `info`: to jest zdarzenie, które ma być
     * widoczne w dzienniku bez szukania.
     *
     * DLACZEGO DZIENNIK, A NIE `product_signals` (`ZapiszSygnal`)
     * Bo `signal_name` w tej tabeli jest zamknięty CHECK-iem w bazie
     * (`product_signals_signal_name_check`), więc nowa nazwa zdarzenia to
     * migracja — a D-050 obiecuje wycofanie Turnstile „bez migracji, bez
     * danych do posprzątania" i ta obietnica jest tu więcej warta niż
     * wygodniejszy wykres. Gdyby liczby okazały się niepokojące, przeniesienie
     * tego do sygnałów jest osobną, świadomą pracą z migracją i wpisem
     * w `docs/DATABASE.md`.
     *
     * BEZ ADRESU IP I BEZ TREŚCI FORMULARZA (AGENTS.md §7). Do pytania
     * „ilu ludzi i gdzie" wystarczy nazwa miejsca; adres IP zamieniłby
     * licznik w rejestr osób, które próbowały się zarejestrować.
     */
    private function zapiszWDzienniku(): void
    {
        Log::warning('Turnstile: formularz odrzucony, bo nie przyszedł token.', [
            'miejsce' => $this->miejsce,
            'powod' => 'brak_tokenu',
            'co_to_znaczy' => 'Wyłączony JavaScript albo niedociągnięty skrypt widgetu. Człowiek dostał komunikat mówiący, co zrobić.',
        ]);
    }
}
