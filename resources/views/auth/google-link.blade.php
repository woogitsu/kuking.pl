<x-layout title="Połączyć to konto z kontem Google?" :noindex="true">
    <h1>Połączyć to konto z kontem Google?</h1>

    {{--
        TO JEST EKRAN, KTÓRY ROZSTRZYGA REGUŁĘ 3 z `GoogleLoginController`:
        konta łączymy WYŁĄCZNIE po jawnym potwierdzeniu przez człowieka.

        Sam atak zamykają wcześniejsze dwa warunki (Google musi potwierdzić
        adres; konta z niepotwierdzonym u nas adresem nie łączymy wcale).
        Ten ekran zamyka coś innego: NIESPODZIANKĘ. Człowiek ma zobaczyć,
        na jakie konto wchodzi i co się z nim stanie, ZANIM to się stanie —
        ta sama zasada, dla której link e-mail nie loguje od razu, tylko
        prowadzi na ekran z przyciskiem (D-056).

        GET niczego nie zmienia. Powiązanie powstaje dopiero po POST poniżej.
    --}}
    <div class="sekcja-strony">
        <p>
            Na adres <strong>{{ $email }}</strong> jest już konto w Kuking:
            <strong>{{ $displayName ?? 'to konto' }}</strong>.
        </p>
        <p>
            Możemy połączyć je z Twoim kontem Google. Od tej chwili będziesz wchodzić
            przyciskiem „Wejdź kontem Google”, a Twoje hasło zostanie takie, jakie było — nadal będzie działać.
            Nic w Twoim profilu, przepisach ani zeszytach się nie zmieni.
        </p>

        <form method="POST" action="{{ route('google.link.store') }}">
            @csrf
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Tak, to moje konto — połącz i wejdź</button>
                <a class="btn btn-quiet" href="{{ route('login') }}">Nie teraz</a>
            </div>
        </form>
    </div>

    {{--
        DROGA WYJŚCIA DLA KOGOŚ, KTO WIDZI TU CUDZE KONTO.

        Z jednej skrzynki i z jednego telefonu korzysta czasem całe
        małżeństwo, a Google wchodzi domyślnie kontem ostatnio używanym.
        Kto widzi na ekranie nie swoje imię, ma się dowiedzieć, co zrobić,
        a nie zgadywać.
    --}}
    <p class="mt-6">
        To nie Twoje konto? Nic nie klikaj — <a href="{{ route('login') }}">wróć do logowania</a>
        i sprawdź, jakim kontem Google jesteś zalogowany na tym urządzeniu.
    </p>
</x-layout>
