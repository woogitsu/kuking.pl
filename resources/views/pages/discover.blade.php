<x-layout title="Świeżo z Kuking" description="Co ostatnio ugotowali ludzie w Kuking.">
    <h1>Świeżo z Kuking</h1>
    <p style="margin-bottom:var(--spacing-6);">
        Wszystko, co ludzie pokazali w ostatnich dniach — po kolei, od najnowszego.
        Bez żadnego układania przez komputer.
    </p>

    <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />

    @if($posts->count() === 0)
        <x-empty-state title="Jeszcze nic tu nie ma">Kuking dopiero się zaczyna.</x-empty-state>
    @else
        <div class="stack">
            @foreach($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>
        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
