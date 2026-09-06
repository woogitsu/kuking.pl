{{--
    Potwierdzenie na EKRANIE, osobno od potwierdzenia e-mailem.

    List może nie dojść albo trafić do spamu, a zgłaszający ma prawo wiedzieć
    OD RAZU, że kliknięcie coś zrobiło. Numer sprawy jest tu także dlatego,
    że część osób zgłasza bez podawania adresu — dla nich to jedyny ślad.
--}}
<x-layout title="Zgłoszenie przyjęte" :noindex="true">
    <h1>Przyjęliśmy Twoje zgłoszenie</h1>

    @if($numer)
        <div class="card">
            <p class="mt-0">Numer sprawy:</p>
            <p class="kod-do-przepisania">{{ $numer }}</p>
            <p class="mb-0">
                Warto go zapisać. Jeśli podałeś adres e-mail, wysłaliśmy tam
                potwierdzenie z tym samym numerem.
            </p>
        </div>
    @endif

    <p>
        Człowiek z naszego zespołu przeczyta zgłoszenie i sprawdzi treść.
        Napiszemy Ci, co postanowiliśmy — także wtedy, gdy uznamy, że treść
        zostaje.
    </p>

    <p>
        <a class="btn btn-quiet" href="{{ route('landing') }}">Wróć na stronę główną</a>
    </p>
</x-layout>
