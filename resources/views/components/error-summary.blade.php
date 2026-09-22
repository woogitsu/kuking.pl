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
@props(['errorBag' => 'default', 'fieldIds' => []])
@php
    // DWA RODZAJE `$errors`, OBA POPRAWNE — nie zakładamy jednego.
    //
    // Zwykle `$errors` to współdzielony `ViewErrorBag` Laravela i wtedy
    // wybieramy z niego worek formularza (`$errorBag`, np. osobne formularze
    // 2FA na jednym ekranie). Ale wyszukiwarka i onboarding dołączają ten
    // komponent przez `@include(..., ['errors' => $searchErrors])` z ZWYKŁYM
    // `MessageBag` z walidatora frazy — bez przekierowania, w miejscu. Ten
    // nie ma `getBag()`, więc bezwarunkowe wywołanie kończyło się 500 na
    // KAŻDYM wyszukiwaniu, także dla gościa. Zwykły worek przyjmujemy więc
    // w całości, tak jak przed wprowadzeniem `$errorBag`.
    $formErrors = $errors instanceof \Illuminate\Support\ViewErrorBag
        ? $errors->getBag($errorBag)
        : $errors;
    // `aktywnyWiersz()` odrzuca `_wiersz` przesłane jako tablica/obiekt
    // zamiast rzutować je wprost na string — inaczej ten sam błąd renderu
    // co w x-field (issue #745), tyle że tu, w podsumowaniu błędów.
    $aktywnyWiersz = \App\Support\WierszFormularza::aktywnyWiersz();
    $wierszSufiks = $aktywnyWiersz !== null
        ? '-'.str_replace(['[', ']', '.'], '-', $aktywnyWiersz)
        : '';
@endphp
@if($formErrors->any())
    <div class="error-summary" role="alert" tabindex="-1">
        <p class="error-summary-title">
            Sprawdź formularz
        </p>
        <ul>
            @foreach($formErrors->keys() as $key)
                <li>
                    <a href="#{{ $fieldIds[$key] ?? 'f-'.str_replace(['[', ']', '.'], '-', $key).$wierszSufiks }}">{{ $formErrors->first($key) }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
