## D-129 · Menu poza panelem pokazuje JEDNO wejście do moderacji, a liczba z kolejek się sumuje

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

### Zgłoszenie

„po co w menu cały panel moderacji i pod spodem przycisk otwórz panel moderacji?
niech będzie tylko otwórz panel moderacji".

### Co było nie tak

Dziewięć ekranów moderacji stało w **zwykłym** menu, obok „Profil"
i „Powiadomienia", a pod nimi przycisk prowadzący dokładnie tam, gdzie prowadziła
ich pierwsza pozycja. Ta sama rzecz powiedziana dwa razy, kosztem dziewięciu wierszy
menu z pracą, której się w tym miejscu nie wykonuje.

### Decyzja

Poza panelem — jedno wejście. W panelu — pełny spis, bo tam się tę pracę wykonuje.

**Nagłówka grupy poza panelem też nie ma.** Grupa jednego elementu nie jest grupą,
a napis „Panel moderacji" nad odnośnikiem „Otwórz panel moderacji" to jedna nazwa
dwa razy pod rząd — czytnik ekranu przeczytałby obie.

### Liczba nie znika, tylko się sumuje

Plakietka przy pozycji „Bez odpowiedzi" była jedynym sygnałem „jest robota" poza
panelem. Usunięcie listy bez niczego w zamian byłoby cichą stratą poprawnej
informacji. Plakietka przenosi się więc na wejście i pokazuje sumę wszystkich pięciu
kolejek; rozbicie zostaje w panelu, jedno kliknięcie dalej. Puste kolejki nie
pokazują „0" — zero to sam hałas.

Sumowanie wypisuje nazwy kolejek **wprost**, a nie `array_sum()`: nowy klucz
w `KolejkiPanelu`, który nie jest kolejką do przejrzenia, nie doliczy się po cichu.
