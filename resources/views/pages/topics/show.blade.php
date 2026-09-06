{{--
    Strona tematu (issue #31).

    Temat bez własnej strony jest etykietą, a nie miejscem. Cała wartość
    tematów polega na tym, że osoba, która nikogo nie obserwuje, ma DOKĄD
    pójść (SOUL.md 4.7).

    Lista jest CHRONOLOGICZNA — żadnego „najpopularniejsze w temacie".
    To ta sama decyzja co przy feedzie i z tego samego powodu: ranking
    zamienia dzielenie się jedzeniem w konkurs.
--}}
<x-layout :title="$topic->name" :description="$topic->description">
    <p class="meta" style="margin-bottom:var(--spacing-2);">
        <a href="{{ route('discover') }}">Świeżo z Kuking</a> · temat
    </p>

    <h1 style="margin-top:0;">{{ $topic->name }}</h1>

    @if($topic->description)
        <p class="lead">{{ $topic->description }}</p>
    @endif

    <div class="stack" style="margin-top:var(--spacing-6);">
        @forelse($posts as $post)
            <x-post-card :post="$post" />
        @empty
            <x-empty-state title="Tu jeszcze nikt nic nie ugotował">
                <p>
                    Ten temat czeka na pierwszy wpis. Jeśli gotujesz coś, co tu pasuje,
                    możesz być pierwszą osobą.
                </p>
                <p style="margin-bottom:0;">
                    <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj zdjęcie</a>
                </p>
            </x-empty-state>
        @endforelse
    </div>

    @if($posts->hasPages())
        <div style="margin-top:var(--spacing-6);">{{ $posts->links() }}</div>
    @endif
</x-layout>
