{{-- Issue #1337: poprawka odrzucona, bo ktoś już odpowiedział. Tekst wraca do skopiowania. --}}
@props(['body', 'returnUrl' => null, 'commentId' => null])
<section class="panel-formularza stack" aria-labelledby="poprawka-po-odpowiedzi">
    <h2 id="poprawka-po-odpowiedzi">Ktoś już odpowiedział na ten komentarz.</h2>
    <p>Po pierwszej odpowiedzi komentarza nie da się poprawić — odpowiedź mogłaby stracić sens. Poniżej jest Twój tekst — nie został zapisany w komentarzu. Skopiuj go, a jeśli chcesz coś sprostować, dopisz odpowiedź pod swoim komentarzem.</p>
    <div class="field">
        <label class="field-label" for="odzyskana-poprawka-po-odpowiedzi">Twój tekst do skopiowania</label>
        <textarea class="field-input" id="odzyskana-poprawka-po-odpowiedzi" rows="8" readonly>{{ $body }}</textarea>
    </div>
    @if($returnUrl)
        <a class="btn btn-secondary" href="{{ $returnUrl }}#{{ $commentId ? 'komentarz-'.$commentId : 'komentarze' }}">Wróć do rozmowy i dopisz odpowiedź</a>
    @endif
</section>
