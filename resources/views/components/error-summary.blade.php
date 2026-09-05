{{--
    Podsumowanie błędów na górze formularza + link do każdego pola.
    Wzorzec z UX_50_PLUS.md: błąd przy polu ORAZ podsumowanie, nigdy tylko
    jedno z dwóch. Poprawnie wpisane dane nie znikają (formularze używają
    old(), patrz x-field).
--}}
@if($errors->any())
    <div class="error-summary" role="alert" tabindex="-1">
        <p class="error-summary-title">
            @if($errors->count() === 1)
                Jednej rzeczy jeszcze brakuje
            @else
                Kilku rzeczy jeszcze brakuje
            @endif
        </p>
        <ul>
            @foreach($errors->keys() as $key)
                <li>
                    <a href="#f-{{ str_replace(['[', ']', '.'], '-', $key) }}">{{ $errors->first($key) }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
