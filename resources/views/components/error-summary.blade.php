@props(['targets' => []])
{{--
    Podsumowanie błędów na górze formularza + link do każdego pola.
    Wzorzec z UX_50_PLUS.md: błąd przy polu ORAZ podsumowanie, nigdy tylko
    jedno z dwóch. Poprawnie wpisane dane nie znikają (formularze używają
    old(), patrz x-field).

    STRONA Z WIELOMA FORMULARZAMI TEGO SAMEGO KSZTAŁTU (issue #243)
    Gdy strona stawia to samo pole w pętli (jeden formularz na sprawę),
    `x-field` dokłada do `id` identyfikator wiersza — patrz `:wiersz` w tamtym
    komponencie i `App\Support\WierszFormularza`. Ten komponent dogaduje
    dokładnie ten sam dopisek z ukrytego pola `_wiersz`, żeby link prowadził
    do WŁAŚCIWEGO pola: tego z formularza, który naprawdę wrócił z błędem,
    a nie do pierwszego pola o tej nazwie w dokumencie. Strona bez pola
    `_wiersz` (zwykły, pojedynczy formularz) zachowuje się jak dawniej —
    `old('_wiersz')` jest wtedy puste i dopisek znika.
--}}
@php
    $wierszSufiks = old(\App\Support\WierszFormularza::POLE) !== null
        ? '-'.str_replace(['[', ']', '.'], '-', (string) old(\App\Support\WierszFormularza::POLE))
        : '';
@endphp
@if($errors->any())
    <div class="error-summary" role="alert" tabindex="-1">
        <p class="error-summary-title">
            Sprawdź formularz
        </p>
        <ul>
            @foreach($errors->keys() as $key)
                <li>
                    <a href="#f-{{ str_replace(['[', ']', '.'], '-', $targets[$key] ?? $targets[explode('.', $key)[0].'.*'] ?? $key) }}{{ $wierszSufiks }}">{{ $errors->first($key) }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
