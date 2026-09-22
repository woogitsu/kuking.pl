{{-- Potwierdzenie ekranowe jest niezależne od e-maila. Bez adresu numer jest jedynym śladem dla zgłaszającego. --}}
<x-layout :title="$numer ? 'Zgłoszenie przyjęte' : 'Potwierdzenie zgłoszenia'" :noindex="true">
    @if($numer)
        <h1>Przyjęliśmy Twoje zgłoszenie</h1>
        <div class="sekcja-strony">
            <p class="mt-0">Numer sprawy:</p>
            <p class="kod-do-przepisania">{{ $numer }}</p>
            <p class="mb-0">
                Warto go zapisać. Jeśli w zgłoszeniu był adres e-mail, wysłaliśmy
                tam potwierdzenie z tym samym numerem.
            </p>
        </div>
        <p>
            Człowiek z naszego zespołu przeczyta zgłoszenie i sprawdzi treść.
            Napiszemy Ci, co postanowiliśmy — także wtedy, gdy uznamy, że treść
            zostaje.
        </p>
    @else
        <h1>Potwierdzenie zgłoszenia</h1>
        <p>Na tej stronie nie ma potwierdzenia wysłania. Jeśli zgłoszenie zostało wysłane,
            mogło do nas dotrzeć. Nie wysyłaj go ponownie tylko z tego powodu.</p>
        <p>Jeśli potrzebujesz pomocy, napisz na
            <a href="mailto:{{ config('kuking.community.contact_email') }}">{{ config('kuking.community.contact_email') }}</a>.</p>
    @endif
    <p>
        <a class="btn btn-quiet" href="{{ route('landing') }}">Wróć na stronę główną</a>
    </p>
</x-layout>