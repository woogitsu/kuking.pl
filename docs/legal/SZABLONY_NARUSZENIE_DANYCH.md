# Naruszenie ochrony danych — ścieżka decyzyjna i szablony (art. 33–34 RODO)

Stan: gałąź `robota/bramka-startowa`, od `main` = `534e0a51`, 20 września 2026.

**Po co ten dokument istnieje.** Szablon napisany w trakcie naruszenia jest
napisany źle. Termin z art. 33 RODO wynosi 72 godziny i biegnie od chwili,
w której administrator się **dowiedział**, a nie od chwili, w której skończył
rozumieć, co się stało. Ten plik ma sprawić, że o trzeciej w nocy wypełnia
się luki, zamiast układać zdania.

**Dlatego jest z lukami, a nie z prozą.** Każde `[…]` jest do wypełnienia.
Pole, którego nie da się wypełnić, zostaje z dopiskiem **„ustalamy"** —
art. 33 ust. 4 RODO wprost dopuszcza zgłoszenie etapami i to jest lepsze
niż zgłoszenie po terminie.

**To nie jest porada prawna.** Ocena, czy dane zdarzenie jest naruszeniem
i czy podlega zgłoszeniu, należy do administratora i jego prawnika.

---

## 1. Ścieżka decyzyjna — cztery pytania po kolei

### Krok 0. Kto stwierdza naruszenie

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA: imię, nazwisko i numer telefonu osoby,
która podejmuje tę decyzję, oraz osoby zastępującej ją przy nieobecności.**

Bez tego pola reszta dokumentu nie działa. Przy jednoosobowej obsłudze
serwisu „kto stwierdza" wygląda na pytanie bez treści — do dnia, w którym
ta jedna osoba jest w szpitalu albo bez zasięgu, a licznik 72 godzin
już biegnie. Zastępca nie musi umieć naprawić awarii; musi umieć **wysłać
zgłoszenie**.

### Krok 1. Czy to jest naruszenie ochrony danych osobowych

Naruszenie to **przypadkowe lub niezgodne z prawem zniszczenie, utracenie,
zmodyfikowanie, nieuprawnione ujawnienie lub nieuprawniony dostęp** do danych
osobowych (art. 4 pkt 12 RODO). Trzy rzeczy, które ludzie mylą:

- **Utrata dostępności też jest naruszeniem.** Skasowana baza bez działającej
  kopii to naruszenie, choć nikt niczego nie zobaczył.
- **Wyciek do jednej osoby też jest naruszeniem.** Skala wpływa na ocenę
  ryzyka, nie na kwalifikację.
- **Awaria bez udziału danych osobowych naruszeniem nie jest.** Padnięty
  serwer, który wrócił bez utraty danych, to incydent dostępności usługi.

Przykłady z tego serwisu, które **byłyby** naruszeniem: wyciek zrzutu bazy,
przejęcie konta z uprawnieniami moderatora, pokazanie treści prywatnej
niewłaściwej osobie, ujawnienie tożsamości zgłaszającego osobie zgłoszonej,
skasowanie danych bez odtwarzalnej kopii, wyciek klucza, którym liczony jest
skrót adresu IP w dzienniku audytu (bo wtedy skróty przestają chronić).

### Krok 2. Od kiedy liczą się 72 godziny

**Od chwili, w której administrator powziął wiadomość o naruszeniu** —
czyli miał uzasadnioną pewność, że doszło do zdarzenia dotyczącego danych
osobowych. Nie od chwili wystąpienia i nie od zakończenia analizy.

Krótki okres na **ustalenie, czy w ogóle coś się stało**, jest dopuszczalny —
ale od chwili uzasadnionej pewności licznik biegnie, także w weekend i w nocy.

**Zapisz datę i godzinę powzięcia wiadomości natychmiast, zanim zaczniesz
naprawiać.** To jedna linijka, której potem nie da się odtworzyć, a to od
niej liczy się wszystko inne.

### Krok 3. Czy zgłaszać do UODO

