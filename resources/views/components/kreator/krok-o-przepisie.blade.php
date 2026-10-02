{{-- Krok 1 z 3 kreatora — o przepisie. Wydzielone z `recipe-wizard.blade.php` (#1387,
     punkt 5); HTML jest taki sam jak przedtem. Wszystko, czego komponent
     potrzebuje, dostaje jawnie — nie sięga do `$this` kreatora.
     Pola nadal wiążą się z kreatorem przez `wire:model` (Livewire czyta
     DOM strony, nie granice komponentów Blade).

     $form              PrzepisForm
     $liczbaKrokow      int — `recipe-wizard::STEPS`
     $juzOpublikowany   bool
     $zrodloImportu     ?string ('url' | 'pdf' | 'zdjecie' | null)
     $heroMediaId       ?string — id zdjęcia dania --}}
@props([
    'form',
    'liczbaKrokow',
    'juzOpublikowany' => false,
    'zrodloImportu' => null,
    'heroMediaId' => null,
])
<section class="panel-formularza">
    <h2 class="form-section-title">Krok 1 z {{ $liczbaKrokow }}: o przepisie</h2>
    {{-- JEDEN MODEL DZIAŁANIA NA JEDNYM EKRANIE.

         Stało tu „Jeśli nie masz teraz czasu — zapisz szkic"
         (docs/brand/COPY_STYLE.md §6, zdanie napisane dla formularza
         na jednej stronie) — a tuż nad tym zdaniem plakietka mówiła,
         że szkic zapisuje się sam. Człowiek dostawał dwa różne opisy
         tego, jak ten ekran działa, i żaden z nich nie był pełny.

         ZMIERZONE, ZANIM WYBRALIŚMY WERSJĘ: autozapis tutaj JEST,
         ale nie jest bezwarunkowy. `saveDraft()` chodzi po każdym
         kroku i po ~3 s przerwy w pisaniu (`wire:model.live.debounce`
         w `x-field` → hook `updated()`), ale bez nazwy przepisu nie
         zapisuje nic, a przy błędzie zapisu mówi o tym wprost.
         Dlatego PRZYCISK „Zapisz szkic" ZOSTAJE, a znika obietnica
         automatu bez warunku — nie odwrotnie. --}}
    <p class="meta mb-4">
        @if($juzOpublikowany)
            Zachowaj nazwę i co najmniej jeden krok przygotowania, żeby zapisać zmiany.
        @else
            Wystarczy nazwa, żeby ruszyć dalej. Od niej zaczyna się też zapisywanie:
        @endif
        {{ $juzOpublikowany ? 'Zmiany zapisują się same' : 'szkic zapisuje się sam' }} po każdym kroku i po chwili przerwy w pisaniu,
        a przycisk „{{ $juzOpublikowany ? 'Zapisz zmiany' : 'Zapisz szkic' }}” robi to od razu.
    </p>

    <x-field name="form.title" label="Nazwa przepisu" required wire="form.title"
             :value="$form->title" placeholder="Rosół babci Zofii" />

    <div class="field @error('heroPhoto') has-error @enderror">
        {{-- Duży obszar wyboru zdjęcia (UI kit v2, `PhotoPicker` —
             patrz komentarz w resources/css/ekran-dodawania.css).
             Natywne pole pliku jest schowane dla oka (D-035), bo
             rysowało angielskie „Choose File / No file chosen";
             zostaje pod klawiaturą i w drzewie dostępności, a klikalna
             jest etykieta. `wire:model` i pozostałe atrybuty pola są
             niezmienione. `<input>` MUSI stać bezpośrednio przed
             `<label>` — obwódkę fokusu rysuje reguła sąsiedztwa. --}}
        <span class="pole-zdjecia-nazwa" id="f-heroPhoto-etykieta">Zdjęcie gotowego dania <span class="meta">(nieobowiązkowe)</span></span>
        {{-- Treść komunikatu idzie z PHP, a nie z `app.js`, żeby liczba
             megabajtów miała jedno źródło (`LimityZdjec`) i nie
             rozjechała się z `config/kuking.php` — issue #111. --}}
        <input class="visually-hidden pole-zdjecia-input" id="f-heroPhoto" type="file" wire:model="heroPhoto"
               accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
               data-blad-wysylki="{{ \App\Support\LimityZdjec::komunikatNieudanejWysylki() }}"
               aria-labelledby="f-heroPhoto-etykieta f-heroPhoto-tytul"
               @error('heroPhoto') aria-invalid="true" aria-describedby="f-heroPhoto-help f-heroPhoto-error" @else aria-describedby="f-heroPhoto-help" @enderror>
        <label class="pole-zdjecia" for="f-heroPhoto">
            <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
            <span class="pole-zdjecia-tytul" id="f-heroPhoto-tytul">{{ $heroMediaId !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
            <span class="field-help" id="f-heroPhoto-help">To zdjęcie zobaczą ludzie na liście przepisów.</span>
        </label>
        @error('heroPhoto')<span class="field-error" id="f-heroPhoto-error">{{ $message }}</span>@enderror
        @if($heroMediaId !== null)
            <p class="meta mt-2">Zdjęcie jest już dodane. Wybierz plik jeszcze raz, jeśli chcesz je zmienić.</p>
        @endif
    </div>

    <x-field name="form.summary" label="Krótko o przepisie" type="textarea" :rows="3" wire="form.summary"
             :value="$form->summary"
             help="Jedno-dwa zdania. Na co ten przepis jest dobry, kiedy go robisz." />

    <div class="siatka-pol">
        {{-- Krok 0,01 musi się zgadzać z regułą `decimal:0,2` wyżej (#750),
             inaczej zapisana 1,25 jest dla przeglądarki `stepMismatch`. --}}
        <x-field name="form.servings" label="Na ile porcji" type="number" inputmode="decimal" wire="form.servings"
                 :value="$form->servings" :min="0.5" :max="999" :step="0.01" />
        <x-field name="form.prep_minutes" label="Przygotowanie (minuty)" type="number" inputmode="numeric" wire="form.prep_minutes"
                 :value="$form->prep_minutes" :min="0" :max="10080" />
        <x-field name="form.cook_minutes" label="Gotowanie / pieczenie (minuty)" type="number" inputmode="numeric" wire="form.cook_minutes"
                 :value="$form->cook_minutes" :min="0" :max="10080" />
    </div>

    {{-- Gotowe sztuki (#2645) — opcjonalnie, osobno od porcji: „24 pierogi”
         nie mówią, ile osób nakarmi przepis. --}}
    <div class="siatka-pol">
        <x-field name="form.yield_count" label="Ile gotowych sztuk wychodzi z tej ilości" type="number" inputmode="numeric" wire="form.yield_count"
                 :value="$form->yield_count" :min="1" :max="9999" :step="1"
                 help="Na przykład 24. Pomaga przeliczyć przepis na inną liczbę sztuk. Zostaw puste, jeśli nie dotyczy." />
        <x-field name="form.yield_unit" label="Czego to sztuki" wire="form.yield_unit"
                 :value="$form->yield_unit"
                 help="Na przykład pierogi albo bułki. Pole nieobowiązkowe." />
    </div>

    {{-- Koszt wg autora (D-286) — pole tekstowe, bo „24,50" z przecinkiem
         ma przejść (uzasadnienie przy tym samym polu w `szczegoly.blade.php`). --}}
    <x-field name="form.estimated_cost_pln" label="Przybliżony koszt całego przepisu (zł)" inputmode="decimal" wire="form.estimated_cost_pln"
             :value="$form->estimated_cost_pln"
             help="Ile mniej więcej kosztują składniki na cały przepis. Wpisz samą liczbę złotych, na przykład 24 albo 24,50. Na stronie przepisu pokażemy to jako szacunek autora." />

    {{-- `id` jest CELEM odnośnika z podsumowania błędów i zdarzenia
         `kreator-fokus-pole`, a atrybuty ARIA wiążą błąd z grupą —
         patrz `x-blad-grupy`. Bez `id` odnośnik do błędu grupy
         wyboru nie prowadził nigdzie (issue #1387, krok 3). --}}
    <fieldset class="border-0 p-0 mt-6" id="f-form-difficulty"
              @error('form.difficulty') tabindex="-1" aria-invalid="true" aria-describedby="f-form-difficulty-error" @enderror>
        <legend class="font-bold mb-3">Jak trudny jest ten przepis?</legend>
        <div class="choice-grid">
            @foreach(\App\Models\Recipe::DIFFICULTY_LABELS as $value => $label)
                <label class="choice">
                    <input type="radio" wire:model="form.difficulty" value="{{ $value }}">
                    <span class="choice-label">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        <x-blad-grupy name="form.difficulty" />
    </fieldset>

    <fieldset class="border-0 p-0 mt-6" id="f-form-visibility"
              @error('form.visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-form-visibility-error" @enderror>
        <legend class="font-bold mb-3">Kto ma widzieć ten przepis?</legend>
        <div class="choice-grid">
            <label class="choice">
                <input type="radio" wire:model="form.visibility" value="public">
                <span><span class="choice-label">Wszyscy</span><span class="choice-help">Także osoby bez konta. Przepis może pojawić się w Google.</span></span>
            </label>
            <label class="choice">
                <input type="radio" wire:model="form.visibility" value="followers">
                <span><span class="choice-label">Tylko osoby, które mnie obserwują</span></span>
            </label>
            <label class="choice">
                <input type="radio" wire:model="form.visibility" value="private">
                <span><span class="choice-label">Tylko ja</span><span class="choice-help">Twój prywatny zeszyt.</span></span>
            </label>
        </div>
        <x-blad-grupy name="form.visibility" />
    </fieldset>

    <div class="form-section">
        <h3 class="form-section-title">Skąd ten przepis</h3>
        {{-- ZDANIE MÓWI, CO TU WPISAĆ, A NIE JAK CZĘSTO TO KTOŚ CZYTA.

             Stało tu „To najczęściej czytana część przepisu" —
             twierdzenie o zachowaniu czytelników, którego nikt nigdy
             nie zmierzył i którego nie ma czym pokryć. --}}
        <p class="meta mb-4">
            Tu napiszesz, skąd masz ten przepis i co Cię z nim wiąże.
        </p>

        <fieldset class="border-0 p-0" id="f-form-source_type"
                  @error('form.source_type') tabindex="-1" aria-invalid="true" aria-describedby="f-form-source_type-error" @enderror>
            <legend class="font-bold mb-3">Ten przepis jest…</legend>
            @if($zrodloImportu === 'url')
                <p class="field-help">Ten przepis pochodzi ze strony {{ $form->source_url }} — źródła szkicu zapisanego ze strony nie da się zmienić.</p>
            @endif
            <div class="choice-grid">
                @foreach(\App\Models\Recipe::SOURCE_LABELS as $value => $label)
                    <label class="choice">
                        <input type="radio" wire:model="form.source_type" value="{{ $value }}" @disabled($zrodloImportu === 'url')>
                        <span class="choice-label">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <x-blad-grupy name="form.source_type" />
        </fieldset>

        {{-- PYTAMY O FRAZĘ, KTÓRA STOI SAMODZIELNIE — uzasadnienie
             przy tym samym polu w `pages/recipes/szczegoly.blade.php`. --}}
        <x-field name="form.source_person" label="Od kogo albo skąd masz ten przepis" wire="form.source_person" :value="$form->source_person"
                 placeholder="od mamy · z gazety · z bloga Nasze smaki"
                 help="Napisz to tak, żeby dało się przeczytać samo: „od mamy”, „z gazety”, „od sąsiadki Haliny”. Pokażemy to przy przepisie dokładnie tak, jak wpiszesz." />

        {{-- POMOC JEST PRAWDZIWA PRZY KAŻDEJ Z TRZECH WIDOCZNOŚCI.

             Stało tu „To zostaje w rodzinie." — nieprawda przy
             przepisie publicznym, a taki jest tu domyślny
             (`PrzepisForm::$visibility = 'public'`). Zdanie zależne od
             `visibility` byłoby tutaj gorsze niż neutralne: pole
             stoi na tym samym kroku co wybór widoczności, a radia
             mają zwykły `wire:model` (bez `.live`), więc wartość
             w kolejnej odpowiedzi bywa o jedno kliknięcie z tyłu.
             Zdanie zależne od stanu, który chwilami jest nieaktualny,
             zamieniłoby jedną nieprawdę na drugą, trudniejszą do
             złapania. To jest prawdziwe zawsze. --}}
        <x-field name="form.source_note" label="Historia tego przepisu" type="textarea" :rows="4" wire="form.source_note"
                 :value="$form->source_note"
                 help="Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. Ta historia jest częścią przepisu — zobaczy ją każdy, kto zobaczy przepis." />

        <x-field name="form.family_since_year" label="W rodzinie od roku" type="number" inputmode="numeric" wire="form.family_since_year"
                 :value="$form->family_since_year" :min="1850" :max="2100" placeholder="1974" />

        <x-field name="form.source_url" label="Adres strony, z której jest przepis" type="url" wire="form.source_url"
                 :value="$form->source_url"
                 help="Podaj, jeśli przepis pochodzi z bloga albo innej strony. Nie publikuj cudzych treści bez zgody." />

        <p class="field-help">
            Zdjęcie starej kartki albo zeszytu dodasz na
            <a href="{{ route('recipes.create.simple') }}">formularzu na jednej stronie</a>,
            a przy {{ $juzOpublikowany ? 'opublikowanym przepisie' : 'zapisanym szkicu' }} — w jego edycji.
        </p>
    </div>
</section>
