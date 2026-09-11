<x-layout title="O Kuking" description="Czym jest Kuking i dlaczego powstał.">
    <article class="prose">
        <h1>O Kuking</h1>

        <p class="text-lead">
            Kuking to miejsce, w którym pokazujesz, co dziś gotujesz, zapisujesz swoje przepisy
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
        {{-- „a nie »kiedyś«" odpierało zarzut, którego nikt nie postawił
             (audyt tekstów 11.09.2026). Sam fakt zostaje i jest sprawdzalny:
             eksport stoi w `/ustawienia/dane` od pierwszego dnia. --}}
        <p>
            Kuking ma być miejscem, z którego da się wszystko zabrać ze sobą — łącznie
            ze zdjęciami. Dlatego eksport własnych danych działa od pierwszego dnia.
        </p>

        {{-- TA STRONA MA PRAWO MÓWIĆ GŁOŚNO — jest jednym z dwóch miejsc,
             obok strony powitalnej, gdzie charakter marki wolno pokazać
             (COPY_STYLE.md §3). Nie wygładzamy jej więc do zera. Zdjęte
             zostały dwa zdania, które ZAPRZECZAŁY ZARZUTOM, KTÓRYCH NIKT NIE
             POSTAWIŁ:

               - „Kuking to nie kolejna baza przepisów." — nikt nie oskarżył
                 nas o bycie bazą przepisów, a zdanie i tak naciskało, że tu
                 są ludzie. Naciskanie daje u czytelnika odwrotne odczucie.
               - „Bez rankingów, bez wyścigu, bez liczników w twarz. Bez
                 algorytmu, który układa Ci stronę główną." — cztery
                 zaprzeczenia pod rząd, a „liczniki w twarz" to jeszcze
                 nienaturalny idiom. Ten sam fakt da się powiedzieć wprost,
                 a przy okazji prawdziwiej: jeden licznik w serwisie JEST
                 („ile osób zapisało to u siebie w zeszycie", D-081), więc
                 „bez liczników" było przesadą.

             Sekcja „Czego tu nie ma i nie będzie" niżej zostaje: to są
             zobowiązania produktowe (AGENTS.md §9 i §12), nie odpieranie
             zarzutów. --}}
        <h2>Co jest tu najważniejsze</h2>
        <ul>
            <li><strong>Ludzie, nie treści.</strong> Tu są ludzie, którzy gotują na co dzień — ich zdjęcia, ich przepisy, ich historie.</li>
            <li><strong>„Ugotowałem” zamiast lajka.</strong> Że komuś naprawdę wyszło z Twojego przepisu, znaczy więcej niż sto serduszek.</li>
            <li><strong>Przepisy po mamie i babci.</strong> Możesz podpisać, po kim jest przepis, dopisać jego historię i dodać zdjęcie starej kartki.</li>
            <li><strong>Spokój.</strong> Wpisy osób, które obserwujesz, stoją w kolejności, w jakiej je dodały. Bez rankingu popularności.</li>
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
