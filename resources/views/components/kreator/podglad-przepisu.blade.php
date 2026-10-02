{{-- Treść podglądu przepisu (krok 4 kreatora) — same odczytane wartości,
     żadnego pola formularza. Wydzielone z `recipe-wizard.blade.php` (#1387,
     punkt 5); HTML jest taki sam jak przedtem. Wszystko, co komponent
     potrzebuje, dostaje jawnie — nie sięga do `$this` kreatora.

     $form               PrzepisForm (tytuł, opis, trudność, „Skąd ten przepis")
     $heroMediaId        id zdjęcia dania albo null
     $etykietaPorcji     ?string  — `previewServingsLabel()`
     $czasRazem          ?int     — `totalMinutes()`
     $etykietaKosztu     ?string  — `previewCostLabel()`
     $grupySkladnikow    lista grup — `groupedIngredients()`
     $kroki              lista kroków — `cleanSteps()`
     $etykietaMinutnika  Closure(mixed): ?string — `previewTimerLabel()` --}}
@props([
    'form',
    'heroMediaId' => null,
    'etykietaPorcji' => null,
    'czasRazem' => null,
    'etykietaKosztu' => null,
    'grupySkladnikow' => [],
    'kroki' => [],
    'etykietaMinutnika',
])
<article class="stack">
    <h3 class="naglowek-podgladu">{{ trim($form->title) !== '' ? trim($form->title) : 'Przepis bez nazwy' }}</h3>

    <ul class="recipe-facts">
        @if($etykietaPorcji !== null)
            <li><span class="badge">{{ $etykietaPorcji }}</span></li>
        @endif
        @if($czasRazem !== null)
            <li><span class="badge">Razem około {{ \App\Support\Czas::czasPrzepisu($czasRazem) }}</span></li>
        @endif
        @if($etykietaKosztu !== null)
            <li><span class="badge">{{ $etykietaKosztu }}</span></li>
        @endif
        @if($form->difficulty !== '')
            <li><span class="badge">{{ \App\Models\Recipe::DIFFICULTY_LABELS[$form->difficulty] ?? $form->difficulty }}</span></li>
        @endif
        @if(trim($form->family_since_year) !== '')
            <li><span class="badge badge-cooked">W rodzinie od {{ trim($form->family_since_year) }}</span></li>
        @endif
    </ul>

    @if($heroMediaId !== null)
        <p class="meta">Zdjęcie gotowego dania jest dodane. Pokaże się na stronie przepisu, kiedy skończymy je przygotowywać.</p>
    @endif

    @if(trim($form->summary) !== '')
        <p class="text-lead">{{ trim($form->summary) }}</p>
    @endif

    @if(trim($form->source_person) !== '' || trim($form->source_note) !== '')
        <section class="recipe-story">
            <h4 class="mt-0 text-title-sm">Skąd ten przepis</h4>
            @if(trim($form->source_person) !== '')
                {{-- Podgląd pokazuje dokładnie to, co strona
                     przepisu — wartość dosłownie, bez doklejonego
                     „Po". Uzasadnienie stoi przy tym samym
                     miejscu w `pages/recipes/show.blade.php`. --}}
                <p><strong>{{ \Illuminate\Support\Str::ucfirst(trim($form->source_person)) }}</strong></p>
            @endif
            @if(trim($form->source_note) !== '')
                <p class="whitespace-pre-line mb-0">{{ trim($form->source_note) }}</p>
            @endif
        </section>
    @endif

    <section>
        <h4 class="text-title-sm">Składniki</h4>
        @php($previewGroups = $grupySkladnikow)
        @if($previewGroups === [])
            <p class="meta">Nie dodano jeszcze składników. Możesz dopisać je później.</p>
        @else
            @foreach($previewGroups as $previewGroup)
                {{-- `<h5>`, bo nagłówkiem tej sekcji podglądu jest
                     `<h4>Składniki` wyżej. Na stronie przepisu ta
                     sama grupa jest `<h3>` — poziom bierze się ze
                     struktury strony, nie z wyglądu, a wygląd
                     daje jedna klasa `.naglowek-grupy`. --}}
                @if($previewGroup['nazwa'] !== null)
                    <h5 class="naglowek-grupy">{{ $previewGroup['nazwa'] }}</h5>
                @endif
                <ul class="ingredient-list">
                    @foreach($previewGroup['skladniki'] as $groupRow)
                        <li>
                            {{ $groupRow['text'] }}
                            @if($groupRow['note'] !== null)<span class="meta"> — {{ $groupRow['note'] }}</span>@endif
                            @if(($groupRow['substitutes'] ?? null) !== null)<span class="skladnik-zamiennik">Zamiast tego: {{ $groupRow['substitutes'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </section>

    <section>
        <h4 class="text-title-sm">Przygotowanie</h4>
        @php($previewSteps = $kroki)
        @if($previewSteps === [])
            <p class="field-error">Nie ma jeszcze żadnego kroku. Wróć do kroku 3 i opisz przynajmniej jeden.</p>
        @else
            @foreach(\App\Domain\Recipes\EtapyPrzygotowania::grupy($previewSteps) as $previewEtap)
            @if($previewEtap['nazwa'] !== null)
                <h5 class="naglowek-grupy">{{ $previewEtap['nazwa'] }}</h5>
            @endif
            <ol class="step-list">
                @foreach($previewEtap['kroki'] as $previewIndex => $previewRow)
                    <li>
                        <span class="step-number" aria-hidden="true">{{ $previewIndex + 1 }}</span>
                        <div>
                            <span class="visually-hidden">Krok {{ $previewIndex + 1 }}.</span>
                            <p class="m-0 whitespace-pre-line">{{ $previewRow['instruction'] }}</p>
                            {{-- Minutnik i zdjęcie w podglądzie, bo podgląd obiecuje,
                                 że tak wygląda gotowy przepis — a przy gotowaniu widać
                                 jedno i drugie. Etykietę liczy `RecipeStep::timerLabel()`,
                                 ten sam kod co w trybie gotowania. --}}
                            @php($previewTimer = $etykietaMinutnika($previewRow['timer_minutes']))
                            @if($previewTimer !== null)
                                <p class="meta m-0">Ustaw sobie kuchenny minutnik na {{ $previewTimer }}.</p>
                            @endif
                            @if($previewRow['media_id'] !== null)
                                <p class="meta m-0">Zdjęcie do tego kroku jest dodane.</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @endforeach
        @endif
    </section>
</article>
