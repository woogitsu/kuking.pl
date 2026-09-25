<?php

declare(strict_types=1);

namespace App\Http\Requests\Recipes;

use App\Domain\Recipes\StepTimer;
use App\Domain\Recipes\TekstNaWiersze;
use App\Models\Recipe;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityTekstuPrzepisu;
use App\Support\LimityZdjec;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Zapis przepisu zwykłym POST-em — ekran dodawania (`recipes.store`)
 * i formularz szczegółów na jednej stronie (`recipes.update`).
 *
 * Wyjęte z `RecipeController::validated()` bez zmiany zachowania (issue #970,
 * krok 1): te same reguły, te same komunikaty, ta sama kolejność. Walidacja
 * idzie w dwóch fazach i tak ma zostać:
 *
 *  1. `rules()` / `messages()` — Laravel sprawdza je, zanim wejdziemy
 *     do kontrolera;
 *  2. `daneZapisu()` — budżet zdjęć kroków i rozbicie pól tekstowych na
 *     wiersze. Woła ją kontroler, dopiero gdy faza 1 przeszła, więc błąd
 *     z fazy 2 NIE miesza się z błędami pól z fazy 1 (hak `after()` by je
 *     połączył — to byłaby zmiana zachowania).
 */
final class ZapisPrzepisuRequest extends FormRequest
{
    /**
     * KOLEJNOŚĆ: POLICY PRZED WALIDACJĄ — tak jak w kontrolerze przed #970.
     *
     * FormRequest waliduje się przy wstrzyknięciu, czyli ZANIM ruszy ciało
     * `update()`. Bez tej metody obca osoba z błędnym formularzem dostałaby
     * komunikaty walidacji (302) zamiast odmowy (403). `Gate::authorize()`
     * rzuca ten sam wyjątek, co `$this->authorize()` w kontrolerze, więc
     * odmowa wygląda identycznie. Ekran dodawania nie ma przepisu w adresie
     * — tam pilnuje trasa (`auth`) i dotąd nie było tu żadnej Policy.
     */
    public function authorize(): bool
    {
        $recipe = $this->przepis();

        if ($recipe !== null) {
            Gate::authorize('update', $recipe);
        }

        return true;
    }

