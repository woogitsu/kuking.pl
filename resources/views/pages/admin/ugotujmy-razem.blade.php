{{--
    „Ugotujmy razem” (F3) — panel gospodarza: jeden przepis na tydzień.

    Zwykły formularz, bez JavaScriptu: lista tygodni i pole na adres
    przepisu. Ekran nie podpowiada przepisów i nie pokazuje liczb — wybór
    ma wynikać z patrzenia na przepisy i ludzi, nie ze słupków (D-275).
    Warto zmieniać autorów z tygodnia na tydzień; lista niżej pokazuje, czyje
    przepisy już były.
--}}
<x-layout title="Ugotujmy razem — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Ugotujmy razem" />

    <h1>Ugotujmy razem</h1>

    <p class="text-lead">
        Wybierz jeden publiczny przepis na tydzień. Strona
        <a href="{{ route('ugotujmy-razem') }}">„Ugotujmy razem”</a> pokaże go
        z podpisem „Wybór gospodarza”, a pod nim wykonania z tego tygodnia,
        od najnowszego. Tydzień trwa od poniedziałku do niedzieli, czasu polskiego.
    </p>

    <x-error-summary />

    @php($bladTygodnia = $errors->first('tydzien'))
    <form class="panel-formularza mb-6" method="POST" action="{{ route('admin.ugotujmy-razem.store') }}">
        @csrf

        <div class="field @if($bladTygodnia) has-error @endif">
            <label for="f-tydzien">Tydzień <span class="meta">(wymagane)</span></label>
            <span class="field-help" id="f-tydzien-help">
                Jeśli na ten tydzień jest już przepis, nowy go zastąpi.
            </span>
            <select class="field-input" id="f-tydzien" name="tydzien" required
                    aria-describedby="f-tydzien-help @if($bladTygodnia) f-tydzien-error @endif"
                    @if($bladTygodnia) aria-invalid="true" @endif>
                @foreach($tygodnie as $iso => $opis)
                    <option value="{{ $iso }}" @selected(old('tydzien') === $iso)>{{ $opis }}</option>
                @endforeach
            </select>
            @if($bladTygodnia)
                <span class="field-error" id="f-tydzien-error">{{ $bladTygodnia }}</span>
            @endif
        </div>

        <x-field
            name="przepis"
            label="Adres przepisu"
            :required="true"
            help="Otwórz przepis w serwisie i skopiuj adres z paska przeglądarki, np. kuking.pl/przepisy/golabki-basi. Przepis musi być publiczny."
        />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Wybierz przepis tygodnia</button>
        </div>
    </form>

    <section class="sekcja-strony" aria-labelledby="zaplanowane-naglowek">
        <h2 id="zaplanowane-naglowek">Ten tydzień i następne</h2>

        @if($zaplanowane->isEmpty())
            <p class="m-0">Nie ma jeszcze wybranego przepisu na ten ani na następne tygodnie.</p>
        @else
            <ul class="stack lista-naga">
                @foreach($zaplanowane as $wybor)
                    <li class="sekcja-strony" data-klucz="wybor-{{ $wybor->getKey() }}">
                        <p class="mb-2">
                            <strong>Tydzień {{ $wybor->tydzien()->opis() }}</strong>
                            @if($wybor->tydzien()->jestBiezacy()) — trwa teraz @endif
                        </p>
                        <p class="mb-2">
                            @if($wybor->recipe)
                                <a href="{{ route('recipes.show', $wybor->recipe->slug) }}">{{ $wybor->recipe->title }}</a>
                                <span class="meta">— {{ $wybor->recipe->attributionLine() }}</span>
                                @if(! $wybor->recipe->isPublished() || $wybor->recipe->visibility !== 'public')
                                    <br><span class="field-error">Ten przepis nie jest już publiczny, więc strona go nie pokazuje. Wybierz inny.</span>
                                @endif
                            @else
                                Przepisu, który był tu wybrany, już nie ma. Wybierz inny.
                            @endif
                        </p>
                        <div class="mt-6">
                            <x-confirm-button
                                :action="route('admin.ugotujmy-razem.destroy', $wybor)"
                                label="Zdejmij ten wybór"
                                question="Zdjąć przepis tygodnia {{ $wybor->tydzien()->opis() }}? Strona „Ugotujmy razem” nie pokaże wtedy przepisu na ten tydzień. Przepis i wykonania zostają."
                                :fields="['potwierdzam' => '1']"
                            />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if($zakonczone->isNotEmpty())
        <section class="sekcja-strony mt-6" aria-labelledby="zakonczone-naglowek">
            <h2 id="zakonczone-naglowek">Ostatnie tygodnie</h2>
            <p>Zakończonych tygodni nie zmieniamy — to archiwum tego, co naprawdę gotowaliśmy.</p>
            <ul class="stack lista-naga">
                @foreach($zakonczone as $wybor)
                    <li>
                        Tydzień {{ $wybor->tydzien()->opis() }}:
                        {{ $wybor->recipe?->title ?? 'przepisu już nie ma' }}
                        @if($wybor->recipe)
                            <span class="meta">— {{ $wybor->recipe->attributionLine() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layout>
