{{-- UUID z old() sprawdza ZachowaneZdjeciaPrzepisu; sam hidden nie daje dostępu. --}}
@if(isset($zachowaneZdjecia[$klucz]))
    @php($zachowaneZdjecie = $zachowaneZdjecia[$klucz])
    <div class="notice" role="status">
        <p class="mt-0"><strong>Twoje zdjęcie jest zachowane.</strong> Możesz poprawić formularz bez wybierania go ponownie.</p>
        <input type="hidden" name="zachowane_zdjecia[{{ $klucz }}]" value="{{ $zachowaneZdjecie->getKey() }}">
        <x-photo :media="$zachowaneZdjecie" variant="thumb" :zoom="false" alt="Zachowane zdjęcie formularza" />
        <label class="choice mt-2">
            <input type="checkbox" name="usun_zachowane[{{ $klucz }}]" value="1">
            <span>Usuń to zachowane zdjęcie</span>
        </label>
    </div>
@elseif(is_array($stareZdjecia) && array_key_exists($klucz, $stareZdjecia))
    <p class="field-help" role="status">Tego zdjęcia nie można już użyć. Wybierz je ponownie.</p>
@endif
