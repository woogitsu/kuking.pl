{{--
    Ponowna publikacja wpisu przywróconego przez moderację jako szkic (#2461).

    Ekran POKAZUJE, kto zobaczy wpis, i nie pozwala tego zmienić: zmiana
    widoczności to zwykła edycja. Wpis wraca na swoje dawne miejsce (zostaje
    pierwotna data), a obserwujący nie dostają powiadomienia.
--}}
@php
    $opis = $post->title
        ?? (filled($post->body) ? \Illuminate\Support\Str::limit($post->body, 160) : null)
        ?? ($post->recipe !== null ? 'Przepis: '.$post->recipe->title : null)
        ?? 'Wpis bez opisu';
    $kto = \App\Domain\Posts\MojeWpisy::widocznosc($post);
    $objasnienie = [
        'Publiczny' => 'Zobaczą go wszyscy, także osoby bez konta. Wpis może pojawić się w Google.',
        'Dla obserwujących' => 'Zobaczą go tylko osoby, które Cię obserwują.',
        'Tylko dla mnie' => 'Zobaczysz go tylko Ty.',
    ][$kto] ?? '';
    $zdjecie = $post->media->first(fn ($m) => $m->maWariantDoPokazania('thumb'));
@endphp
<x-layout title="Opublikuj wpis ponownie" :noindex="true">
    <h1>Opublikuj wpis ponownie</h1>

    <p>Moderacja zdjęła ukrycie tego wpisu, ale został u Ciebie jako szkic. Gdy go opublikujesz, wróci pod swoim dawnym adresem, z komentarzami i zdjęciami, na swoje dawne miejsce.</p>

    <section class="card mb-5" aria-labelledby="wpis-do-publikacji">
        @if($zdjecie)
            <x-photo :media="$zdjecie" variant="thumb" :zoom="false" sizes="160px" alt="" />
        @endif
        <h2 id="wpis-do-publikacji" class="mt-3 mb-2 text-xl">{{ $opis }}</h2>
        <p class="m-0"><strong>Kto zobaczy ten wpis:</strong> <span data-widocznosc>{{ $kto }}</span></p>
        <p class="meta m-0 mt-2">{{ $objasnienie }}</p>
        <p class="meta m-0 mt-2">Obserwujący nie dostaną powiadomienia. Widoczności nie zmienisz na tym ekranie — jeśli chcesz ją zmienić, najpierw <a href="{{ route('posts.edit', $post) }}">edytuj wpis</a>.</p>
    </section>

    <form method="POST" action="{{ route('posts.restored.publish', $post) }}" novalidate>
        @csrf
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Opublikuj</button>
            <a class="btn btn-quiet" href="{{ route('collections.own-posts') }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
