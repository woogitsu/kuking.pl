# Trzy decyzje, które musi podjąć właściciel — R1, R6, umowy powierzenia

Stan: `origin/main` = `b34c2973`, 20 września 2026.
Nic z tego dokumentu **nie zostało wykonane** — to są warianty do wyboru.

**To nie jest lista pytań.** Każda pozycja ma dwa warianty, koszt każdego
i konsekwencje wyboru. Wariant „nie robimy nic" jest wypisany tam, gdzie
realnie istnieje, i tam, gdzie nie istnieje, też jest to powiedziane wprost.

Ocena, czy dany wariant **wystarcza prawnie**, należy do prawnika. Ten
dokument mówi, co każdy wariant znaczy dla kodu, dla pracy i dla człowieka
po drugiej stronie ekranu.

---

## R1 — polityka obiecuje „pełną kopię", paczka świadomie pełna nie jest

### Co dokładnie się rozjeżdża

Polityka (`resources/legal/polityka-prywatnosci.md:88`) mówi, że można
poprosić o **pełną kopię** swoich danych. Paczka niesie dwanaście sekcji
i świadomie pomija osiem kategorii, które polityka gdzie indziej wymienia
jako przechowywane:

| Czego nie ma w paczce | Tabela | Czy polityka mówi, że to trzymamy |
|---|---|---|
| tożsamości zewnętrzne (id Google/Facebooka + data) | `tozsamosci_zewnetrzne` | tak, `polityka:65` |
| wiadomości „Napisz do nas" i nasza odpowiedź | `contact_messages` | tak, `polityka:33` |
| zgłoszenia, decyzje moderatora, odwołania | `reports`, `moderation_actions`, `appeals` | tak, `polityka:28` |
| dziennik zdarzeń bezpieczeństwa (skrót IP) | dziennik audytu | tak, `polityka:29` |
| zdarzenia analityczne | `product_signals` | tak, `polityka:31` |
| wcześniejsze wersje własnych przepisów | `recipe_versions` | tak, `polityka:27` |
| dziennik zgód | `dziennik_zgod` | nie wprost |
| obserwowane tagi | `tag_follows` | nie wprost |

### Wariant A — zawęzić zdanie w polityce

**Co trzeba napisać, żeby było prawdziwe.** Zamiast „pełną kopię":

> możesz poprosić o **kopię swoich treści** — wpisów, przepisów, zdjęć
> i komentarzy, razem z listą tego, czego kopia nie zawiera. Jeżeli
> potrzebujesz czegoś spoza tej paczki (na przykład historii zgłoszeń albo
> korespondencji z nami), napisz na **kontakt@kuking.pl** — przygotujemy to
> ręcznie w terminie z art. 12 ust. 3 RODO.

Dwa warunki, żeby to zdanie nie było kolejną obietnicą bez pokrycia:

1. **Ktoś musi realnie obsłużyć taką prośbę.** Dziś nie ma ani procedury,
   ani licznika terminu (to jest osobny rozjazd R7). Bez tego zdanie
   przenosi problem z paczki do skrzynki.
2. Paczka **już** mówi, czego nie zawiera (`czego_nie_zawiera`), a od
   19 września mówi to także ekran zamawiania (R2). Zdanie w polityce ma
   z nimi współbrzmieć, nie zaprzeczać.

**Koszt:** jedno zdanie w dokumencie + decyzja, kto obsługuje prośby ręczne.
**Ryzyko:** człowiek dostaje mniej, niż się spodziewał po słowie „kopia",
ale wie o tym z góry i ma drogę po resztę.

### Wariant B — poszerzyć paczkę

**Co trzeba dołożyć.** Osiem kategorii z tabeli wyżej. Praca dzieli się na
trzy bardzo różne grupy — i to jest sedno tej decyzji:

