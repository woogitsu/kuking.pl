{{--
    Tagi promowane — panel gospodarza (D-021, „tag promowany — lista
    gospodarza", etap 5/5).

    Ta lista zastępuje trzy rzeczy, które dawał Temat: gwarancję, że nowe
    konto nie widzi pustki (onboarding czyta ją wprost, `Tag::promowane()`),
    „temat tygodnia" i przygotowane okazje sezonowe — wszystko jednym
    formularzem, bez drugiego typu obiektu w interfejsie.

    Zwykłe formularze, bez JavaScriptu — ten sam standard co reszta serwisu,
    mimo że to ekran wyłącznie dla gospodarza.
--}}
<x-layout title="Tagi promowane" :noindex="true">
    <h1>Tagi promowane</h1>

    <p class="lead">
        Ta lista zastępuje dawne Tematy. Nowe konto widzi ją w onboardingu,
        a strona główna z niej buduje pierwszy feed osoby, która jeszcze
        nikogo nie obserwuje. „Temat tygodnia" i sezonowe okazje (Wigilia,
        tłusty czwartek) to zwykły tag na tej liście, z notatką.
    </p>

    <x-error-summary />

    <form class="card mb-6" method="POST" action="{{ route('admin.tag-promotions.store') }}">
        @csrf

        <x-field
            name="nazwa_tagu"
            label="Dodaj tag do listy"
            help="Wpisz dokładną nazwę istniejącego, aktywnego tagu, np. „sernik”. Ten ekran nie tworzy nowych tagów."
        />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Dodaj do promowanych</button>
        </div>
    </form>

    @if($promowane->isEmpty())
        <x-empty-state title="Lista jest pusta">
            <p class="mb-0">
                Onboarding pokazuje zachętę do pominięcia kroku, dopóki nie
                dodasz tu choć jednego tagu — dodaj kilka najbardziej
                uniwersalnych, żeby nowe konta miały co zaznaczyć.
            </p>
        </x-empty-state>
    @else
        <ol class="stack lista-naga">
            @foreach($promowane as $tag)
                <li class="card">
                    <div class="flex items-center justify-between gap-3">
                        <strong>{{ $tag->name }}</strong>
                        <a href="{{ route('tags.show', $tag) }}">Zobacz stronę tagu</a>
                    </div>

                    <form method="POST" action="{{ route('admin.tag-promotions.update', $tag) }}" class="mt-3">
                        @csrf
                        @method('PUT')

                        <x-field
                            name="note"
                            label="Notatka (nieobowiązkowo)"
                            :value="$tag->promotion?->note"
                            help="Np. „Temat tygodnia: rozgrzewające zupy na jesień”."
                        />

                        <div class="form-actions">
                            <button class="btn btn-secondary" type="submit">Zapisz notatkę</button>
                            <button class="btn btn-quiet" type="submit" name="w_gore" value="1">W górę</button>
                            <button class="btn btn-quiet" type="submit" name="w_dol" value="1">W dół</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.tag-promotions.destroy', $tag) }}" class="mt-3">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-quiet" type="submit">Zdejmij z promowanych</button>
                    </form>
                </li>
            @endforeach
        </ol>
    @endif
</x-layout>
