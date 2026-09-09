<x-layout title="Pomoc" description="Jak korzystać z Kuking — krok po kroku.">
    <article class="prose">
        <h1>Pomoc</h1>

        <h2>Jak dodać zdjęcie swojego dania?</h2>
        <ol>
            <li>Kliknij <strong>Dodaj</strong> — na telefonie znajdziesz ten przycisk na dole ekranu, pośrodku.</li>
            <li>Wybierz <strong>Zdjęcie i kilka słów</strong>.</li>
            <li>Kliknij pole ze zdjęciem. Telefon zapyta, czy chcesz zrobić zdjęcie teraz, czy wybrać je z galerii.</li>
            <li>Napisz jedno zdanie — albo nic nie pisz, to też jest w porządku.</li>
            <li>Kliknij <strong>Opublikuj</strong>.</li>
        </ol>

        <h2>Tekst jest dla mnie za mały</h2>
        <p>
            Wejdź w <a href="{{ route('settings.accessibility') }}">Ustawienia → Rozmiar tekstu</a>
            i wybierz rozmiar, przy którym czyta Ci się wygodnie. Zapisze się na Twoim koncie —
            będzie taki sam na telefonie i na komputerze.
        </p>

        <h2>Co to znaczy „Ugotowałem”?</h2>
        <p>
            To najważniejszy przycisk w Kuking. Klikasz go, kiedy naprawdę ugotujesz coś
            z czyjegoś przepisu. Autor dostanie o tym wiadomość — i to jest tu najmilsza rzecz.
            Możesz dodać zdjęcie, ale nie musisz.
        </p>

        <h2>Kto widzi to, co publikuję?</h2>
        <p>
            Przy każdym wpisie i przepisie sam wybierasz: <strong>wszyscy</strong>,
            <strong>tylko osoby, które Cię obserwują</strong>, albo <strong>tylko Ty</strong>.
            Możesz to zmienić w każdej chwili.
        </p>

        <h2>Nie pamiętam hasła</h2>
        <p>
            Na stronie logowania kliknij <a href="{{ route('password.request') }}">Nie pamiętam hasła</a>.
            Wyślemy Ci wiadomość z linkiem. Jeśli nie przychodzi, sprawdź folder „Spam”.
        </p>

        <h2>Ktoś zachowuje się nieprzyjemnie</h2>
        <p>
            Pod każdą treścią jest przycisk <strong>Zgłoś</strong>. Przeczytamy każde zgłoszenie.
            Możesz też zablokować konkretną osobę na jej profilu — wtedy nie zobaczycie
            już wzajemnie swoich treści.
        </p>

        <h2>Chcę zabrać swoje przepisy</h2>
        <p>
            Wejdź w <a href="{{ route('settings.data') }}">Ustawienia → Twoje dane</a>
            i kliknij „Przygotuj paczkę z moimi danymi”. Dostaniesz plik, który otworzysz
            na swoim komputerze.
        </p>

        <h2>Coś nie działa albo mam pomysł</h2>
        <p>
            Wejdź na <a href="{{ route('kontakt') }}">Napisz do nas</a> i opisz to własnymi
            słowami. Nie musisz mieć konta. To ta sama droga dla awarii i dla pomysłów —
            i to jest inna droga niż zgłaszanie czyjegoś wpisu.
        </p>

        <h2>Nadal nie wiem, co kliknąć</h2>
        <p>
            <a href="{{ route('kontakt') }}">Napisz do nas</a> albo wyślij zwykłego e-maila na
            <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>.
            Odpisujemy po ludzku i naprawdę czytamy każdą wiadomość.
        </p>
    </article>
</x-layout>
