# Projekt zmian: polityka prywatności i rejestr — odczyt zdjęcia kartki (OpenAI)

**Status: PROJEKT, NIEWŁĄCZONY.** Przygotowany 26 września 2026 razem z kodem
odczytu (D-296, D-297, D-298). **Nie jest wklejony do
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

---

## 1. `resources/legal/polityka-prywatnosci.md`

### 1.1 Tabela odbiorców (§3, obecny wiersz `:55`) — DRUGI wiersz OpenAI

| Odbiorca | Cel | Kto i gdzie |
|---|---|---|
| OpenAI | **Odczyt przepisu ze zdjęcia kartki lub zeszytu — tylko gdy sam o to poprosisz i po udzieleniu osobnej zgody.** Komputer odczytuje pismo, a tekst trafia do Twojego prywatnego szkicu | OpenAI, L.L.C. (USA) — patrz akapit o przekazywaniu poza EOG |

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

### 1.3 Korekta `:73` — zdanie, które przestaje być prawdą bez zastrzeżenia

Obecnie: „Wysyłamy wyłącznie treść **publiczną**, czyli taką, którą w serwisie
zobaczyłby każdy, także ktoś bez konta.”

Proponowane: „**Przy sprawdzaniu treści** wysyłamy wyłącznie treść
**publiczną**, czyli taką, którą w serwisie zobaczyłby każdy, także ktoś bez
konta. Jedyny wyjątek to zdjęcie kartki, które sam wyślesz do odczytu po
udzieleniu zgody (akapit niżej).”

### 1.4 Korekta `:83` — przekazanie poza EOG

W zdaniu o OpenAI: „Drugi to **OpenAI**: treść wpisu i pomniejszone zdjęcie
**oraz — tylko na Twoje żądanie i za Twoją zgodą — zdjęcie kartki do odczytu
przepisu** idą do OpenAI, L.L.C. w Stanach Zjednoczonych, bez danych
pozwalających Cię wskazać (patrz akapity wyżej).”

### 1.5 Tabela §2 — nowy wiersz

| Odczyt przepisu ze zdjęcia kartki | zdjęcie kartki, odczytany tekst, ślad zlecenia (data, stan), zgoda w dzienniku zgód | Zgoda (odczyt), wykonanie umowy (przechowanie szkicu) | Zdjęcie i szkic: do usunięcia przepisu lub konta; ślad zlecenia: 90 dni; odpowiedź modelu: 30 dni; dziennik zgód: jak przy zgodzie na e-mail |

### 1.6 Nagłówek i wersja

„Ten dokument opisuje stan serwisu na <data scalenia>” + `config/kuking.php`
→ `zgody.wersja_polityki` = ta sama data. **Tylko w PR scalającym polityki.**

---

## 2. `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` — nowa czynność §3.18

### 3.18 Odczyt przepisu ze zdjęcia kartki na żądanie (OpenAI) — przekazanie poza EOG

- **Cel:** przepisanie przepisu z odręcznej albo drukowanej kartki do
  prywatnego szkicu na wyraźne żądanie osoby, która ją sfotografowała.
- **Dane, które faktycznie wychodzą** (kod: `app/Domain/Import/KlientLuna.php`,
  `ObrazDoOdczytu.php`, `OdczytKartki.php`): stała instrukcja, schemat
  odpowiedzi i zdjęcie kartki jako JPEG ≤ 2000 px zmierzone z bajtów, z wariantu
  przekodowanego (bez EXIF/XMP/GPS); `store: false`. Nie wychodzi e-mail,
  nazwa, IP, identyfikatory, pole `user`.
- **Kategorie danych:** treść kartki; **przypadkowo** dane osób trzecich
  (imiona, telefony, adresy) i możliwe dane o zdrowiu (art. 9) — ograniczane
  prośbą o zasłonięcie i instrukcją dla modelu, by takie dopiski pomijał.
  Nie przetwarzamy pisma w celu identyfikacji osoby (to nie są dane biometryczne).
- **Podstawa:** art. 6 ust. 1 lit. a RODO — zgoda `odczyt_ai` w `dziennik_zgod`
  (D-296), sprawdzana przed każdą wysyłką; wycofanie w ustawieniach.
- **Odbiorca:** OpenAI, L.L.C. (USA) — podmiot przetwarzający; **DPA: DO
  PODPISANIA PRZED WŁĄCZENIEM** (`REJESTR_UMOW_POWIERZENIA.md` §2.5).
- **Przekazanie poza EOG:** EU-US Data Privacy Framework + SCC (jak §3.7).
  **DO UZUPEŁNIENIA:** data sprawdzenia DPF, SCC, ewentualne Zero Data Retention.
- **Terminy:** zlecenie 90 dni, odpowiedź modelu 30 dni
  (`kuking:sprzataj-importy`); zdjęcie i szkic jak treść autora; po stronie
  OpenAI — **DO UZUPEŁNIENIA** z warunków API.
- **Środki:** osobny klucz API (osobny projekt OpenAI z limitem wydatków),
  host i ścieżka w kodzie (D-250), budżet dzienny i miesięczny w bazie
  (D-297), limit 5/30 odczytów na osobę, brak auto-publikacji (D-298).

Tabele §4 (odbiorcy) i §5 (przekazania) — dopisać drugi cel OpenAI.

## 3. `docs/legal/REJESTR_UMOW_POWIERZENIA.md` §2.5

Status OpenAI: „DPA — WARUNEK włączenia odczytu kartek (D-296). Bez podpisanej
umowy `OPENAI_IMPORT_KEY` pozostaje pusty.”

## 4. Krótka ocena ryzyk (DPIA-lite) — do spisania przez właściciela

Nowa technologia + dane osób trzecich + transfer do USA: jedna strona z
ryzykami i środkami z projektu `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §5, §10.
