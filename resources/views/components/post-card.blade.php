{{--
    Karta wpisu (UI kit v2, etap B — ekrany 01_desktop_feed i 05_mobile_feed).

    UKŁAD Z PACZKI WŁAŚCICIELA
    Nagłówek (kto i kiedy) → kilka słów → zdjęcie → pasek akcji oddzielony
    kreską. Ta kolejność jest z mockupów i ma powód: człowiek najpierw widzi,
    KTO gotował, a dopiero potem CO. Kuking jest społecznością ludzi,
    nie galerią zdjęć (docs/PRODUCT.md).

    „UGOTOWAŁEM" JEST GŁÓWNĄ AKCJĄ, NIE OZDOBNIKIEM
    Gdy wpis powstał z przepisu, „Ugotowałem" stoi PIERWSZE i jako jedyne ma
    pełny kolor marki. To jest najcenniejszy sygnał w całym serwisie — realne
    wykonanie przepisu przez inną osobę — i zawsze powiadamia autora
    (AGENTS.md §1). Komentarz jest obok, jako akcja drugiego planu.

    BEZ JAVASCRIPTU I BEZ NAJEŻDŻANIA MYSZĄ
    Wszystkie akcje to zwykłe odnośniki. Nic się nie odsłania po najechaniu
    ani po przesunięciu palcem — dla części naszych użytkowników to jedyna
    droga do funkcji (AGENTS.md §5).
--}}
@props(['post'])
@php $author = $post->author; @endphp
<article class="card post-card">
    <div class="post-card-head">
        <a href="{{ route('profile.show', $author->profile->username) }}" aria-hidden="true" tabindex="-1">
            <x-avatar :user="$author" :size="52" />
        </a>
        <div class="min-w-0">
            <a class="author-name" href="{{ route('profile.show', $author->profile->username) }}">{{ $author->displayName() }}</a>
            <p class="meta m-0">
                <a href="{{ $post->url() }}" class="link-jak-tekst">
                    <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ \App\Support\Czas::dataLubNic($post->published_at, 'j F Y, H:i') }}</time>
                </a>
                {{-- Widoczność przy dacie, tak jak w kicie (ekran 01: „2 godz.
                     temu · publicznie"). Także dla wpisu publicznego: autor ma
                     wiedzieć jednym spojrzeniem, kto to widzi, a nie dopiero
                     po wejściu w edycję. --}}
                {{-- WPIS WSKAZUJĄCY PRZEPIS PYTA O WIDOCZNOŚĆ PRZEPISU
                     (issue #368). Taki wpis ma `visibility = 'public'` na
                     stałe i to nie jest jego widoczność, tylko brak własnego
                     zawężenia — bramką jest przepis
                     (`Post::scopeZWidocznymPrzepisem()`). Bez tego pytania
                     karta napisałaby autorowi „publicznie" pod przepisem,
                     który widzą wyłącznie jego obserwujący.

                     `?->` i `??`: ekrany, które doładowują sam
                     `recipe:id,title,slug` bez kolumny `visibility`,
                     dostałyby `null` — wtedy zostaje widoczność wpisu, czyli
                     zachowanie sprzed tej zmiany. --}}
                @php $widocznosc = $post->recipe?->visibility ?? $post->visibility; @endphp
                @if($widocznosc === 'followers')
                    · <span class="badge">Tylko dla obserwujących</span>
                @elseif($widocznosc === 'private')
                    · <span class="badge">Tylko dla mnie</span>
                @else
                    · <span>publicznie</span>
                @endif
                {{-- Plakietka cicha „konto przykładowe" (D-032)
                     stoi w TYM SAMYM wierszu metadanych, po ostatniej
                     kropce — nie osobną linią pod nazwą autora jak dawniej.
                     Kropkę-separator rysuje sam komponent. --}}
                <x-konto-przykladowe :user="$author" />
            </p>
        </div>

        @auth
            {{--
                MENU „…" (UI kit v2, ekran 01).

                `<details>`, nie przycisk sterowany skryptem: menu, które bez
                JavaScriptu nie otwiera się wcale, jest ozdobą udającą przycisk
                (AGENTS.md §5). Ten sam wzorzec co przy potwierdzeniach
                kasowania.

                W środku siedzą rzeczy, po które NIE sięga się odruchowo —
                dlatego zeszły z paska akcji pod spodem: tam zostają tylko
                „Ugotowałem" i komentarze.

                MA WIDOCZNY NAPIS, NIE SAME KROPKI (AGENTS.md:176).
                Do 11 września 2026 całą treścią tego przycisku było
                `<span aria-hidden="true">···</span>`, czyli trzy kropki
                SCHOWANE przed czytnikiem ekranu, a nazwa dostępna istniała
                wyłącznie w `aria-label`. Oko dostawało znak bez podpisu,
                czytnik ekranu podpis bez znaku, i nikt nie dostawał obu.
                Za tymi kropkami stoją „Edytuj wpis" i „Usuń wpis" — a
                `docs/UX_50_PLUS.md`:27 nazywa dokładnie ten wzorzec
                („`♡ ⋮ ↗` bez podpisów") słabym.

                Napis „Więcej" jest teraz w treści przycisku, więc czyta go
                i oko, i czytnik ekranu. `aria-label` ZOSTAJE, bo na liście
                wpisów jest tych przycisków tyle, ile kart: „Więcej" samo
                w sobie nie mówi, przy którym wpisie stoi. Zaczyna się od
                widocznego napisu, więc spełnia WCAG 2.2 AA 2.5.3
                (Label in Name) — czytnik mówi „Więcej przy tym wpisie",
                a człowiek widzi „Więcej".

                `<x-ikona nazwa="more">` zamiast trzech kropek wpisanych
                z klawiatury. Ten kształt jest w `components/ikona.blade.php`
                od dawna, pod nazwą `more`, i nie był tu używany. Ikona
                z komponentu ma rozmiar podany w pikselach, `aria-hidden`
                i `focusable="false"` z jednego miejsca — znak `···` zależał
                od tego, jak rysuje go czcionka, i był w treści przycisku
                jedyną rzeczą do przeczytania.
            --}}
            <details class="post-card-menu">
                <summary aria-label="Więcej przy tym wpisie">
                    <x-ikona nazwa="more" :rozmiar="24" class="post-card-menu-ikona" />
                    <span>Więcej</span>
                </summary>
                <div class="post-card-menu-tresc">
                    <a href="{{ $post->url() }}">Otwórz wpis</a>
                    {{-- `@can`, a nie `auth()->id() === $post->author_id`: reguła
                         „kto może zmieniać ten wpis" ma jedno miejsce
                         (`PostPolicy::update`), a nie kopię w widoku obok kopii
                         w kontrolerze (AGENTS.md §7). Dziś obie mówiły to samo;
                         jutro ktoś poprawi jedną i menu pokaże „Edytuj" komuś,
                         kto po kliknięciu dostanie 403. --}}
                    @can('update', $post)
                        <a href="{{ route('posts.edit', $post) }}">Edytuj wpis</a>
                        @if($post->media->count() > 1)
                            <a href="{{ route('posts.media.edit', $post) }}">Zdjęcia w tym wpisie</a>
                        @endif
                        {{--
                            „Usuń wpis" — akcja destrukcyjna, odsunięta od
                            zwykłych akcji i wymagająca potwierdzenia
                            (AGENTS.md §5). `.danger-zone` daje odstęp
                            i kreskę, `x-confirm-button` to ten sam wzorzec
                            potwierdzenia bez JavaScriptu co na stronie wpisu
                            (`pages/posts/show.blade.php`).
                        --}}
                        <div class="danger-zone">
                            <x-confirm-button
                                :action="route('posts.destroy', $post)"
                                label="Usuń wpis"
                                question="Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie." />
                        </div>
                    @else
                        <a href="{{ route('reports.create', ['type' => 'post', 'id' => $post->getKey()]) }}">Zgłoś ten wpis</a>
                    @endcan
                </div>
            </details>
        @endauth
    </div>

    {{--
        DŁUGI WPIS POKAZUJE SIĘ NA KARCIE W SKRÓCIE (issue #354)

        Jeden wpis z przepisem — lista składników i kroki — wypełniał na
        telefonie cały ekran i wypychał wszystko poniżej. Pod nim nie było
        widać ani dna karty, ani następnego wpisu; feed przestawał być feedem.

        Skracamy NA SERWERZE i dajemy zwykły odnośnik do strony wpisu. Nie
        rozwijamy treści w miejscu (`<details>`): rozwinięcie przesuwa
        wszystko poniżej, a przy 50+ to realny koszt — człowiek gubi miejsce,
        w którym czytał. Na stronie wpisu i tak są komentarze oraz całe
        zdjęcie, więc to tam prowadzi „Czytaj dalej".

        Próg (wiersze I znaki), powód rezygnacji z `-webkit-line-clamp`
        i sposób cięcia po całych wyrazach: `App\Support\ZapowiedzWpisu`.

        NA STRONIE SAMEGO WPISU NIE SKRACAMY — I TO NIE JEST DROBIAZG.
        Ta sama karta stoi w feedzie i na `pages/posts/show.blade.php`.
        Gdyby skracała także tam, całej treści nie dałoby się przeczytać
        NIGDZIE, a „Czytaj dalej" prowadziłoby na stronę, na której człowiek
        już stoi — czyli byłoby martwym przyciskiem (D-053). Warunek pyta
        o trasę, a nie o dodatkowy parametr komponentu, bo dzięki temu
        żaden z ośmiu widoków używających karty nie musi o niczym pamiętać.
    --}}
    @if($post->body)
        @php
            $wpisZTrasy = request()->route('post');
            $naStronieTegoWpisu = request()->routeIs('posts.show')
                && $wpisZTrasy instanceof \App\Models\Post
                && $wpisZTrasy->is($post);

            $skracamy = ! $naStronieTegoWpisu && \App\Support\ZapowiedzWpisu::czyZaDluga($post->body);
        @endphp

        <div class="post-card-body">{{ $skracamy ? \App\Support\ZapowiedzWpisu::skroc($post->body) : $post->body }}</div>

        @if($skracamy)
            {{-- Odnośnik, nie przycisk: czytnik ekranu ogłasza go jako
                 odnośnik, klawiatura go łapie, a bez skryptu działa tak samo
                 jak ze skryptem. `aria-label` zaczyna się od widocznego
                 napisu (WCAG 2.2 AA 2.5.3), bo w feedzie tych odnośników
                 jest tyle, ile skróconych wpisów — samo „Czytaj dalej" nie
                 mówi, przy którym wpisie stoi. Ten sam wzorzec co przy
                 menu „Więcej" wyżej. --}}
            <p class="post-card-czytaj-dalej">
                <a href="{{ $post->url() }}" aria-label="Czytaj dalej — cały wpis od {{ $author->displayName() }}">Czytaj dalej</a>
            </p>
        @endif
    @endif

    {{--
        TRZY SPOSOBY POKAZANIA ZDJĘĆ, JEDNA DECYZJA AUTORA (issue #92)

        Wpis może mieć do sześciu zdjęć i to autor wybiera, jak mają się
        pokazać: zwykle (jedno pod drugim), karuzelą albo kolażem. Nie jest to
        ustawienie widza ani zgadywanie po liczbie zdjęć — człowiek, który
        fotografował danie z czterech stron, wie lepiej niż my, czy chce
        pokazać je obok siebie, czy po kolei.

        `trybWyswietlaniaZdjec()` liczy się na modelu, nie tutaj: przy jednym
        zdjęciu wszystkie trzy tryby dają ten sam widok, a wpis może stracić
        zdjęcia długo po wyborze autora. Widok pyta o gotową odpowiedź.

        Wpis zapisany przed tą zmianą ma `display_mode = 'normal'` z wartości
        domyślnej w bazie, więc trafia w tę samą gałąź co zawsze i wygląda
        dokładnie jak wczoraj — bez migracji danych.
    --}}
    {{-- ZDJĘCIE WPISU WSKAZUJĄCEGO PRZEPIS JEST ZDJĘCIEM PRZEPISU
         (issue #368). Taki wpis powstaje bez `body` i bez ani jednego
         własnego zdjęcia — świadomie, bo wpis ma PROWADZIĆ do przepisu,
         a nie go duplikować. Gdyby zdjęcie było kopiowane przy publikacji,
         wymiana zdjęcia głównego w przepisie zostawiłaby w strumieniu stare.
         Tu nie ma czego synchronizować: karta czyta relację.

         Warunek `media->isEmpty()`, a nie „czy jest przepis": wpis „ugotowane
         z przepisu" ma i przepis, i WŁASNE zdjęcie dania — i to własne
         zdjęcie ma wygrać, bo pokazuje, co ugotował TEN człowiek. --}}
    @if($post->media->isEmpty() && $post->recipe?->heroMedia)
        <div class="photo-grid">
            <a href="{{ route('recipes.show', $post->recipe->slug) }}">
                <x-photo :media="$post->recipe->heroMedia"
                         :zoom="false"
                         :alt="$post->recipe->heroMedia->alt_text ?: 'Zdjęcie do przepisu: '.$post->recipe->title" />
            </a>
        </div>
    @elseif($post->media->isNotEmpty())
        @switch($post->trybWyswietlaniaZdjec())
            @case(\App\Models\Post::DISPLAY_CAROUSEL)
                <x-karuzela-zdjec :post="$post" />
                @break

            @case(\App\Models\Post::DISPLAY_COLLAGE)
                <x-kolaz-zdjec :post="$post" />
                @break

            @default
                <div class="photo-grid">
                    @foreach($post->media as $media)
                        <x-photo :media="$media" />
                    @endforeach
                </div>
        @endswitch
    @endif

    @if($post->recipe)
        <p class="post-card-recipe">
            <span class="badge badge-cooked">Z przepisu</span>
            <a href="{{ route('recipes.show', $post->recipe->slug) }}">{{ $post->recipe->title }}</a>
        </p>
    @endif

    {{--
        TEMATY WPISU (issue: docs/product/PROSTOTA_JAK_GARNEK.md).

        Autor wybiera do pięciu tagów przy publikacji (`x-tagi-formularz`),
        ale do tej zmiany żaden widok, który POKAZUJE wpis, nie oddawał ich
        z powrotem — jedyną drogą na stronę tagu był adres, który trzeba
        było już znać. Garnek.pl miał ten sam pomysł pod inną nazwą: strona
        zdjęcia wprost wymieniała fotofora, do których zdjęcie trafiło.

        `relationLoaded()`, NIE `$post->tags` wprost: karta stoi też na
        ekranach, które tagów nie doładowują (np. `PostController::show`,
        zakładka „Ugotowane" na profilu). Odwołanie się do relacji wprost
        odpaliłoby tam osobne zapytanie PER WPIS — `TagController`
        i `App\Domain\Feed\TagFeed` już ładowały `tags:id,slug,name` na
        zapas, więc ten warunek tylko bierze to, co jest, i nigdzie nic
        nie dociąga po cichu.

        `.chipsy`/`.chip` to gotowe, już przetestowane klasy (patrz
        `pages/search.blade.php`, `components/szyna-profilu.blade.php`) —
        żadnego nowego CSS, więc rozmiar dotyku i kontrast mają policzone
        pokrycie od pierwszego dnia.
    --}}
    @if($post->relationLoaded('tags') && $post->tags->isNotEmpty())
        <nav class="chipsy post-card-tagi" aria-label="Tematy tego wpisu">
            @foreach($post->tags as $tag)
                <a class="chip" href="{{ route('tags.show', $tag) }}">{{ $tag->name }}</a>
            @endforeach
        </nav>
    @endif

    {{--
        „3 osoby zapisały to u siebie w zeszycie" (issue #275, D-081).

        Cała reguła — kto się liczy, od ilu osób widzi liczbę autor, a od ilu
        ktokolwiek inny, i jak brzmi zdanie po polsku — żyje w
        `App\Domain\Collections\ZapisyWpisu`, nie tutaj. Widok tylko pyta i,
        jeśli jest sens, pokazuje. Ten sam podział co przy liczniku
        społeczności w stopce (`LiczbaKukingow`).

        NAD PASKIEM AKCJI, NIE W NIM. W pasku stoją rzeczy, które się ROBI;
        to jest zdanie, które się CZYTA. Wmieszane między przyciski
        wyglądałoby jak czwarty przycisk i zabierałoby „Ugotowałem"
        pierwszeństwo (AGENTS.md §1).

        `zapisow_count` bierze się z zapytania ekranu (`ZapisyWpisu::dolicz()`).
        Ekran, który go nie dolicza — „kuKINGi na dziś", strona powitalna —
        nie pokazuje tu nic i NIE odpala zapytania na kartę; ten sam wzorzec co
        `relationLoaded('tags')` wyżej. To jest świadome: liczba pod daniem
        wybranym redakcyjnie do zestawu czterech byłaby zestawieniem, a nie
        docenieniem (D-081).
    --}}
    @php $zapisy = app(\App\Domain\Collections\ZapisyWpisu::class); @endphp
    @if($zapisy->widocznaDla($post, auth()->user()))
        <p class="post-card-zapisy" data-rola="liczba-zapisow">{{ $zapisy->zdanie($post) }}</p>
    @endif

    <div class="post-card-actions">
        @auth
            @if($post->recipe)
                {{-- Pierwsza pozycja i jedyny przycisk w kolorze marki.
                     Kolejność w kodzie = kolejność dla klawiatury i czytnika
                     ekranu, więc waga wizualna i waga w nawigacji się zgadzają. --}}
                <a class="btn btn-primary" href="{{ route('cooked.create', $post->recipe->slug) }}">
                    <x-ikona nazwa="chef" :rozmiar="22" />
                    Ugotowałem
                </a>
            @endif
        @endauth

        <a class="btn btn-secondary" href="{{ $post->url() }}">
            <x-ikona nazwa="chat" :rozmiar="22" />
            @if(($post->comments_count ?? 0) > 0)
                Komentarze ({{ $post->comments_count }})
            @else
                Napisz komentarz
            @endif
        </a>

        @auth
            {{--
                „ZAPISUJĘ" (decyzja właściciela, `docs/DECISIONS.md` D-036).

                Do tej zmiany przycisk nosił „Zapisz", a przycisk zapisu
                przepisu (`recipes/show.blade.php`) już wtedy mówił
                „Zapisuję" — dwie nazwy dla jednej czynności na ekranie.
                `BRAND_EXTENDED.md` §3 zabrania synonimów: nazwa funkcji
                jest jedna. Ujednolicone na „Zapisuję" wszędzie.

                Zeszyt przyjmuje od tej zmiany także wpisy. To jest inna
                potrzeba niż zapisanie przepisu: zapisany przepis znaczy „chcę
                to ugotować i mam listę składników", a zapisane zdjęcie —
                „chcę kiedyś zrobić coś TAKIEGO". Przy wpisie żadnego przepisu
                zwykle nie ma.

                PO ZAPISANIU KARTA MÓWI, ŻE JEST ZAPISANE (issue #275, D-081)
                Stał tu komentarz „DLACZEGO ZAWSZE »ZAPISUJĘ«, A NIE
                »ZAPISANO«": sprawdzenie stanu to jedno zapytanie na kartę,
                czyli dwadzieścia na przewinięcie feedu. Pomiar był prawdziwy,
                wniosek nie — stan da się doliczyć TYM SAMYM zapytaniem, którym
                ekran i tak pobiera wpisy (`ZapisyWpisu::dolicz()` dokłada
                `czy_zapisany` do SELECT-a; zero dodatkowych zapytań, pilnuje
                tego `LicznikZapisowBezWachlarzaZapytanTest`).

                Cisza po kliknięciu była realną usterką ze zgłoszenia #275.
                Potwierdzenie ISTNIAŁO — kontroler ustawia komunikat „Zapisane
                w zeszycie …", a `components/layout.blade.php` pokazuje go
                w `.flash` z `aria-live` — ale na GÓRZE strony, a klika się
                w połowie feedu. Człowiek nie widział odpowiedzi TAM, GDZIE
                patrzył, więc klikał drugi raz albo uznawał, że nie działa (ten
                sam mechanizm co przy zdjęciu na sesji z 63-letnią testerką).
                Nie dokładamy drugiego mechanizmu komunikatów: pokazujemy STAN
                w miejscu akcji, tak jak ekran przepisu robi to od dawna
                (`recipes/show.blade.php`, `$isSaved`).

                STAN JEST ZDANIEM, NIE DRUGIM PRZYCISKIEM — I TO JEST CELOWE.
                Ekran przepisu zamienia w tym miejscu przycisk na „Usuń
                z zeszytu". Tutaj nie, bo karta stoi w feedzie: podwójne
                kliknięcie w grupie 50+ to norma, nie pomyłka (issue #43),
                a przycisk kasujący pod tym samym palcem zabierałby z zeszytu
                to, co ktoś właśnie do niego włożył. Zostaje odnośnik do
                zeszytu — bo „wyjąć z zeszytu można w samym zeszycie" i to się
                nie zmieniło.

                Ekran, który `czy_zapisany` nie dolicza, dostaje „Zapisuję" jak
                dawniej. Zapis jest idempotentny, więc drugie kliknięcie daje
                dokładnie ten sam skutek co pierwsze (`SavePostToCollection`).
            --}}
            @if($zapisy->czyZapisany($post))
                <a class="btn btn-secondary" href="{{ route('collections.index') }}" data-rola="stan-zapisu">
                    <x-ikona nazwa="book" :rozmiar="22" />
                    Masz to w zeszycie
                </a>
            @else
                <form method="POST" action="{{ route('collections.save-post', $post) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">
                        <x-ikona nazwa="book" :rozmiar="22" />
                        Zapisuję
                    </button>
                </form>
            @endif
        @endauth

        {{-- „Zgłoś" przeniosło się do menu „…" nad wpisem (UI kit v2).
             Pasek akcji ma nieść to, po co człowiek tu przyszedł. --}}
    </div>
</article>
