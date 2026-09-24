@props(['body', 'returnUrl' => null])
<section class="panel-formularza stack" aria-labelledby="poprawka-po-czasie">
    <h2 id="poprawka-po-czasie">Czas na poprawienie komentarza minął.</h2>
    <p>Poprawkę można zapisać przez 15 minut od dodania komentarza. Poniżej jest Twój tekst — nie został zapisany w komentarzu. Skopiuj tekst, żeby zachować go u siebie. Możesz też wkleić go do nowego komentarza.</p>
    <div class="field">
        <label class="field-label" for="odzyskana-poprawka">Twój tekst do skopiowania</label>
        <textarea class="field-input" id="odzyskana-poprawka" rows="8" readonly>{{ $body }}</textarea>
    </div>
    @if($returnUrl)
        <a class="btn btn-secondary" href="{{ $returnUrl }}#komentarze">Wróć do rozmowy</a>
    @endif
</section>
