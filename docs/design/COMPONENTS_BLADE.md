# Komponenty Blade — Kuking.pl

> **Uwaga, 12 września 2026 (`docs/AUDYT_2026-09.md`, B3 #8).** Osiem z dwunastu
> sekcji niżej — `Button`, `Alert`/`Toast`, `AutosaveBadge`, `WizardSteps`,
> `PhotoPicker`, `FollowButton`, `ConfirmDialog`, `Pagination` — to **projekt
> API komponentu, nie kod z tego repozytorium**: żadnego z tych plików nie ma
> w `resources/views/components/`, a żaden widok nie używa tagów `<x-button>`,
> `<x-toast>`, `<x-alert>`, `<x-autosave-badge>`, `<x-wizard-steps>`,
> `<x-photo-picker>`, `<x-follow-button>`, `<x-confirm-dialog>` ani
> `<x-pagination>`. Realne odpowiedniki tego, co te sekcje opisują, żyją pod
> innymi nazwami: `confirm-button.blade.php` (zamiast `ConfirmDialog`),
> `show-more.blade.php` (zamiast `Pagination` — „Pokaż więcej", zgodnie
> z `AGENTS.md` §5, bez infinite scroll; bez skryptu odnośnik „Następna
> strona …”, ze skryptem `resources/js/pokaz-wiecej.js` przycisk, który
> dokleja porcję do listy wskazanej parametrem `lista` — #986),
> `recipe-wizard.blade.php` (zamiast
> `WizardSteps`) i klasa `.pole-zdjecia` w arkuszu (zamiast `PhotoPicker`).
> Pozostałe cztery sekcje — `Field`, `ErrorSummary`, `PostCard`, `Avatar` —
> opisują pliki, które w repozytorium naprawdę są.

Gotowe do wklejenia komponenty Laravel 13 / Blade (anonymous + class-based components), zgodne z tokenami z `tokens.css` i zasadami z `DESIGN_SYSTEM.md`. Wszystkie teksty po polsku, a11y wbudowane (nie doklejane później).

Założenia:
- Blade component tags (`<x-...>`), pliki w `resources/views/components/`.
- Tam gdzie potrzebna jest logika (np. generowanie unikalnego `id`, stan `aria-*` zależny od propa) używamy class-based component (`php artisan make:component`) z widokiem; w pozostałych — anonimowy komponent samego Blade.
- Interaktywność (autosave, licznik znaków, toast) — Alpine.js `x-data` lokalnie, Livewire 4 tam gdzie stan serwerowy.

---

## 1. `Button`

`resources/views/components/button.blade.php`

```blade
@props([
    'variant' => 'primary', // primary | secondary | quiet | danger
    'as' => 'button',       // button | a
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'disabledReason' => null, // tekst wyjaśniający, wyświetlany pod przyciskiem
    'loading' => false,
])

@php
    $variantClass = match ($variant) {
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'quiet' => 'btn-quiet',
        'danger' => 'btn-danger',
        default => 'btn-primary',
    };
    $tag = $as === 'a' ? 'a' : 'button';
    $isDisabled = $disabled || $loading;
@endphp

<div>
    @if ($tag === 'a')
        <a
            {{ $attributes->class(['btn', $variantClass])->merge($isDisabled ? ['aria-disabled' => 'true', 'tabindex' => '-1'] : []) }}
            @if(!$isDisabled) href="{{ $href }}" @endif
        >
            @if ($loading)
                <span aria-hidden="true" class="animate-spin" style="width:1.1em;height:1.1em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%"></span>
            @endif
            {{ $loading ? 'Zapisywanie…' : $slot }}
        </a>
    @else
        <button
            type="{{ $type }}"
            {{ $attributes->class(['btn', $variantClass]) }}
            @if($isDisabled) disabled aria-disabled="true" @endif
        >
            @if ($loading)
                <span aria-hidden="true" class="animate-spin" style="width:1.1em;height:1.1em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%"></span>
            @endif
            {{ $loading ? 'Zapisywanie…' : $slot }}
        </button>
    @endif

    {{-- Zasada produktowa: disabled NIGDY bez wyjaśnienia --}}
    @if ($isDisabled && $disabledReason && !$loading)
        <p class="field-help" role="note">{{ $disabledReason }}</p>
    @endif
</div>
```

Użycie:

```blade
<x-button variant="primary">Opublikuj</x-button>

<x-button variant="primary" :disabled="!$hasPhoto" disabled-reason="Dodaj zdjęcie, żeby opublikować.">
    Opublikuj
</x-button>

<x-button variant="danger" x-on:click="$dispatch('open-confirm-dialog')">
    Usuń wpis
</x-button>
```

---

## 2. `Field`

`resources/views/components/field.blade.php`

```blade
@props([
    'label',
    'name',
    'type' => 'text', // text | textarea | select | number | email | password
    'help' => null,
    'error' => null,
    'required' => false,
    'options' => [], // dla type=select: ['value' => 'Etykieta']
])

@php
    $id = $attributes->get('id') ?? 'field-' . $name;
    $helpId = $id . '-help';
    $errorId = $id . '-error';
    $describedBy = trim(($help ? $helpId : '') . ' ' . ($error ? $errorId : ''));
@endphp

<div class="field @if($error) has-error @endif">
    <label for="{{ $id }}">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true">*</span>
            <span class="sr-only">(wymagane)</span>
        @endif
    </label>

    @if ($type === 'textarea')
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            class="field-input"
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($error) aria-invalid="true" @endif
            {{ $attributes->except(['id', 'class']) }}
        >{{ $slot }}</textarea>
    @elseif ($type === 'select')
        <select
            id="{{ $id }}"
            name="{{ $name }}"
            class="field-input"
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($error) aria-invalid="true" @endif
            {{ $attributes->except(['id', 'class']) }}
        >
            @foreach ($options as $value => $optionLabel)
                <option value="{{ $value }}">{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            name="{{ $name }}"
            class="field-input"
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if($error) aria-invalid="true" @endif
            {{ $attributes->except(['id', 'class']) }}
        >
    @endif

    {{-- Pomoc jest STAŁA — nie znika po fokusie ani po wpisaniu treści --}}
    @if ($help)
        <span id="{{ $helpId }}" class="field-help">{{ $help }}</span>
    @endif

    {{-- Błąd: co jest nie tak + co zrobić, zawsze razem --}}
    @if ($error)
        <span id="{{ $errorId }}" class="field-error" role="alert">{{ $error }}</span>
    @endif
</div>
```

Użycie (Livewire — dane po nieudanej walidacji NIE znikają, bo `wire:model` trzyma stan):

```blade
<x-field
    label="Nazwa przepisu"
    name="title"
    wire:model="title"
    required
    help="Np. „Sernik babci Heleny”."
    :error="$errors->first('title')"
/>

<x-field
    label="Liczba porcji"
    name="servings"
    type="number"
    wire:model="servings"
    help="Na ile osób wystarczy to danie."
    :error="$errors->first('servings')"
/>
```

---

## 3. `ErrorSummary`

`resources/views/components/error-summary.blade.php`

```blade
@props(['errors'])

@if ($errors->any())
    <div
        class="error-summary"
        role="alert"
        tabindex="-1"
        x-data
        x-init="$el.focus()"
        {{-- Fokus przenosi się tu automatycznie po nieudanej próbie zapisu,
             żeby czytnik ekranu od razu ogłosił listę problemów. --}}
    >
        <p class="error-summary-title">
            Znaleziono {{ $errors->count() }} {{ trans_choice('błąd|błędy|błędów', $errors->count()) }} — popraw je, żeby kontynuować.
        </p>
        <ul>
            @foreach ($errors->keys() as $field)
                <li>
                    <a href="#field-{{ $field }}">{{ $errors->first($field) }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
```

Użycie na górze formularza:

```blade
<x-error-summary :errors="$errors" />
```

---

## 4. `Alert` / `Toast`

`resources/views/components/alert.blade.php`

```blade
@props([
    'variant' => 'info', // info | success | warning | danger
])

@php
    $isInterrupting = in_array($variant, ['danger', 'warning']);
    $classes = match ($variant) {
        'success' => 'bg-success-tint text-success-tint-ink',
        'danger' => 'bg-danger-tint text-danger-tint-ink',
        'warning' => 'bg-accent-tint text-accent-tint-ink',
        default => 'bg-surface-sunken text-ink',
    };
    $icon = match ($variant) {
        'success' => '✓',
        'danger' => '⚠',
        'warning' => '⚠',
        default => 'ℹ',
    };
@endphp

<div
    {{ $attributes->class(['flex items-start gap-3 rounded-md p-4', $classes]) }}
    role="{{ $isInterrupting ? 'alert' : 'status' }}"
>
    <span aria-hidden="true" class="text-lead">{{ $icon }}</span>
    <div class="text-body">{{ $slot }}</div>
</div>
```

`resources/views/components/toast.blade.php` (Alpine, montowany raz w `AppShell`, sterowany zdarzeniami `toast`):

```blade
@props(['duration' => 5000])

<div
    x-data="{
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, ...detail });
            setTimeout(() => this.remove(id), {{ $duration }});
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
    }"
    x-on:toast.window="add($event.detail)"
    class="fixed inset-x-0 bottom-20 z-50 flex flex-col items-center gap-2 px-4 lg:bottom-6"
    aria-live="polite"
    aria-atomic="true"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            class="card flex w-full max-w-sm items-center justify-between gap-3"
            :class="toast.variant === 'danger' ? 'border-danger' : ''"
            :role="toast.variant === 'danger' ? 'alert' : 'status'"
        >
            <span x-text="toast.message" class="text-body"></span>
            <button
                type="button"
                class="btn-quiet"
                x-on:click="remove(toast.id)"
                aria-label="Zamknij powiadomienie"
            >✕</button>
        </div>
    </template>
</div>
```

Wywołanie z dowolnego miejsca aplikacji: `window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Przepis zapisany.', variant: 'success' } }))`.

---

## 5. `AutosaveBadge`

`resources/views/components/autosave-badge.blade.php`

```blade
@props(['state' => 'idle']) {{-- idle | saving | saved | error --}}

@php
    $text = match ($state) {
        'saving' => 'Zapisywanie…',
        'saved' => 'Szkic zapisany.',
        'error' => 'Nie udało się zapisać szkicu — sprawdź połączenie z internetem.',
        default => '',
    };
    $classes = match ($state) {
        'error' => 'text-danger',
        'saved' => 'text-success',
        default => 'text-ink-muted',
    };
@endphp

<p
    class="text-help {{ $classes }} flex items-center gap-2"
    aria-live="polite"
    aria-atomic="true"
>
    @if ($state === 'saved')
        <span aria-hidden="true">✓</span>
    @endif
    <span>{{ $text }}</span>
</p>
```

Użycie z Livewire (aktualizacja `$state` po `updated()` na polach formularza z `wire:model.live.debounce.1000ms`):

```blade
<x-autosave-badge :state="$autosaveState" />
```

---

## 6. `WizardSteps`

`resources/views/components/wizard-steps.blade.php`

```blade
@props(['current', 'total', 'label'])

<div class="wizard-steps" role="group" aria-label="Postęp formularza">
    <span class="wizard-steps-current" aria-current="step">
        Krok {{ $current }} z {{ $total }} — {{ $label }}
    </span>
    <div class="wizard-steps-track" aria-hidden="true">
        @for ($i = 1; $i <= $total; $i++)
            <span class="wizard-steps-dot" data-done="{{ $i <= $current ? 'true' : 'false' }}"></span>
        @endfor
    </div>
</div>
```

Użycie:

```blade
<x-wizard-steps :current="2" :total="3" label="Składniki" />

<div class="actions flex gap-3">
    <x-button variant="secondary" wire:click="previousStep" :disabled="$currentStep === 1"
        disabled-reason="To pierwszy krok — nie ma wcześniejszego.">
        Wstecz
    </x-button>
    <x-button variant="secondary" wire:click="saveDraft">Zapisz szkic</x-button>
    <x-button variant="primary" wire:click="nextStep">Dalej: przygotowanie</x-button>
</div>
```

---

## 7. `PhotoPicker`

`resources/views/components/photo-picker.blade.php` (Alpine steruje podglądem lokalnie, Livewire uploadem):

```blade
@props(['name' => 'photo', 'label' => 'Zdjęcie', 'wireModel' => 'photo'])

<div
    x-data="{
        preview: null,
        onChange(e) {
            const file = e.target.files[0];
            if (!file) { this.preview = null; return; }
            this.preview = URL.createObjectURL(file);
        },
    }"
    class="field"
>
    <label for="{{ $name }}">{{ $label }}</label>

    <div
        class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed p-8 text-center"
        style="border-color: var(--color-border-strong); background: var(--color-surface-sunken)"
    >
        <template x-if="!preview">
            <div class="flex flex-col items-center gap-2">
                <span aria-hidden="true" class="text-title-sm">📷</span>
                <span class="text-body">Wybierz zdjęcie z telefonu lub komputera</span>
                <span class="field-help">Zdjęcie z telefonu jest w porządku — nie musi być profesjonalne.</span>
            </div>
        </template>
        <template x-if="preview">
            <img :src="preview" alt="Podgląd wybranego zdjęcia" class="max-h-64 rounded-md" style="aspect-ratio:4/3;object-fit:cover">
        </template>

        <input
            id="{{ $name }}"
            type="file"
            accept="image/*"
            class="mt-2"
            wire:model="{{ $wireModel }}"
            x-on:change="onChange($event)"
            aria-describedby="{{ $name }}-help"
        >
    </div>
    <span id="{{ $name }}-help" class="field-help">Maksymalny rozmiar pliku: 15 MB.</span>

    {{-- Pasek postępu wysyłki — Livewire udostępnia zdarzenia uploadu --}}
    <div wire:loading wire:target="{{ $wireModel }}" class="mt-2">
        <div
            role="progressbar"
            aria-label="Wysyłanie zdjęcia"
            aria-valuemin="0"
            aria-valuemax="100"
            class="h-2 w-full overflow-hidden rounded-pill"
            style="background: var(--color-surface-sunken)"
        >
            <div class="h-full" style="background: var(--color-brand-solid); width: 60%"></div>
        </div>
        <span class="field-help" aria-live="polite">Wysyłanie zdjęcia…</span>
    </div>

    @error($wireModel)
        <span class="field-error" role="alert">
            {{-- Przykład zgodny z regułą UX_50_PLUS.md: co jest nie tak + co zrobić --}}
            {{ str_contains($message, 'max') ? 'Plik jest za duży. Wybierz zdjęcie mniejsze niż 15 MB.' : $message }}
        </span>
    @enderror
</div>
```

---

## 8. `PostCard`

`resources/views/components/post-card.blade.php`

```blade
@props(['post'])

<article class="card" aria-labelledby="post-{{ $post->id }}-author">
    <div class="flex items-center gap-3">
        <x-avatar :user="$post->author" />
        <div>
            <p id="post-{{ $post->id }}-author" class="text-body font-bold" style="margin:0">
                {{ $post->author->display_name }}
            </p>
            <p class="text-help text-ink-muted" style="margin:0">
                <time datetime="{{ $post->created_at->toIso8601String() }}">
                    {{ $post->created_at->diffForHumans() }}
                </time>
            </p>
        </div>
    </div>

    @if ($post->body)
        <p class="text-body-lg" style="margin-top: var(--spacing-4)">{{ $post->body }}</p>
    @endif

    @if ($post->photo_url)
        <img
            src="{{ $post->photo_url }}"
            alt="{{ $post->photo_alt ?? 'Zdjęcie dania od ' . $post->author->display_name }}"
            class="w-full rounded-lg"
            style="aspect-ratio: 4/3; object-fit: cover; margin: var(--spacing-4) 0"
            loading="lazy"
        >
    @endif

    <div class="actions flex flex-wrap gap-2" style="margin-top: var(--spacing-3)">
        <x-button variant="primary" :href="route('posts.cook', $post)" as="a">Ugotowałem</x-button>
        <x-button variant="secondary" x-on:click="$dispatch('open-comments', { postId: {{ $post->id }} })">
            Komentuj
        </x-button>
        <x-save-to-collection :item="$post" />
    </div>

    <p class="text-help text-ink-muted" style="margin-top: var(--spacing-3)">
        <a href="{{ route('posts.show', $post) }}#comments">
            {{ trans_choice('{0} Brak komentarzy|{1} 1 komentarz|[2,*] :count komentarzy', $post->comments_count, ['count' => $post->comments_count]) }}
        </a>
    </p>
</article>
```

---

## 9. `Avatar`

`resources/views/components/avatar.blade.php`

```blade
@props(['user', 'size' => 'md']) {{-- sm | md | lg --}}

@php
    $sizeClass = match ($size) {
        'sm' => 'w-9 h-9 text-body',
        'lg' => 'w-16 h-16 text-title-sm',
        default => 'w-12 h-12 text-body-lg',
    };
    $initial = mb_strtoupper(mb_substr($user->display_name, 0, 1));
@endphp

@if ($user->avatar_url)
    <img
        src="{{ $user->avatar_url }}"
        alt="Zdjęcie profilowe: {{ $user->display_name }}"
        class="{{ $sizeClass }} rounded-pill"
        style="object-fit: cover"
    >
@else
    {{-- Inicjał jest dekoracyjny: nazwa użytkownika jest już wyświetlona
         osobno jako tekst obok awatara, więc SR nie musi czytać litery. --}}
    <span
        aria-hidden="true"
        class="{{ $sizeClass }} rounded-pill flex items-center justify-center font-bold"
        style="background: var(--color-surface-sunken); color: var(--color-ink)"
    >{{ $initial }}</span>
@endif
```

---

## 10. `FollowButton`

`resources/views/components/follow-button.blade.php` (class-based, bo potrzebuje logiki Livewire):

```php
<?php
// resources/views/components/follow-button.php -> app/Livewire/FollowButton.php
namespace App\Livewire;

use App\Models\User;
use Livewire\Component;

class FollowButton extends Component
{
    public User $user;
    public bool $isFollowing;

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->isFollowing = auth()->user()?->isFollowing($user) ?? false;
    }

    public function toggle(): void
    {
        auth()->user()->toggleFollow($this->user);
        $this->isFollowing = ! $this->isFollowing;

        $this->dispatch('toast', message: $this->isFollowing
            ? "Obserwujesz {$this->user->display_name}."
            : "Przestałeś obserwować {$this->user->display_name}.",
            variant: 'success');
    }

    public function render()
    {
        return view('livewire.follow-button');
    }
}
```

`resources/views/livewire/follow-button.blade.php`

```blade
<button
    type="button"
    wire:click="toggle"
    wire:loading.attr="disabled"
    class="btn {{ $isFollowing ? 'btn-secondary' : 'btn-primary' }}"
    aria-pressed="{{ $isFollowing ? 'true' : 'false' }}"
>
    {{ $isFollowing ? 'Obserwujesz' : 'Obserwuj' }}
</button>
```

---

## 11. `ConfirmDialog`

`resources/views/components/confirm-dialog.blade.php`

```blade
@props([
    'variant' => 'default', // default | danger
    'title',
    'confirmLabel' => 'Potwierdź',
    'cancelLabel' => 'Anuluj',
])

<div
    x-data="{ open: false, triggerEl: null }"
    x-on:open-confirm-dialog.window="open = true; triggerEl = $event.detail?.trigger ?? document.activeElement"
    x-on:keydown.escape.window="if (open) { open = false; triggerEl?.focus() }"
    x-show="open"
    x-cloak
    style="display: none"
>
    {{-- Tło blokujące interakcję — NIE lekki overlay, focus jest uwięziony w dialogu --}}
    <div class="fixed inset-0 z-40" style="background: rgba(43,36,29,.4)" x-on:click="open = false; triggerEl?.focus()"></div>

    <div
        x-show="open"
        x-trap.noscroll="open"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        class="card fixed left-1/2 top-1/2 z-50 w-full max-w-md -translate-x-1/2 -translate-y-1/2"
        style="box-shadow: var(--shadow-popover)"
    >
        <h2 id="confirm-dialog-title" class="text-title-sm">{{ $title }}</h2>
        <div class="text-body" style="margin: var(--spacing-3) 0 var(--spacing-6)">
            {{ $slot }}
        </div>
        <div class="actions flex flex-wrap gap-3">
            <x-button variant="secondary" x-on:click="open = false; triggerEl?.focus()">
                {{ $cancelLabel }}
            </x-button>
            <x-button
                :variant="$variant === 'danger' ? 'danger' : 'primary'"
                x-on:click="$dispatch('confirm-dialog-confirmed'); open = false; triggerEl?.focus()"
            >
                {{ $confirmLabel }}
            </x-button>
        </div>
    </div>
</div>
```

Użycie (usunięcie wpisu — **nigdy modal na modalu**, ten dialog jest jedynym otwartym na raz):

```blade
<x-button variant="danger" x-on:click="$dispatch('open-confirm-dialog', { trigger: $el })">
    Usuń wpis
</x-button>

<x-confirm-dialog
    variant="danger"
    title="Usunąć ten wpis?"
    confirm-label="Usuń wpis"
    cancel-label="Zostaw wpis"
    x-on:confirm-dialog-confirmed.window="$wire.deletePost()"
>
    Wpis zniknie z Twojego profilu. Możesz go przywrócić w ciągu 30 dni w
    <strong>Ustawienia → Twoje dane</strong>.
</x-confirm-dialog>
```

---

## 12. `Pagination` („Pokaż więcej")

`resources/views/components/pagination.blade.php` (Livewire, bez infinite scroll):

```blade
@props(['hasMore', 'loadedCount', 'totalLabel' => 'wpisów'])

<div class="flex flex-col items-center gap-3" style="padding: var(--spacing-8) 0">
    @if ($hasMore)
        <x-button
            variant="secondary"
            wire:click="loadMore"
            wire:loading.attr="disabled"
            wire:target="loadMore"
        >
            <span wire:loading.remove wire:target="loadMore">Pokaż więcej</span>
            <span wire:loading wire:target="loadMore">Wczytywanie…</span>
        </x-button>
    @else
        <p class="text-help text-ink-muted">To już wszystkie {{ $totalLabel }}.</p>
    @endif

    {{-- Ogłoszenie dla czytnika ekranu po doładowaniu — nie przenosi fokusu,
         nie przerywa, tylko informuje w tle. --}}
    <p class="sr-only" aria-live="polite">
        Załadowano {{ $loadedCount }} {{ $totalLabel }}.
    </p>
</div>
```

---

## Uwaga o `.sr-only`

Tailwind 4 dostarcza `.sr-only` domyślnie (wbudowana utility), nie trzeba jej definiować w `tokens.css`. Używana w kilku miejscach powyżej do tekstu wyłącznie dla czytników ekranu (np. „(wymagane)”, ogłoszenia `aria-live`).
