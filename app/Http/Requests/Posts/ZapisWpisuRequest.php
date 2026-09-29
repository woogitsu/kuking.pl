<?php

declare(strict_types=1);

namespace App\Http\Requests\Posts;

use App\Models\Post;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * Wejście formularza „Dodaj zdjęcie" (`posts.store`) i „Zadaj pytanie"
 * (`questions.store`) — wyjęte z `PostController::store()` bez zmiany
 * zachowania (issue #970, krok 3): te same reguły, te same komunikaty,
 * ta sama kolejność.
 *
 * Walidacja idzie w DWÓCH fazach i tak ma zostać:
 *
 *  1. `rules()` / `messages()` — tylko zdjęcia. Laravel sprawdza je, zanim
 *     wejdziemy do kontrolera. To jest świadomie PIERWSZE: zdjęcia trafiają
 *     na dysk przed walidacją reszty, żeby błąd treści nie zabrał wyboru
 *     z galerii (audyt C1).
 *  2. `walidatorTresci()` — treść, widoczność i tytuł pytania. Woła ją
 *     kontroler dopiero po wgraniu zdjęć i obsłużeniu przycisków tagów, bo
 *     wtedy pola mogą być jeszcze puste. To NIE jest hak `after()`: błąd
 *     treści ma wrócić z `old()` zawierającym już UUID-y zdjęć i tagi,
 *     a tego domyślne przekierowanie FormRequestu nie umie.
 */
final class ZapisWpisuRequest extends FormRequest
{
    public function pytanie(): bool
    {
        return $this->routeIs('questions.store');
    }

    public function authorize(): bool
    {
        abort_if($this->pytanie() && ! config('kuking.questions.enabled'), 404);

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:'.($this->pytanie() ? 1 : LimityZdjec::maksZdjecNaWysylke())],
            'photos.*' => ['file', new ObslugiwaneZdjecie(komunikatZaDuzyPlik: $this->bladRozmiaruZdjecia()), 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
            'media_ids' => ['nullable', 'array', 'max:'.LimityZdjec::maksZdjecNaWysylke()],
            'media_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.*.image' => 'Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.',
            'photos.*.max' => $this->bladRozmiaruZdjecia(),
            // Zwykły formularz nie wysyła tu nic poza UUID-ami zachowanych
            // zdjęć; zepsuta wartość nie może skończyć się „musi być UUID"
            // (issue #871).
            'media_ids.*.uuid' => LimityZdjec::komunikatZepsutegoZachowanegoZdjecia(),
            'photos.max' => $this->pytanie() ? 'Do pytania wybierz jedno zdjęcie.' : LimityZdjec::komunikatZaDuzoZdjec(),
        ];
    }

    /**
     * Faza 2: treść, widoczność, tytuł pytania.
     *
     * Zwraca walidator, a nie rzuca wyjątku — kontrola nad tym, co trafia
     * do starego wejścia (`media_ids`, `tag_names`), zostaje w kontrolerze.
     */
    public function walidatorTresci(): Validator
    {
        $pytanie = $this->pytanie();

        return ValidatorFactory::make($this->all(), [
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => $pytanie ? ['exclude'] : ['required', 'in:public,followers,private'],
            'title' => $pytanie ? ['required', 'string', 'min:10', 'max:180'] : ['exclude'],
        ], [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'title.required' => 'Napisz pytanie w tytule.',
            'title.min' => 'Rozwiń pytanie do co najmniej 10 znaków.',
            'title.max' => 'Skróć tytuł pytania do 180 znaków.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ]);
    }

    /**
     * Widoczność, z jaką powstaje wpis: pytanie jest zawsze publiczne
     * i nie przyjmuje pola widoczności z formularza.
     *
     * @param  array<string, mixed>  $dane  wynik `walidatorTresci()->validated()`
     */
    public function widocznosc(array $dane): string
    {
        return $this->pytanie() ? Post::VISIBILITY_PUBLIC : $dane['visibility'];
    }

    /**
     * Stare wejście formularza: wszystko poza plikami, plus identyfikatory
     * zdjęć, które już są na dysku.
     *
     * Pliki lecą do kosza świadomie — `withInput()` i tak ich nie przeniesie,
     * a `UploadedFile` w sesji wskazuje plik tymczasowy, którego po żądaniu
     * już nie ma.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, mixed>
     */
    public function wejscieBezPlikow(array $mediaIds): array
    {
        return $this->except('photos', 'media_ids') + ['media_ids' => $mediaIds];
    }

    /**
     * To samo co `wejscieBezPlikow()`, plus zachowana lista tagów — dwa
     * niezależne mechanizmy ratowania danych (identyfikatory zdjęć kontra
     * wolny tekst).
     *
     * UWAGA NA `+`: operator sumy tablic zachowuje wartość z LEWEJ strony
     * przy zbieżnych kluczach, więc `tag_names` musi zniknąć z lewej strony
     * PRZED złożeniem — inaczej stara lista (sprzed „Dodaj"/„Usuń")
     * wygrałaby z nową.
     *
     * @param  list<string>  $mediaIds
     * @param  list<string>  $tagNames
     * @return array<string, mixed>
     */
    public function wejscieBezPlikowITagow(array $mediaIds, array $tagNames): array
    {
        return $this->except('photos', 'media_ids', 'tag_names')
            + ['media_ids' => $mediaIds, 'tag_names' => $tagNames];
    }

    private function bladRozmiaruZdjecia(): string
    {
        return 'Jedno ze zdjęć waży za dużo. Wybierz ponownie wszystkie nowe zdjęcia — każde do '
            .LimityZdjec::maksMegabajtowDoKomunikatu().' MB.';
    }
}
