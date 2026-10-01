<x-layout title="To Twoja sesja" :noindex="true">
    <div class="stack max-w-[38rem] mx-auto">
        <h1>To jest Twoja sesja</h1>
        <p>Nie musisz dołączać do własnej sesji. Ten link wyślij osobie, którą zapraszasz.</p>
        <a class="btn btn-primary" href="{{ route('wspolne-gotowanie.show', $sesja) }}">Wróć do sesji</a>
    </div>
</x-layout>
