<x-layout
    title="Pokaż, co dziś ugotowałeś"
    description="Kuking to polska społeczność ludzi, którzy naprawdę gotują. Wrzuć zdjęcie obiadu, zapisz przepisy po mamie i zobacz, komu z Twojego przepisu wyszło.">

    <section style="text-align:center; padding:var(--spacing-6) 0 var(--spacing-10);">
        <h1 class="text-title-lg">Pokaż, co dziś ugotowałeś</h1>
        <p style="font-size:var(--text-lead); max-width:34rem; margin:0 auto var(--spacing-6);">
            Kuking to miejsce dla ludzi, którzy gotują naprawdę — w swojej kuchni,
            z tego, co jest. Wrzucasz zdjęcie i kilka słów. Nic więcej nie musisz.
        </p>

        <div style="display:flex; gap:var(--spacing-3); justify-content:center; flex-wrap:wrap;">
            <a class="btn btn-primary" href="{{ route('register') }}">Zostań kuKINGiem — to darmowe</a>
            <a class="btn btn-secondary" href="{{ route('discover') }}">Najpierw się rozejrzę</a>
        </div>
    </section>

    <section class="card mb-8">
        <h2>Trzy rzeczy, które Kuking robi dla Ciebie</h2>
        <ul style="padding-left:var(--spacing-6);">
            <li><strong>Twoje przepisy nie zginą.</strong> Zeszyt z przepisami można zgubić, a telefon się psuje. Tu zostaje wszystko — i możesz to w każdej chwili pobrać na swój komputer.</li>
            <li><strong>Ktoś naprawdę ugotuje z Twojego przepisu.</strong> Kiedy komuś wyjdzie, dowiesz się o tym i zobaczysz zdjęcie. To jest tu najmilsza rzecz.</li>
            <li><strong>Przepisy po mamie i babci mają tu swoje miejsce.</strong> Możesz podpisać, po kim jest przepis, dopisać historię i dodać zdjęcie starej kartki z zeszytu.</li>
        </ul>
    </section>

    <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />

    <h2>Świeżo z Kuking</h2>
    <p class="meta mb-5">To, co ludzie ugotowali w ostatnich dniach.</p>

    @if($posts->count() === 0)
        <x-empty-state title="Kuking dopiero się zaczyna">
            Jeszcze nic tu nie ma. Jeśli lubisz gotować, możesz być jedną z pierwszych osób,
            które tu coś pokażą.
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>

        <p style="text-align:center; margin-top:var(--spacing-8);">
            <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto i pokaż swoje</a>
        </p>
    @endif
</x-layout>
