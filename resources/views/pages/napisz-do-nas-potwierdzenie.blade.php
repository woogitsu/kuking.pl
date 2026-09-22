{{-- Potwierdzenie należy do jednego wysłania w tej sesji; brak danych nie oznacza braku adresu. --}}
<x-layout :title="$przyjeta ? 'Wiadomość wysłana' : 'Potwierdzenie wiadomości'" :noindex="true">
    @if($przyjeta)
        <h1>Mamy Twoją wiadomość</h1>
        <div class="sekcja-strony">
            @if($odpowiedzNa)
                <p class="mt-0 mb-0">
                    Odpowiedź wyślemy na <strong>{{ $odpowiedzNa }}</strong>.
                    @auth
                        Adres konta możesz zmienić w <a href="{{ route('settings.email') }}">ustawieniach adresu e-mail</a>.
                        Potrzebujesz obecnego hasła i dostępu do nowej skrzynki.
                        Jeśli nie możesz tego zrobić, napisz ze swojej poczty na
                        <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>.
                    @else
                        Jeśli to nie jest adres, który czytasz,
                        <a href="{{ route('kontakt') }}">napisz do nas jeszcze raz</a> — z tym właściwym.
                    @endauth
                </p>
            @else
                <p class="mt-0 mb-0">
                    W formularzu nie było adresu e-mail, więc nie mamy jak odpisać —
                    ale wiadomość przeczytamy. Jeśli chcesz odpowiedź,
                    <a href="{{ route('kontakt') }}">napisz do nas jeszcze raz</a> i podaj adres.
                </p>
            @endif
        </div>
        <p>
            Czyta je {{ config('kuking.community.host_name') }}. Nie ma tu całodobowego
            dyżuru — czasem odpowiedź przyjdzie tego samego dnia, czasem po weekendzie.
        </p>
    @else
        <h1>Potwierdzenie wiadomości</h1>
        <p>Na tej stronie nie ma potwierdzenia wysłania. Jeśli wiadomość została wysłana,
            mogła do nas dotrzeć. Nie wysyłaj jej ponownie tylko z tego powodu.</p>
        <p>Jeśli potrzebujesz pomocy, napisz ze swojej poczty na
            <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>.</p>
    @endif
    <p>
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">
            Wróć do Kuking
        </a>
    </p>
</x-layout>