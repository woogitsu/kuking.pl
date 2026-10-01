## D-017 · Przepis zostaje wolnym tekstem; to kit dopasowuje się do danych

**Data:** 6 września 2026 · Status: **częściowo nieaktualna — patrz D-033**
· issues #44, #92, UI kit v2 etap C

> **Poprawka z 8 września.** Zdanie „składniki z kolumną ilości: nie i nie
> będzie" **przestało być prawdziwe dzień po napisaniu**:
> `recipe_ingredients` ma `quantity`, `unit_id` i `no_amount` od 5 września.
> Aktualny stan: ilość JEST, skalowania porcji nie ma, grup składników nie ma
> — a właściciel przyjął oba do zbudowania (D-033). Reszta tego wpisu, czyli
> zasada „kit dopasowuje się do danych, nie odwrotnie", obowiązuje dalej.

Ekran przepisu w UI kicie v2 rysuje składniki jako wiersze **nazwa + ilość**
w dwóch kolumnach, a kroki z **pogrubionymi tytułami**. Produkt tych danych
świadomie NIE zbiera: formularz przepisu mówi „Pisz tak, jak mówisz:
«szklanka mąki»", a kroki są zwykłym tekstem bez nagłówka.

Zbudowanie układu z kitu oznaczało więc jedno z dwojga: albo udawać strukturę,
której nie ma, albo zmienić to, o co pytamy człowieka. Właściciel rozstrzygnął.

### Co odrzucono

**Rozbicie składnika na ilość i nazwę w formularzu.** Ma realną zaletę:
odblokowuje skalowanie porcji i zamienniki (AGENTS.md §9) oraz zamyka #44
(„sól do smaku nie skaluje się razy trzy"). Cena jest jednak dokładnie tam,
gdzie produkt najmniej może sobie na nią pozwolić — w pierwszym formularzu,
który wypełnia osoba przepisująca zeszyt babci. „Szczypta soli", „tyle, żeby
ciasto było miękkie" i „pół szklanki, ale mama dawała więcej" nie mają pola
na ilość. Formularz, który każe je rozbić, każe też zdecydować, czego nie
zapisać — a to jest odwrotność obietnicy „Twoje przepisy nie zginą".

**Wpisanie struktury na siłę do widoku.** Rysowanie kolumny „ilość" wypełnianej
zgadywanką z tekstu daje ekran, który wygląda jak kit i kłamie w połowie
wierszy. Gorzej: kłamie akurat tam, gdzie autor był najbardziej precyzyjny.

### Co wybrano

**Model wpisywania zostaje bez zmian.** Układ ekranu przepisu budujemy według
kitu — panel boczny ze zdjęciem, kafle liczb, akcje, „Skąd ten przepis?" —
ale **składniki idą jako czytelna lista bez kolumny ilości**, a kroki jako
numerowane akapity bez wymyślonych tytułów.

**Cena, wprost:** ekran przepisu nie będzie wyglądał jeden do jednego jak
plansza z kitu. To jest świadome: kit rysował dane, których ten produkt nie ma
i mieć nie chce. Zamknięte zostaje też, na teraz, skalowanie porcji po stronie
danych — #44 zostaje otwarte i czeka.

**Zmiana wymaga:** danych z realnego użycia, że ludzie sami wpisują ilości
w przewidywalnym kształcie, albo gotowego parsera/AI, który proponuje rozbicie
JAKO PODPOWIEDŹ DO POTWIERDZENIA, nigdy jako wymagane pole (AGENTS.md §9,
ROADMAP → V2). Źródłem prawdy zostaje wtedy nadal to, co człowiek napisał.

📄 `docs/design/kit-v2/IMPLEMENTATION_GUIDE.md` etap C ·
`docs/brand/COPY_STYLE.md` · `docs/ROADMAP.md` → V2 · issue #44
