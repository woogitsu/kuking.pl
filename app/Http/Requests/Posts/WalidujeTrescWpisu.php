<?php

declare(strict_types=1);

namespace App\Http\Requests\Posts;

/**
 * Reguły i komunikaty treści wpisu wspólne dla zapisu (`ZapisWpisuRequest`)
 * i edycji (`EdycjaWpisuRequest`) — jedno miejsce zamiast dwóch kopii, które
 * mogłyby się rozjechać (issue #970, krok 4). Komunikaty są dokładnie te,
 * które wcześniej stały w kontrolerze.
 */
trait WalidujeTrescWpisu
{
    /**
     * @param  bool  $pytanie  tytuł jest wymagany tylko przy pytaniu
     * @param  bool  $widocznoscZFormularza  pytanie przy zapisie nie przyjmuje pola widoczności
     * @return array<string, list<string>>
     */
    protected static function regulyTresci(bool $pytanie, bool $widocznoscZFormularza): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4000'],
            'visibility' => $widocznoscZFormularza ? ['required', 'in:public,followers,private'] : ['exclude'],
            'title' => $pytanie ? ['required', 'string', 'min:10', 'max:180'] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function komunikatyTresci(): array
    {
        return [
            'body.max' => 'Ten wpis jest za długi. Zmieść się w 4000 znakach.',
            'title.required' => 'Napisz pytanie w tytule.',
            'title.min' => 'Rozwiń pytanie do co najmniej 10 znaków.',
            'title.max' => 'Skróć tytuł pytania do 180 znaków.',
            'visibility.required' => 'Zaznacz, kto ma widzieć ten wpis.',
            // `in` mówi, CO WYBRAĆ, nie że „wybrana wartość jest
            // nieprawidłowa" (issue #86) — trzy opcje z ekranu, wprost.
            'visibility.in' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
        ];
    }
}
