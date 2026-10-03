{{--
    NA ILE SZTUK — wybór widza nad listą składników, gdy autor podał, ile
    gotowych sztuk wychodzi z przepisu (#2645, V2).

    ZWYKŁY FORMULARZ GET, BEZ JAVASCRIPTU. „Przelicz” prowadzi pod
    `?sztuki=N#skladniki`, więc działa przy słabym zasięgu i po wysłaniu
    linku rodzinie. Pole jest tekstowe z klawiaturą numeryczną
    (`inputmode`), żeby przeglądarka nie odrzucała wpisu po cichu; błąd mówi,
    co zrobić, a wpisana liczba zostaje w polu (przechodzi przez
    `WyborSztuk::wartoscPola()` — do strony wraca tylko to, co wygląda jak liczba).

    JEDNA PODSTAWA. Gdy wybrano sztuki, wybór porcji jest ukryty
    (`_wybor-porcji`), a współczynnik liczy się tylko ze sztuk. Powrót do
    ilości autora przy zapamiętanych porcjach to jawne `?porcje=autor`,
    bo adres bez parametru przywraca wtedy tę preferencję.

    Przepis bez podanej liczby sztuk nie ma tu nic.
--}}
@if($wyborSztuk->dostepny())
    @php
        $adresPowrotuSztuk = route('recipes.show', array_filter([
            'recipe' => $recipe->slug,
            'druk' => ($dlaPomocnika ?? false) ? 1 : null,
            'dla' => ($dlaPomocnika ?? false) ? 'pomocnika' : null,
            'qr' => ($dlaPomocnika ?? false) && ($qrNaKartce ?? false) ? 1 : null,
            'porcje' => $zapamietanePorcje->maUstawienie() ? 'autor' : null,
        ], fn ($wartosc) => $wartosc !== null)).'#skladniki';
    @endphp
    <form class="porcje-wybor" method="get" action="{{ route('recipes.show', $recipe->slug) }}#skladniki" novalidate
          aria-labelledby="sztuki-wybor-tytul">
        @if($dlaPomocnika ?? false)
            <input type="hidden" name="druk" value="1">
            <input type="hidden" name="dla" value="pomocnika">
            @if($qrNaKartce ?? false)<input type="hidden" name="qr" value="1">@endif
        @endif
        <p class="porcje-wybor-tytul" id="sztuki-wybor-tytul">Z tych ilości wychodzi {{ $wyborSztuk->etykietaAutora() }}</p>
        <x-field name="sztuki" label="Na ile sztuk?" type="text" inputmode="numeric" :value="$wyborSztuk->wartoscPola()"
                 :bez-oznaczenia="true"
                 help="Wpisz, ile sztuk chcesz zrobić, na przykład 36. Przeliczymy składniki." />
        <div class="porcje-wybor-przyciski">
            <button type="submit" class="btn btn-secondary">Przelicz składniki</button>
            @if($wyborSztuk->przeliczone())
                <a class="btn btn-secondary" href="{{ $adresPowrotuSztuk }}" rel="nofollow">Pokaż ilości z przepisu</a>
            @endif
        </div>
    </form>

    @if($wyborSztuk->odrzucone)
        <p class="porcje-wybor-uwaga">
            Tej liczby sztuk nie da się przeliczyć. Pokazujemy ilości z przepisu.
            Wpisz liczbę od {{ \App\Domain\Recipes\Porcje\GotoweSztuki::NAJMNIEJ }} do {{ \App\Domain\Recipes\Porcje\GotoweSztuki::NAJWIECEJ }}, na przykład 36.
        </p>
    @endif

    @if($wyborSztuk->przeliczone())
        <div class="porcje-wybor-uwaga">
            <p class="m-0">
                <strong>Przeliczone na {{ $wyborSztuk->etykieta() }}.</strong>
                Autor podał ilości na {{ $wyborSztuk->etykietaAutora() }}.
                Zaokrągliliśmy je po kuchennemu. Szczypta, „do smaku” i składniki bez liczby zostały bez zmian.
                Czasu, temperatury ani wielkości naczynia nie przeliczamy. Tryb gotowania pokazuje na razie ilości autora.
            </p>
        </div>
    @endif
@endif
