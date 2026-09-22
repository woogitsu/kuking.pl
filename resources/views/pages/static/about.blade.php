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
        {{-- „a nie »kiedyś«" odpierało zarzut, którego nikt nie postawił
             (audyt tekstów 11.09.2026). Sam fakt zostaje i jest sprawdzalny:
             eksport stoi w `/ustawienia/twoje-dane` od pierwszego dnia. --}}
        <p>
            <x-kuking-word /> ma być miejscem, z którego da się wszystko zabrać ze sobą — łącznie
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

             Sekcja z zobowiązaniami niżej ZOSTAJE — to są zobowiązania
             produktowe (AGENTS.md §9 i §12), nie odpieranie zarzutów.
             Zmieniła się jej FORMA, nie treść: patrz komentarz przy niej. --}}
        <h2>Co jest tu najważniejsze</h2>
        <ul>
            <li><strong>Ludzie, nie treści.</strong> Tu są ludzie, którzy gotują na co dzień — ich zdjęcia, ich przepisy, ich historie.</li>
            <li><strong>„Ugotowałem” zamiast lajka.</strong> Że komuś naprawdę wyszło z Twojego przepisu, znaczy więcej niż sto serduszek.</li>
            <li><strong>Przepisy po mamie i babci.</strong> Możesz podpisać, po kim jest przepis, dopisać jego historię i dodać zdjęcie starej kartki.</li>
            <li><strong>Spokój.</strong> Wpisy osób, które obserwujesz, stoją w kolejności, w jakiej je dodały. Bez rankingu popularności.</li>
        </ul>

        {{-- SEKCJA PRZEPISANA NA FORMĘ TWIERDZĄCĄ — decyzja właściciela.

             Stał tu tytuł „Czego tu nie ma i nie będzie" i pod nim trzy zdania
             zaczynające się od „Nie". TREŚĆ OBIETNIC SIĘ NIE ZMIENIŁA — zmieniła
             się forma. Zaprzeczenie każe czytelnikowi najpierw wyobrazić sobie
             to, czego nie ma, i dopiero potem to odjąć; twierdzenie mówi od razu,
             co dostaje.

             ODWZOROWANIE STAREGO NA NOWE — żadne zobowiązanie nie znika:
               „nie generujemy przepisów sztuczną inteligencją"
                   → „Wszystko tutaj napisali ludzie, którzy to gotują"
               „nie kupujemy ruchu"
                   → „Rośniemy z polecenia"
               „nie importujemy masowo cudzych przepisów"
                   → „Każdy przepis wpisał tu jego właściciel — jeden po drugim"
               „nie robimy rankingów najpopularniejszych użytkowników"
                   → „Wszystkie konta są tu równe"

             DWA ZOBOWIĄZANIA DOŁOŻONE NA WYRAŹNĄ DECYZJĘ WŁAŚCICIELA: „bez opłat"
             i „bez reklam". Nigdy reklamy, nigdy płatny dostęp — przyszłe
             zarabianie to najwyżej dobrowolne wsparcie na hosting
             (`docs/MONETIZATION.md`: reklama „wykluczona, nie odłożona",
             zbiórka na hosting „dopuszczalna", „co darmowe, zostaje darmowe").

             DLACZEGO WOLNO TU OBIECYWAĆ PRZYSZŁOŚĆ, choć COPY_STYLE §7 każe
             mierzyć każde zdanie kodem, który dziś stoi w repozytorium: to nie
             jest obietnica cudzego zachowania ani harmonogramu, którego nie mamy.
             To zobowiązanie co do WŁASNEGO postępowania, a jego złamanie
             byłoby widoczne z ekranu w sekundę — reklama albo ściana płatności
             nie dadzą się ukryć. Takie zdanie wolno napisać.

             BRZMIENIE ZOBOWIĄZANIA O OPŁATACH JEST CYTATEM Z DECYZJI, NIE
             WYMYŚLONE TUTAJ. `GLOS_MARKI.md` §6 (decyzja B1) przyjął „bez opłat
             i bez reklam", a odrzucił wprost dwa warianty, w które ta sekcja
             wchodziła sama: „to darmowe" (sprzedażowo, C3) i „za darmo, na
             zawsze" (obietnica na przyszłość bez gwarancji). Dlatego akapit
             wprowadzający niesie zatwierdzone brzmienie co do słowa, a punkt
             listy mówi „Korzystasz bez opłat" — nie „Zawsze za darmo".

             To jedyne „bez" w roli głównej myśli w tej sekcji i stąd się bierze:
             jest zobowiązaniem, którego łamanie byłoby widoczne, a nie zaletą,
             której nikt nie sprawdzi (§6, reguła końcowa). Punkty listy są
             twierdzeniami bez wyjątku.

             `<x-kuking-word />` STOI TU RAZ, W AKAPICIE WPROWADZAJĄCYM. Nie
             dlatego, że istnieje sufit na ekran — ten zniesiono decyzją B2
             (`GLOS_MARKI.md` §2, PR #398 usunął razem z nim test, który go
             pilnował). Obowiązuje „raz na akapit, nagłówek albo punkt listy"
             (§4) i kryterium „marka nie konkuruje z zadaniem". Nazwa stoi
             w akapicie, bo tam jest PODMIOTEM zobowiązania — to ona się
             zobowiązuje. W punktach listy byłaby ozdobą: każdy z nich mówi
             „tu" albo „u nas" i to wystarcza.

             Czego tu ŚWIADOMIE NIE MA: zdania tłumaczącego, dlaczego ta sekcja
             jest napisana twierdzeniami. Rodzina D-140 — tekst dla człowieka nie
             uzasadnia własnego brzmienia. Uzasadnienie jest w tym komentarzu
             i tu zostaje. --}}
        <h2>Na co możesz liczyć</h2>
        <p>
            <x-kuking-word /> jest bez opłat i bez reklam. Podpisujemy się pod każdym
            z tych zdań.
        </p>
        <ul>
            <li><strong>Korzystasz bez opłat.</strong> Konto, przepisy, zdjęcia, pobranie własnych danych. Gdyby zabrakło pieniędzy na serwery, poprosimy o wsparcie wprost — dorzucenie się będzie Twoim wyborem.</li>
            <li><strong>Cały ekran należy do gotowania.</strong> Miejsce, w którym inne serwisy stawiają reklamy, u nas zajmuje czyjeś danie.</li>
            <li><strong>Wszystko tutaj napisali ludzie, którzy to gotują.</strong> Każdy przepis i każdy wpis wyszedł z czyjejś kuchni.</li>
            <li><strong>Każdy przepis wpisał tu jego właściciel.</strong> Ze swojego zeszytu, ze swojej głowy albo po mamie — jeden po drugim, ręcznie.</li>
            <li><strong>Rośniemy z polecenia.</strong> Ludzie trafiają tu dlatego, że ktoś im o tym miejscu powiedział.</li>
            <li><strong>Wszystkie konta są tu równe.</strong> Jedyna kolejność w tym serwisie to ta, w jakiej ludzie dodają wpisy.</li>
        </ul>

        <h2>Kontakt</h2>
        <p>Napisz do nas: <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a></p>
    </article>
</x-layout>