**Zgłaszasz, chyba że** jest mało prawdopodobne, by naruszenie skutkowało
ryzykiem naruszenia praw lub wolności osób fizycznych (art. 33 ust. 1 RODO).
Domyślną odpowiedzią jest **tak**. „Mało prawdopodobne" to wyjątek, który
trzeba uzasadnić i **udokumentować** — bo art. 33 ust. 5 każe prowadzić
wewnętrzną dokumentację **każdego** naruszenia, także niezgłoszonego.

Zgłoszenie po 72 godzinach jest nadal obowiązkowe; dołącza się do niego
wyjaśnienie opóźnienia.

### Krok 4. Czy powiadamiać osoby

**Powiadamiasz, gdy naruszenie może powodować WYSOKIE ryzyko** dla praw
i wolności (art. 34 ust. 1 RODO) — bez zbędnej zwłoki, prostym językiem.

**Kiedy powiadomienia osób NIE trzeba** (art. 34 ust. 3): gdy dane były
zabezpieczone tak, że są nieczytelne dla nieuprawnionego (np. mocne
szyfrowanie, a klucz nie wyciekł); gdy po naruszeniu podjęto środki
eliminujące wysokie ryzyko; gdy wymagałoby to niewspółmiernie dużego
wysiłku — wtedy wystarczy publiczny komunikat. **Każde z tych trzech
odstąpień trzeba uzasadnić na piśmie**, a UODO może i tak nakazać
powiadomienie.

**Uwaga szczególna dla tego serwisu:** hasła są przechowywane jako
nieodwracalne skróty, ale polityka prywatności mówi wprost, że skrótu
krótkiego albo popularnego hasła da się dojść zgadywaniem. Wyciek skrótów
haseł **nie jest** automatycznie przypadkiem z art. 34 ust. 3 lit. a.

### Krok 5. Co zrobić niezależnie od odpowiedzi

1. Zapisz do wewnętrznej dokumentacji naruszeń (art. 33 ust. 5) — także
   jeśli nie zgłaszasz. Wystarczy: co, kiedy się dowiedziałeś, skutki,
   co zrobiłeś, dlaczego nie zgłosiłeś.
2. Przejdź runbook techniczny: `SECURITY_BASELINE.md` §10.
3. Jeśli naruszenie powstało po stronie dostawcy — sprawdź, co umowa
   powierzenia mówi o jego obowiązku zawiadomienia Ciebie i w jakim czasie:
   `REJESTR_UMOW_POWIERZENIA.md`.

---

## 2. Szablon zgłoszenia do UODO (art. 33 RODO)

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** droga złożenia. Zgłoszenie składa się
do Prezesa Urzędu Ochrony Danych Osobowych; UODO udostępnia w tym celu
formularz elektroniczny. **Sprawdź aktualną drogę i wymagane konto ZANIM
będzie potrzebne** — zakładanie konta w systemie urzędu w trakcie biegu
72 godzin jest najgorszym możliwym momentem. Poniższy szablon jest treścią
do wklejenia w pola formularza, nie zamiennikiem formularza.

