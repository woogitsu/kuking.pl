## D-188 · Linia 📄 ma strażnika, a martwy odnośnik znika, zamiast zgadywać cel

**Data:** 12 września 2026 · PR #459 · Status: **obowiązuje**

### Kontekst

Linia `📄` na końcu każdego wpisu jest **jedyną** drogą od decyzji do kodu, który ją
realizuje. Pliki się przenoszą, klasy testowe zmieniają nazwy — a dziennik nie miał
żadnego automatu, który by to zauważył. Przy 181 wpisach nikt nigdy nie przeszedł tych
referencji ręcznie.

Zmierzone: **112** bloków referencji, ok. **780** pojedynczych referencji, martwe trzy.

### Decyzja

`OdnosnikiDziennikaDecyzjiIstniejaTest` chodzi po tych liniach przy każdym przebiegu.
Lista znanych wyjątków jest **pusta i ma taka zostać** — pierwszy dopisany wyjątek
zamienia strażnika w formalność, bo następny martwy odnośnik trafi tam odruchowo.

Martwy odnośnik, którego celu nie da się ustalić, **usuwamy**. D-149 wskazywał plik
`…/WZORCE_SAMOUZASADNIANIA`, którego nigdy nie było pod żadną ścieżką. Kusiło, żeby
wskazać sąsiedni dokument z tego samego katalogu — ale w żadnym pliku tamtego katalogu
nie ma słowa „samouzasadnianie", więc byłoby to zgadnięcie podane jako referencja.
Zgadnięty odnośnik jest dokładnie tym samym błędem co zły numer decyzji (D-187), tylko
o jedną warstwę niżej.

### Próg minimalnej liczby sprawdzonych pozycji

Skan, który nic nie znalazł, wygląda identycznie jak skan, który znalazł wszystko
i wszystko było w porządku (pułapka 2). Dlatego test oblewa także wtedy, gdy bloków
albo sprawdzonych celów jest mniej, niż być powinno.

📄 `docs/DECISIONS.md` · `OdnosnikiDziennikaDecyzjiIstniejaTest` ·
`docs/PULAPKI_TESTOW.md` · D-187
