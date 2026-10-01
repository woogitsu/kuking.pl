<x-layout title="Zaproszenie do wspólnego gotowania" :noindex="true">
    {{--
        LINK DO WSPÓLNEGO GOTOWANIA (#2385). Ten widok powstaje WYŁĄCZNIE dla
        osoby, która przeszła wszystkie bramki dołączenia (konto aktywne, brak
        blokady z gospodarzem, `RecipePolicy::view` dla przepisu) — tytuł
        przepisu nie trafia do nikogo, kto go nie mógłby zobaczyć. Samo
        otwarcie strony niczego nie zużywa; dołącza dopiero przycisk.
    --}}
    <div class="stack max-w-[38rem] mx-auto">
        <h1>Zaproszenie do wspólnego gotowania</h1>

        <p class="text-lg"><strong>{{ $gospodarz->displayName() }}</strong> zaprasza Cię do wspólnego gotowania: <strong>{{ $recipe->title }}</strong>.</p>

        <section class="panel-formularza" aria-labelledby="wg-co-znaczy">
            <h2 id="wg-co-znaczy" class="mt-0">Co to znaczy</h2>
            <ul>
                <li>widzisz ten sam przepis i te same odhaczone kroki co gospodarz,</li>
                <li>możesz odhaczać kroki — pozostałe osoby zobaczą to po odświeżeniu,</li>
                <li>gospodarz i pozostali pomocnicy (jest ich w sesji najwyżej trzech) widzą Twoją nazwę i to, które kroki odhaczono przez Ciebie — bez wiadomości i bez publikowania czegokolwiek,</li>
                <li>sesja kończy się sama po 24 godzinach od założenia i wtedy znika razem z odhaczeniami.</li>
            </ul>
            <p class="m-0">Możesz wyjść w każdej chwili. Gospodarz może Ci odebrać dostęp.</p>
        </section>

        <form method="POST" action="{{ route('wspolne-gotowanie.link.accept', $token) }}">
            @csrf
            <button class="btn btn-primary" type="submit">Dołączam</button>
        </form>
        <p class="meta m-0">Link jest ważny do {{ \App\Support\Czas::data($wazneDo, 'j F, H:i') }} i działa raz.</p>
        <a class="btn btn-secondary" href="{{ route('home') }}">Nie, dziękuję</a>
    </div>
</x-layout>
