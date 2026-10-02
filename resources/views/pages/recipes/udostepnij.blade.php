{{--
    „POKAŻ TEN PRZEPIS WYBRANEJ OSOBIE" — EKRAN AUTORA (#2650, D-333).

    Jedna kolumna, od góry: co zobaczy ta osoba i czego udostępnienie NIE
    zmienia, kto ma dostęp (z „Odbierz dostęp" przy każdej osobie), potem
    formularz. Formularz ma dwa kroki bez JavaScriptu: najpierw nazwa konta,
    potem potwierdzenie z odbiorcą i zakresem (issue: „przed potwierdzeniem
    pokazać odbiorcę i zakres"). Odebranie dostępu idzie przez
    `x-confirm-button`, z nazwą osoby w mianowniku osobno (COPY_STYLE).
--}}
@php
    $ktoWidzi = match ($recipe->visibility) {
        'public' => 'Wszyscy',
        'followers' => 'Osoby, które Cię obserwują',
        default => 'Tylko Ty',
    };
@endphp
<x-layout :title="'Udostępnij przepis: '.$recipe->title" :noindex="true">
    <h1>Pokaż przepis wybranej osobie</h1>
    <p class="meta">Przepis „{{ $recipe->title }}”</p>

    <x-error-summary />

    <section class="panel-formularza mb-6" aria-labelledby="co-zobaczy">
        <h2 id="co-zobaczy" class="mt-0">Co zobaczy ta osoba</h2>
        <ul>
            <li>ten jeden przepis — zdjęcie, historię, składniki i kroki,</li>
            <li>na osobnej stronie, po zalogowaniu na swoje konto.</li>
        </ul>
        <p>Nie zobaczy skanu kartki ani wcześniejszych wersji. Nie skomentuje przepisu, nie oznaczy „Ugotowałem”, nie zapisze go w zeszycie i nie zrobi z niego swojej wersji. Nie może go też pokazać dalej.</p>
        <p>Dostęp możesz odebrać w każdej chwili — działa od razu. Tego, co ta osoba zdąży wydrukować albo przepisać, nie da się cofnąć.</p>
        <p class="m-0"><strong>Kto widzi ten przepis w serwisie: {{ $ktoWidzi }}.</strong> Udostępnienie tego nie zmienia — przepis nie trafia do Obserwowanych, wyszukiwarki ani na Twój profil dla innych.</p>
    </section>

    <h2>Kto ma dostęp</h2>
    @if($udostepnienia->isEmpty())
        <p>Na razie nikt poza osobami, które widzą go w serwisie.</p>
    @else
        <ul class="stack list-none p-0" data-udostepnienia>
            @foreach($udostepnienia as $udostepnienie)
                <li class="card">
                    <p class="m-0"><strong>{{ $udostepnienie->recipient?->displayName() }}</strong>
                        @if($udostepnienie->recipient?->profile?->username)
                            <span class="meta">{{ '@'.$udostepnienie->recipient->profile->username }}</span>
                        @endif
                    </p>
                    <p class="meta mt-1">Ma dostęp od {{ \App\Support\Czas::data($udostepnienie->created_at) }}.</p>
                    <x-confirm-button
                        :action="route('recipes.shares.destroy', ['recipe' => $recipe, 'share' => $udostepnienie])"
                        label="Odbierz dostęp"
                        question="Odebrać tej osobie dostęp do przepisu? Nie otworzy go już."
                        :name="$udostepnienie->recipient?->displayName()" />
                </li>
            @endforeach
        </ul>
    @endif

    @if($mozeUdostepniac)
        <p class="meta mt-6">Przepis możesz pokazać najwyżej {{ $limit }} {{ \App\Support\Odmiana::rzeczownik($limit, 'osobie', 'osobom', 'osobom') }}.</p>

        @if($kandydat !== null)
            <section class="panel-formularza mt-4" aria-labelledby="potwierdz-udostepnienie" data-potwierdzenie-udostepnienia>
                <h2 id="potwierdz-udostepnienie" class="mt-0">Sprawdź, komu pokazujesz przepis</h2>
                <p class="m-0"><strong>{{ $kandydat->displayName() }}</strong>
                    @if($kandydat->profile?->username)
                        <span class="meta">{{ '@'.$kandydat->profile->username }}</span>
                    @endif
                </p>
                <p>Ta osoba będzie mogła czytać przepis „{{ $recipe->title }}”. Nie dostanie powiadomienia, więc powiedz jej o tym. Przepis znajdzie w „Moje”, w części „Przepisy udostępnione mi”.</p>
                <form method="POST" action="{{ route('recipes.shares.store', $recipe) }}">
                    @csrf
                    <input type="hidden" name="nazwa" value="{{ $nazwaKandydata }}">
                    <input type="hidden" name="potwierdzam" value="1">
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Tak, pokaż tej osobie</button>
                        <a class="btn btn-quiet" href="{{ route('recipes.shares.index', $recipe) }}">To nie ta osoba</a>
                    </div>
                </form>
            </section>
        @else
            <section class="panel-formularza mt-4" aria-labelledby="komu-pokazac">
                <h2 id="komu-pokazac" class="mt-0">Komu pokazać przepis</h2>
                <form method="POST" action="{{ route('recipes.shares.store', $recipe) }}" novalidate>
                    @csrf
                    <x-field name="nazwa" label="Nazwa konta" required
                             help="Jest na profilu tej osoby, po znaku @ — na przykład halina.k. Na następnym kroku zobaczysz, kogo wskazuje, i dopiero wtedy potwierdzisz." />
                    <button class="btn btn-primary" type="submit">Dalej</button>
                </form>
            </section>
        @endif
    @elseif($recipe->visibility === 'public' && $recipe->isPublished())
        <p class="notice mt-6">Ten przepis widzą wszyscy, więc nie trzeba go nikomu osobno pokazywać. Jeśli chcesz pokazać go tylko wybranym osobom, w edycji przepisu, przy pytaniu „Kto ma widzieć ten przepis?”, wybierz „Tylko ja”.</p>
    @elseif(! $recipe->isPublished())
        <p class="notice mt-6">Pokazać można tylko opublikowany przepis. Odebrać dostęp możesz zawsze.</p>
    @else
        <p class="notice mt-6">Teraz nie możesz pokazywać przepisu nowym osobom. Odebrać dostęp możesz zawsze.</p>
    @endif

    <a class="btn btn-secondary mt-8" href="{{ route('recipes.show', $recipe) }}">Wróć do przepisu</a>
</x-layout>