```
ZGŁOSZENIE NARUSZENIA OCHRONY DANYCH OSOBOWYCH
Art. 33 rozporządzenia (UE) 2016/679 (RODO)

A. ADMINISTRATOR
Nazwa:            SAMSUFI Spółka z ograniczoną odpowiedzialnością
Adres:            Jagiellońska 4A, 19-120 Knyszyn, Polska
KRS / NIP / REGON: 0000901262 / 5423435334 / 388971059
Serwis:           Kuking.pl
Osoba do kontaktu w sprawie zgłoszenia: [imię i nazwisko]
Telefon / e-mail: [___] / [___]
Inspektor ochrony danych: [dane IOD albo „nie wyznaczono"]

B. CHARAKTER ZGŁOSZENIA
[ ] zgłoszenie pełne
[ ] zgłoszenie etapowe — informacje uzupełnimy sukcesywnie (art. 33 ust. 4)
[ ] zgłoszenie po upływie 72 godzin — przyczyna opóźnienia: [___]

C. KIEDY
Data i godzina wystąpienia naruszenia:        [___] (albo „ustalamy")
Data i godzina powzięcia wiadomości:          [___]   ← OD TEGO LICZĄ SIĘ 72 h
Czy naruszenie nadal trwa:                    [tak / nie / ustalamy]
Data i godzina niniejszego zgłoszenia:        [___]

D. CHARAKTER NARUSZENIA
Rodzaj (można zaznaczyć więcej niż jeden):
[ ] naruszenie poufności (nieuprawniony dostęp lub ujawnienie)
[ ] naruszenie integralności (nieuprawniona zmiana danych)
[ ] naruszenie dostępności (utrata lub zniszczenie danych)

Opis, co się stało — chronologicznie, bez ocen:
[___]

Przyczyna, jeśli znana:
[___]   (np. błąd w kodzie, przejęte poświadczenie, błąd ludzki,
         naruszenie po stronie podmiotu przetwarzającego — wskazać który)

E. KATEGORIE I PRZYBLIŻONA LICZBA OSÓB
Kategorie osób (zaznaczyć):
[ ] zarejestrowani użytkownicy serwisu
[ ] osoby niezalogowane korzystające z formularzy publicznych
[ ] osoby trzecie widoczne w treściach publikowanych przez użytkowników
[ ] osoby zgłaszające treści i osoby zgłaszane
Przybliżona liczba osób:               [___] (albo „ustalamy")
Podstawa oszacowania:                  [___]

F. KATEGORIE I PRZYBLIŻONA LICZBA WPISÓW DANYCH
Zaznaczyć, czego naruszenie dotyczy:
[ ] adresy e-mail
[ ] skróty haseł
[ ] dane profilu (nazwa, opis, zdjęcie profilowe)
[ ] treści użytkowników (wpisy, przepisy, komentarze)
[ ] zdjęcia (w oryginale mogą zawierać EXIF i współrzędne GPS)
[ ] treści oznaczone jako prywatne
[ ] zgłoszenia, decyzje moderacyjne, odwołania — w tym tożsamość zgłaszających
[ ] dziennik zdarzeń bezpieczeństwa (skróty adresów IP)
[ ] wiadomości z formularza „Napisz do nas"
[ ] identyfikatory kont zewnętrznych (Google, Facebook)
[ ] inne: [___]
Przybliżona liczba wpisów:             [___] (albo „ustalamy")

G. MOŻLIWE KONSEKWENCJE DLA OSÓB
[___]   (np. przejęcie konta, ujawnienie tożsamości zgłaszającego wobec
         osoby zgłoszonej, ujawnienie miejsca zamieszkania z EXIF-u,
         ujawnienie danych o zdrowiu zawartych w treści napisanej
         dobrowolnie, utrata treści bez kopii)

H. ŚRODKI ZASTOSOWANE I PROPONOWANE
Zastosowane natychmiast:
[___]   (np. unieważnienie sesji, rotacja kluczy, odcięcie punktu wejścia,
         zdjęcie treści z widoku publicznego, odtworzenie z kopii)
Planowane, z terminem:
[___]
Środki minimalizujące skutki dla osób:
[___]

I. POWIADOMIENIE OSÓB (art. 34)
[ ] powiadomiliśmy — data i sposób: [___]
[ ] powiadomimy — planowana data: [___]
[ ] nie powiadamiamy, bo: [ ] dane nieczytelne dla nieuprawnionego
                          [ ] podjęto środki eliminujące wysokie ryzyko
                          [ ] niewspółmiernie duży wysiłek → komunikat publiczny
    Uzasadnienie odstąpienia: [___]

J. PODMIOTY PRZETWARZAJĄCE, KTÓRYCH NARUSZENIE DOTYCZY
[___]   (wykaz i role: REJESTR_CZYNNOSCI_PRZETWARZANIA.md §4)

K. PRZEKAZANIA POZA EOG, KTÓRYCH NARUSZENIE DOTYCZY
[___]   (OpenAI, Cloudflare, Google — REJESTR_CZYNNOSCI_PRZETWARZANIA.md §5)

L. ZAŁĄCZNIKI
[___]   (fragmenty dzienników, oś czasu, korespondencja z dostawcą)

Podpis / osoba składająca: [___]
```

---

## 3. Szablon powiadomienia osób (art. 34 RODO)

