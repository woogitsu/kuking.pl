<x-layout title="Szukaj" :noindex="true">
    {{--
        PRAWA SZYNA (UI kit v2, ekran 03 „Szukaj i odkrywaj").

        Nazwa nagłówka ZOSTAJE „Szukaj", nie „Szukaj i odkrywaj" z makiety —
        `docs/brand/BRAND_EXTENDED.md` §1.1 ma to rozstrzygnięte wprost dla
        pojęcia „Wyszukiwanie": nazwa obowiązująca to „Szukaj", a „Odkrywaj"/
        „Discover" są na liście „nigdy". To jest ten sam rodzaj rozjazdu kitu
        z COPY_STYLE co „Komu wyszło" na ekranie przepisu (STAN_WDROZENIA_KITU
        z 7 września) — i tak samo rozstrzygnięty na rzecz COPY_STYLE.

        Treść szyny: patrz komentarz w `SearchController::__construct()` —
        to jest tablica „kuKINGi na dziś", nie dosłowne „Smaki września"
        z liczbami przepisów i nie lista osób z liczbą obserwujących.
    --}}
    <x-slot:rail>
        <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" :wybrane="$board['wybrane']" />
    </x-slot:rail>

    <h1>Szukaj</h1>

    {{--
        POLE WYSZUKIWANIA W WIĘKSZYM UKŁADZIE Z KITU (ekrany 03 i 07).

        Etykieta ZOSTAJE widoczna nad polem — `docs/UX_50_PLUS.md`: „etykieta
        pola jest zawsze widoczna; placeholder nie jest etykietą". Kit rysuje
        samą ikonę lupy i tekst wewnątrz pola bez osobnej etykiety nad nim;
        to jest dopuszczalne w statycznej makiecie, ale nie w produkcie, który
        ma czytnikom ekranu i osobom słabiej widzącym pokazać, czym jest to
        pole, ZANIM w nie klikną. Ikona więc DOCHODZI do istniejącego pola,
        etykieta i tekst pomocy zostają bez zmian.
    --}}
    <form class="panel-formularza" method="GET" action="{{ route('search') }}">
        @include('components.error-summary', ['errors' => $searchErrors])
        <div class="field @if($searchErrors->has('q')) has-error @endif">
            <label for="f-q">Czego szukasz?</label>
            <span class="field-help" id="f-q-help">
                Możesz wpisać nazwę dania, składnik albo imię osoby. Polskie znaki nie mają znaczenia —
                „zurek” znajdzie „żurek”.
            </span>
            <div class="wyszukiwarka-pole-wiersz">
                <x-ikona nazwa="search" :rozmiar="24" class="wyszukiwarka-ikona" />
                <input class="field-input wyszukiwarka-input" id="f-q" name="q" type="search" value="{{ $phrase }}"
                       aria-describedby="f-q-help{{ $searchErrors->has('q') ? ' f-q-error' : '' }}"
                       @if($searchErrors->has('q')) aria-invalid="true" @endif
                       placeholder="żurek, pierogi, Basia">
            </div>
            @if($searchErrors->has('q'))
                <span class="field-error" id="f-q-error">{{ $searchErrors->first('q') }}</span>
            @endif
        </div>
        <input type="hidden" name="sekcja" value="{{ $section }}">
        @if($maksMinut !== null)
            <input type="hidden" name="czas" value="{{ $maksMinut }}">
        @endif
        @if($obserwowani)
            {{-- Wybór „Od osób, które obserwuję” (#2440) przeżywa nowe wyszukanie frazy. --}}
            <input type="hidden" name="obserwowani" value="1">
        @endif

        {{--
            FILTR ALERGENÓW (#1902, D-333) — tylko przy włączonej fladze.

            Zwijany, ale bez skryptu (`<details>`), w TYM SAMYM formularzu co fraza:
            jeden przycisk „Szukaj" i jeden adres (`bez[]=gluten&bez[]=milk`).
            Bezstanowy — niczego nie zapisujemy o szukającym. Nazwa nie obiecuje
            niczego: „według autorów", bo to zaznaczenia autorów, nie badanie.
            Zakres „Ludzie" go nie ma — osoby nie mają alergenów w przepisach.
        --}}
        @if(config('kuking.alergeny.wlaczone') && $section !== 'ludzie')
            <details class="mt-4" id="filtr-alergenow" @if($bezAlergenow !== []) open @endif>
                <summary class="btn btn-secondary">Bez wskazanych alergenów (według autorów)</summary>
                <p class="meta mt-3">
                    Zaznacz alergeny, których autor ma nie wskazywać w przepisie, i kliknij „Szukaj”.
                    Pokażemy tylko przepisy, w których autor zaznaczył brak tych alergenów. Przepisy,
                    w których autor nie sprawdził alergenów, są pominięte.
                </p>
                <div class="choice-grid">
                    @foreach(\App\Domain\Recipes\Alergeny\Alergen::cases() as $alergen)
                        <label class="choice">
                            <input type="checkbox" name="bez[]" value="{{ $alergen->value }}" @checked(in_array($alergen->value, $bezAlergenow, true))>
                            <span class="choice-label">{{ $alergen->etykieta() }}</span>
                        </label>
                    @endforeach
                </div>
            </details>
        @endif
        <button class="btn btn-primary mt-4" type="submit">Szukaj</button>
    </form>

    {{--
        ZAKRESY Z KITU (ekran 03_desktop_search_discover).

        To są zwykłe odnośniki, nie przyciski sterowane skryptem — zawężenie
        wyników musi działać bez JavaScriptu (AGENTS.md), a każdy zakres ma
        własny adres, który da się zapisać w zakładkach i wysłać komuś.

        Przy wpisanej frazie zakres niesie `nawigacja=1`: to przeglądanie
        wyników już wyszukanej frazy, nie nowe wyszukanie (issue #943,
        `SearchController`).

        `aria-current="page"` zamiast samego koloru: który zakres jest włączony,
        musi być słyszalne dla czytnika ekranu, a nie tylko widoczne.
    --}}
    @php
        // Progi czasu (#1997). Etykieta jest też nagłówkiem wyników, a w pustym
        // stanie — mową potoczną („w pół godziny"), nie liczbą z adresu.
        $progiCzasu = [
            15 => ['Do 15 minut', 'kwadrans'],
            30 => ['Do 30 minut', 'pół godziny'],
            60 => ['Do godziny', 'godzinę'],
        ];
        $bazaZakresu = ['q' => $phrase] + ($phrase === '' ? [] : ['nawigacja' => 1]);
        // Wybór alergenów zostaje przy zakresach przepisów, tak jak czas.
        $alergenyWAdresie = $bezAlergenow === [] ? [] : ['bez' => $bezAlergenow];
        $nazwyWybranych = \App\Domain\Recipes\Alergeny\Alergen::nazwyZKodow($bezAlergenow);
        // „Od osób, które obserwuję” (#2440) zostaje przy zakresach przepisów, tak jak czas i alergeny.
        $obserwowaniWAdresie = $obserwowani ? ['obserwowani' => 1] : [];
        $czasWAdresie = $maksMinut !== null ? ['czas' => $maksMinut] : [];
    @endphp
    <nav class="chipsy mt-6" aria-label="Co przeszukujemy">
        @foreach([
            'wszystko' => 'Wszystko',
            'przepisy' => 'Przepisy',
            'ludzie' => 'Ludzie',
            'tanie' => 'Do '.\App\Domain\Recipes\KosztPrzepisu::TANIE_DO.' zł',
        ] as $klucz => $etykieta)
            {{-- Wybrany czas zostaje przy przejściu między zakresami przepisów;
                 „Wszystko" i „Ludzie" go gubią, bo ludzie nie mają czasu
                 przygotowania (SearchController, #1997). --}}
            <a class="chip"
               href="{{ route('search', $bazaZakresu + ['sekcja' => $klucz] + (in_array($klucz, ['przepisy', 'tanie'], true) && $maksMinut !== null ? ['czas' => $maksMinut] : []) + (in_array($klucz, ['przepisy', 'tanie'], true) ? $alergenyWAdresie + $obserwowaniWAdresie : [])) }}"
               @if($section === $klucz) aria-current="page" @endif>{{ $etykieta }}</a>
        @endforeach
    </nav>

    {{--
        CZAS PRZYGOTOWANIA (issue #1997). Osobny wiersz, bo to warunek na
        przepisy, a nie rodzaj treści. Te same zwykłe odnośniki co zakresy
        wyżej: działają bez JavaScriptu, z klawiatury, a wybór siedzi
        w adresie (`?czas=15|30|60`), więc przeżywa odświeżenie i wysłanie
        komuś. Etykieta jest widoczna, nie tylko w `aria-label`.
    --}}
    @if($bezNieznane > 0)
        <p class="notice mt-4" role="status">
            Adres ma alergen, którego nie rozpoznajemy, więc go pomijamy. Wybierz alergeny z listy nad wynikami.
        </p>
    @endif

    @if($section !== 'ludzie')
        @if($czasNieznany)
            <p class="notice mt-4" role="status">
                Adres ma czas, którego nie rozpoznajemy. Pokazujemy przepisy bez limitu czasu.
                Jeśli chcesz, wybierz niżej, ile masz czasu.
            </p>
        @endif
        <p class="mt-4 mb-0 font-semibold" id="czas-przepisu-etykieta">Ile masz czasu?</p>
        <nav class="chipsy chipsy-czasu" aria-labelledby="czas-przepisu-etykieta">
            <a class="chip"
               href="{{ route('search', $bazaZakresu + ['sekcja' => $section] + $alergenyWAdresie + $obserwowaniWAdresie) }}"
               @if($maksMinut === null) aria-current="page" @endif>Bez limitu czasu</a>
            @foreach($progiCzasu as $minuty => [$etykieta])
                <a class="chip"
                   href="{{ route('search', $bazaZakresu + ['sekcja' => $section === 'wszystko' ? 'przepisy' : $section, 'czas' => $minuty] + $alergenyWAdresie + $obserwowaniWAdresie) }}"
                   @if($maksMinut === $minuty) aria-current="page" @endif>{{ $etykieta }}</a>
            @endforeach
        </nav>
        @if($maksMinut !== null)
            <p class="meta">Liczymy przygotowanie i gotowanie razem. Przepis, przy którym autor nie podał czasu, tu nie trafia.</p>
        @endif
    @endif

    {{--
        CZYJE PRZEPISY (#2440, D-275). Jawny wybór zalogowanej osoby, nie dobór
        przez serwis: tylko zwęża wyniki do przepisów osób, które ona obserwuje
        (`follows.follower_id` = ona), kolejność zostaje ta sama. Zwykłe odnośniki
        jak zakresy i czas; w adresie wyłącznie `obserwowani=1`.
    --}}
    @auth
        @if($section !== 'ludzie')
            <p class="mt-4 mb-0 font-semibold" id="autorzy-przepisu-etykieta">Czyje przepisy?</p>
            <nav class="chipsy" aria-labelledby="autorzy-przepisu-etykieta">
                <a class="chip"
                   href="{{ route('search', $bazaZakresu + ['sekcja' => $section] + $czasWAdresie + $alergenyWAdresie) }}"
                   @if(! $obserwowani) aria-current="page" @endif>Wszyscy autorzy</a>
                <a class="chip"
                   href="{{ route('search', $bazaZakresu + ['sekcja' => $section === 'wszystko' ? 'przepisy' : $section, 'obserwowani' => 1] + $czasWAdresie + $alergenyWAdresie) }}"
                   @if($obserwowani) aria-current="page" @endif>Od osób, które obserwuję</a>
            </nav>
        @endif
    @endauth
    @if($obserwowaniGosc)
        <p class="notice mt-4" role="status">
            Wybór „Od osób, które obserwuję” działa po zalogowaniu, bo to Twoja lista. Pokazujemy przepisy wszystkich autorów.
            <a href="{{ route('login') }}">Zaloguj się</a>, jeśli chcesz go użyć.
        </p>
    @endif

    @if($phrase === '')
        <p class="meta meta-samodzielne">Wpisz coś w pole powyżej i kliknij „Szukaj”.</p>
        <section class="marka-szukaj-tagi" aria-labelledby="polecane-tagi-title">
            <h2 id="polecane-tagi-title">Polecane tagi</h2>
            @if($promowaneTagi->isNotEmpty())
                <ul class="lista-naga marka-szukaj-siatka">
                    @foreach($promowaneTagi as $tag)
                        <li class="marka-szukaj-tag">
                            <h3>{{ $tag->name }}</h3>
                            @if(filled($tag->promotion?->note))
                                <p>{{ $tag->promotion?->note }}</p>
                            @endif
                            <a class="marka-szukaj-tag-link" href="{{ route('tags.show', $tag) }}" aria-label="Zobacz tag: {{ $tag->name }}">Zobacz tag</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p>Nie ma jeszcze polecanych tagów.</p>
            @endif
            <p><a class="btn btn-secondary marka-szukaj-wszystkie" href="{{ route('tags.index') }}">Wszystkie tagi</a></p>
        </section>
        {{--
            Ekran wyszukiwania bez frazy nie może kończyć się na samej
            instrukcji — to ślepy zaułek (docs/product/SOUL.md 4.11: pusty
            stan to zaproszenie, nie ściana). Ten sam odnośnik używa już
            `kuking-board.blade.php` i `tags/show.blade.php` w tej samej roli.
        --}}
        <p class="meta">Nie wiesz, od czego zacząć? Zajrzyj do <a href="{{ route('discover') }}">Świeżo z <x-kuking-word /></a>.</p>
    @elseif($zaKrotka)
        {{--
            Osobny, uczciwy tekst — nie „Nic nie znaleźliśmy" (SearchController
            tłumaczy dlaczego: przy jednym znaku silnik w ogóle nie szukał).
        --}}
        <p class="meta">Fraza „{{ $phrase }}” jest za krótka, żeby zacząć szukać. Wpisz co najmniej dwa znaki.</p>
    @elseif($searchErrors->isEmpty())
        @php
            // Puste jest dopiero wtedy, gdy pusty jest KAŻDY przeszukiwany
            // zakres. Przy „Wszystko" samo zero przepisów nie znaczy jeszcze
            // „nic nie znaleźliśmy" — obok mogą być ludzie.
            $nicNieMa = (! $szukaPrzepisow || $recipes->isEmpty())
                && (! $szukaLudzi || $people->isEmpty());
        @endphp

        @if($nicNieMa && $odPrzepisu === 0 && $odOsoby === 0)
            {{--
                Tekst gałęzi „przepisy" jest dosłownym
                cytatem z docs/brand/COPY_STYLE.md §6 „Puste stany" — ten
                dokument wiąże każdy tekst widoczny dla użytkownika i ma tu
                gotowe brzmienie, nie tylko przykład.
            --}}
            <x-empty-state title="Nic nie znaleźliśmy">
                @if($obserwowani)
                    {{-- „Od osób, które obserwuję” (#2440): dwa różne powody pustego wyniku
                         i zawsze droga do wszystkich autorów — wyników nie poszerzamy po cichu. --}}
                    @if(! $obserwujeKogos)
                        Nie obserwujesz jeszcze nikogo, więc nie mamy czyich przepisów przeszukać. Obserwuj osoby na ich profilach albo pokaż przepisy wszystkich autorów.
                    @else
                        Żadna z osób, które obserwujesz, nie ma przepisu pasującego do „{{ $phrase }}”{{ $maksMinut !== null || $bezAlergenow !== [] ? ' przy wybranych dodatkowych ograniczeniach' : '' }}.
                        Wpisz krócej albo pokaż przepisy wszystkich autorów.
                    @endif
                @elseif($bezAlergenow !== [])
                    {{-- Filtr alergenów (#1902): przepisy niesprawdzone są pominięte,
                         więc „nic" nie znaczy „wszystkie zawierają" — trzeba to powiedzieć. --}}
                    Nie ma przepisów do „{{ $phrase }}”, w których autor zaznaczył brak: {{ $nazwyWybranych }}.
                    Spróbuj odznaczyć jeden alergen albo poszukaj bez filtra i przeczytaj składniki samodzielnie.
                @elseif($section === 'przepisy' && $maksMinut !== null)
                    Nie ma przepisu do „{{ $phrase }}”, który zmieściłby się w {{ $progiCzasu[$maksMinut][1] }}.
                    @if($maksMinut < 60)
                        Spróbuj dłuższego czasu albo „Bez limitu czasu”.
                    @else
                        Spróbuj „Bez limitu czasu”.
                    @endif
                    Czas podaje autor, a nie każdy go wpisuje.
                @elseif($section === 'tanie')
                    {{-- Koszt podaje autor i nie każdy go podaje (D-286) — to
                         trzeba powiedzieć, inaczej „nic" brzmi jak „nie ma
                         tanich przepisów". --}}
                    @if($maksMinut !== null)
                        Nie ma przepisu do „{{ $phrase }}” z kosztem do {{ \App\Domain\Recipes\KosztPrzepisu::TANIE_DO }} zł, który zmieściłby się w {{ $progiCzasu[$maksMinut][1] }}.
                        Koszt i czas podaje autor, a nie każdy je wpisuje — spróbuj „Bez limitu czasu” albo zakresu „Przepisy”.
                    @else
                        Nie ma przepisu do „{{ $phrase }}” z kosztem do {{ \App\Domain\Recipes\KosztPrzepisu::TANIE_DO }} zł.
                        Koszt podaje autor, a nie każdy go wpisuje — spróbuj zakresu „Przepisy”.
                    @endif
                @elseif($section === 'ludzie')
                    Nie ma tu osoby o nazwie „{{ $phrase }}”.
                @elseif($section === 'wszystko')
                    {{-- „Wszystko" przeszukuje przepisy I ludzi (#944). Tekst
                         o samym przepisie zmieniałby znaczenie zapytania
                         komuś, kto wpisał imię — do czego zachęca pomoc pola. --}}
                    Nie znaleźliśmy ani przepisu, ani osoby pasującej do „{{ $phrase }}”.
                    Sprawdź, czy wszystko jest dobrze wpisane, albo wpisz krócej: samo imię albo jedną nazwę dania.
                @else
                    Nie ma jeszcze przepisu, który by pasował do „{{ $phrase }}”. Może to Ty go dodasz?
                @endif
            </x-empty-state>

            @if($obserwowani)
                <p class="text-center">
                    <a class="btn btn-primary" href="{{ route('search', $bazaZakresu + ['sekcja' => $section] + $czasWAdresie + $alergenyWAdresie) }}">Pokaż przepisy wszystkich autorów</a>
                </p>
            @endif

            {{--
                Droga dalej, nie ślepy zaułek (SOUL.md 4.11, IMPLEMENTATION_GUIDE
                etap D). Kto szuka przepisu — może go dodać. Każdy, niezależnie
                od zakresu — może zamiast tego zobaczyć, co dzieje się w Kuking
                teraz, tym samym odnośnikiem co przy pustej frazie wyżej.
            --}}
            <p class="text-center">
                @if($section !== 'ludzie')
                    {{-- W „Wszystko" fraza mogła być imieniem, więc bez „taki". --}}
                    <a class="btn btn-primary" href="{{ route('recipes.create') }}">{{ $section === 'wszystko' ? 'Dodaj przepis' : 'Dodaj taki przepis' }}</a>
                @endif
                <a class="btn btn-quiet" href="{{ route('discover') }}">Zajrzyj do Świeżo z <x-kuking-word /></a>
            </p>
        @endif

        @if($odPrzepisu > 0)
            <p><a class="btn btn-quiet" href="{{ route('search', $poczatekPrzepisow) }}">Wróć do początku przepisów</a></p>
            @if($recipes->isEmpty())
                <p>W tym zakresie nie ma już przepisów. Wróć do początku wyników.</p>
            @endif
        @endif
        @if($odOsoby > 0)
            <p><a class="btn btn-quiet" href="{{ route('search', $poczatekOsob) }}">Wróć do początku osób</a></p>
            @if($people->isEmpty())
                <p>W tym zakresie nie ma już osób. Wróć do początku wyników.</p>
            @endif
        @endif

        @if($szukaPrzepisow && $recipes->isNotEmpty())
            <h2 class="mt-6">{{ $maksMinut !== null ? $progiCzasu[$maksMinut][0] : 'Przepisy' }}</h2>

            @if($obserwowani)
                <p class="meta" role="note">
                    Pokazujemy tylko przepisy osób, które obserwujesz.
                    <a href="{{ route('search', $bazaZakresu + ['sekcja' => $section] + $czasWAdresie + $alergenyWAdresie) }}">Pokaż przepisy wszystkich autorów</a>
                </p>
            @endif

            @if($bezAlergenow !== [])
                <p class="notice" role="note">
                    Pokazujemy tylko przepisy, w których autor zaznaczył brak: {{ $nazwyWybranych }}.
                    Przepisy, w których autor nie sprawdził alergenów, są pominięte.
                </p>
            @endif

            <p class="meta">
                @if($odPrzepisu > 0 && $recipes->count() === 1)
                    Pokazujemy przepis {{ $odPrzepisu + 1 }}.
                @elseif($odPrzepisu > 0)
                    Pokazujemy przepisy {{ $odPrzepisu + 1 }}–{{ $odPrzepisu + $recipes->count() }}.
                @elseif($jestWiecej ?? false)
                    Pokazujemy {{ $recipes->count() }} {{ \App\Support\Odmiana::rzeczownik($recipes->count(), 'przepis', 'przepisy', 'przepisów') }}. Jest ich więcej.
                @else
                    Znaleziono {{ $recipes->count() }} {{ \App\Support\Odmiana::rzeczownik($recipes->count(), 'przepis', 'przepisy', 'przepisów') }}.
                @endif
            </p>
            <div class="stack">
                @foreach($recipes as $recipe)
                    @if($bezAlergenow !== [])
                        <div>
                            <x-recipe-card :recipe="$recipe" :pokaz-widocznosc="true" />
                            {{-- Jedna linia, tylko przy aktywnym filtrze (#1902): bez plakietki
                                 na zwykłych kartach, żeby nie udawała certyfikatu. --}}
                            <p class="meta">Autor zaznaczył brak: {{ $nazwyWybranych }}</p>
                        </div>
                    @else
                        <x-recipe-card :recipe="$recipe" :pokaz-widocznosc="true" />
                    @endif
                @endforeach
            </div>

            @if($bezAlergenow !== [])
                <p class="meta">To zaznaczenia autorów, nie badania. Przy gotowych produktach zawsze czytaj etykietę.</p>
            @endif

            @if($jestWiecej ?? false)
                {{-- Zwykły odnośnik, nie przycisk sterowany skryptem: dalsze
                     wyniki muszą być osiągalne bez JavaScriptu (AGENTS.md). --}}
                <p class="text-center">
                    <a class="btn btn-quiet"
                       href="{{ route('search', $nastepnePrzepisy) }}">
                        Pokaż więcej przepisów
                    </a>
                </p>
            @endif
        @endif

        @if($szukaLudzi && $people->isNotEmpty())
            <h2 class="mt-8">Ludzie</h2>

            <p class="meta">
                @if($odOsoby > 0 && $people->count() === 1)
                    Pokazujemy osobę {{ $odOsoby + 1 }}.
                @elseif($odOsoby > 0)
                    Pokazujemy osoby {{ $odOsoby + 1 }}–{{ $odOsoby + $people->count() }}.
                @elseif($jestWiecejOsob ?? false)
                    Pokazujemy {{ $people->count() }} {{ \App\Support\Odmiana::rzeczownik($people->count(), 'osobę', 'osoby', 'osób') }}. Jest ich więcej.
                @else
                    Znaleziono {{ $people->count() }} {{ \App\Support\Odmiana::rzeczownik($people->count(), 'osobę', 'osoby', 'osób') }}.
                @endif
            </p>
            <div class="stack-tight">
                @foreach($people as $person)
                    <div class="card flex gap-3 items-center">
                        <x-avatar :user="$person->user" :size="52" />
                        <div>
                            <a class="author-name" href="{{ route('profile.show', $person->username) }}">{{ $person->display_name }}</a>
                            <p class="meta m-0">&#64;{{ $person->username }} @if($person->speciality) · {{ $person->speciality }} @endif</p>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($jestWiecejOsob ?? false)
                {{-- Ten sam zwykły odnośnik co przy przepisach: dalsze wyniki
                     muszą być osiągalne bez JavaScriptu (AGENTS.md). --}}
                <p class="text-center">
                    <a class="btn btn-quiet"
                       href="{{ route('search', $nastepneOsoby) }}">
                        Pokaż więcej osób
                    </a>
                </p>
            @endif
        @endif
    @endif
</x-layout>
