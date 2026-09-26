{{--
    Pasek „Zmieniliśmy regulamin" (#1811, D-306).

    Decyzja właściciela z 26.09.2026: zmianę regulaminu ogłaszamy komunikatem
    w serwisie, bez maili. Kto i kiedy go widzi, rozstrzyga
    `App\Domain\Zgody\ZmianaRegulaminu::pokazac()` — tu jest tylko wygląd.

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
    <div class="notice pasek-zmiany-regulaminu" role="status" data-pasek-zmiany-regulaminu>
        <p class="m-0">
            <strong>Zmieniliśmy regulamin.</strong>
            <a href="{{ route('terms') }}#co-sie-zmienilo">Zobacz, co się zmieniło</a>
        </p>
        <form method="POST" action="{{ route('terms.notice.dismiss') }}">
            @csrf
            <button class="btn btn-secondary" type="submit">Zamknij</button>
        </form>
    </div>
@endif
