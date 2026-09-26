## D-281 — „Komentarze (N)” pod zwykłym wpisem liczy odpowiedzi; pytanie nie (#1801, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela ·
Dotyczy **#1801**, **#372**, **#1396**

**Problem.** Licznik na karcie wpisu szedł po `Post::comments()`, czyli po
samych korzeniach wątków. Wpis z jednym komentarzem i trzema odpowiedziami
pokazywał „Komentarze (1)”, a strona — cztery wypowiedzi. Żywa rozmowa
wyglądała na kartach jak pojedynczy komentarz.

**Decyzja.** Pod **zwykłym wpisem** licznik liczy WSZYSTKIE komentarze
razem z odpowiedziami — to, co widz przeczyta po rozwinięciu. Pod
**pytaniem** bez zmian (#372): liczą się tylko odpowiedzi najwyższego
poziomu, a rozmowa pod odpowiedzią nie podbija ani etykiety „Odpowiedzi”,
ani `QAPage.answerCount`.

Granice widoczności są te same co w widoku: blokada w obie strony, konto
autora, status i miękkie usunięcie; odpowiedź pod korzeniem, którego widz nie
widzi, nie liczy się (#1396); ślad usuniętego korzenia liczy się przy daniu,
przy pytaniu nie. Nagłówek „Komentarze (N)” na stronie zwykłego wpisu mówi tę
samą liczbę co karta.

**W kodzie.** Jedna definicja: `Post::licznikWidocznychKomentarzy()`, używana
przez `withVisibleCommentCount()` (wszystkie strumienie) i nagłówek w
`PostController::show`. Wynik dalej w `comments_count`. Pilnuje
`LicznikKomentarzyLiczyOdpowiedziTest` (kontrole ujemne: powrót do samych
korzeni i pominięcie warunku widocznego korzenia — oba oblewają).
Komentarze pod przepisem i pod „Ugotowałem” — rozszerzenie w **D-309**.
