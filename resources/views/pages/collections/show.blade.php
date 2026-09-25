{{--
    OPIS DLA WYSZUKIWARKI I PODGLĄDU LINKU (issue #965). Publiczny zeszyt jest
    od tej zmiany dostępny bez konta, więc trafia do indeksu i do podglądów
    linków wysłanych rodzinie. Prywatny ma `noindex` i opisu nie potrzebuje —
    nie oddajemy go nawet w nagłówku strony.
--}}
@php
    $opisStrony = $collection->isPublic()
        ? \Illuminate\Support\Str::limit(
            trim((string) $collection->description) !== ''
                ? trim((string) $collection->description)
                : 'Zeszyt „'.$collection->name.'” — przepisy i wpisy zebrane'.($collection->owner ? ' przez '.$collection->owner->displayName() : '').' w Kuking.',
            160,
        )
        : null;
@endphp
<x-layout :title="$collection->name" :noindex="! $collection->isPublic()" :description="$opisStrony">
    {{--
        PRAWA SZYNA (issue #205): pozostałe zeszyty tej samej osoby.

        To jest jedyna czynność, którą naprawdę robi się Z TEGO ekranu —
        przejście do drugiego zeszytu wymagało do tej pory cofnięcia się
        na „Moje". Kontroler oddaje tu wyłącznie zeszyty, które oglądający
        ma prawo otworzyć (`CollectionController::show()`); widok niczego
        nie filtruje sam.

        Osoba, która ma tylko jeden zeszyt, nie dostaje żadnego bloku —
        pusta szyna jest lepsza niż karta, która nic nie wnosi.
    --}}
    @if($inneZeszyty->isNotEmpty())
        <x-slot:rail>
            <x-szyna-blok
                :tytul="auth()->id() === $collection->owner_id ? 'Twoje inne zeszyty' : 'Inne zeszyty tej osoby'"
                id="szyna-inne-zeszyty"
                ikona="book"
                :wiecej="auth()->id() === $collection->owner_id ? route('collections.index') : null">
                <x-szyna-linki akcja="Otwórz zeszyt" :pozycje="$inneZeszyty->map(fn ($zeszyt) => [
                    'href' => route('collections.show', $zeszyt),
                    'nazwa' => $zeszyt->name,
                    'podpis' => $zeszyt->description,
                ])->all()" />
            </x-szyna-blok>
        </x-slot:rail>
    @endif

    <div class="marka-zeszyt">
    <h1>{{ $collection->name }}</h1>
    @if($saveContext !== [])
        <section class="panel-formularza mb-5">
            @if($saveContent)
                <h2>Dokończ zapis</h2>
                <p>{{ $saveContent instanceof \App\Models\Recipe ? $saveContent->title : (trim($saveContent->body ?? '') ?: 'Zdjęcie bez opisu') }}</p>
                <form method="POST" data-dokoncz-zapis action="{{ $saveContent instanceof \App\Models\Recipe ? route('collections.save', $saveContent->slug) : route('collections.save-post', $saveContent) }}">
                    @csrf
                    <input type="hidden" name="collection_id" value="{{ $collection->getKey() }}">
                    <input type="hidden" name="open_collection" value="1">
                    <button class="btn btn-primary" type="submit">Zapisuję w tym zeszycie</button>
                </form>
                <a class="btn btn-secondary mt-3" href="{{ $saveContent->url() }}">Wróć {{ $saveContent instanceof \App\Models\Recipe ? 'do przepisu' : 'do wpisu' }}</a>
            @else
                <p>Ta treść nie jest już dostępna. Zeszyt został utworzony, ale niczego w nim nie zapisaliśmy. Poszukaj innego przepisu lub wpisu.</p>
                <a class="btn btn-secondary" href="{{ route('search') }}">Szukaj</a>
            @endif
            <a class="btn btn-secondary mt-3" href="{{ route('collections.show', $collection) }}">Zostaw zeszyt bez tego zapisu</a>
        </section>
    @endif
    @if($collection->description)
        <p>{{ $collection->description }}</p>
    @endif
    <p class="meta mb-5">
        {{ $collection->isPublic() ? 'Ten zeszyt widzą wszyscy.' : 'Ten zeszyt widzisz tylko Ty.' }}
    </p>

    @if($recipes->count() === 0 && ($posts ?? collect())->count() === 0 && ($niewidoczne ?? 0) === 0)
        <x-empty-state title="W tym zeszycie nic jeszcze nie ma" action="Poszukaj przepisów" :href="route('search', ['sekcja' => 'przepisy'])" />
    @else
        @if($recipes->count() > 0)
            <h2>Przepisy</h2>
            <div class="marka-zeszyt-przepisy">
                @foreach($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" uklad="kafel" />
                @endforeach
            </div>
            <x-show-more :paginator="$recipes" czego="przepisów" />
        @endif

        @if(($posts ?? collect())->count() > 0)
            {{-- Wpisy odłożone „na potem" (UI kit v2, ekran 01). Osobna sekcja,
                 a nie wymieszane z przepisami: to są dwie różne rzeczy i dwa
                 różne powody, dla których się je zapisuje. --}}
            <h2 class="mt-8">Zapisane wpisy</h2>
            <div class="stack">
                @foreach($posts as $post)
                    {{-- `:zeszyt` daje karcie kontekst TEGO zeszytu, więc
                         zamiast odnośnika „Masz to w zeszycie" pokazuje
                         przycisk usuwający TYLKO stąd (issue #775, #776). --}}
                    <x-post-card :post="$post" :zeszyt="$collection" />
                @endforeach
            </div>
            <x-show-more :paginator="$posts" czego="zapisanych wpisów" />
        @endif

        @if(($niewidoczne ?? 0) > 0)
            {{--
                NIE MÓWIMY, CO TU BYŁO — MÓWIMY, ŻE COŚ BYŁO.

                Zapisana treść, którą autor pokazywał obserwującym, znika
                po tym, jak przestaniesz go obserwować. Ciche zniknięcie
                wygląda jak utrata danych („miałam to tu wczoraj"), a pokazanie
                treści łamie widoczność, którą autor sobie ustawił. Zostaje
                trzecia droga: powiedzieć ILE, nie mówiąc CZEGO.
            --}}
            <p class="notice mt-6" data-niedostepne-zapisy>
                {{ $niewidoczne }}
                {{ \App\Support\Odmiana::rzeczownik($niewidoczne, 'zapis nie jest dla Ciebie dostępny', 'zapisy nie są dla Ciebie dostępne', 'zapisów nie jest dla Ciebie dostępnych') }}.
                Te zapisy nadal są w tym zeszycie.
            </p>
        @endif
    @endif

    @guest
        {{--
            GOŚĆ NIE DOSTAJE ŻADNEJ AKCJI ZA LOGOWANIEM (issue #965).

            Zeszyt „Wszyscy" otwiera się bez konta, ale zapisywanie i własne
            zeszyty wymagają konta. Zamiast przycisku, który przerzuca
            na logowanie bez słowa wyjaśnienia, mówimy wprost, co trzeba zrobić.
        --}}
        <section class="panel-formularza mt-8" data-rola="zeszyt-gosc">
            <h2>Chcesz mieć własny zeszyt?</h2>
            <p>Zaloguj się albo załóż konto, a zapiszesz ulubione przepisy we własnym zeszycie.</p>
            <div class="form-actions">
                <a class="btn btn-primary" href="{{ route('login') }}">Zaloguj się</a>
                <a class="btn btn-secondary" href="{{ route('register') }}">Załóż konto</a>
            </div>
        </section>
    @endguest

    @if(auth()->id() === $collection->owner_id)
        {{--
            COFNIĘCIE PUBLICZNEGO UDOSTĘPNIENIA BEZ KASOWANIA ZESZYTU (#777).

            Do tej zmiany jedyną widoczną drogą do zamknięcia publicznego
            zeszytu było usunięcie go w całości — razem z nazwą, opisem
            i wszystkimi zapisami. „Edytuj zeszyt" prowadzi na formularz
            z tymi samymi trzema polami co przy zakładaniu, więc zmiana
            widoczności nie wymaga już utraty niczego innego.
        --}}
        <a class="btn btn-secondary mt-6" href="{{ route('collections.edit', $collection) }}">Edytuj zeszyt</a>
    @endif

    @if(auth()->id() === $collection->owner_id && ! $collection->is_default)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('collections.destroy', $collection)"
                label="Usuń ten zeszyt"
                :question="'Usunąć zeszyt „'.$collection->name.'”? Same przepisy zostaną — znikną tylko z tego zeszytu.'" />
        </div>
    @endif
    </div>
</x-layout>
