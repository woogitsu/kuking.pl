<x-layout title="O Kuking" description="Czym jest Kuking i dlaczego powstał.">
    <article class="prose">
        <h1>O <x-kuking-word /></h1>

        <p class="text-lead">
            <x-kuking-word /> to miejsce, w którym pokazujesz, co dziś gotujesz, zapisujesz swoje przepisy
            i poznajesz ludzi, którzy naprawdę gotują.
        </p>

        <h2>Dlaczego to powstało</h2>
        <p>
            Przepisy giną. Zeszyty się rozsypują, telefony się psują, grupy na Facebooku
            zamykają się razem z administratorem. To samo bywa z serwisami internetowymi —
            i mamy na to dwa świeże, polskie przykłady.
        </p>
        <p>
            Garnek.pl, gdzie ludzie latami trzymali zdjęcia swojej codzienności, przestał
            działać 25 listopada 2024 roku — oficjalnie dlatego, że przychody z reklam
            nie pokrywały już kosztów utrzymania. Wolontariusze próbowali ratować archiwum,
            ale zabrakło czasu, żeby pobrać także zdjęcia. Zdjęcia ludzi przepadły.
        </p>
        <p>
            Durszlak.pl też się zamknął. Serwis miał „zeszyty” — osobiste zbiory zapisanych
            przepisów, które ludzie budowali latami. Zniknęły razem z nim.
        </p>

        {{-- ŹRÓDŁA STOJĄ TU, BO WYMIENIAMY DWIE ISTNIEJĄCE FIRMY Z NAZWY.
             Wolno tak pisać, dopóki mówi się prawdę — a prawdę trzeba mieć czym
             pokazać, i to czytelnikowi, nie tylko sobie w notatce badawczej
             (`docs/research/COMPETITIVE_LANDSCAPE.md`). Dla osoby, do której ta
             strona mówi, „skąd to wiemy" jest też zwykłą uprzejmością: dostaje
             odnośnik zamiast prośby o zaufanie.

             DATY DURSZLAKA CELOWO NIE PODAJEMY. Zamknięcie i przepadnięcie
             zeszytów są potwierdzone w prasie kulinarnej, ale ROKU nie udało
             się potwierdzić w źródle, które podaje go wprost. Fakt bez daty
             jest prawdziwy; fakt z datą „mniej więcej" już nie. --}}
        <p class="meta">
            Skąd to wiemy:
            <a href="https://wiki.archiveteam.org/index.php/Garnek.pl"
               target="_blank" rel="noopener">zapis wyłączenia Garnek.pl w Archiveteamie</a>
            ·
            <a href="https://rondel.pl/przepis,koniec-popularnego-serwisu-kulinarnego-durszlakpl-zeszyty-z-przepisami-przepadly.html"
               target="_blank" rel="noopener">informacja o zamknięciu Durszlak.pl</a>
        </p>
        <p>
            <x-kuking-word /> ma być miejscem, z którego da się wszystko zabrać ze sobą — łącznie
            ze zdjęciami. Dlatego eksport własnych danych działa od pierwszego dnia,
            a nie „kiedyś”.
        </p>

        <h2>Co jest tu najważniejsze</h2>
        <ul>
            <li><strong>Ludzie przed przepisami.</strong> <x-kuking-word /> to nie kolejna baza przepisów — to ludzie, którzy gotują na co dzień.</li>
            <li><strong>„Ugotowałem” zamiast lajka.</strong> Że komuś naprawdę wyszło z Twojego przepisu, znaczy więcej niż sto serduszek.</li>
            <li><strong>Przepisy po mamie i babci.</strong> Możesz podpisać, po kim jest przepis, dopisać jego historię i dodać zdjęcie starej kartki.</li>
            <li><strong>Spokój.</strong> Bez rankingów i bez algorytmu, który układałby Ci stronę główną.</li>
        </ul>

        <h2>Czego tu nie ma i nie będzie</h2>
        <ul>
            <li>Nie kupujemy ruchu i nie generujemy przepisów sztuczną inteligencją, żeby wypełnić serwis treścią.</li>
            <li>Nie importujemy masowo cudzych przepisów.</li>
            <li>Nie robimy rankingów najpopularniejszych użytkowników.</li>
        </ul>

        <h2>Kontakt</h2>
        <p>Napisz do nas: <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a></p>
    </article>
</x-layout>
