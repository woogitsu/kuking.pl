{{--
    „Moje wpisy” w „Moje” (D-328). Wszystkie własne wpisy zalogowanej osoby,
    od najnowszego — także „tylko dla mnie”, „dla obserwujących”, szkice
    i ukryte przez moderację. Profil pokazuje wyłącznie opublikowane, więc
    tylko tutaj autor zobaczy swój szkic albo wpis, który ukryła moderacja.

    Każda karta mówi SŁOWAMI dwie rzeczy: kto ten wpis widzi i w jakim jest
    stanie. Bez ikon i bez koloru jako jedynego nośnika — to są informacje,
    od których zależy, czy ktoś się czegoś wystraszy.

    Bez JavaScriptu: lista to zwykłe odnośniki, a „Następna strona” działa
    jako link (`x-show-more`); skrypt tylko dokleja kolejną porcję.
--}}
<x-layout title="Moje wpisy" :noindex="true">
    <div class="marka-zeszyt">

    <p class="mb-3"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

    <h1>Moje wpisy</h1>
    <p class="mb-5">Wszystkie Twoje wpisy, od najnowszego — także te tylko dla Ciebie i tylko dla obserwujących. Tę listę widzisz tylko Ty.</p>

    {{-- „Szukaj w moich wpisach” (#2465): zwykły GET, działa bez JavaScriptu. Szuka w opisie
         i tytule wpisu (`FrazaWMoichWpisach`); pusta fraza to cała lista. --}}
    @if($maWpisy)
        <form class="panel-formularza mb-6" method="GET" action="{{ route('collections.own-posts') }}" role="search" aria-label="{{ \App\Domain\Posts\FrazaWMoichWpisach::ETYKIETA }}" novalidate>
            <div class="field @if($fraza->blad) has-error @endif">
                <label for="f-szukaj-wpisy">{{ \App\Domain\Posts\FrazaWMoichWpisach::ETYKIETA }}</label>
                <span class="field-help" id="f-szukaj-wpisy-help">Wpisz kawałek opisu albo tytułu wpisu, np. „pierogi”. Polskie znaki nie mają znaczenia — „zurek” znajdzie „Żurek”.</span>
                <input class="field-input" id="f-szukaj-wpisy" name="szukaj" type="search" value="{{ $fraza->fraza }}"
                       maxlength="{{ \App\Domain\Search\SearchQuery::MAX_PHRASE_LENGTH }}"
                       aria-describedby="f-szukaj-wpisy-help{{ $fraza->blad ? ' f-szukaj-wpisy-error' : '' }}"
                       @if($fraza->blad) aria-invalid="true" @endif>
                @if($fraza->blad)
                    <span class="field-error" id="f-szukaj-wpisy-error">{{ $fraza->blad }}</span>
                @endif
            </div>
            <button class="btn btn-primary mt-4" type="submit">Szukaj</button>
        </form>
    @endif

    @if($fraza->aktywna())
        <section class="stack mb-6" aria-labelledby="wyniki-w-wpisach" data-wyniki-w-wpisach>
            <h2 id="wyniki-w-wpisach" class="m-0">Wyniki dla „{{ $fraza->fraza }}”</h2>
            @if($wpisy->total() === 0)
                {{-- Brak dopasowań to nie pusty dorobek — mówimy, czego nie znaleźliśmy i co zrobić. --}}
                <p class="m-0">Nie znaleźliśmy wśród Twoich wpisów niczego z „{{ $fraza->fraza }}” w opisie ani w tytule. Spróbuj krótszego kawałka. Wszystkie Twoje wpisy są nadal na liście.</p>
            @else
                <p class="meta m-0">Znaleźliśmy {{ $wpisy->total() }} {{ \App\Support\Odmiana::rzeczownik($wpisy->total(), 'wpis', 'wpisy', 'wpisów') }}, od najnowszego.</p>
            @endif
            <p class="m-0"><a class="btn btn-secondary" href="{{ route('collections.own-posts') }}">Pokaż wszystkie wpisy</a></p>
        </section>
    @endif

    @if($wpisy->total() === 0 && ! $fraza->aktywna())
        <x-empty-state title="Nie masz jeszcze żadnego wpisu" action="Dodaj wpis" :href="route('add')">
            Zrób zdjęcie tego, co dziś gotujesz, napisz kilka słów i opublikuj. Twoje wpisy znajdziesz potem tutaj.
        </x-empty-state>
    @endif
        {{-- Kontener zostaje także po opróżnieniu całej listy: „Pokaż więcej”
             rozpoznaje koniec bez usuwania wcześniej wczytanych kart. --}}
        <div class="stack" id="lista-moich-wpisow">
            @foreach($wpisy as $wpis)
                @php
                    $zdjecie = $wpis->media->first(fn ($m) => $m->maWariantDoPokazania('thumb'));
                    $opis = $wpis->title
                        ?? (filled($wpis->body) ? \Illuminate\Support\Str::limit($wpis->body, 160) : null)
                        ?? ($wpis->recipe !== null ? 'Przepis: '.$wpis->recipe->title : null)
                        ?? 'Wpis bez opisu';
                    $data = $wpis->published_at ?? $wpis->created_at;
                @endphp
                <article class="card" data-klucz="moj-wpis-{{ $wpis->getKey() }}" data-moj-wpis>
                    @if($zdjecie)
                        <x-photo :media="$zdjecie" variant="thumb" :zoom="false" sizes="160px" alt="" />
                    @endif
                    <h2 class="mt-3 mb-2 text-xl">{{ $opis }}</h2>
                    <p class="meta m-0">
                        @if($wpis->published_at && $wpis->status !== \App\Models\Post::STATUS_DRAFT)
                            Opublikowany <time datetime="{{ $data->toIso8601String() }}">{{ \App\Support\Czas::dataWpisu($data) }}</time>
                        @else
                            Założony <time datetime="{{ $data->toIso8601String() }}">{{ \App\Support\Czas::dataWpisu($data) }}</time>
                        @endif
                    </p>
                    <p class="m-0 mt-2">
                        <span class="badge" data-widocznosc>{{ \App\Domain\Posts\MojeWpisy::widocznosc($wpis) }}</span>
                        · <span class="badge" data-stan>{{ \App\Domain\Posts\MojeWpisy::stan($wpis) }}</span>
                    </p>
                    @if($wpis->status === \App\Models\Post::STATUS_HIDDEN)
                        <p class="meta m-0 mt-2">Tego wpisu nie widzi nikt poza Tobą i moderacją. Otwórz go, żeby zobaczyć szczegóły.</p>
                    @endif
                    <p class="m-0 mt-3">
                        <a class="btn btn-secondary" href="{{ $wpis->url() }}">Otwórz wpis</a>
                        @if($wpis->status === \App\Models\Post::STATUS_DRAFT && \App\Domain\Posts\Actions\PublishRestoredDraft::powodOdmowy($wpis) === null)
                            <a class="btn btn-primary" href="{{ route('posts.restored.confirm', $wpis) }}" data-opublikuj-przywrocony>Opublikuj</a>
                        @endif
                    </p>
                </article>
            @endforeach
        </div>

        <x-show-more :paginator="$wpisy" czego="wpisów" lista="lista-moich-wpisow" />
    </div>
</x-layout>
