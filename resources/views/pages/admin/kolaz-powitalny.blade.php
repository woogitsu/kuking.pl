{{--
    KOLAŻ NA POWITANIE — wybór zdjęć do hero strony powitalnej.

    Zgłoszenie właściciela: „dodaj funkcję w panelu admina by ustawiać te
    zdjęcia spośród wszystkich publicznych od użytkowników".

    Ekran trzyma konwencję `/admin/kuking-na-dzis`: jedna strona, lista
    z polami wyboru, jeden przycisk „Zapisz", osobna strefa czyszczenia.
    Nie ma tu ani jednej liczby przy zdjęciu — ani polubień, ani zapisów
    w zeszytach — bo to nie jest ranking i nie ma podpowiadać wyboru.

    PODGLĄD NA GÓRZE, NIE NA DOLE. Kolaż ma dwa stany („wybór gospodarza"
    i „dobór automatyczny") i uzupełnia się do czterech zdjęć sam. Bez
    podglądu gospodarz nie ma jak sprawdzić, CO naprawdę stoi dziś na
    stronie — a to jest dokładnie ten rodzaj ekranu, który wygląda poprawnie
    w obu stanach i w żadnym nie mówi, w którym jest.
--}}
<x-layout title="Kolaż na powitanie — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Kolaż na powitanie" />

    <h1>Kolaż na powitanie</h1>
    <p class="mb-5">
        To maksymalnie cztery zdjęcia w prawym górnym rogu strony powitalnej — pierwsza rzecz,
        jaką widzi ktoś, kto trafił tu z wyszukiwarki i nie ma jeszcze konta.
        Zaznacz najwyżej {{ $slotow }} zdjęcia. Jeśli zaznaczysz mniej, brakujące
        dobierzemy automatycznie z najnowszych publicznych zdjęć — najpierw po jednym od osoby, a w razie potrzeby po dwa.
    </p>

    {{--
        ZDANIE O TYM, CZEGO TEN WYBÓR DOTYCZY.

        Stoi tu dlatego, że obowiązujący regulamin (`resources/legal/regulamin.md`
        §5.2) opisuje licencję jako zgodę na „publiczne pokazywanie treści zgodnie
        z ustawieniami widoczności, które sam wybierasz" — i ani słowem nie mówi
        o użyciu promocyjnym. Klauzula, która mówi o kolażach wprost, jest na razie
        PROJEKTEM do weryfikacji przez prawnika (`docs/legal/LICENCJA_UGC_PROJEKT.md`,
        §3 lit. f) i sama zabrania powoływania się na siebie „w kampanii ani
        w produkcie". Nie rozstrzygamy tego w panelu i nie udajemy, że jest
        rozstrzygnięte — ale człowiek, który klika „Zapisz", ma wiedzieć, o czym
        decyduje.
    --}}
    <p class="notice">
        Pamiętaj, czego dotyczy ten wybór: zdjęcie z kolażu pokazujemy ludziom,
        którzy nie mają jeszcze konta, jako zachętę do rejestracji. To inne użycie
        niż pokazanie go w strumieniu wpisów, a obowiązujący regulamin opisuje
        licencję od użytkownika jako zgodę na pokazywanie treści zgodnie z jej
        widocznością — nie na użycie promocyjne. Do czasu rozstrzygnięcia tej
        sprawy wybieraj zdjęcia osób, które nie miałyby nic przeciwko, i pytaj
        autora, jeśli masz wątpliwość.
    </p>

    <x-error-summary />

    {{-- --------------------------------------------------------------------
         CO STOI DZIŚ NA STRONIE POWITALNEJ
         -------------------------------------------------------------------- --}}
    {{-- `sekcja-strony`, nie panel: podgląd niczego nie wymaga, tylko pokazuje
         stan. Panel z mocną obwódką zarezerwowany jest dla tego, co się
         wypełnia (docs/design/ROLE_KART.md, role 2 i 3). --}}
    <section class="sekcja-strony mb-6">
        <h2 class="form-section-title">Co widzi teraz gość</h2>

        @if($podglad->isEmpty())
            <p class="meta">
                Kolażu nie ma na stronie. W serwisie nie ma jeszcze {{ $slotow }}
                publicznych zdjęć od aktywnych kont, a kolaż z dziurą w miejscu
                brakującego zdjęcia wyglądałby na usterkę — więc prawa strona
                powitania zostaje na razie pusta.
            </p>
        @else
            {{-- Utility Tailwinda, a nie nowa klasa w arkuszu: ten ekran nie
                 wnosi żadnego nowego WZORCA wyglądu, tylko układa w rząd rzeczy,
                 które panel już ma. Nowa klasa w `app.css` byłaby tu jedną
                 regułą więcej do utrzymania przy zerowym zysku.

                 `flex-none` na miniaturze celowo: domyślne `flex-shrink` zgniata
                 element o zadanym rozmiarze, gdy tekst obok jest długi.

                 MINIATURA W PIKSELACH (`w-[96px]`), NIE W `rem` (`w-24`) —
                 POPRAWKA PO POMIARZE, nie estetyka. `w-24` to 6rem, czyli przy
                 czcionce przeglądarki 200% dwa razy więcej: 192 px.
                 Zmierzone automatem dostępności na pierwszej wersji tego ekranu:
                 `scrollWidth` 429 px przy oknie 320, 360 i 414 px — strona
                 przewijała się w bok, czyli naruszenie WCAG 2.2 AA 1.4.10
                 (Reflow) na wszystkich trzech szerokościach telefonu.
                 Zdjęcie nie jest tekstem i nie ma powodu rosnąć razem z nim —
                 tak samo rozwiązuje to `.miniatura-64` w panelu tablicy dnia.
                 `flex-wrap` dokłada drugie zabezpieczenie: gdy na opis zostaje
                 za mało miejsca, schodzi on pod miniaturę zamiast rozpychać
                 wiersz. --}}
            <ul class="flex flex-wrap gap-5 list-none p-0 m-0">
                @foreach($podglad as $kafel)
                    <li class="flex flex-wrap gap-3 items-center">
                        <img src="{{ $kafel['media']->url('thumb') }}"
                             alt="Zdjęcie {{ $loop->iteration }} z kolażu — autor: {{ $kafel['autor']->displayName() }}"
                             width="96" height="96"
                             class="w-[96px] h-[96px] object-cover rounded-lg flex-none"
                             loading="lazy">
                        <span class="min-w-0">
                            <span class="choice-label">{{ $kafel['autor']->displayName() }}</span>
                            <span class="choice-help">
                                {{ $kafel['wybrane'] ? 'wybrane przez Ciebie' : 'dobrane automatycznie' }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- --------------------------------------------------------------------
         WYBÓR
         -------------------------------------------------------------------- --}}
    {{-- Panel na `<form>`, nie na sekcjach w środku — ten sam wzorzec co
         `admin/daily-board`: cztery białe prostokąty jeden w drugim nie mówiły
         nic poza tym, że są białe. --}}
    <form class="panel-formularza" method="POST" action="{{ route('admin.hero-kolaz') }}">
        @csrf
        @method('PUT')

        <section class="form-section">
            <h2 class="form-section-title">Zdjęcia do wyboru</h2>
            <p class="meta">
                Wyłącznie zdjęcia przy wpisach publicznych i opublikowanych, od kont
                aktywnych. Wpis prywatny, „tylko dla obserwujących", schowany przez
                moderację albo z konta zawieszonego nie pojawi się na tej liście
                i wypadnie z kolażu, jeśli zmieni stan już po wyborze.
            </p>

            @forelse($kandydaci as $wpis)
                @foreach($wpis->media as $zdjecie)
                    @continue(! $zdjecie->isReady())
                    <div class="wiersz-listy">
                        <label class="choice" for="zdjecie-{{ $zdjecie->getKey() }}">
                            <input id="zdjecie-{{ $zdjecie->getKey() }}" type="checkbox" name="zdjecia[]"
                                   value="{{ $zdjecie->getKey() }}"
                                   @checked(in_array((string) $zdjecie->getKey(), $wybrane, true))>
                            <span class="flex flex-wrap gap-3 items-start flex-1">
                                <img src="{{ $zdjecie->url('thumb') }}"
                                     alt=""
                                     width="96" height="96"
                                     class="w-[96px] h-[96px] object-cover rounded-lg flex-none"
                                     loading="lazy">
                                <span class="min-w-0">
                                    <span class="choice-label">{{ $wpis->author->displayName() }}</span>
                                    <span class="choice-help">
                                        {{ \App\Support\Czas::dataLubNic($wpis->published_at, 'j F Y') }}
                                        @if($wpis->body) — {{ \Illuminate\Support\Str::limit($wpis->body, 90) }} @endif
                                    </span>
                                </span>
                            </span>
                        </label>
                    </div>
                @endforeach
            @empty
                <p class="meta">Nie ma jeszcze ani jednego publicznego zdjęcia do wyboru.</p>
            @endforelse
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz kolaż</button>
            <a class="btn btn-quiet" href="{{ route('landing') }}">Zobacz stronę powitalną</a>
        </div>
    </form>

    <div class="danger-zone">
        <h2>Wyczyść wybór</h2>
        <p>Kolaż wróci do doboru automatycznego: najnowsze publiczne zdjęcia, najpierw po jednym od osoby, a w razie potrzeby po dwa.</p>
        <x-confirm-button
            :action="route('admin.hero-kolaz')"
            label="Wyczyść wybór zdjęć"
            question="Wyczyścić wybór? Kolaż dobierze zdjęcia sam." />
    </div>
</x-layout>