Wymagania art. 34 ust. 2: **prosty język**, opis charakteru naruszenia,
dane kontaktowe, możliwe konsekwencje, zastosowane i proponowane środki.

Dwie rzeczy specyficzne dla tego serwisu: piszemy do ludzi po 50. roku
życia, więc **żadnego żargonu i żadnej ogólnikowej pociechy**; i piszemy
z serwisu, który ma jeden adres kontaktowy — **kontakt@kuking.pl** — więc
nie obiecujemy infolinii, której nie ma.

**Czego w tym liście nie wolno napisać:** „bezpieczeństwo naszych
użytkowników jest dla nas priorytetem". To zdanie nie niesie żadnej
informacji i w dniu naruszenia czyta się jak kpina.

```
Temat: Ważna informacja o bezpieczeństwie Twoich danych w Kuking

Dzień dobry,

piszemy, bo doszło do zdarzenia, które dotknęło Twoje dane w Kuking.
Chcemy, żebyś dowiedział(a) się tego od nas i wiedział(a), co robić.

CO SIĘ STAŁO
[___]   Jedno–trzy zdania, konkretnie. Co, kiedy, jak długo trwało.
        Bez słów, których nie zna ktoś spoza informatyki.

JAKIE TWOJE DANE MOGŁY BYĆ TYM OBJĘTE
[___]   Wymień po ludzku: „Twój adres e-mail", „zdjęcia, które
        opublikowałeś(-aś)", „treść Twoich wiadomości do nas".
        Napisz też, czego to NIE dotyczyło, jeśli wiesz na pewno —
        to jest dla czytelnika równie ważna informacja.

CO TO MOŻE DLA CIEBIE ZNACZYĆ
[___]   Konkretne ryzyko, nie ogólnik. Na przykład: „ktoś może próbować
        wejść na Twoje konto, jeśli używasz tego samego hasła w innym
        serwisie".

CO JUŻ ZROBILIŚMY
[___]   Lista skończonych czynności, z datami. Nie plany.

CO RADZIMY ZROBIĆ TOBIE
[___]   Najwyżej trzy punkty, każdy zaczynający się od czasownika.
        Na przykład: „Zmień hasło w Kuking." / „Jeśli tego samego hasła
        używasz gdzie indziej — zmień je także tam."
        Jeśli nic nie musisz robić, napisz to wprost: to najważniejsze
        zdanie takiego listu.

GDZIE PYTAĆ
Napisz na kontakt@kuking.pl — odpowiadamy na każdą wiadomość.
Masz też prawo złożyć skargę do Prezesa Urzędu Ochrony Danych Osobowych.

Przepraszamy.
[imię osoby prowadzącej serwis]
SAMSUFI sp. z o.o., Jagiellońska 4A, 19-120 Knyszyn
```

**Jak to wysłać.** Listy wychodzą przez EmailLabs, a wysyłka ma dobowy
budżet (`app/Domain/Security/DziennyBudzetListow.php`). Przy powiadomieniu
wszystkich użytkowników **budżet może się skończyć w połowie** — sprawdź
limit i ustal kolejność wysyłki, zanim naciśniesz. Komunikat na stronie
serwisu nie zastępuje powiadomienia indywidualnego, ale jest sensownym
uzupełnieniem, gdy wysyłka trwa dłużej niż dobę.

---

## 4. Wewnętrzna dokumentacja naruszeń (art. 33 ust. 5)

Obowiązek dotyczy **każdego** naruszenia, także takiego, którego nie
zgłoszono. **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** gdzie ten rejestr
fizycznie leży (repozytorium nie jest dobrym miejscem — wpis może zawierać
dane osobowe). Minimalny wiersz:

| Pole | Treść |
|---|---|
| Numer i data wpisu | |
| Data i godzina powzięcia wiadomości | |
| Opis zdarzenia | |
| Kategorie i przybliżona liczba osób oraz wpisów danych | |
| Skutki | |
| Zastosowane środki | |
| Czy zgłoszono do UODO — jeśli nie, dlaczego | |
| Czy powiadomiono osoby — jeśli nie, na której podstawie z art. 34 ust. 3 | |
| Kto podjął decyzję | |
