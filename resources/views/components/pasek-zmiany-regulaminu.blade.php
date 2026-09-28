{{--
    Pasek „Zmieniliśmy regulamin" (#1811, D-306, D-327).

    Decyzja właściciela z 26.09.2026: zmianę regulaminu ogłaszamy komunikatem
    w serwisie, bez maili. Kto i kiedy go widzi, rozstrzyga
    `App\Domain\Zgody\ZmianaRegulaminu::pokazac()` — tu jest tylko wygląd.

    Zmiana ISTOTNA (D-327): pasek stoi od dnia publikacji i mówi, od kiedy
    obowiązuje nowa wersja; do tego dnia dopisuje, że obowiązuje poprzednia.
    Zmiana DROBNA obowiązuje od razu, więc pasek nie podaje żadnego terminu.

    Dwie drogi, obie z napisem (AGENTS.md §5: ikona nie jest jedynym opisem):
    odnośnik do regulaminu, gdzie na górze stoi „Co się zmieniło", i przycisk
    „Zamknij". Zamknięcie to formularz POST — działa bez JavaScriptu i nie da
    się go wywołać samym podglądem linku. `role="status"`, nie `alert`:
    to wiadomość, nie błąd.
--}}
@php
    $zmianaRegulaminu = app(\App\Domain\Zgody\ZmianaRegulaminu::class);
@endphp
@if($zmianaRegulaminu->pokazac(auth()->user()))
    @php
        $wersjaRegulaminu = $zmianaRegulaminu->dokument();
    @endphp
    <div class="notice pasek-zmiany-regulaminu" role="status" data-pasek-zmiany-regulaminu>
        <p class="m-0">
            <strong>Zmieniliśmy regulamin.</strong>
            @if($wersjaRegulaminu->istotna)
                @if($wersjaRegulaminu->wOkresiePrzejsciowym())
                    <span data-pasek-termin>Nowa wersja obowiązuje od {{ \App\Support\Czas::data($wersjaRegulaminu->obowiazujeOd()) }}. Do tego dnia obowiązuje poprzednia.</span>
                @else
                    <span data-pasek-termin>Nowa wersja obowiązuje od {{ \App\Support\Czas::data($wersjaRegulaminu->obowiazujeOd()) }}.</span>
                @endif
            @endif
            <a href="{{ route('terms') }}#co-sie-zmienilo">Zobacz, co się zmieniło</a>
        </p>
        <form method="POST" action="{{ route('terms.notice.dismiss') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Zamknij</button>
        </form>
    </div>
@endif
