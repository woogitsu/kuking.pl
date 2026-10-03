{{--
    DODATKOWY CZAS PRZY MINUTNIKU KROKU (#2458, decyzja właściciela z 2.10.2026).

    Cały blok jest ukryty bez JavaScriptu (jak przycisk minutnika, D-053): bez
    skryptu nie ma czego uruchomić, a martwych kontrolek nie zostawiamy — zdanie
    o kuchennym minutniku nad blokiem zostaje. Skrypt odkrywa przycisk „Ustaw
    dodatkowy czas” w trakcie odliczania i po alarmie; formularz otwiera się na
    żądanie. Dotyczy wyłącznie lokalnego terminu tego jednego minutnika —
    czas autora i odhaczenia zostają.

    Widoczna etykieta pola, błąd PRZY polu oraz w podsumowaniu nad nim,
    przyciski `.btn-cook` (>= 48 px). Jeden element na stronie — id są unikalne.
--}}
<button type="button" class="btn btn-secondary btn-cook cook-timer-dodaj" hidden>
    Ustaw dodatkowy czas
</button>
<form class="cook-timer-dodatkowy" novalidate hidden>
    <p class="field-error cook-timer-dodatkowy-podsumowanie" role="alert" hidden></p>
    <div class="field">
        <label for="f-minutnik-dodatkowy">Ile dodatkowych minut?</label>
        <span class="field-help" id="f-minutnik-dodatkowy-pomoc">Pełne minuty, najwięcej 3 godziny naraz. Dodamy je do tego minutnika; czas autora zostaje bez zmian.</span>
        <input class="field-input cook-timer-dodatkowy-minuty" type="text" inputmode="numeric" autocomplete="off" id="f-minutnik-dodatkowy" aria-describedby="f-minutnik-dodatkowy-pomoc f-minutnik-dodatkowy-blad">
        <p class="field-error cook-timer-dodatkowy-blad" id="f-minutnik-dodatkowy-blad" hidden></p>
    </div>
    <button type="submit" class="btn btn-secondary btn-cook">Dodaj czas</button>
    <button type="button" class="btn btn-quiet btn-cook cook-timer-dodatkowy-anuluj">Nie dodawaj</button>
</form>
