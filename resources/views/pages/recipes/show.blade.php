@php
    $isPublic = $recipe->visibility === 'public' && $recipe->isPublished();
    $total = $recipe->totalMinutes();
    // Jedna odpowiedź na „ile porcji" dla znaczka i dla structured data
    // (audyt A28) — dwa osobne teksty to dwie okazje do rozjazdu.
    $porcje = $recipe->servingsLabel();
@endphp
<x-layout
    :title="$recipe->title"
    :description="\Illuminate\Support\Str::limit($recipe->summary ?? $recipe->title, 155)"
    :noindex="! $isPublic"
    {{-- Karta do wysłania rodzinie (issue #14). Zdjęcie podajemy TYLKO dla
         przepisu publicznego: przy szkicu i przepisie dla znajomych nie ma
         czego udostępniać, a adres zdjęcia nie ma po co trafiać do znacznika,
         który zbierają scrapery. --}}
    :image="$isPublic ? $recipe->heroMedia : null"
    ogType="article">

    <x-slot:head>
        @if($isPublic)
            {{--
                Structured data. Świadomie BEZ aggregateRating: nie mamy skali
                ocen, mamy realne wykonania. Podajemy je jako
                interactionStatistic — uczciwie i zgodnie ze znaczeniem
                (docs/seo/SEO_TECHNICAL.md).
            --}}
            @php
                $recipeJsonLd = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Recipe',
                'name' => $recipe->title,
                'description' => $recipe->summary,
                'datePublished' => $recipe->published_at?->toDateString(),
                'author' => [
                    '@type' => 'Person',
                    'name' => $recipe->source_person ?: $recipe->author->displayName(),
                    'url' => route('profile.show', $recipe->author->profile->username),
                ],
                'image' => $recipe->heroMedia?->isReady() ? [$recipe->heroMedia->url('large')] : null,
                'recipeYield' => $porcje,
                'prepTime' => $recipe->prep_minutes ? 'PT'.$recipe->prep_minutes.'M' : null,
                'cookTime' => $recipe->cook_minutes ? 'PT'.$recipe->cook_minutes.'M' : null,
                'totalTime' => $recipe->totalTimeIso(),
                'recipeIngredient' => $recipe->ingredients->pluck('ingredient_text')->all(),
                'recipeInstructions' => $recipe->steps->map(fn ($step) => [
                    '@type' => 'HowToStep',
                    'position' => $step->position + 1,
                    'text' => $step->instruction,
                ])->all(),
                'interactionStatistic' => $cookedCount > 0 ? [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CookAction',
                    'userInteractionCount' => $cookedCount,
                ] : null,
                'inLanguage' => 'pl-PL',
            ], static fn ($value) => $value !== null && $value !== []);
            @endphp
            <x-json-ld :data="$recipeJsonLd" />

            @php
                $breadcrumbJsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Kuking', 'item' => route('landing')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Przepisy', 'item' => route('discover')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $recipe->title],
                ],
            ];
            @endphp
            <x-json-ld :data="$breadcrumbJsonLd" />
        @endif
    </x-slot:head>

    <article class="stack">
        <header>
            <p class="meta" style="margin-bottom:var(--spacing-2);">{{ $recipe->attributionLine() }}</p>
            <h1 style="margin-top:0;">{{ $recipe->title }}</h1>

            @if($recipe->status === \App\Models\Recipe::STATUS_HIDDEN)
                {{--
                    Ukrycie przez moderację nie jest szkicem (audyt A08).
                    Wcześniej autor dostawał tu komunikat „To jest szkic.
                    Kliknij Edytuj”, a „Edytuj” oddawało 403 — interfejs
                    obiecywał akcję, której nie ma, i nie mówił, co zrobić.
                --}}
                <p class="notice">
                    <strong>Ten przepis jest ukryty przez moderację.</strong>
                    Nie widzą go inne osoby i na razie nie da się go zmieniać.
                    Jeśli uważasz, że to pomyłka, napisz do nas:
                    {{ config('kuking.community.contact_email') }}
                </p>
            @elseif(! $recipe->isPublished())
                <p class="notice"><strong>To jest szkic.</strong> Widzisz go tylko Ty. Kliknij „Edytuj”, żeby dokończyć i opublikować.</p>
            @endif

            <div style="display:flex; align-items:center; gap:var(--spacing-3); margin-bottom:var(--spacing-4);">
                <x-avatar :user="$recipe->author" :size="44" />
                <div>
                    <a class="author-name" href="{{ route('profile.show', $recipe->author->profile->username) }}">{{ $recipe->author->displayName() }}</a>
                    <p class="meta" style="margin:0;">
                        @if($recipe->published_at)
                            <time datetime="{{ $recipe->published_at->toIso8601String() }}">{{ $recipe->published_at->translatedFormat('j F Y') }}</time>
                        @endif
                    </p>
                </div>
            </div>
        </header>

        @if($recipe->heroMedia)
            <x-photo :media="$recipe->heroMedia" variant="large" :priority="true"
                     class="post-photo" />
        @endif

        <ul class="recipe-facts">
            @if($porcje)<li><span class="badge">{{ $porcje }}</span></li>@endif
            @if($total)<li><span class="badge">Razem około {{ $total }} min</span></li>@endif
            @if($recipe->difficultyLabel())<li><span class="badge">{{ $recipe->difficultyLabel() }}</span></li>@endif
            @if($cookedCount > 0)<li><span class="badge badge-cooked">Ugotowane {{ $cookedCount }} ×</span></li>@endif
            @if($recipe->family_since_year)<li><span class="badge badge-cooked">W rodzinie od {{ $recipe->family_since_year }}</span></li>@endif
        </ul>

        @if($recipe->summary)
            <p style="font-size:var(--text-lead);">{{ $recipe->summary }}</p>
        @endif

        {{-- „Skąd ten przepis” stoi PRZED składnikami. To jest decyzja
             produktowa, nie kolejność przypadkowa. --}}
        @if($recipe->source_note || $recipe->source_person)
            <section class="recipe-story">
                <h2 style="margin-top:0; font-size:var(--text-title-sm);">Skąd ten przepis</h2>
                @if($recipe->source_person)
                    <p><strong>Po {{ $recipe->source_person }}.</strong></p>
                @endif
                @if($recipe->source_note)
                    <p style="white-space:pre-line; margin-bottom:0;">{{ $recipe->source_note }}</p>
                @endif
                @if($recipe->sourceScan)
                    <div style="margin-top:var(--spacing-4); max-width:22rem;">
                        <x-photo :media="$recipe->sourceScan" variant="feed" class="post-photo" />
                        <p class="meta">Kartka, z której jest ten przepis.</p>
                    </div>
                @endif
            </section>
        @endif

        @if($recipe->source_type === 'external' && $recipe->source_url)
            <p class="meta">Przepis pochodzi ze strony: <a href="{{ $recipe->source_url }}" rel="nofollow noopener">{{ $recipe->source_url }}</a></p>
        @endif

        <section>
            <h2>Składniki</h2>
            @if($recipe->ingredients->isEmpty())
                <p class="meta">Autor jeszcze nie dodał składników.</p>
            @else
                <ul class="ingredient-list">
                    @foreach($recipe->ingredients as $ingredient)
                        <li>
                            {{ $ingredient->ingredient_text }}
                            @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section>
            <h2>Przygotowanie</h2>
            @if($recipe->steps->isEmpty())
                <p class="meta">Autor jeszcze nie opisał przygotowania.</p>
            @else
                <ol class="step-list">
                    @foreach($recipe->steps as $step)
                        <li>
                            <span class="step-number" aria-hidden="true">{{ $step->position + 1 }}</span>
                            <div>
                                <span class="visually-hidden">Krok {{ $step->position + 1 }}.</span>
                                <p style="margin:0; white-space:pre-line;">{{ $step->instruction }}</p>
                                @if($step->media)
                                    <div style="margin-top:var(--spacing-3); max-width:20rem;">
                                        <x-photo :media="$step->media" variant="feed" class="post-photo" />
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- Główna akcja przepisu. Nie „Lubię to”, a „Ugotowałem”. --}}
        <section class="card" style="background-color:var(--color-brand-tint);">
            <h2 style="margin-top:0;">Ugotowałeś z tego przepisu?</h2>
            <p>{{ $recipe->author->displayName() }} naprawdę chce o tym wiedzieć. Wystarczy jedno kliknięcie.</p>
            <div style="display:flex; gap:var(--spacing-3); flex-wrap:wrap;">
                @auth
                    <a class="btn btn-primary" href="{{ route('cooked.create', $recipe->slug) }}">Ugotowałem</a>
                    @if($isSaved)
                        <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-secondary" type="submit">Usuń z zeszytu</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('collections.save', $recipe->slug) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Zapisuję</button>
                        </form>
                    @endif
                @else
                    <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto, żeby dać znać autorowi</a>
                @endauth
            </div>
        </section>

        @auth
            <p>
                {{-- Przycisk pyta Policy, a nie tylko o autorstwo: dla przepisu
                     ukrytego przez moderację edycja jest zamknięta (audyt A08),
                     więc nie pokazujemy guzika prowadzącego do 403. --}}
                @if(auth()->id() === $recipe->author_id)
                    @can('update', $recipe)
                        <a class="btn btn-secondary" href="{{ route('recipes.edit', $recipe->slug) }}">Edytuj przepis</a>
                    @endcan
                @else
                    <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'recipe', 'id' => $recipe->slug]) }}">Zgłoś</a>
                @endif
            </p>
        @endauth

        @if($cookedEvents->isNotEmpty())
            <section class="stack">
                <h2>Komu wyszło</h2>
                <p class="meta">Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie.</p>
                @foreach($cookedEvents as $event)
                    <x-cooked-card :event="$event" />
                @endforeach
            </section>
        @endif

        @if(auth()->id() === $recipe->author_id)
            <div class="danger-zone">
                <x-confirm-button
                    :action="route('recipes.destroy', $recipe->slug)"
                    label="Usuń ten przepis"
                    question="Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną być widoczne." />
            </div>
        @endif

        <x-comment-thread :comments="$recipe->comments" :action="route('recipes.comment', $recipe->slug)" />
    </article>
</x-layout>
