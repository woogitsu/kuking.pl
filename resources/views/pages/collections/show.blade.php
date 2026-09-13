<x-layout :title="$collection->name" :noindex="! $collection->isPublic()">
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
    @if($collection->description)
        <p>{{ $collection->description }}</p>
    @endif
    <p class="meta mb-5">
        {{ $collection->isPublic() ? 'Ten zeszyt widzą wszyscy.' : 'Ten zeszyt widzisz tylko Ty.' }}
    </p>

    @if($recipes->count() === 0 && ($posts ?? collect())->count() === 0 && ($niewidoczne ?? 0) === 0)
        <x-empty-state title="W tym zeszycie nic jeszcze nie ma" action="Poszukaj przepisów" :href="route('discover')" />
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
                    <x-post-card :post="$post" />
                @endforeach
            </div>
            <x-show-more :paginator="$posts" czego="zapisanych wpisów" />
        @endif

        @if(($niewidoczne ?? 0) > 0)
            {{--
                NIE MÓWIMY, CO TU BYŁO — MÓWIMY, ŻE COŚ BYŁO.

                Wpis zapisany, gdy autor pokazywał go obserwującym, znika
                po tym, jak przestaniesz go obserwować. Ciche zniknięcie
                wygląda jak utrata danych („miałam to tu wczoraj"), a pokazanie
                treści łamie widoczność, którą autor sobie ustawił. Zostaje
                trzecia droga: powiedzieć ILE, nie mówiąc CZEGO.
            --}}
            <p class="notice mt-6">
                {{ $niewidoczne }}
                {{ \App\Support\Odmiana::rzeczownik($niewidoczne, 'zapisany wpis', 'zapisane wpisy', 'zapisanych wpisów') }}
                {{ $niewidoczne === 1 ? 'nie jest' : 'nie są' }}
                już dla Ciebie widoczne — autor zmienił ustawienia albo konto nie jest już dostępne.
                Nic nie zniknęło z Twojego zeszytu.
            </p>
        @endif
    @endif

    @if(auth()->id() === $collection->owner_id && ! $collection->is_default)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('collections.destroy', $collection)"
                label="Usuń ten zeszyt"
                question="Usunąć ten zeszyt? Same przepisy zostaną — znikną tylko z tego zeszytu." />
        </div>
    @endif
    </div>
</x-layout>