| Grupa | Kategorie | Koszt | Uwaga |
|---|---|---|---|
| **tanie, dane wyłącznie tej osoby** | obserwowane tagi, dziennik zgód, wcześniejsze wersje własnych przepisów | mała: trzy zapytania i trzy sekcje w `CollectUserExportData` | nic tu nie dotyczy innych ludzi |
| **średnie, dane tej osoby o technicznym charakterze** | tożsamości zewnętrzne, zdarzenia analityczne, dziennik zdarzeń bezpieczeństwa | średnia: trzeba zdecydować, czy oddajemy skrót IP i identyfikatory techniczne | skrót IP jest daną osobową; oddanie go nikomu nie szkodzi, ale trzeba to opisać |
| **trudne, dane splecione z cudzymi** | zgłoszenia, decyzje moderatora, odwołania, wiadomości „Napisz do nas" | duża | **tu jest prawdziwy problem, patrz niżej** |

**Dlaczego trzecia grupa nie jest kwestią pracy, tylko decyzji.**
Zgłoszenie ma dwie strony. Jeśli oddamy autorowi treści komplet zgłoszeń na
jego temat, oddamy też informację **o zgłaszających** — a serwis obiecuje
zgłaszającym, że ich nie wskazuje (`ZgloszenieNieUjawniaPrywatnejTresciTest`
pilnuje tego z drugiej strony). To samo dotyczy decyzji moderatora, w których
stoi uzasadnienie. Poszerzenie paczki o tę grupę wymaga **wcześniejszej
decyzji, co anonimizujemy**, a nie tylko dopisania sekcji.

### Czy któraś z tych danych nie powinna wyjść z innych powodów

Tak, i to jest argument, którego nie widać od strony RODO:

- **zgłoszenia i decyzje moderacyjne** — oddanie ich w paczce daje osobie
  ukaranej materiał do ustalenia, kto ją zgłosił, nawet jeśli wytniemy imię;
  przy małej społeczności wystarczy data i treść;
- **dziennik zdarzeń bezpieczeństwa** — pokazuje, co i kiedy wykrywamy,
  czyli podpowiada, jak tego uniknąć następnym razem;
- **zdarzenia analityczne** — same w sobie niegroźne, ale ich format zdradza,
  co mierzymy; to argument słaby i nie powinien przeważyć.

### Rekomendacja do rozważenia

**Wariant A teraz, wariant B częściowo później.** Konkretnie: zawęzić zdanie
(A), a niezależnie dołożyć do paczki **grupę tanią** — obserwowane tagi,
dziennik zgód i wcześniejsze wersje własnych przepisów. To są dane wyłącznie
tej osoby, nikogo nie dotykają i po ich dołożeniu zdanie z wariantu A robi się
jeszcze uczciwsze. Grupa trudna zostaje świadomie na później, z zapisanym
powodem.

---

## R6 — polityka opisuje ciasteczka jako „technicznie niezbędne", a dwa są preferencyjne

### Co dokładnie się rozjeżdża

`polityka:95` mówi o „technicznie niezbędnych plikach cookies (np. do
utrzymania sesji logowania)". Poza sesją i `XSRF-TOKEN` serwis stawia jeszcze
dwa, oba na **rok**:

| Ciasteczko | Co trzyma | Kod |
|---|---|---|
| `kuking_text_scale` | wybraną wielkość tekstu | `app/Http/Controllers/Settings/AccessibilitySettingsController.php:46` |
| `motyw` | jasny/ciemny motyw | `app/Http/Controllers/ThemeController.php:69` |

**Obietnica merytoryczna trzyma się w całości:** żadne z nich nie służy
statystyce ani reklamie — sprawdzone po kolei. Niuans jest inny: oba są
ustawiane **na wyraźne życzenie użytkownika**, więc są ciasteczkami
preferencji, a polityka ich nie nazywa.

### Wariant A — dopisać je do polityki jako ciasteczka preferencji

**Co napisać:**

> Używamy plików cookies technicznie niezbędnych (utrzymanie sesji
> logowania, ochrona formularzy) oraz **dwóch zapamiętujących Twoje
> ustawienia wyglądu** — wielkość tekstu i jasny albo ciemny motyw. Te dwa
> powstają dopiero wtedy, gdy sam zmienisz ustawienie, żyją rok i nie służą
> statystyce ani reklamie.

