{{-- Krok 3 z 3 kreatora — przygotowanie. Wydzielone z `recipe-wizard.blade.php` (#1387,
     punkt 5); HTML jest taki sam jak przedtem. Wszystko, czego komponent
     potrzebuje, dostaje jawnie — nie sięga do `$this` kreatora.
     Pola nadal wiążą się z kreatorem przez `wire:model` (Livewire czyta
     DOM strony, nie granice komponentów Blade).

     $steps             wiersze kroków (`_key`, `instruction`, `timer_minutes`, `mediaId`, `photo`)
     $liczbaKrokow      int — `recipe-wizard::STEPS`
     $zOdczytu          bool — szkic z odczytu zdjęcia
     $skan              ?Media — `skanOdczytu()` --}}
@props([
    'steps',
    'liczbaKrokow',
    'zOdczytu' => false,
    'skan' => null,
])
<section class="panel-formularza">
    <h2 class="form-section-title">Krok 3 z {{ $liczbaKrokow }}: przygotowanie</h2>
    <p class="meta mb-4">
        Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.
        Puste wiersze zostaną pominięte.
        Przyciski „Przenieś w górę” i „Przenieś w dół” są nieaktywne tam, gdzie nie ma już gdzie przenosić.
    </p>

    @error('steps')<p class="field-error mb-4">{{ $message }}</p>@enderror

    @if($zOdczytu)
        @include('pages.import.partials.oryginal', ['skan' => $skan])
    @endif

    @foreach($steps as $index => $row)
        <div class="wizard-row" wire:key="krok-{{ $row['_key'] ?? $index }}">
            <x-field :name="'steps.'.$index.'.instruction'" :label="'Krok '.($index + 1).': co się robi'" type="textarea" :rows="3"
                     :wire="'steps.'.$index.'.instruction'" :value="$row['instruction'] ?? ''"
                     :placeholder="$index === 0 ? 'Kurczaka zalej zimną wodą i zagotuj. Zbierz szumowiny.' : null" />

            {{-- `x-field` wolno tu użyć, choć nazwa ma kropki: kreator
                 wysyła dane przez `wire:model`, a nie POST-em, więc
                 atrybut `name` nigdy nie przechodzi przez parser
                 formularzy PHP-a (który zamienia kropki na
                 podkreślenia). W formularzu bez JavaScriptu to samo
                 pole jest rozpisane ręcznie, z nawiasami w `name`. --}}
            <x-field :name="'steps.'.$index.'.timer_minutes'" label="Ile minut ma trwać ten krok?"
                     type="number" inputmode="numeric"
                     :wire="'steps.'.$index.'.timer_minutes'" :value="$row['timer_minutes'] ?? ''"
                     :min="0" :max="\App\Domain\Recipes\StepTimer::MAX_MINUTES"
                     help="Wpisz liczbę minut — na przykład 45. Przy gotowaniu pokażemy wtedy: „Ustaw sobie kuchenny minutnik na 45 minut”. Zostaw puste, jeśli ten krok nie potrzebuje odliczania." />

            <div class="field @error("steps.{$index}.photo") has-error @enderror">
                {{-- Duży obszar wyboru zdjęcia — ten sam wzorzec co
                     przy „Zdjęcie gotowego dania" wyżej w tym pliku:
                     pole pliku schowane dla oka (D-035), klikalna
                     etykieta, `<input>` bezpośrednio przed nią. --}}
                <span class="pole-zdjecia-nazwa" id="f-steps-{{ $index }}-photo-etykieta">
                    Zdjęcie do tego kroku <span class="meta">(nieobowiązkowe)</span>
                </span>
                <input class="visually-hidden pole-zdjecia-input" id="f-steps-{{ $index }}-photo" type="file"
                       wire:model="steps.{{ $index }}.photo"
                       accept="{{ \App\Support\LimityZdjec::atrybutAccept() }}"
                       data-blad-wysylki="{{ \App\Support\LimityZdjec::komunikatNieudanejWysylki() }}"
                       aria-labelledby="f-steps-{{ $index }}-photo-etykieta f-steps-{{ $index }}-photo-tytul"
                       @error("steps.{$index}.photo") aria-invalid="true" aria-describedby="f-steps-{{ $index }}-photo-help f-steps-{{ $index }}-photo-error" @else aria-describedby="f-steps-{{ $index }}-photo-help" @enderror>
                <label class="pole-zdjecia" for="f-steps-{{ $index }}-photo">
                    <span class="pole-zdjecia-ikona"><x-ikona nazwa="image" :rozmiar="32" /></span>
                    <span class="pole-zdjecia-tytul" id="f-steps-{{ $index }}-photo-tytul">{{ ($row['mediaId'] ?? null) !== null ? 'Zmień zdjęcie' : 'Dodaj zdjęcie' }}</span>
                    <span class="field-help" id="f-steps-{{ $index }}-photo-help">
                        Przydaje się tam, gdzie trudno opisać słowami — jak zawinąć ciasto,
                        jak gęsty ma być sos.
                    </span>
                </label>
                @error("steps.{$index}.photo")<span class="field-error" id="f-steps-{{ $index }}-photo-error">{{ $message }}</span>@enderror

                @if(($row['mediaId'] ?? null) !== null)
                    <p class="meta mt-2">
                        Zdjęcie do tego kroku jest już dodane. Wybierz plik jeszcze raz,
                        jeśli chcesz je zmienić.
                    </p>
                    <button class="btn btn-secondary mt-2" type="button"
                            wire:click="removeStepPhoto({{ $index }})"
                            wire:confirm="Na pewno usunąć zdjęcie z tego kroku? Sam krok zostanie.">
                        Usuń zdjęcie z tego kroku
                    </button>
                @endif
            </div>

            <div class="wizard-row-actions">
                <div class="wizard-row-move">
                    <button class="btn btn-secondary" type="button"
                            wire:click="moveStepUp({{ $index }})"
                            @disabled($index === 0)>Przenieś w górę</button>
                    <button class="btn btn-secondary" type="button"
                            wire:click="moveStepDown({{ $index }})"
                            @disabled($index === count($steps) - 1)>Przenieś w dół</button>
                </div>
                <div class="wizard-row-remove">
                    <button class="btn btn-danger" type="button"
                            wire:click="removeStep({{ $index }})"
                            @if(trim((string) ($row['instruction'] ?? '')) !== '') wire:confirm="Na pewno usunąć ten krok przygotowania? Tej operacji nie da się cofnąć." @endif
                    >Usuń ten wiersz</button>
                </div>
            </div>
        </div>
    @endforeach

    <div class="form-actions">
        <button class="btn btn-secondary" type="button" wire:click="addStep">Dodaj krok</button>
    </div>
</section>
