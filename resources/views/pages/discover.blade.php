<x-layout title="Świeżo z Kuking" description="Co ostatnio ugotowali ludzie w Kuking.">
    <h1>Świeżo z Kuking</h1>
    <p class="mb-6">
        Wszystko, co ludzie pokazali w ostatnich dniach — po kolei, od najnowszego.
        Bez żadnego układania przez komputer.
    </p>

    <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />

    @if($posts->count() === 0)
        {{--
            Etap D kitu v2 — naprawa pustego stanu.

            Ten pusty stan łamał WŁASNĄ regułę komponentu (patrz komentarz
            w `empty-state.blade.php`: „zawsze jeden przycisk z tekstem") —
            wcześniej wołany był bez `action`/`href`, więc kończył się na
            samym zdaniu, bez żadnej drogi dalej. Tekst wyjaśnienia też był
            inny niż w `docs/brand/COPY_STYLE.md` §6 „pusty feed", mimo że to
            dokładnie ten sam przypadek, który `home.blade.php` ma poprawnie.

            Przycisk zależy od tego, KTO patrzy: `/odkryj` działa też bez
            konta (FeedController::discover), a `/dodaj/zdjecie` konta
            wymaga — więc gość dostaje to samo zaproszenie co na stronie
            powitalnej (`landing.blade.php`, identyczny przypadek „nic tu
            jeszcze nie ma"), nie martwy odnośnik kończący się na logowaniu.
        --}}
        @auth
            <x-empty-state title="Jeszcze nic tu nie ma" action="Dodaj pierwsze zdjęcie" :href="route('posts.create')">
                Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
            </x-empty-state>
        @else
            <x-empty-state title="Jeszcze nic tu nie ma" action="Załóż konto i pokaż swoje" :href="route('register')">
                Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
            </x-empty-state>
        @endauth
    @else
        <div class="stack">
            @foreach($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>
        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
