<x-layout title="Świeżo z Kuking" description="Co ostatnio ugotowali ludzie w Kuking.">
    <h1>Świeżo z Kuking</h1>
    <p style="margin-bottom:var(--spacing-6);">
        Wszystko, co ludzie pokazali w ostatnich dniach — po kolei, od najnowszego.
        Bez żadnego układania przez komputer.
    </p>

    @if($suggestedPeople->isNotEmpty())
        <section class="card" style="margin-bottom:var(--spacing-6);">
            <h2>Kogo warto obserwować</h2>
            <div class="stack-tight">
                @foreach($suggestedPeople as $person)
                    <div style="display:flex; align-items:center; gap:var(--spacing-3); flex-wrap:wrap;">
                        <x-avatar :user="$person" :size="44" />
                        <div style="flex:1; min-width:10rem;">
                            <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                            <p class="meta" style="margin:0;">{{ $person->profile->speciality ?? 'Gotuje w Kuking' }}</p>
                        </div>
                        @auth
                            <form method="POST" action="{{ route('social.follow', $person->profile->username) }}">
                                @csrf
                                <button class="btn btn-secondary" type="submit">Obserwuj</button>
                            </form>
                        @endauth
                    </div>
                @endforeach
            </div>
        </section>
    @endif

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
