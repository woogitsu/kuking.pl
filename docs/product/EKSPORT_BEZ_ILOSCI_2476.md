# Wybór „Bez ilości” w bieżącym przepisie paczki (#2476)

Każdy rekord `przepisy[].skladniki[]` w nowych `dane.json` ma pole
`bez_ilosci` o wartości logicznej `true` lub `false`. To zapis jawnego wyboru
autora z bieżącego składnika. `ile: null` samo nie niesie tej informacji:
może też oznaczać tylko brak rozpoznanej liczby w tekście.

Zmiana dopisuje pole i nie zmienia znaczenia żadnego dotychczasowego pola ani
numeru formatu paczki (`o_tym_pliku.wersja_formatu = 1`). Starsza paczka bez
`bez_ilosci` pozostaje czytelna; odbiorca danych nie powinien zgadywać
`true` na podstawie `ile: null`. Podgląd tekstowego importu nadal bierze
ograniczony zestaw pól i nie przenosi tego wyboru. Nie obiecujemy pełnego
odtworzenia przepisu ani konta z paczki.

Historyczne migawki pozostają niezmienione; dzisiejsza flaga nie jest
przepisywana do dawnego stanu. HTML zachowuje tekst składnika dokładnie tak,
jak autor go wpisał, bez dopisywania „do smaku” (D-232). Test buduje ZIP
szkicu bez migawki z dwoma identycznymi tekstami i ilościami `null`, ale
przeciwnymi wartościami flagi. Cofnięcie zmiany polega na zaprzestaniu
dodawania pola w przyszłych paczkach; nie ma migracji danych.
