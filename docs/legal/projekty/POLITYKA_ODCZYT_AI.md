# Projekt zmian: polityka prywatności i rejestr — odczyt przez model (OpenAI): zdjęcie kartki, tekst strony, skan PDF

**Status: PROJEKT, NIEWŁĄCZONY.** Przygotowany 26 września 2026 razem z kodem
odczytu zdjęcia kartki (D-296, D-297, D-298); **rozszerzony 29 września 2026
(issue #2031) o tekst strony z adresu i skan PDF (D-300 pkt 9).** **Nie jest wklejony do
`resources/legal/polityka-prywatnosci.md`** i **nie podbija
`kuking.zgody.wersja_polityki`**, bo wersję polityki podbijają równolegle inne
zmiany (#1816, #1617) — dwie niezależne podwyżki tej samej wersji zderzyłyby
się, a dziennik zgód wiąże wiersz z datą dokumentu. Wkleja i podbija wersję
ten, kto scala zmiany polityki, JEDNYM PR-em.

**Warunek, bez którego tego nie wklejamy i nie włączamy funkcji:** umowa
powierzenia (DPA) z OpenAI wpisana do `docs/legal/REJESTR_UMOW_POWIERZENIA.md`
§2.5 (dziś brak) i odczytany z warunków OpenAI okres przechowywania danych
wysłanych do API. Polityka (`:83`) obiecuje dopisanie nowego celu, „zanim
trafi tam pierwszy rekord” — więc `OPENAI_IMPORT_KEY` zostaje pusty do chwili
scalenia tych zmian.

**Trzy źródła, trzy osobne zgody (issue #2031).** Model może dostać: (1) zdjęcie
kartki — trwała zgoda `odczyt_ai` (D-296, `InformacjaOdczytuAi`); (2) tekst
strony z adresu, gdy strona nie ma danych przepisu, i (3) obrazy stron skanu
PDF bez warstwy tekstu — dla obu jednorazowa zgoda zaznaczana w formularzu
danego importu (D-300 pkt 9, `InformacjaTekstuZrodlaAi`). **Zgoda na jedno
źródło niczego nie odblokowuje w pozostałych.** Polityka i rejestr muszą
opisywać każde z nich osobno; opisanie samego zdjęcia kartki (poprzednia
wersja tego projektu) nie wystarcza.

**Gdzie to już jest w repozytorium (bez podbijania polityki):** rejestr
czynności — `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` **§3.23** (numer
§3.18 z pierwotnego projektu jest zajęty przez „Urodziny”; §3.23 obejmuje
wszystkie trzy źródła, więc dawny punkt 2 poniżej jest wykonany); wersjonowany
ekran zgody dla tekstu strony i PDF — komponent `x-zgoda-zrodlo-ai`
(`resources/views/components/zgoda-zrodlo-ai.blade.php`) z wersją
`InformacjaTekstuZrodlaAi::WERSJA`. **Do wklejenia przy scalaniu polityk
(#1816) zostają punkty 1.1–1.6 poniżej.**

---

## 1. `resources/legal/polityka-prywatnosci.md`

### 1.1 Tabela odbiorców (§3, obecny wiersz `:55`) — DRUGI wiersz OpenAI

| Odbiorca | Cel | Kto i gdzie |
|---|---|---|
| OpenAI | **Odczyt przepisu ze zdjęcia kartki lub zeszytu, z tekstu strony internetowej albo ze skanu PDF — tylko gdy sam o to poprosisz i po udzieleniu osobnej zgody na dane źródło.** Komputer odczytuje pismo albo wskazuje, które wiersze tekstu są przepisem, a wynik trafia do Twojego prywatnego szkicu | OpenAI, L.L.C. (USA) — patrz akapit o przekazywaniu poza EOG |

### 1.2 Nowy akapit po `:73` — „Co wysyłamy przy odczycie zdjęcia kartki”

> **Odczyt przepisu ze zdjęcia kartki.** Gdy wybierzesz „Przepisz z kartki
> lub zeszytu” i zgodzisz się na odczyt, wysyłamy do OpenAI **samo zdjęcie
> tej kartki** — przekodowane u nas do formatu JPEG, o dłuższym boku najwyżej
> 2000 pikseli, **bez danych z aparatu** (bez daty i bez współrzędnych
> miejsca). **Nie wysyłamy Twojego adresu e-mail, nazwy konta, adresu IP ani
> żadnego identyfikatora.** OpenAI odczytuje pismo, a my wpisujemy tekst do
> Twojego **prywatnego szkicu** — nic nie jest publikowane bez Twojego
> kliknięcia. Na kartce bywają dane innych osób (nazwisko, telefon,
> informacja o zdrowiu) — prosimy, zasłoń je przed zrobieniem zdjęcia.
> **Podstawą jest Twoja zgoda** (art. 6 ust. 1 lit. a RODO), zapisana w
> dzienniku zgód z datą i wersją tej polityki. Możesz ją wycofać w każdej
> chwili w ustawieniach prywatności — zdjęcia kartek dalej dodasz do
> przepisu, tylko tekst wpiszesz ręcznie. Wycofanie nie zmienia tego, co
> odczytano wcześniej. Po naszej stronie zdjęcie kartki zostaje przy
> przepisie do jego usunięcia albo usunięcia konta, ślad zlecenia odczytu —
> 90 dni, a techniczna odpowiedź modelu (do wyjaśniania błędów odczytu) —
> 30 dni. Po stronie OpenAI: [DO UZUPEŁNIENIA po odczytaniu warunków API
> i DPA — okres przechowywania na potrzeby wykrywania nadużyć; ustawienie
> `store: false` znaczy, że odpowiedź nie jest zapisywana do późniejszego
> pobrania].

### 1.2a Nowy akapit obok poprzedniego — „Co wysyłamy przy imporcie ze strony internetowej i z PDF”

> **Import przepisu ze strony internetowej i z pliku PDF.** Gdy dodajesz
> przepis z adresu strony, najpierw próbujemy odczytać go **u siebie** — jeśli
> strona podaje dane przepisu w sposób, który rozumiemy, albo plik PDF ma
> warstwę tekstową, **nic nie wysyłamy nikomu**. Tylko gdy strona nie ma takich
> danych, a Ty zaznaczysz pole zgody pod formularzem, wysyłamy do OpenAI
> **sam tekst tej strony** (najwyżej 12 000 znaków; bez adresu strony, bez
> zdjęć, bez menu i komentarzy czytelników) — komputer wskazuje wtedy tylko,
> które wiersze są tytułem, składnikami i krokami, a tekst szkicu składamy u
> siebie z oryginału. Przy pliku PDF będącym skanem, bez tekstu, po zaznaczeniu
> zgody wysyłamy **obrazy stron pliku** (bez danych zapisanych w pliku) —
> wszystko, co na nich widać, więc usuń z pliku strony z cudzymi danymi.
> **Nie wysyłamy Twojego adresu e-mail, nazwy konta ani adresu IP.**
> **Ta zgoda dotyczy tylko jednego wysłania**: nie zapisujemy jej na później
> i nie zastępuje zgody na odczyt zdjęć kartek (ani odwrotnie). Podstawą
> jest Twoja zgoda (art. 6 ust. 1 lit. a RODO); zapisujemy datę zgody przy
> próbie importu, a wersję informacji, którą widziałeś, niesie formularz — z
> nieaktualną informacją nic nie wysyłamy. Wycofanie zgody nie jest potrzebne:
> bez zaznaczenia pola nic nie wychodzi. Po naszej stronie plik PDF i obrazy
> jego stron nie są zapisywane, odpowiedź modelu nie jest zapisywana, a ślad
> próby importu z datą zgody trzymamy 90 dni. Po stronie OpenAI: [DO
> UZUPEŁNIENIA po odczytaniu warunków API i DPA — okres przechowywania na
> potrzeby wykrywania nadużyć; `store: false` znaczy, że odpowiedź nie jest
> zapisywana do późniejszego pobrania].

**Sprawdzić przed wklejeniem:** wszystkie liczby (12 000 znaków, 90 dni)
i „nie zapisujemy” pochodzą z kodu na dzień 29.09.2026 (`TekstStrony`,
`OdczytajSkanPdf`, `PlatnyOdczytImportu`, `PrzedawnioneImporty`) — ponownie
zweryfikować w dniu scalenia.

### 1.3 Korekta `:73` — zdanie, które przestaje być prawdą bez zastrzeżenia

Obecnie: „Wysyłamy wyłącznie treść **publiczną**, czyli taką, którą w serwisie
zobaczyłby każdy, także ktoś bez konta.”

Proponowane: „**Przy sprawdzaniu treści** wysyłamy wyłącznie treść
**publiczną**, czyli taką, którą w serwisie zobaczyłby każdy, także ktoś bez
konta. Jedyne wyjątki to zdjęcie kartki, tekst strony internetowej i strony skanu
PDF, które sam wyślesz do odczytu po udzieleniu zgody na dane źródło (akapity
niżej).”

### 1.4 Korekta `:83` — przekazanie poza EOG

W zdaniu o OpenAI: „Drugi to **OpenAI**: treść wpisu i pomniejszone zdjęcie
**oraz — tylko na Twoje żądanie i za Twoją zgodą na dane źródło — zdjęcie
kartki, tekst strony internetowej albo obrazy stron skanu PDF do odczytu
przepisu** idą do OpenAI, L.L.C. w Stanach Zjednoczonych, bez danych
pozwalających Cię wskazać (patrz akapity wyżej).”

### 1.5 Tabela §2 — nowe wiersze

| Odczyt przepisu ze zdjęcia kartki | zdjęcie kartki, odczytany tekst, ślad zlecenia (data, stan), zgoda w dzienniku zgód | Zgoda (odczyt), wykonanie umowy (przechowanie szkicu) | Zdjęcie i szkic: do usunięcia przepisu lub konta; ślad zlecenia: 90 dni; odpowiedź modelu: 30 dni; dziennik zgód: jak przy zgodzie na e-mail |
| Import przepisu ze strony internetowej lub z PDF (odczyt przez model tylko przy stronie bez danych przepisu i skanie PDF) | wysłany tekst strony albo obrazy stron PDF (nie są u nas zapisywane), data zgody i stan próby importu, szkic | Zgoda jednorazowa (odczyt przez model), wykonanie umowy (szkic) | Ślad próby importu z datą zgody: 90 dni; szkic: do usunięcia przepisu lub konta; plik PDF, obrazy stron i odpowiedź modelu: nie zapisujemy |

### 1.6 Nagłówek i wersja

„Ten dokument opisuje stan serwisu na <data scalenia>” + `config/kuking.php`
→ `zgody.wersja_polityki` = ta sama data. **Tylko w PR scalającym polityki.**

---

## 2. `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` — WYKONANE (issue #2031)

Czynność wpisana jako **§3.23** (nie §3.18 — ten numer zajmują „Urodziny”)
razem z wierszami w tabelach §4 (odbiorcy) i §5 (przekazania). Obejmuje trzy
źródła (zdjęcie kartki, tekst strony, skan PDF) z osobnym opisem tego, co
wychodzi, jakiej zgody wymaga i jak długo jest przechowywane. Rejestr nie jest
dokumentem publicznym, więc jego uzupełnienie nie wymaga podbijania wersji
polityki. Pola „DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA” (DPA, DPF, SCC, retencja
u OpenAI) zostają puste do decyzji właściciela.

## 3. `docs/legal/REJESTR_UMOW_POWIERZENIA.md` §2.5

Status OpenAI: „DPA — WARUNEK włączenia odczytu kartek, tekstu stron i skanów
PDF (D-296, D-300 pkt 9). Bez podpisanej umowy `OPENAI_IMPORT_KEY` pozostaje
pusty.”

## 4. Krótka ocena ryzyk (DPIA-lite) — do spisania przez właściciela

Nowa technologia + dane osób trzecich + transfer do USA: jedna strona z
ryzykami i środkami z projektu `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §5, §10.
