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
        <p>
            Kuking ma być miejscem, z którego da się wszystko zabrać ze sobą — łącznie
            ze zdjęciami. Dlatego eksport własnych danych działa od pierwszego dnia,
            a nie „kiedyś”.
        </p>

        <h2>Co jest tu najważniejsze</h2>
        <ul>
            <li><strong>Ludzie, nie treści.</strong> Kuking to nie kolejna baza przepisów. To ludzie, którzy gotują na co dzień.</li>
            <li><strong>„Ugotowałem” zamiast lajka.</strong> Że komuś naprawdę wyszło z Twojego przepisu, znaczy więcej niż sto serduszek.</li>
            <li><strong>Przepisy po mamie i babci.</strong> Możesz podpisać, po kim jest przepis, dopisać jego historię i dodać zdjęcie starej kartki.</li>
            <li><strong>Spokój.</strong> Bez rankingów, bez wyścigu, bez liczników w twarz. Bez algorytmu, który układa Ci feed.</li>
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
