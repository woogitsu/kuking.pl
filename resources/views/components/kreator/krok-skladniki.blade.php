{{-- Krok 2 z 3 kreatora — składniki. Wydzielone z `recipe-wizard.blade.php` (#1387,
     punkt 5); HTML jest taki sam jak przedtem. Wszystko, czego komponent
     potrzebuje, dostaje jawnie — nie sięga do `$this` kreatora.
     Pola nadal wiążą się z kreatorem przez `wire:model` (Livewire czyta
     DOM strony, nie granice komponentów Blade).

     $ingredients       wiersze składników (`_key`, `text`, `group_name`, `note`, `substitutes`, `no_amount`)
     $liczbaKrokow      int — `recipe-wizard::STEPS`
     $zOdczytu          bool — szkic z odczytu zdjęcia
     $skan              ?Media — `skanOdczytu()` --}}
@props([
    'ingredients',
    'liczbaKrokow',
    'zOdczytu' => false,
    'skan' => null,
])
<section class="panel-formularza">
    <h2 class="form-section-title">Krok 2 z {{ $liczbaKrokow }}: składniki</h2>
    <p class="meta mb-4">
        Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”.
        Nie musisz nic przeliczać na gramy. Puste wiersze zostaną pominięte.
        {{-- Zdanie wyżej jest wprost z docs/brand/COPY_STYLE.md, §6 „Przepis”. --}}
        Grupę wypełnij tylko wtedy, gdy przepis ma osobne części, na przykład „Ciasto” i „Nadzienie”.
        Przyciski „Przenieś w górę” i „Przenieś w dół” są nieaktywne tam, gdzie nie ma już gdzie przenosić.
    </p>

    @error('ingredients')<p class="field-error mb-4">{{ $message }}</p>@enderror

    @if($zOdczytu)
        @include('pages.import.partials.oryginal', ['skan' => $skan])
    @endif

    @foreach($ingredients as $index => $row)
        <div class="wizard-row" wire:key="skladnik-{{ $row['_key'] ?? $index }}">
            <x-field :name="'ingredients.'.$index.'.text'" :label="'Składnik '.($index + 1)"
                     :wire="'ingredients.'.$index.'.text'" :value="$row['text'] ?? ''"
                     :placeholder="$index === 0 ? '1 kurczak, najlepiej zagrodowy' : null" />

            <div class="siatka-pol-szeroka">
                <x-field :name="'ingredients.'.$index.'.group_name'" label="Grupa składników"
                         :wire="'ingredients.'.$index.'.group_name'" :value="$row['group_name'] ?? ''"
                         placeholder="Ciasto" />
                <x-field :name="'ingredients.'.$index.'.note'" label="Uwaga do składnika"
                         :wire="'ingredients.'.$index.'.note'" :value="$row['note'] ?? ''"
                         placeholder="najlepiej wiejskie" />
            </div>

            {{-- Zamiennik od autora (D-284) — widz zobaczy go pod
                 składnikiem jako „Zamiast tego: …”. Nieobowiązkowy. --}}
            <x-field :name="'ingredients.'.$index.'.substitutes'" label="Czym można to zastąpić (nieobowiązkowe)"
                     :wire="'ingredients.'.$index.'.substitutes'" :value="$row['substitutes'] ?? ''"
                     placeholder="margaryna albo olej kokosowy" />

            {{--
                „BEZ ILOŚCI” — SÓL DO SMAKU (issue #44).

                Nieobowiązkowe i domyślnie wyłączone. Ma znaczenie
                dla przeliczania porcji na stronie przepisu (V2, D-284):
                przepis razy trzy poprosiłby inaczej o trzy szczypty
                soli i o trzy razy „ile weźmie”. To nie jest drobiazg
                kosmetyczny — to moment, w którym przepis przestaje
                wyglądać na napisany przez człowieka.
            --}}
            <label class="choice mt-3">
                <input type="checkbox" wire:model="ingredients.{{ $index }}.no_amount">
                <span>
                    <span class="choice-label">Bez ilości</span>
                    <span class="choice-help">Zaznacz, jeśli nie podajesz liczby i jednostki. Sposób dozowania wpisz w nazwie składnika, np. „mleko — ile weźmie”.</span>
                </span>
            </label>

            <div class="wizard-row-actions">
                <div class="wizard-row-move">
                    <button class="btn btn-secondary" type="button"
                            wire:click="moveIngredientUp({{ $index }})"
                            @disabled($index === 0)>Przenieś w górę</button>
                    <button class="btn btn-secondary" type="button"
                            wire:click="moveIngredientDown({{ $index }})"
                            @disabled($index === count($ingredients) - 1)>Przenieś w dół</button>
                </div>
                <div class="wizard-row-remove">
                    <button class="btn btn-danger" type="button"
                            wire:click="removeIngredient({{ $index }})"
                            @if(trim((string) ($row['text'] ?? '')) !== '') wire:confirm="Na pewno usunąć składnik „{{ trim((string) $row['text']) }}”? Tej operacji nie da się cofnąć." @endif
                    >Usuń ten wiersz</button>
                </div>
            </div>
        </div>
    @endforeach

    <div class="form-actions">
        <button class="btn btn-secondary" type="button" wire:click="addIngredient">Dodaj składnik</button>
    </div>
</section>
