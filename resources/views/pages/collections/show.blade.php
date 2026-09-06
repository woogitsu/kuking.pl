<x-layout :title="$collection->name" :noindex="! $collection->isPublic()">
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
            <div class="stack">
                @foreach($recipes as $recipe)
                    <x-recipe-card :recipe="$recipe" />
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
</x-layout>
