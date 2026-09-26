## D-173 · Data wpisu w strumieniu gubi rok — ale tylko wtedy, gdy wolno

**Data:** 12 września 2026 · PR #443 · Status: **obowiązuje**

### Kontekst

Po pomiarze główki karty wpisu (D-172) zostały dwa warianty skrócenia daty. Pomiar
pokazał, że **oba dokładają 0–1 wiersza** ponad to, co dało skrócenie przycisku,
i są między sobą nie do odróżnienia. Właściciel wybrał krótką datę.

### Decyzja

`Czas::dataWpisu()`, używane **wyłącznie na karcie wpisu**. Wpis z bieżącego roku:
„12 września, 10:04". Starszy: „12 września 2025, 10:04".

### Dlaczego nie „3 godziny temu"

Bo to jest **archiwum, do którego ludzie wracają** — „2 lata temu" nie mówi, kiedy.
Zysk wobec krótkiej daty: 0–1 wiersza, czyli żaden.

### Gdzie tego nie używamy

Ekrany moderacji, odwołań, wiadomości i eksportu danych. Tam data jest **dowodem
w sprawie albo terminem, po którym coś się kończy** — pełny rok kosztuje jedno słowo
i zostaje.

### Próg roku liczony w strefie człowieka, nie w UTC

To nie jest drobiazg i ma własny test. 31 grudnia 23:30 UTC to w Polsce już
1 stycznia, 00:30. Wpis sprzed godziny jest wtedy z **poprzedniego** roku człowieka,
a z **bieżącego** roku UTC — próg liczony w UTC gubiłby rok przy wpisach z sylwestra
przez pierwsze dwie godziny polskiej doby (jedną zimą).

📄 `app/Support/Czas.php` · `DataWpisuGubiRokTylkoWTymRokuTest` · D-172 · issue #87
