{{-- Krok 2 z 3 kreatora — składniki. Wydzielone z `recipe-wizard.blade.php` (#1387,
     punkt 5); HTML jest taki sam jak przedtem. Wszystko, czego komponent
     potrzebuje, dostaje jawnie — nie sięga do `$this` kreatora.
     Pola nadal wiążą się z kreatorem przez `wire:model` (Livewire czyta
     DOM strony, nie granice komponentów Blade).

     $ingredients       wiersze składników (`_key`, `text`, `group_name`, `note`, `substitutes`, `no_amount`)
     $grupy             istniejące grupy (`ZmianaNazwyGrupy::grupy()`), do zbiorczej zmiany nazwy (#2444)
     $grupaDoZmiany     ?string — klucz wybranej grupy
     $podgladGrupy      ?array — podgląd zmiany przed zatwierdzeniem
     $komunikatGrupy    string — wynik ostatniej zmiany
     $nowaNazwaGrupy    string — wpisana nowa nazwa
     $liczbaKrokow      int — `recipe-wizard::STEPS`
     $zOdczytu          bool — szkic z odczytu zdjęcia
     $skan              ?Media — `skanOdczytu()` --}}
@props([
    'ingredients',
    'grupy' => [],
    'grupaDoZmiany' => null,
    'podgladGrupy' => null,
    'komunikatGrupy' => '',
    'nowaNazwaGrupy' => '',
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

    {{-- ZMIANA NAZWY CAŁEJ GRUPY (V2, #2444). Drugorzędna i nieobowiązkowa:
         pokazuje się dopiero wtedy, gdy przepis ma choć jedną grupę. Zmienia
         tylko `group_name` wierszy wybranej grupy — po potwierdzeniu i bez
         nowej tabeli grup. --}}
    @if($grupy !== [] || $komunikatGrupy !== '')
        <section class="wizard-row" aria-labelledby="zmiana-grupy-naglowek">
            <h3 class="m-0" id="zmiana-grupy-naglowek">Zmiana nazwy całej grupy (nieobowiązkowe)</h3>

            <div role="status">
                @if($komunikatGrupy !== '')
                    <p class="notice mt-3 mb-0">{{ $komunikatGrupy }}</p>
                @endif
            </div>

            @if($grupaDoZmiany === null)
                <p class="meta mt-3 mb-3">
                    Chcesz zmienić nagłówek, na przykład „Ciasto” na „Spód”? Nie poprawiaj go przy każdym składniku.
                    Wybierz grupę, wpisz nową nazwę i zatwierdź jedną zmianę. Zobaczysz, ile składników obejmie.
                </p>
                <div class="form-actions">
                    @foreach($grupy as $grupa)
                        <button class="btn btn-secondary" type="button"
                                wire:key="grupa-{{ md5($grupa['klucz']) }}"
                                wire:click="wybierzGrupeDoZmiany({{ \Illuminate\Support\Js::from($grupa['klucz']) }})">
                            Zmień nazwę grupy „{{ $grupa['nazwa'] }}” ({{ $grupa['liczba'] }} {{ \App\Support\Odmiana::rzeczownik($grupa['liczba'], 'składnik', 'składniki', 'składników') }})
                        </button>
                    @endforeach
                </div>
            @elseif($podgladGrupy === null)
                <div class="mt-3">
                    <x-field name="nowaNazwaGrupy" label="Nowa nazwa grupy (zostaw puste, aby usunąć nagłówek)"
                             wire="nowaNazwaGrupy" wire-modifier="blur" :value="$nowaNazwaGrupy"
                             help="Zmiana obejmie wszystkie składniki tej grupy, także te, które stoją w liście osobno." />
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="button" wire:click="pokazZmianeGrupy">Zobacz zmianę</button>
                    <button class="btn btn-secondary" type="button" wire:click="anulujZmianeGrupy">Anuluj</button>
                </div>
            @else
                <div class="mt-3" aria-live="polite">
                    <p class="m-0"><strong>Obecna nazwa:</strong> „{{ $podgladGrupy['obecna'] }}”</p>
                    <p class="m-0"><strong>Nowa nazwa:</strong> {{ $podgladGrupy['nowa'] === '' ? 'bez nagłówka' : '„'.$podgladGrupy['nowa'].'”' }}</p>
                    <p class="m-0"><strong>Objęte składniki:</strong> {{ $podgladGrupy['liczba'] }}</p>

                    @if($podgladGrupy['nowa'] === '')
                        <p class="notice mt-3 mb-0">Składniki przejdą do części bez nagłówka. Żaden składnik nie zostanie usunięty.</p>
                    @elseif($podgladGrupy['scalaLiczba'] !== null)
                        <p class="notice mt-3 mb-0">
                            Grupa „{{ $podgladGrupy['nowa'] }}” już istnieje ({{ $podgladGrupy['scalaLiczba'] }} {{ \App\Support\Odmiana::rzeczownik($podgladGrupy['scalaLiczba'], 'składnik', 'składniki', 'składników') }}).
                            Po zatwierdzeniu składniki obu grup będą pod jednym nagłówkiem „{{ $podgladGrupy['nowa'] }}”. Kolejność składników się nie zmieni.
                        </p>
                    @endif
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="button" wire:click="zatwierdzZmianeGrupy">
                        {{ $podgladGrupy['scalaLiczba'] !== null ? 'Połącz grupy i zmień nazwę' : ($podgladGrupy['nowa'] === '' ? 'Usuń nagłówek grupy' : 'Zatwierdź zmianę nazwy') }}
                    </button>
                    <button class="btn btn-secondary" type="button" wire:click="wrocDoNazwyGrupy">Wróć do nazwy</button>
                    <button class="btn btn-secondary" type="button" wire:click="anulujZmianeGrupy">Anuluj</button>
                </div>
            @endif
        </section>
    @endif

    @foreach($ingredients as $index => $row)
        <div class="wizard-row" wire:key="skladnik-{{ $row['_key'] ?? $index }}">
            <x-field :name="'ingredients.'.$index.'.text'" :label="'Składnik '.($index + 1)"
                     :wire="'ingredients.'.$index.'.text'" :value="$row['text'] ?? ''"
                     :placeholder="$index === 0 ? '1 kurczak, najlepiej zagrodowy' : null" />
            <x-dyktowanie :cel="'f-ingredients-'.$index.'-text'" />

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
