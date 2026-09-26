{{-- Issue #936: poprawka wpisu, który moderacja ukryła albo usunęła. Nic nie
     zapisano, ale wpisany tekst nie znika (AGENTS.md §5) — wraca do skopiowania. --}}
<x-layout title="Tego wpisu nie da się teraz poprawić">
    <section class="panel-formularza stack" aria-labelledby="poprawka-pod-decyzja">
        <h1 id="poprawka-pod-decyzja">Tego wpisu nie da się teraz poprawić.</h1>
        <p>{{ $komunikat }}</p>
        <p>Poniżej jest Twój tekst — nie został zapisany we wpisie. Skopiuj go, żeby zachować go u siebie.</p>
        <div class="field">
            <label class="field-label" for="odzyskana-poprawka-wpisu">Twój tekst do skopiowania</label>
            <textarea class="field-input" id="odzyskana-poprawka-wpisu" rows="8" readonly>{{ $body }}</textarea>
        </div>
        <a class="btn btn-secondary" href="{{ $returnUrl }}">Wróć do wpisu</a>
    </section>
</x-layout>
