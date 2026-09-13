@props(['stats', 'username', 'wariant'])

{{--
    Pięć liczb profilu pochodzi z jednego zestawu danych kontrolera.
    D-210 zastępuje dawny układ D-091: jeden egzemplarz listy stoi w pasie
    pod nagłówkiem na każdej szerokości, przed archiwum. Nie dublujemy go
    w szynie ani nie przenosimy węzłów JavaScriptem.

    Zachowane są kolejność i adresy: wpisy, przepisy, Ugotowałem,
    obserwujący i obserwowani. Dwa ostatnie prowadzą do list osób.
    To liczby dorobku konkretnej osoby, bez rankingów i porównań (§12).
    Historyczna nazwa wariantu „karta” pozostaje klasą wspólnego komponentu;
    jego bieżące położenie i wygląd określa `marka-profil.css`.
--}}

<ul
    class="profil-liczniki {{ $wariant === 'szyna' ? 'profil-liczby-w-szynie' : 'profil-liczby-karta' }}"
    aria-label="Liczby tego profilu">
    <x-licznik-profilu rodzaj="wpisy" :ile="$stats['posts']" />
    <x-licznik-profilu rodzaj="przepisy" :ile="$stats['recipes']" />
    <x-licznik-profilu rodzaj="ugotowania" :ile="$stats['cooked']" />
    <x-licznik-profilu
        rodzaj="obserwujacy"
        :ile="$stats['followers']"
        :href="route('social.followers', $username)" />
    <x-licznik-profilu
        rodzaj="obserwowani"
        :ile="$stats['following']"
        :href="route('social.following', $username)" />
</ul>
