{{--
    „Twoje tematy" — jeden ekran z całą listą (issue #31).

    Wybór zapada w onboardingu, w momencie, w którym człowiek chętnie
    odpowiada na pytania o siebie. Potem nie było go gdzie zmienić,
    a zainteresowania się zmieniają. To jest jedyny ekran, na którym widać,
    co właściwie decyduje o zawartości strony głównej.

    Zwykły formularz, bez JavaScriptu: zaznaczenie i „Zapisz".
--}}
<x-layout title="Twoje tematy" :noindex="true">
    <h1>Twoje tematy</h1>

    <p class="lead">
        Z tych tematów budujemy Twoją stronę główną, dopóki nikogo nie
        obserwujesz. Kiedy zaczniesz obserwować ludzi, ich wpisy będą
        ważniejsze niż tematy — i to one pojawią się na górze.
    </p>

    <form method="POST" action="{{ route('settings.topics.update') }}">
        @csrf
        @method('PUT')

        <div class="choice-grid">
            @foreach($topics as $topic)
                <label class="choice">
                    <input type="checkbox" name="topics[]" value="{{ $topic->getKey() }}"
                           @checked(in_array($topic->getKey(), $followed, true))>
                    <span class="choice-label">
                        {{ $topic->name }}
                        @if($topic->description)
                            <span class="meta">{{ $topic->description }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
        </div>
    </form>

    <x-ustawienia-nawigacja aktywne="topics" />
</x-layout>
