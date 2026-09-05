{{--
    Pole formularza.

    Etykieta jest ZAWSZE widoczna — placeholder nie jest etykietą.
    Błąd jest powiązany z polem przez aria-describedby, więc czytnik ekranu
    przeczyta go razem z etykietą.

    Pole działa w dwóch trybach:

    - zwykły formularz HTML (domyślny): wartość bierze się z old() i przetrwa
      nieudaną walidację bez JavaScriptu;
    - kreator Livewire (`wire="nazwa.wlasciwosci"`): wartość bierze się ze
      stanu komponentu, a `wire:model` ma ZAWSZE debounce — `wire:model.live`
      bez debounce psuje INP (docs/seo/SEO_TECHNICAL.md). W tym trybie old()
      jest świadomie pomijane, żeby dane z innego, wcześniejszego formularza
      nie nadpisały tego, co człowiek widzi w kreatorze.
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    'autocomplete' => null,
    'placeholder' => null,
    'rows' => null,
    'inputmode' => null,
    'min' => null,
    'max' => null,
    'step' => null,
    'wire' => null,
    'wireModifier' => 'live.debounce.3000ms',
])
@php
    $id = 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $error = $errors->first($name);
    $binding = $wire === null ? null : 'wire:model.'.$wireModifier;
    $current = $wire === null ? old($name, $value) : $value;
    $describedBy = collect([
        $help ? $id.'-help' : null,
        $error ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp
<div class="field @if($error) has-error @endif">
    <label for="{{ $id }}">
        {{ $label }}
        @if($required)
            <span class="meta">(wymagane)</span>
        @else
            <span class="meta">(nieobowiązkowe)</span>
        @endif
    </label>

    @if($help)
        <span class="field-help" id="{{ $id }}-help">{{ $help }}</span>
    @endif

    @if($type === 'textarea')
        <textarea class="field-input" id="{{ $id }}" name="{{ $name }}"
                  rows="{{ $rows ?? 5 }}"
                  @if($binding) {{ $binding }}="{{ $wire }}" @endif
                  @if($placeholder) placeholder="{{ $placeholder }}" @endif
                  @if($required) required @endif
                  @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                  @if($error) aria-invalid="true" @endif
        >{{ $current }}</textarea>
    @else
        <input class="field-input" id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
               value="{{ $current }}"
               @if($binding) {{ $binding }}="{{ $wire }}" @endif
               @if($placeholder) placeholder="{{ $placeholder }}" @endif
               @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
               @if($inputmode) inputmode="{{ $inputmode }}" @endif
               @if($min !== null) min="{{ $min }}" @endif
               @if($max !== null) max="{{ $max }}" @endif
               @if($step !== null) step="{{ $step }}" @endif
               @if($required) required @endif
               @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
               @if($error) aria-invalid="true" @endif>
    @endif

    @if($error)
        <span class="field-error" id="{{ $id }}-error">{{ $error }}</span>
    @endif
</div>
