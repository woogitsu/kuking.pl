## D-309 — Ta sama liczba komentarzy wszędzie: przepis i „Ugotowałem” też liczą odpowiedzi (#1801, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela ·
Rozszerza **D-281**

**Problem.** Po D-281 karta i strona zwykłego wpisu liczyły rozmowę razem
z odpowiedziami, ale nagłówek „Komentarze (N)” pod przepisem dalej brał
`total()` stronicowania (same wątki), a pod „Ugotowałem” — liczbę wczytanych
korzeni. Ta sama etykieta znaczyła dwie różne rzeczy na sąsiednich ekranach.

**Decyzja właściciela.** Licznik komentarzy liczy WSZYSTKIE komentarze razem
z odpowiedziami — spójnie wszędzie, gdzie serwis tę liczbę pokazuje. Jedyny
wyjątek to pytanie (#372): etykieta „Odpowiedzi” i `QAPage.answerCount`
liczą odpowiedzi najwyższego poziomu, bo to inna rzecz niż komentarz.

**Przegląd miejsc (grep, 26.09.2026).** Liczbę komentarzy pokazują: karta
wpisu we wszystkich strumieniach (start, odkrywanie, tagi, profil, zeszyt,
tablica dnia — `withVisibleCommentCount()`), nagłówek strony wpisu, pytania,
przepisu i „Ugotowałem”. Powiadomienia, tygodniowy list i eksport danych
liczby komentarzy pod treścią nie pokazują; API publicznego nie ma. Licznik
w panelu moderacji (`komentarzy_count` konta) liczy wypowiedzi osoby, nie
rozmowę pod treścią — decyzja go nie dotyczy.

**W kodzie.** Przepis i „Ugotowałem”: `Comment::policzRozmowe()` — te same
granice co `Post::licznikWidocznychKomentarzy()` przy daniu (`widoczneDla()`
na każdej wypowiedzi, odpowiedź tylko pod widocznym korzeniem, ślad
usuniętego korzenia liczony). Pilnuje `LicznikKomentarzyLiczyOdpowiedziTest`
(kontrola ujemna: powrót do `total()` / braku `:ile` oblewa oba nowe testy).

**Wycofanie.** Bez schematu i danych — powrót do liczenia wątków to zmiana
dwóch linijek w kontrolerach i nowa decyzja właściciela.
