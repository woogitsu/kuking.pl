<x-layout title="O Kuking" description="Czym jest Kuking i dlaczego powstał.">
    <article class="prose">
        <h1>O Kuking</h1>

        <p style="font-size:var(--text-lead);">
            Kuking to miejsce, w którym pokazujesz, co dziś gotujesz, zapisujesz swoje przepisy
            i poznajesz ludzi, którzy naprawdę gotują.
        </p>

        <h2>Dlaczego to powstało</h2>
        <p>
            Przepisy giną. Zeszyty się rozsypują, telefony się psują, grupy na Facebooku
            zamykają się razem z administratorem, a serwisy, na których ludzie trzymali
            zdjęcia swoich obiadów, po prostu znikają. Kuking ma być miejscem, z którego
            da się wszystko zabrać ze sobą — dlatego pobranie własnych danych działa
            od pierwszego dnia, a nie „kiedyś”.
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
