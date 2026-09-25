## D-130 · Długi wpis na karcie skraca się do „Czytaj dalej"; próg patrzy na WIERSZE i na znaki

**Data:** 11 września 2026 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„Na głównej długie wpisy można skrócić dać czytaj dalej" — jeden przepis ze
składnikami wypełniał na telefonie cały ekran i wypychał wszystko poniżej.

### Dlaczego próg w samych znakach by nie działał

`.post-card-body` ma `white-space: pre-line`, więc **każde przełamanie autora
zostaje osobnym wierszem**. „500 g mąki / 350 ml wody / 7 g drożdży" ma mało znaków
i dużo wierszy — a ekran zjadają wiersze. Skracamy, gdy przekroczony JEDEN z progów:
osiem wierszy albo czterysta znaków.

Cięcie po pełnych wierszach, potem po całych wyrazach (`Str::words()`, nie
`Str::limit()`).

### „Czytaj dalej" prowadzi na stronę wpisu, nie rozwija w miejscu

Nic nie skacze pod palcem, działa bez JavaScriptu, a czytelnik ląduje tam, gdzie
i tak są komentarze.

### Dlaczego odpada `-webkit-line-clamp`

Klamra nie potrafi powiedzieć szablonowi, **czy** przyciąć — odnośnik pokazałby się
także pod wpisem dwuzdaniowym, czyli byłby martwym przyciskiem (D-053). Do tego
wysokość klamry trzeba by podać w `rem`, a wtedy próg mierzyłby co innego u każdego
czytelnika (D-082, D-107).

### Wyjątek, bez którego byłby martwy przycisk

Ta sama karta stoi też na stronie pojedynczego wpisu. Bez warunku „czy jestem na
stronie TEGO wpisu" całej treści nie dałoby się przeczytać nigdzie, a „Czytaj dalej"
prowadziłoby samo do siebie.

### Progi są hipotezą, nie pomiarem

400 znaków i 8 wierszy to liczby z rozumowania, nie ze zmierzenia na telefonie. Są
publicznymi stałymi, żeby zmiana była jedną cyfrą.
