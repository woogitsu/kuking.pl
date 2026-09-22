{{--
    Strona sprawy zgłaszającego po wygaśnięciu (issue #798, HTTP 410).

    DLACZEGO OSOBNY WIDOK, A NIE KOLEJNA GAŁĄŹ W `reporter.blade.php`
    Bo ta strona ma NIE POKAZYWAĆ sprawy — ani numeru, ani skutku decyzji,
    ani treści odwołania. Gdyby stała w tamtym widoku jako `@else`, pierwsza
    poprawka w sąsiedniej gałęzi mogłaby tu wpuścić dane, których po
    wygaśnięciu pokazywać nie wolno. Osobny plik nie dostaje ich wcale.

    DLACZEGO NIE 403
    Człowiek z linkiem sprzed roku niczego złego nie zrobił, a 403
    („Ta strona nie jest dla Ciebie") mówiłoby mu nieprawdę: ta strona BYŁA
    dla niego. 410 znaczy „było, nie ma" — i to jest dokładnie ten stan.

    CO MA STĄD WYNIEŚĆ: GDZIE SZUKAĆ ODPOWIEDZI. Odpowiedź poszła mailem
    (`NotifyReporterAppealOutcome`), a jeśli jej nie ma — jest adres, pod
    który można napisać. Tak samo mówi list z decyzją
    (`DecyzjaWSprawieZgloszenia`), żeby oba kanały nie rozjeżdżały się
    w obietnicy.
--}}
<x-layout title="Ta strona sprawy już wygasła" :noindex="true">
    <h1>Ta strona sprawy już wygasła</h1>

    <article class="sekcja-strony">
        <p>
            Odnośnik z naszego listu pokazuje sprawę, dopóki jest otwarta,
            i jeszcze {{ config('kuking.moderation.reporter_case_link_days') }} dni po tym,
            jak na nią odpowiemy. Ten czas już minął, więc strony sprawy tu nie ma.
        </p>

        <p>
            <strong>Odpowiedź wysłaliśmy e-mailem</strong> — na adres, z którego
            przyszło Twoje zgłoszenie. Poszukaj wiadomości od nas w skrzynce,
            także w folderze ze spamem.
        </p>

        <p>
            Jeśli jej nie znajdujesz albo pojawiły się nowe okoliczności, napisz na
            {{ config('kuking.community.contact_email') }} — odpiszemy. Jeśli masz numer
            sprawy z naszego listu, podaj go w wiadomości; jeśli nie masz, napisz i tak.
        </p>
    </article>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('landing') }}">Strona główna</a>
        <a class="btn btn-quiet" href="{{ route('help') }}">Pomoc</a>
    </div>
</x-layout>
