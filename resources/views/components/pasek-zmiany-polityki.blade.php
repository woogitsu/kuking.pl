{{--
    Pasek „Zmieniliśmy politykę prywatności” (D-327, D-332).

    Bliźniak `pasek-zmiany-regulaminu`. Kto i kiedy go widzi, rozstrzyga
    `App\Domain\Zgody\ZmianaPolityki::pokazac()` — tu jest tylko wygląd.
    Zmiana ISTOTNA: pasek stoi od dnia publikacji, mówi od kiedy obowiązuje
    nowa wersja i że do tego dnia obowiązuje poprzednia. Zmiana DROBNA
    obowiązuje od razu i pasek nie podaje terminu.

    Dwie drogi, obie z napisem (AGENTS.md §5): odnośnik do polityki, gdzie
    na górze stoi „Co się zmieniło”, i przycisk „Zamknij” (formularz POST,
    działa bez JavaScriptu). `role="status"`, nie `alert`.
--}}
@php
    $zmianaPolityki = app(\App\Domain\Zgody\ZmianaPolityki::class);
@endphp
@if($zmianaPolityki->pokazac(auth()->user()))
    @php
        $wersjaPolityki = $zmianaPolityki->dokument();
    @endphp
    <div class="notice pasek-zmiany-regulaminu" role="status" data-pasek-zmiany-polityki>
        <p class="m-0">
            <strong>Zmieniliśmy politykę prywatności.</strong>
            @if($wersjaPolityki->istotna)
                @if($wersjaPolityki->wOkresiePrzejsciowym())
                    <span data-pasek-termin>Nowa wersja obowiązuje od {{ \App\Support\Czas::data($wersjaPolityki->obowiazujeOd()) }}. Do tego dnia obowiązuje poprzednia.</span>
                @else
                    <span data-pasek-termin>Nowa wersja obowiązuje od {{ \App\Support\Czas::data($wersjaPolityki->obowiazujeOd()) }}.</span>
                @endif
            @endif
            <a href="{{ route('privacy') }}#co-sie-zmienilo">Zobacz, co się zmieniło</a>
        </p>
        <form method="POST" action="{{ route('privacy.notice.dismiss') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Zamknij</button>
        </form>
    </div>
@endif