    /** Przepis z adresu — `null` na ekranie dodawania. */
    public function przepis(): ?Recipe
    {
        $recipe = $this->route('recipe');

        return $recipe instanceof Recipe ? $recipe : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $existing = $this->przepis();

        return [
            'title' => ['required', 'string', 'min:3', 'max:'.LimityTekstuPrzepisu::POLA['title']],
            'summary' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['summary']],
            /*
             * KROK 0,01 — DECYZJA WŁAŚCICIELA Z 20.09.2026 (issue #750).
             *
             * Kolumna `servings` to `decimal(6,2)` — dwa miejsca po
             * przecinku i ani jednego więcej. Formularz kiedyś deklarował
             * `step="0.5"`, a walidacja dopuszczała `1.25`, więc pole było
             * nieprawidłowe wobec własnej deklaracji (`stepMismatch=true`
             * mimo `checkValidity()`), a `1.255` znikało po cichu jako
             * `1.26` — bez słowa dla człowieka, który to wpisał.
             *
             * `decimal:0,2` dopuszcza 0, 1 albo 2 miejsca po przecinku, czyli
             * dokładnie tyle, ile udźwignie kolumna: `4`, `1.5`, `1.25` —
             * TAK; `1.255` — NIE, z komunikatem niżej każącym POPRAWIĆ, a nie
             * cichym zaokrągleniem. `step="0.01"` w `szczegoly.blade.php`
             * musi się z tym zgadzać, inaczej wraca ten sam błąd na nowo.
             */
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:999', 'decimal:0,2'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'visibility' => ['required', 'in:public,followers,private'],
            /*
             * `nullable`, nie `required` (issue #364). Ekran dodawania nie
             * pyta „ten przepis jest…" — to jedno z dziewięciu kółek wyboru,
             * które z niego wyleciały. Brak pola znaczy „mój własny"
             * (`PublishRecipe` stawia `Recipe::SOURCE_OWN`), a formularz
             * szczegółów pyta dalej i dalej przysyła wartość.
             */
            'source_type' => ['nullable', 'in:own,family,adaptation,external'],
            'source_person' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['source_person']],
            'source_note' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['source_note']],
            // Decyzja właściciela (#900): niezmieniony dawny adres nie blokuje edycji.
            'source_url' => ['nullable', $existing !== null && $this->input('source_url') === $existing->source_url ? 'url' : 'url:http,https', 'max:'.LimityTekstuPrzepisu::POLA['source_url']],
            'family_since_year' => ['nullable', 'integer', 'min:1850', 'max:2100'],
            'hero_photo' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            // „Dopisz przepis” z własnego wpisu (#1334): identyfikator wpisu,
            // którego zdjęcie ma zostać zdjęciem głównym. O tym, czy wolno,
            // rozstrzyga `PostPolicy::dopiszPrzepis` w kontrolerze, nie ta reguła.
            'z_wpisu' => ['nullable', 'uuid'],
            'source_scan' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'ingredients' => ['nullable', 'array', 'max:'.Recipe::MAX_INGREDIENTS],
            'ingredients.*.text' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.text']],
            'ingredients.*.group_name' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.group_name']],
            'ingredients.*.note' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['ingredients.*.note']],
            // „Bez ilości” — sól do smaku, mleko ile weźmie (issue #44).
            // Pole wysyła zwykły checkbox, więc przychodzi jako "1" albo
            // nie przychodzi wcale.
            'ingredients.*.no_amount' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:'.Recipe::MAX_STEPS],
            // TOŻSAMOŚĆ KROKU, przenoszona przez POST w ukrytym polu.
            //
            // `uuid`, bo kolumna `recipe_steps.id` jest typu uuid — byle jaki
            // tekst wywaliłby zapytanie zamiast dać komunikat. To pole NIE
            // JEST autoryzacją: `PublishRecipe` dopasowuje je wyłącznie do
            // kroków tego przepisu, więc cudzy identyfikator nic nie daje.
            'steps.*.id' => ['nullable', 'uuid'],
            'steps.*.instruction' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['steps.*.instruction']],
            // Człowiek wpisuje MINUTY, bo tak myśli o gotowaniu. Sekundy
            // (`recipe_steps.timer_seconds`, `data-timer-sekundy` w trybie
            // gotowania) liczy `StepTimer` w warstwie domenowej — tu stoi
            // tylko ta sama granica, żeby błąd trafił PRZY POLU, a nie
            // wyjątkiem nad całym formularzem.
            'steps.*.timer_minutes' => ['nullable', 'integer', 'min:0', 'max:'.StepTimer::MAX_MINUTES],
            // Zdjęcie kroku idzie DOKŁADNIE tą samą drogą co każde inne
            // zdjęcie w tym serwisie: `ObslugiwaneZdjecie` w walidacji,
            // `StoreUploadedImage` w zapisie, ten sam limit rozmiaru.
            'steps.*.photo' => ['nullable', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'steps.*.remove_photo' => ['nullable', 'boolean'],

            /*
             * DWA POLA Z EKRANU DODAWANIA (issue #364).
             *
             * Wchodzą TYM SAMYM POST-em co tablice `ingredients` i `steps`
             * z formularza szczegółów i nie kłócą się z nimi: rozstrzyga to,
             * które pole W OGÓLE PRZYSZŁO w żądaniu (niżej). Dzięki temu
             * jedna trasa `recipes.store` obsługuje oba ekrany i obie kończą
             * w tej samej akcji domenowej.
             *
             * Granice są wysokie celowo. Nie są miarą tego, „ile przepis
             * powinien mieć" — od tego są `Recipe::MAX_INGREDIENTS`
             * i `MAX_STEPS`, sprawdzane po rozbiciu na wiersze i mówiące
             * wprost, ile wierszy jest za dużo. Te dwie liczby mają tylko
             * odciąć wklejenie całej książki kucharskiej, zanim zacznie
             * chodzić parser.
             */
            'skladniki_tekst' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['skladniki_tekst']],
            'przygotowanie_tekst' => ['nullable', 'string', 'max:'.LimityTekstuPrzepisu::POLA['przygotowanie_tekst']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Podaj nazwę przepisu — na przykład „Rosół babci Zofii”.',
            'title.min' => 'Nazwa przepisu musi mieć co najmniej 3 znaki. Dopisz kilka liter.',
            'title.max' => 'Nazwa przepisu jest za długa. Skróć ją do 180 znaków.',
            'summary.max' => 'Krótki opis jest za długi. Zostaw najwyżej 2000 znaków — resztę wpisz w historii przepisu.',
            'servings.numeric' => 'Liczba porcji musi być liczbą. Wpisz na przykład 4.',
            'servings.min' => 'Liczba porcji musi być większa od zera. Wpisz na przykład 4.',
            'servings.max' => 'Ta liczba porcji jest nierealna. Wpisz najwyżej 999.',
            'servings.decimal' => 'Liczba porcji może mieć najwyżej dwa miejsca po przecinku (setne). Zamiast 1,255 wpisz 1,25 albo 1,26.',
            'prep_minutes.integer' => 'Czas przygotowania podaj w pełnych minutach, na przykład 20.',
            'prep_minutes.min' => 'Czas przygotowania nie może być ujemny. Wpisz na przykład 20.',
            'prep_minutes.max' => 'Czas przygotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            'cook_minutes.integer' => 'Czas gotowania podaj w pełnych minutach, na przykład 90.',
            'cook_minutes.min' => 'Czas gotowania nie może być ujemny. Wpisz na przykład 90.',
            'cook_minutes.max' => 'Czas gotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest nieprawidłowa"
            // (issue #86) — a te dwa pola akurat renderują się jako <select>,
            // więc zdanie jest tym samym, co widać na ekranie.
            'difficulty.in' => 'Wybierz poziom trudności: łatwy, średni albo trudny.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten przepis.',
            'visibility.in' => 'Zaznacz, kto ma widzieć ten przepis: wszyscy, obserwujący czy tylko Ty.',
            'source_type.required' => 'Zaznacz, skąd jest ten przepis.',
            'source_type.in' => 'Zaznacz, skąd jest ten przepis: Twój własny, rodzinny, adaptacja czy z zewnątrz.',
            'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — wystarczy krótka wzmianka, na przykład „od mamy”.',
            'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
            'source_url.url' => 'Wklej adres strony zaczynający się od http:// lub https://.',
            // Te trzy komunikaty są celowo IDENTYCZNE jak w komponencie
            // `recipe-wizard` (droga z JavaScriptem) — to jest ten sam
            // formularz na jednej stronie, więc ma mówić to samo (issue #86,
            // przykład z treści zgłoszenia: „The family since year field
            // must be at least 1850").
            'family_since_year.integer' => 'Rok wpisz czterema cyframi, na przykład 1974.',
            'family_since_year.min' => 'Ten rok jest za wczesny. Wpisz rok od 1850.',
            'family_since_year.max' => 'Ten rok jest za późny. Wpisz rok do 2100.',
            'hero_photo.image' => 'Zdjęcie główne musi być plikiem JPG, PNG lub WebP.',
            // Wcześniej brakowało tych komunikatów — za duży plik pokazywał
            // domyślny, angielski błąd Laravela (narusza AGENTS.md).
            'hero_photo.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'source_scan.max' => LimityZdjec::komunikatZaDuzyPlik(),
            // Komunikaty minutnika są WSPÓLNE z warstwą domenową
            // (`StepTimer::KOMUNIKAT_*`), a nie przepisane drugi raz. Ta sama
            // wartość odrzucona przez formularz i przez akcję domenową musi
            // mówić to samo zdanie — inaczej człowiek widzi dwa różne
            // tłumaczenia jednej reguły, zależnie od tego, którą drogą szedł.
            'steps.*.timer_minutes.integer' => StepTimer::KOMUNIKAT_NIE_LICZBA,
            'steps.*.timer_minutes.min' => StepTimer::KOMUNIKAT_UJEMNY,
            'steps.*.timer_minutes.max' => StepTimer::KOMUNIKAT_ZA_DUZO,
            'steps.*.photo.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'skladniki_tekst.max' => 'Lista składników jest bardzo długa. Zostaw najwyżej 30 000 znaków — resztę dopisz po opublikowaniu.',
            'przygotowanie_tekst.max' => 'Opis przygotowania jest bardzo długi. Zostaw najwyżej 120 000 znaków — resztę dopisz po opublikowaniu.',
        ];
    }

    /**
     * Druga faza walidacji i dane dla `ZapiszPrzepisZFormularza`.
     *
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: array<array-key, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function daneZapisu(): array
    {
        $data = $this->validated();

        // BUDŻET ZDJĘĆ KROKÓW — sprawdzany PRZED wgraniem czegokolwiek.
        //
        // Bez tego nadmiarowe zdjęcia albo znikałyby bez słowa (PHP obcina
        // części żądania po `max_file_uploads`), albo całe żądanie odpadałoby
        // na `post_max_size` razem z tokenem CSRF i całym wpisanym tekstem
        // (audyt A31). Limit MUSI więc powiedzieć, co zrobić, i musi to
        // powiedzieć, zanim zaczniemy cokolwiek zapisywać.
        $zdjeciaKrokow = 0;

        foreach (array_keys($data['steps'] ?? []) as $index) {
            if ($this->hasFile("steps.{$index}.photo")) {
                $zdjeciaKrokow++;
            }
        }

        if ($zdjeciaKrokow > LimityZdjec::maksZdjecKrokowNaZapis()) {
            throw ValidationException::withMessages([
                'steps' => LimityZdjec::komunikatZaDuzoZdjecKrokow(),
            ]);
        }

        /*
         * SKŁADNIKI I KROKI — Z JEDNEGO POLA TEKSTOWEGO ALBO Z WIERSZY.
         *
         * Rozstrzyga OBECNOŚĆ pola w żądaniu, nie jego pustość. Ekran
         * dodawania wysyła `skladniki_tekst` zawsze, także pusty — i pusty
         * ma znaczyć „bez składników", bo właściciel zgodził się na przepis
         * bez ani jednego (#364). Gdyby rozstrzygała pustość, wyczyszczenie
         * pola po cichu zostawiałoby stare wiersze i przepis kłamałby listą,
         * której autor już nie widzi.
         *
         * Formularz szczegółów tych dwóch pól nie ma w ogóle, więc idzie
         * drugą gałęzią — tą samą, co przed #364, co do wiersza.
         */
        $zTekstu = $this->exists('skladniki_tekst');
        $krokiZTekstu = $this->exists('przygotowanie_tekst');

        if ($zTekstu) {
            $ingredients = TekstNaWiersze::skladniki($data['skladniki_tekst'] ?? null);

            if (count($ingredients) > Recipe::MAX_INGREDIENTS) {
                throw ValidationException::withMessages([
                    'skladniki_tekst' => 'To bardzo dużo składników — zmieść się w '
                        .Recipe::MAX_INGREDIENTS.' wierszach. Sprawdź, czy nie trafił tu przez pomyłkę opis przygotowania.',
                ]);
            }
        } else {
            $ingredients = array_values(array_map(
                static fn (array $row): array => [
                    'text' => $row['text'] ?? '',
                    'group_name' => $row['group_name'] ?? null,
                    'note' => $row['note'] ?? null,
                    'no_amount' => (bool) ($row['no_amount'] ?? false),
                ],
                $data['ingredients'] ?? [],
            ));
        }

        if ($krokiZTekstu) {
            $steps = array_map(
                static fn (array $row): array => [
                    'id' => null,
                    'instruction' => $row['instruction'],
                    'timer_minutes' => null,
                    'media_id' => null,
                    'remove_media' => false,
                ],
                TekstNaWiersze::kroki($data['przygotowanie_tekst'] ?? null),
            );

            /*
             * BŁĄD PRZY POLU, A NIE NAD CAŁYM FORMULARZEM (AGENTS.md §5).
             *
             * Bez tego pusty opis przygotowania wracał z `PublishRecipe`
             * jako `BladDlaCzlowieka` i lądował pod kluczem `title` — czyli
             * zdanie „Opisz przynajmniej jeden krok" świeciło na czerwono
             * przy NAZWIE przepisu, którą człowiek wypełnił poprawnie.
             * Reguła zostaje ta sama i dalej pilnuje jej akcja domenowa;
             * tu stoi tylko po to, żeby komunikat trafił tam, gdzie jest
             * robota do zrobienia.
             */
            if ($steps === [] && $this->input('action') !== 'draft') {
                throw ValidationException::withMessages([
                    'przygotowanie_tekst' => 'Napisz, co się po kolei robi — bez tego nikt nie ugotuje tego przepisu. Wystarczy jedno zdanie.',
                ]);
            }

            if (count($steps) > Recipe::MAX_STEPS) {
                throw ValidationException::withMessages([
                    'przygotowanie_tekst' => 'To bardzo dużo kroków — zmieść się w '
                        .Recipe::MAX_STEPS.' krokach. Pusta linijka zaczyna nowy krok, więc sprawdź, czy nie ma ich za dużo.',
                ]);
            }
        } else {
            // KLUCZE ZOSTAJĄ TAKIE, JAK W ŻĄDANIU — bez `array_values()`.
            // Po nich `zdjeciaKrokow()` szuka pliku (`steps.3.photo`)
            // i po nich adresuje komunikat błędu, a widok wypisuje go przez
            // `@error("steps.3.photo")` z numerem WIERSZA FORMULARZA. Gdyby
            // klucze zostały tu przenumerowane, plik z wiersza o numerze
            // nieciągłym nie zostałby znaleziony, a błąd wylądowałby pod
            // cudzym wierszem. Kolejność zapisu bierze się z kolejności
            // elementów tablicy, nie z wartości kluczy, więc numeracja
            // pozycji w bazie na tym nie traci.
            $steps = array_map(
                static fn (array $row): array => [
                    'id' => $row['id'] ?? null,
                    'instruction' => $row['instruction'] ?? '',
                    'timer_minutes' => $row['timer_minutes'] ?? null,
                    // ZAWSZE null: identyfikator zdjęcia NIE JEST polem tego
                    // formularza i nie ma go w regułach walidacji wyżej.
                    // Wypełnia go wyłącznie `ZapiszPrzepisZFormularza` — z pliku,
                    // który naprawdę przyszedł w TYM żądaniu. Ukryte pole
                    // z `media_id` dałoby klientowi możliwość podania cudzego
                    // identyfikatora; tożsamość, której formularz potrzebuje,
                    // niesie `id` KROKU, a to jest dopasowywane wyłącznie
                    // wewnątrz tego przepisu.
                    'media_id' => null,
                    'remove_media' => (bool) ($row['remove_photo'] ?? false),
                ],
                $data['steps'] ?? [],
            );
        }

        return [
            'recipe' => [
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'servings' => $data['servings'] ?? null,
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'cook_minutes' => $data['cook_minutes'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
                'visibility' => $data['visibility'],
                'source_type' => $data['source_type'] ?? null,
                'source_person' => $data['source_person'] ?? null,
                'source_note' => $data['source_note'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'family_since_year' => $data['family_since_year'] ?? null,
            ],
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
    }

    /** Identyfikator wpisu z „Dopisz przepis” (#1334) albo `null`. */
    public function zWpisu(): ?string
    {
        $id = $this->validated('z_wpisu');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Plik z pola, ale tylko taki, który `hasFile()` uznaje za przesłany —
     * dokładnie ten sam warunek, który kontroler sprawdzał przed #970.
     */
    public function przeslanyPlik(string $pole): ?UploadedFile
    {
        if (! $this->hasFile($pole)) {
            return null;
        }

        $plik = $this->file($pole);

        return $plik instanceof UploadedFile ? $plik : null;
    }

    /**
     * Zdjęcia kroków pod TYMI SAMYMI kluczami, co wiersze z `daneZapisu()`.
     *
     * Klucze są numerami wierszy formularza (`steps.3.photo`), więc plik
     * z wiersza o numerze nieciągłym trafia do swojego kroku, a błąd — pod
     * swój wiersz.
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     * @return array<array-key, UploadedFile>
     */
    public function zdjeciaKrokow(array $steps): array
    {
        $zdjecia = [];

        foreach (array_keys($steps) as $index) {
            $plik = $this->przeslanyPlik("steps.{$index}.photo");

            if ($plik !== null) {
                $zdjecia[$index] = $plik;
            }
        }

        return $zdjecia;
    }
}
