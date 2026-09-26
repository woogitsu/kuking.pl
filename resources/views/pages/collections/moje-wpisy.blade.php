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

    @if($wpisy->isEmpty())
        <x-empty-state title="Nie masz jeszcze żadnego wpisu" action="Dodaj wpis" :href="route('add')">
            Zrób zdjęcie tego, co dziś gotujesz, napisz kilka słów i opublikuj. Twoje wpisy znajdziesz potem tutaj.
        </x-empty-state>
    @else
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
                        @if($wpis->published_at)
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
                    </p>
                </article>
            @endforeach
        </div>

        <x-show-more :paginator="$wpisy" czego="wpisów" lista="lista-moich-wpisow" />
    @endif
    </div>
</x-layout>
