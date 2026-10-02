<?php

declare(strict_types=1);

namespace App\Http\Requests\Cooked;

use App\Domain\Recipes\Gotowanie\DzienGotowania;
use App\Domain\Recipes\Gotowanie\PorcjeWykonania;
use App\Models\Recipe;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Support\Str;

/**
 * Wejście formularza „Ugotowałem" (`cooked.store`) — wyjęte
 * z `CookedEventController::store()` bez zmiany zachowania (issue #970).
 *
 * Kolejność zostaje ta sama co w kontrolerze: NAJPIERW przepis i Policy
 * (`authorize()`), potem — już w kontrolerze — rozpoznanie ponowionego
 * wysłania, i dopiero dwie fazy walidacji. `rules()` jest CELOWO puste:
 * gdyby zdjęcia były tu, Laravel sprawdziłby je przed rozpoznaniem
 * ponowienia, a ono ma iść pierwsze (#873).
 *
 *  1. `walidujZdjecia()` — tylko zdjęcia. Idą przed resztą pól, bo poprawne
 *     zdjęcia zapisują się na dysk PRZED walidacją treści i wracają
 *     jako identyfikatory (audyt C1, issue #872: poprawne dane nigdy nie
 *     znikają).
 *  2. `walidatorPol()` — notatki, czas, trudność, „zrobię ponownie". Zwraca
 *     walidator, a nie rzuca wyjątku: `$request->validate()` odesłałby stare
 *     dane i nadpisał nimi `media_ids` z nowo zapisanymi zdjęciami.
 */
final class ZapisWykonaniaRequest extends FormRequest
{
    private ?Recipe $przepis = null;

    public function authorize(): bool
    {
        $slug = $this->route('recipe');

        // Trasa `cooked.store` zawsze niesie slug; co innego to nie ten adres.
        if (! is_string($slug)) {
            abort(404);
        }

        $this->przepis = Recipe::where('slug', $slug)->firstOrFail();

        Gate::inspect('cook', $this->przepis)->authorize();

        return true;
    }

