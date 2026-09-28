{{--
    Issue #982: poprawkę odrzucono, bo komentarz zmienił się w innej karcie.
    Stoi w otwartym „Popraw” tuż nad polem: zapisana treść tutaj, tekst
    z formularza w polu niżej (`old('body')`) — nic nie ginie. `id` to ten sam
    `f-wersja-<wiersz>`, który buduje `x-error-summary` dla klucza `wersja`,
    więc link z podsumowania błędów prowadzi właśnie tu.
--}}
@props(['comment', 'wiersz'])
@if(\App\Support\WierszFormularza::jestAktywny($wiersz) && $errors->has('wersja'))
    <section class="notice stack mt-2" id="f-wersja-{{ $wiersz }}" tabindex="-1" aria-labelledby="f-wersja-{{ $wiersz }}-tytul">
        <h3 id="f-wersja-{{ $wiersz }}-tytul">Tak ten komentarz jest zapisany teraz</h3>
        <p class="whitespace-pre-line">{{ $comment->body }}</p>
        <p>{{ $errors->first('wersja') }}</p>
    </section>
@endif