**Koszt:** jeden akapit.
**Konsekwencja:** dokument opisuje stan faktyczny. **Czy ciasteczko
preferencji ustawione na wyraźne życzenie mieści się w zwolnieniu z obowiązku
zgody — to jest pytanie do prawnika**, nie do audytu kodu. Ten wariant
niczego nie przesądza; sprawia, że prawnik ma co oceniać.

### Wariant B — nie używać ciasteczek do preferencji

Trzymać wielkość tekstu i motyw wyłącznie w `localStorage` (bez wysyłania
na serwer) albo w profilu zalogowanej osoby.

**Koszt:** przepisanie obu kontrolerów i obsługi po stronie przeglądarki;
**gość traci ustawienie przy pierwszym renderze** — strona mignie w domyślnym
motywie, zanim skrypt zdąży odczytać `localStorage`. Dla serwisu, którego
grupą docelową są osoby 50+ i który ma jawnie ustawioną skalę tekstu, to jest
realne pogorszenie dostępności.

### Rekomendacja do rozważenia

**Wariant A.** Wariant B kosztuje dostępność, żeby rozwiązać problem opisu,
a nie problem zbierania danych. Opis da się poprawić jednym akapitem.

---

## Umowy powierzenia — kto naprawdę dostaje dane

Lista odbiorców jest **zweryfikowana wobec kodu** i stoi w
`COMPLIANCE.md` §7.2. Powtarzam ją tu bez szczegółów technicznych, bo to jest
lista do rozmowy z prawnikiem:

| Odbiorca | Rola | Co trafia |
|---|---|---|
| Railway | hosting aplikacji i bazy | wszystko |
| Cloudflare | R2 (zdjęcia), Turnstile (7 formularzy), Web Analytics | zdjęcia; adres IP i cechy przeglądarki; adresy stron |
| OpenAI | ocena treści przez model | treść wpisu i pomniejszone zdjęcie, bez danych wskazujących osobę |
| Dostawca poczty (EmailLabs) | listy transakcyjne | adres e-mail i treść listu |
| Google | logowanie kontem Google | potwierdzenie tożsamości, e-mail, imię |
| Meta | logowanie Facebookiem — **osobny administrator**, nie procesor | zakres po stronie Meta |

### Wariant A — podpisać DPA z każdym i wybrać region UE, gdzie się da

**Koszt:** przegląd sześciu umów; przy Railway, Cloudflare i OpenAI to są
standardowe DPA dostępne z panelu, przy EmailLabs — umowa polska.
**Konsekwencja:** lista gotowości przestaje mieć P0 bez pokrycia.

### Wariant B — ograniczyć liczbę odbiorców przed startem

Realnie da się odjąć **dwóch**:

- **OpenAI** — wyłączenie oceny modelem zostawia trzy sygnały automatu
  (wzorzec, odnośnik, powtórzenie). Traci się wykrywanie nienawiści, przemocy
  i treści seksualnych, czyli **tej klasy treści, dla której zero-tolerancja
  z playbooka w ogóle istnieje**. Przy jednym moderatorze to jest realne
  pogorszenie bezpieczeństwa, nie oszczędność.
- **Logowanie Facebookiem** — zostaje Google, hasło i link. Meta jest
  jedynym odbiorcą będącym **osobnym administratorem**, więc odjęcie jej
  najbardziej upraszcza obraz prawny.

**Czego nie da się odjąć:** Railway, Cloudflare i poczty. Bez nich nie ma
serwisu.

### Rekomendacja do rozważenia

**Wariant A dla czterech nieusuwalnych odbiorców**, a przy Meta — decyzja
produktowa, nie prawna: czy logowanie Facebookiem daje tyle, żeby było warte
osobnego administratora w opisie. OpenAI zostawić; jego usunięcie kupuje
prostotę prawną kosztem bezpieczeństwa ludzi.

---

## Czego ten dokument nie rozstrzyga

Nie jest poradą prawną i nie ocenia, czy którykolwiek wariant **wystarcza**
w świetle RODO, DSA ani ePrivacy. Mówi, co każdy wariant znaczy w kodzie,
ile pracy kosztuje i co traci albo zyskuje człowiek po drugiej stronie.
Wybór — i jego skutki prawne — należą do właściciela i jego prawnika.
