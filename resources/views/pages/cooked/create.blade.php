@php $napis = \App\Support\Forma::dla(auth()->user(), 'Ugotowałam', 'Ugotowałem', 'Ugotowałem'); @endphp
<x-layout title="{{ $napis }}" :noindex="true">
    <h1>{{ $napis }}: {{ $recipe->title }}</h1>
    <p class="mb-5">
        @if($recipe->author_id !== auth()->id() && $recipe->author->mozeCzytac())
            {{ $recipe->author->displayName() }} dowie się, że ktoś ugotował z tego przepisu.
        @else
            Zapisz wykonanie tego przepisu.
        @endif
        <strong>Nie musisz wypełniać żadnego pola</strong> — wystarczy, że klikniesz „Wyślij”.
    </p>

    {{-- Błąd pojedynczego pliku ma klucz `photos.0`, a pole plików jest jedno:
         `f-photos` (issue #874). --}}
    <x-error-summary :field-ids="['photos.*' => 'f-photos', 'media_ids' => 'f-photos', 'media_ids.*' => 'f-photos']" />

    <form class="panel-formularza" method="POST" action="{{ route('cooked.store', $recipe->slug) }}" enctype="multipart/form-data" novalidate>
        @csrf

        {{-- Tożsamość TEGO wysłania formularza (ADR
             docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md). Bez niej dwa
             kliknięcia „Wyślij" dawały dwa wykonania i DWA powiadomienia
             u autora — a powiadomienia nie da się cofnąć.

             To NIE jest unikalność na parze (osoba, przepis): drugie
             prawdziwe gotowanie przychodzi z nowego formularza, więc z nowym
             kluczem, i zapisuje się normalnie (D-005).

             Zwykłe ukryte pole, bez JavaScriptu. Nazwa bez fragmentu
             „token", inaczej pole ginie na ekranie 419 (ADR §1.4.4). --}}
        @if(($kluczWyslania ?? null) !== null)
            <input type="hidden" name="klucz_wyslania" value="{{ $kluczWyslania }}">
        @endif

        {{-- Wersja przepisu otwarta przy tym formularzu (#2378). Zwykłe ukryte
             pole, bez JavaScriptu. Serwer sprawdza, że należy do tego przepisu. --}}
        @if(($wersjaPrzepisu ?? null) !== null)
            <input type="hidden" name="wersja_przepisu" value="{{ $wersjaPrzepisu }}">
        @endif

        {{-- KTO TO ZOBACZY — PRZED PIERWSZYM POLEM, NIE DOPIERO PRZY „WYŚLIJ" (#2071).

             Wykonanie nie ma własnej widoczności: `CookedEventPolicy::view()`
             oddaje decyzję `RecipePolicy::view()` dla powiązanego przepisu
             (blokada z kucharzem i konto kucharza mogą ją tylko zawęzić —
             stąd „mogą zobaczyć”, a nie „zobaczą”). Zdanie mówi więc
             o odbiorcach przepisu, a nie o „autorze", i nie udaje wyboru
             prywatności, którego ten formularz nie ma. Moderatorów wymienia
             wprost (przegląd PR #2156): `CookedEventPolicy` wpuszcza ich
             z urzędu także do wykonania przepisu prywatnego, którego
             `RecipePolicy::view()` im nie pokazuje.

             Stoi nad zdjęciem i notatką, bo formularz wypełnia się z góry na
             dół: informacja pod ostatnim polem przychodziła, gdy osobista
             uwaga była już napisana. Zgodność zdania z Policy dla każdej
             widoczności przepisu pilnuje `KomunikatUgotowalemMowiPrawdeTest`. --}}
        <p class="notice">
            <strong>Kto to zobaczy?</strong>
            Twoje wykonanie, zdjęcia i odpowiedzi mogą zobaczyć osoby, które mogą zobaczyć ten przepis. Dostęp do nich mogą mieć także moderatorzy Kuking.
        </p>

        {{-- Ten sam obszar wyboru zdjęcia co na „Dodaj zdjęcie" i w formularzu
             przepisu (`.pole-zdjecia`, resources/css/ekran-dodawania.css).
             Do tej zmiany stał tu goły `<input type="file">` z angielskim
             „Choose File / No file chosen". Po D-035 pole jest schowane dla
             oka, ale zostaje pod klawiaturą i w drzewie dostępności, a klikalna
             jest etykieta. `<input>` MUSI stać bezpośrednio przed `<label>` —
             obwódkę fokusu rysuje reguła sąsiedztwa. --}}
        <div class="field @error('photos') has-error @enderror @error('photos.*') has-error @enderror">
            <span class="pole-zdjecia-nazwa" id="f-photos-etykieta">Zdjęcie tego, co Ci wyszło</span>

            {{-- Zdjęcia, które przetrwały błąd innego pola (issue #872).
                 Przeglądarka nie pozwala wypełnić pola pliku z serwera, więc
                 wracają jako identyfikatory. Listę przygotowuje kontroler
                 i przepuszcza przez bramkę właściciela (issue #871). --}}
            @if(($zachowane ?? collect())->isNotEmpty())
                <div class="notice">
                    <strong>Twoje zdjęcia są zachowane.</strong>
                    Nie musisz wybierać ich jeszcze raz — popraw tylko to, co wypisaliśmy na górze formularza.
                    <ul class="stack-tight lista-naga mt-3">
                        @foreach($zachowane as $zdjecie)
                            <li>
                                <input type="hidden" name="media_ids[]" value="{{ $zdjecie->getKey() }}">
                                <x-photo :media="$zdjecie" variant="thumb" :zoom="false" />
                                <button class="btn btn-quiet" type="submit" name="usun_zdjecie" value="{{ $zdjecie->getKey() }}" formnovalidate>Usuń to zdjęcie</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Przy błędzie opis pola rośnie o TREŚĆ BŁĘDU (issue #1572),
                 żeby czytnik ekranu po przejściu z podsumowania do pola
                 przeczytał, co jest nie tak. Pomoc zostaje pierwsza. --}}
            @php
                $opisZdjec = implode(' ', array_keys(array_filter([
                    'f-photos-help' => true,
                    'f-photos-error' => $errors->has('photos'),
                    'f-photos-plik-error' => $errors->has('photos.*'),
                    'f-media-ids-error' => $errors->has('media_ids.*'),
                ])));
                $bladZdjec = $errors->has('photos') || $errors->has('photos.*') || $errors->has('media_ids.*');
            @endphp
            <input class="visually-hidden pole-zdjecia-input" id="f-photos" type="file" name="photos[]"
                   accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                   multiple
                   data-usuwanie-zdjec
                   aria-labelledby="f-photos-etykieta f-photos-tytul"
                   aria-describedby="{{ $opisZdjec }}" @if($bladZdjec) aria-invalid="true" @endif>
            <label class="pole-zdjecia" for="f-photos">
                <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                <span class="pole-zdjecia-tytul" id="f-photos-tytul">Dodaj zdjęcie</span>
                <span class="field-help" id="f-photos-help">
                    To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne.
                    {{ \App\Support\LimityZdjec::pomocLiczbyZdjec(($zachowane ?? collect())->count()) }}
                    Największy plik: {{ \App\Support\LimityZdjec::maksMegabajtowDoKomunikatu() }} MB.
                </span>
            </label>
            @error('photos')<span class="field-error" id="f-photos-error">{{ $message }}</span>@enderror
            @error('photos.*')<span class="field-error" id="f-photos-plik-error">{{ $message }}</span>@enderror
            @error('media_ids.*')<span class="field-error" id="f-media-ids-error">{{ $message }}</span>@enderror
        </div>

        <x-field name="note" label="Jak wyszło?" type="textarea" :rows="4"
                 help="Na przykład: „Wyszło pięknie, tylko soli mniej.”" />

        {{-- POMOC MÓWI, CO TU WPISAĆ I GDZIE TO TRAFI, A NIE JAK CZĘSTO
             KTOŚ TO CZYTA.

             Stało tu „To najczęściej czytana część." — trzecie i ostatnie
             miejsce tego samego niezmierzonego twierdzenia (dwa pozostałe:
             `components/recipe-wizard.blade.php` i
             `pages/recipes/create.blade.php`). Nikt nigdy nie mierzył, co
             w cudzym wykonaniu czyta się najczęściej, a tutaj zdanie było
             dodatkowo mylące: „część" znaczyło raz część przepisu, raz część
             tego formularza.

             Nowe zdanie mówi rzecz sprawdzalną przy kodzie: notatka trafia na
             kartę wykonania (`components/cooked-card.blade.php`), podpisana
             dokładnie tak. --}}
        {{-- PRYWATNY DOPISEK Z GOTOWANIA (#2587): tylko propozycja. Nic nie
             trafia do pola samo — ani do wykonania, ani do autora przepisu.
             Pole poniżej jest widoczne przy wykonaniu jako „Po swojemu”,
             a dopisek do tej chwili widziała tylko osoba, która go zapisała,
             więc mówimy to wprost, zanim zapadnie decyzja. --}}
        @if($dopisek['stan'] === 'propozycja')
            <div class="stack" role="group" aria-label="Prywatny dopisek z gotowania">
                <p class="m-0"><strong>Masz prywatny dopisek z gotowania:</strong> {{ $dopisek['tresc'] }}</p>
                <p class="m-0">Dotąd ten dopisek jest prywatny: widzisz go tylko Ty. Jeśli go wstawisz do pola „Coś po swojemu?”, po wysłaniu będzie widać go przy wykonaniu, podpisany „Po swojemu”. Możesz go wcześniej poprawić albo usunąć z pola.</p>
                <a class="btn btn-secondary" href="{{ route('cooked.create', ['recipe' => $recipe->slug, 'dopisek' => 'wstaw']) }}">Wstaw dopisek do pola poniżej</a>
            </div>
        @elseif($dopisek['stan'] === 'wstawiony')
            <p class="m-0" role="status">Wstawiliśmy dopisek z gotowania do pola poniżej. Sprawdź go: po wysłaniu będzie widać go przy wykonaniu jako „Po swojemu”. Możesz go poprawić albo wyczyścić pole.</p>
        @elseif($dopisek['stan'] === 'zajete')
            <p class="m-0">Masz prywatny dopisek z gotowania, ale nie wstawiamy go do formularza, w którym już coś wpisano. Dopisek zostaje prywatny.</p>
        @endif

        <x-field name="changes_note" label="Coś po swojemu?" type="textarea" :rows="3"
                 :value="$dopisek['stan'] === 'wstawiony' ? $dopisek['tresc'] : null"
                 help="Zamiana składnika, inny czas, inna forma. Pokażemy to przy Twoim wykonaniu, podpisane „Po swojemu”." />

        <x-field name="actual_minutes" label="Ile Ci to zajęło (w minutach)" type="number"
                 inputmode="numeric" :min="0" :max="10080" />

        {{-- PRYWATNY DZIEŃ GOTOWANIA (#2583). Zwykłe pole daty, bez
             JavaScriptu, domyślnie PUSTE: nie wypełniamy go ze zdjęcia ani
             z planera. Serwer zapisuje wykonanie z dzisiejszą datą dodania
             (to na niej stoi feed i „Ugotujmy razem"); ten dzień zobaczy
             tylko osoba, która go podała. Formularz ma `novalidate`, więc
             błąd pokazuje serwer — przy polu i w podsumowaniu. --}}
        <x-field name="dzien_gotowania" label="Dzień gotowania (tylko dla Ciebie)" type="date"
                 :min="\App\Domain\Recipes\Gotowanie\DzienGotowania::NAJWCZESNIEJSZY" :max="\App\Support\Czas::dzisiajData()"
                 help="Wypełnij, jeśli gotowanie było innego dnia niż dziś. Ten dzień zobaczysz tylko Ty — wykonanie zapiszemy z dzisiejszą datą dodania." />

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-would_make_again"
                  @error('would_make_again') tabindex="-1" aria-invalid="true" aria-describedby="f-would_make_again-error" @enderror>
            <legend class="font-bold mb-3">Zrobisz to jeszcze raz?</legend>
            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="would_make_again" value="1" @checked(old('would_make_again') === '1')>
                    <span class="choice-label">Tak, zrobię ponownie</span>
                </label>
                <label class="choice">
                    <input type="radio" name="would_make_again" value="0" @checked(old('would_make_again') === '0')>
                    <span class="choice-label">Raczej nie powtórzę</span>
                </label>
                <label class="choice">
                    <input type="radio" name="would_make_again" value="" @checked(old('would_make_again') === '' || old('would_make_again') === null)>
                    <span class="choice-label">Nie podaję</span>
                </label>
            </div>
            <x-blad-grupy name="would_make_again" />
        </fieldset>

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-perceived_difficulty"
                  @error('perceived_difficulty') tabindex="-1" aria-invalid="true" aria-describedby="f-perceived_difficulty-error" @enderror>
            <legend class="font-bold mb-3">Jak trudne to było dla Ciebie?</legend>
            <div class="choice-grid">
                @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                    <label class="choice">
                        <input type="radio" name="perceived_difficulty" value="{{ $value }}" @checked(old('perceived_difficulty') === $value)>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
                <label class="choice">
                    <input type="radio" name="perceived_difficulty" value="" @checked(empty(old('perceived_difficulty')))>
                    <span class="choice-label">Nie podaję</span>
                </label>
            </div>
            <x-blad-grupy name="perceived_difficulty" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wyślij</button>
            <a class="btn btn-quiet" href="{{ route('recipes.show', $recipe->slug) }}">Wróć do przepisu</a>
        </div>
    </form>
</x-layout>
