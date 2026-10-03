# Wybór przy wczytywaniu paczki partiami (#2843)

Podgląd nowej paczki wstępnie zaznacza najwyżej tyle nowych pozycji, ile mieści
jedna część. Gdy osoba zaznaczy więcej i zapisze pierwszą część, jej wybór
pozostaje w sesji pod kluczem zależnym od konta i tokenu tej konkretnej paczki.
Kolejny podgląd na nowo sprawdza uprawnienia i stan każdej pozycji. Zapisany
wybór przecina z listą pozycji wciąż kwalifikujących się do wczytania; pozycji
odznaczonych nie dodaje ponownie. Po błędzie walidacji wraca rzeczywisty wybór
z formularza, również gdy był pusty. Błąd innej paczki nie zmienia zaznaczeń.

Sama sesja nie uprawnia do utworzenia danych. Akcja zapisu nadal czyta prywatną
paczkę, sprawdza aktualny podgląd, Policy i limit każdej części. Po zakończeniu
lub próbie otwarcia wygasłej paczki zapamiętany wybór jest usuwany; osobne
konto i osobna paczka mają odrębne klucze.

Rollback kodu nie wymaga migracji. Cofnięcie przywraca wadę ponownego
zaznaczania odznaczonych pozycji, dlatego po cofnięciu należy wstrzymać
wczytywanie wieloczęściowych paczek do ponownego wdrożenia poprawki.