    /**
     * Przepis z adresu — dostępny dopiero po `authorize()`, czyli w każdym
     * kontrolerze, który dostał ten Request.
     */
    public function przepis(): Recipe
    {
        return $this->przepis ?? abort(404);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Klucz wysłania z żądania. Wartość niebędąca UUID-em schodzi do `null`,
     * czyli do „zapisz normalnie" — zawodzimy otwarcie, nie zamknięcie
     * (ADR §4.3).
     */
    public function kluczWyslania(): ?string
    {
        // Wyłącznik awaryjny — TA SAMA bramka, co przy renderowaniu formularza.
        // Bez niej wyłącznik działa tylko w połowie: karta otwarta PRZED
        // przełączeniem nadal niesie klucz w DOM-ie i odsyła go, więc częściowy
        // indeks dalej obowiązuje — dokładnie w tej awarii, dla której ten
        // wyłącznik istnieje. `config/kuking.php` obiecuje, że po wyłączeniu
        // „kolumna dostaje NULL"; ta linijka jest tym, co tę obietnicę dowozi.
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $klucz = $this->input('klucz_wyslania');

        return is_string($klucz) && Str::isUuid($klucz) ? $klucz : null;
    }

    /**
     * Wersja przepisu, którą formularz pokazał w chwili otwarcia (#2378).
     * Nie-UUID schodzi do `null`; czy wersja należy do przepisu, sprawdza
     * `RecordCookedEvent`.
     */
    public function wersjaPrzepisu(): ?string
    {
        $wersja = $this->input('wersja_przepisu');

        return is_string($wersja) && Str::isUuid($wersja) ? $wersja : null;
    }

    /**
     * Faza 1: zdjęcia. Rzuca `ValidationException` jak `$request->validate()`.
     */
    public function walidujZdjecia(): void
    {
        ValidatorFactory::make($this->all(), [
            // BYŁO "max:4" wpisane na sztywno, niezależnie od
            // `config('kuking.media.max_per_post')` — dokładnie ten rozjazd
            // (ta sama liczba w dwóch miejscach) pozwolił na wysyłkę do
            // 4 × 15 MB = 60 MB w jednym żądaniu, ponad dwa razy więcej,
            // niż mieści `post_max_size` z `docker/php.ini` (audyt A31).
            // Teraz obowiązuje TEN SAM budżet co w PostController.
            'photos' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'photos.*' => ['file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'media_ids' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'media_ids.*' => ['uuid'],
        ], [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            // Wcześniej nie było tu komunikatu — przy przekroczeniu rozmiaru
            // albo liczby zdjęć człowiek widziałby domyślny, angielski
            // komunikat Laravela. To łamie "błędy po polsku" z AGENTS.md.
            'photos.*.max' => LimityZdjec::komunikatZaDuzyPlik(),
            'photos.max' => LimityZdjec::komunikatZaDuzoZdjec(),
            'media_ids.*.uuid' => LimityZdjec::komunikatZepsutegoZachowanegoZdjecia(),
        ])->validate();
    }

    /**
     * Faza 2: pozostałe pola.
     */
    public function walidatorPol(): Validator
    {
        return ValidatorFactory::make($this->all(), [
            'note' => ['nullable', 'string', 'max:2000'],
            'changes_note' => ['nullable', 'string', 'max:1000'],
            'would_make_again' => ['nullable', 'boolean'],
            'perceived_difficulty' => ['nullable', 'in:easy,medium,hard'],
            'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            // Prywatny dzień gotowania (#2583). Jedna reguła w `DzienGotowania`
            // (też w akcji), z komunikatem mówiącym, co zrobić.
            'dzien_gotowania' => ['nullable', 'string', function (string $pole, mixed $wartosc, \Closure $niepowodzenie): void {
                $blad = is_string($wartosc) ? DzienGotowania::blad(trim($wartosc)) : DzienGotowania::KOMUNIKAT_NIEZROZUMIALY;

                if ($blad !== null) {
                    $niepowodzenie($blad);
                }
            }],
            // Prywatna liczba faktycznych porcji (#2540). Jedna reguła w
            // `PorcjeWykonania` (też w akcji), z komunikatem mówiącym, co zrobić.
            'faktyczne_porcje' => ['nullable', 'string', function (string $pole, mixed $wartosc, \Closure $niepowodzenie): void {
                $blad = is_string($wartosc) ? PorcjeWykonania::blad(trim($wartosc)) : PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY;

                if ($blad !== null) {
                    $niepowodzenie($blad);
                }
            }],
        ], [
            'faktyczne_porcje.string' => PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY,
            'dzien_gotowania.string' => DzienGotowania::KOMUNIKAT_NIEZROZUMIALY,
            'note.max' => 'Ta uwaga jest za długa. Zmieść się w 2000 znakach.',
            'changes_note.max' => 'To jest za długie. Zmieść się w 1000 znakach.',
            // `in` ma mówić, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — to pole renderuje się jako
            // trzy przyciski, więc zdanie wymienia dokładnie te trzy.
            'perceived_difficulty.in' => 'Wybierz, jak trudny był ten przepis: łatwy, średni albo trudny.',
            // Trzy komunikaty z przeglądu komunikatów: `would_make_again`
            // bez własnego zdania dostawał szablon reguły `boolean` z nazwą
            // pola zawierającą cudzysłów drukarski (cudzysłów w cudzysłowie),
            // a `actual_minutes` mówił „musi być nie mniejsze niż 0" i nazywał
            // pole inaczej niż etykieta na ekranie. Teraz zdania wymieniają
            // to, co człowiek widzi: dwa przyciski i „Ile Ci to zajęło".
            'would_make_again.boolean' => 'Zaznacz jedną z odpowiedzi: „Tak, zrobię ponownie” albo „Raczej nie powtórzę”.',
            'actual_minutes.integer' => 'Wpisz sam czas w minutach, samymi cyframi — na przykład 90.',
            'actual_minutes.min' => 'Czas nie może być ujemny. Wpisz liczbę minut, na przykład 90.',
            'actual_minutes.max' => 'Ten czas jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
        ]);
    }

    /**
     * Stare dane do formularza bez plików, z listą zdjęć, które już leżą na
     * serwerze. `usun_zdjecie` nie wraca — to był jednorazowy przycisk.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, mixed>
     */
    public function wejscieBezPlikow(array $mediaIds): array
    {
        return $this->except('photos', 'media_ids', 'usun_zdjecie') + ['media_ids' => $mediaIds];
    }
}
