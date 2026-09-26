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
<x-layout title="Tagi promowane — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Tagi promowane" />

    <h1>Tagi promowane</h1>

    <p class="text-lead">
        Ta lista zastępuje dawne Tematy. Nowe konto widzi ją zaraz po
        założeniu, a strona główna układa z niej pierwsze wpisy dla osoby,
        która jeszcze nikogo nie obserwuje. „Temat tygodnia" i sezonowe okazje (Wigilia,
        tłusty czwartek) to zwykły tag na tej liście, z notatką.
    </p>

    <x-error-summary />

    <form class="panel-formularza mb-6" method="POST" action="{{ route('admin.tag-promotions.store') }}">
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
                {{-- `sekcja-strony`, nie `panel-formularza`, mimo pola w środku: notatka
                     jest nieobowiązkowa, a wypełnienia wymaga formularz wyżej. --}}
                <li class="sekcja-strony">
                    <div class="flex items-center justify-between gap-3">
                        <strong>{{ $tag->name }}</strong>
                        <a href="{{ route('tags.show', $tag) }}">Zobacz stronę tagu</a>
                    </div>

                    <form method="POST" action="{{ route('admin.tag-promotions.update', $tag) }}" class="mt-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_wiersz" value="{{ $tag->getKey() }}">

                        <x-field
                            name="note"
                            :wiersz="$tag->getKey()"
                            label="Notatka"
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

    @if($wyroznienia !== null)
        {{-- Tag tygodnia (issue #18): zwykły tag wyróżniony na wybrane dni.
             Blok na stronie głównej pokazuje się tylko w tych dniach, a po
             ich końcu znika — strona tagu i wpisy zostają. --}}
        <section class="sekcja-strony mt-6" aria-labelledby="tag-tygodnia-naglowek">
            <h2 id="tag-tygodnia-naglowek">Tag tygodnia</h2>

            <p>
                W wybrane dni strona główna zaprasza do dodania wpisu z tym tagiem.
                Dwa wyróżnienia nie mogą nachodzić na siebie.
            </p>

            <form class="panel-formularza" method="POST" action="{{ route('admin.tag-highlights.store') }}">
                @csrf

                <x-field
                    name="tag_tygodnia"
                    label="Tag"
                    :required="true"
                    help="Dokładna nazwa istniejącego, aktywnego tagu, np. „pierogi”."
                />
                <x-field name="od_dnia" type="date" label="Pierwszy dzień" :required="true" />
                <x-field name="do_dnia" type="date" label="Ostatni dzień" :required="true" />
                <x-field
                    name="notatka_tygodnia"
                    label="Notatka gospodarza"
                    help="Jedno zdanie, np. „Pokażcie swoje pierogi — z czym je robicie?”."
                />

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Zaplanuj tag tygodnia</button>
                </div>
            </form>

            @if($wyroznienia->isNotEmpty())
                <h3 class="mt-6">Zaplanowane i zakończone</h3>
                <ul class="stack lista-naga">
                    @foreach($wyroznienia as $wyroznienie)
                        <li class="sekcja-strony">
                            <p class="mb-2">
                                <strong>{{ $wyroznienie->tag?->name }}</strong> —
                                od {{ \App\Support\Czas::data($wyroznienie->starts_on) }}
                                do {{ \App\Support\Czas::data($wyroznienie->ends_on) }}
                            </p>
                            @if($wyroznienie->note)
                                <p class="mb-2">{{ $wyroznienie->note }}</p>
                            @endif
                            <form method="POST" action="{{ route('admin.tag-highlights.destroy', $wyroznienie) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-quiet" type="submit">Usuń to wyróżnienie</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-layout>
