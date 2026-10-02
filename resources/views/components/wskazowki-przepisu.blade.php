@props(['wskazowki', 'najnowszaWersja' => null])
{{--
    WSKAZÓWKI OD GOTUJĄCYCH — sekcja na stronie przepisu (#2352, D-333).

    Pokazujemy WYŁĄCZNIE wskazówki przyjęte przez kucharza (kontroler pyta
    o status `accepted` i o widoczność wykonania dla tego widza) i NIC, gdy
    ich nie ma — pusta sekcja zachęcałaby do liczenia, czego brakuje.

    Kolejność to kolejność zgód, bez rankingu (AGENTS.md §8, §12); nikt tu
    nie ma licznika ani „najlepszej wskazówki". Podpis jak w galerii „Komu
    wyszło": ta sama nazwa kucharza i link do jego wykonania. Tekst jest
    uwagą z wykonania, ale moderacja ma ją osobno (D-333, 1.10.2026): „Zgłoś”
    prowadzi do zgłoszenia SAMEJ WSKAZÓWKI (`recipe_hint`), a moderacja może ją
    ukryć w tej sekcji bez ruszania wykonania. Zgłoszenie całego wykonania ma
    swój przycisk na jego stronie („Zobacz to wykonanie”). Kucharz nie zgłasza
    własnej wskazówki — ma „Wycofaj zgodę”.
--}}
@if($wskazowki->isNotEmpty())
    <section class="stack" aria-labelledby="wskazowki-gotujacych">
        <h2 id="wskazowki-gotujacych" class="m-0">Wskazówki od gotujących</h2>
        <p class="meta m-0">Uwagi osób, które ugotowały ten przepis. Pokazujemy je za ich zgodą, w kolejności zgód.</p>
        <div class="stack" id="lista-wskazowek">
            @foreach($wskazowki as $wskazowka)
                @php
                    $wykonanie = $wskazowka->cookedEvent;
                    $kucharz = $wykonanie->user;
                @endphp
                <article class="card stack" data-klucz="wskazowka-{{ $wskazowka->getKey() }}">
                    <blockquote class="wskazowka-cytat tekst-jak-napisano">{{ $wykonanie->note }}</blockquote>
                    <div class="flex gap-3 items-center">
                        <x-avatar :user="$kucharz" :size="44" />
                        <div class="min-w-0">
                            <a class="author-name" href="{{ route('profile.show', $kucharz->profile->username) }}">{{ $kucharz->displayName() }}</a>
                            <p class="meta m-0">
                                ugotowane
                                <time datetime="{{ $wykonanie->cooked_at->toIso8601String() }}">{{ \App\Support\Czas::data($wykonanie->cooked_at, 'j F Y') }}</time>
                            </p>
                        </div>
                    </div>
                    @if($najnowszaWersja !== null && $wskazowka->recipe_version_number !== null && $wskazowka->recipe_version_number < $najnowszaWersja)
                        <p class="meta m-0">Przepis był zmieniany po tej wskazówce.</p>
                    @endif
                    <p class="m-0">
                        <a class="btn btn-secondary" href="{{ route('cooked.show', $wykonanie) }}">Zobacz to wykonanie</a>
                        @auth
                            @if(auth()->id() !== $wykonanie->user_id)
                                <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'recipe_hint', 'id' => $wskazowka->getKey()]) }}">Zgłoś</a>
                            @endif
                        @else
                            <x-zglos-dla-goscia typ="recipe_hint" :id="$wskazowka->getKey()" />
                        @endauth
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
