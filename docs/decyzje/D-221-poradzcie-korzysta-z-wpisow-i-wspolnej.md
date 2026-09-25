## D-221 — Poradźcie korzysta z wpisów i wspólnej rozmowy (#372, 18 września 2026)

Pytanie jest wpisem kind=question z tytułem 10–180 znaków, opcjonalnym opisem,
jednym zdjęciem i najwyżej trzema tagami. Nazwa Poradźcie i adresy /pytania
realizują D-147/D-163. Formularz działa zwykłym POST; podpowiedzi hashtagów
są ulepszeniem. Nie powstaje drugi system komentarzy ani powiadomień.

Główne komentarze są odpowiedziami, zagnieżdżone zachowują rozmowę. Widoczność
odpowiedzi respektuje dotychczasowe blokady i statusy. Kolejka gospodarza
czeka na główną odpowiedź innej osoby widoczną dla pytającego. Publiczny
szczegół emituje QAPage z widocznymi odpowiedziami, bez udawania odpowiedzi
zaakceptowanej. Lista jest chronologiczna, z filtrem bez odpowiedzi i tagiem.

Usunięcie treści odpowiedzi z dziećmi zapisuje body_removed_at, zachowując
wątek. Ślad nie jest odpowiedzią w licznikach ani QAPage. Nie odgadujemy
historycznych usunięć z samego zdania „Komentarz usunięty.”. Down migracji
odmawia utraty istniejących znaczników, także w miękko usuniętych wierszach.

Flaga kuking.questions.enabled pozostaje domyślnie false i obejmuje także
profile, feedy oraz powiadomienia. Surowe operacje utrzymaniowe zachowują dane.
Uruchomienie publiczne wymaga odbioru i etapów z #372; samo scalenie kodu
nie jest dowodem uruchomienia funkcji. Stan kontroli i ograniczenia są w
docs/product/ODBIOR_PORADZCIE_372.md.
