# Kuking — COPY_STYLE v2: proponowane rozszerzenie

Ten dokument nie zastępuje obecnego `docs/brand/COPY_STYLE.md`.
Jest zestawem reguł, które proponuję dopisać po audycie.

## 1. Główna zasada

**Kuking ma być ciepły, ale interfejs ma być przezroczysty.**

Osobowość marki nie może konkurować z zadaniem użytkownika.

## 2. Hierarchia tonu

| Typ ekranu | Charakter marki | Priorytet |
|---|---:|---|
| Landing / kampania | wysoki | idea produktu |
| About | średni | historia i wartości |
| Feed / profil | niski | treść użytkownika |
| Onboarding | niski | decyzja i postęp |
| Formularz | bardzo niski | wykonanie zadania |
| Ustawienia | minimalny | skutek działania |
| Błąd | minimalny | stan + naprawa |
| Bezpieczeństwo | brak żartu | precyzja |
| Moderacja / prawo | brak żartu | fakt + skutek + prawo użytkownika |
| E-mail techniczny | brak żartu | bezpieczeństwo i działanie |

## 3. Jedna linia charakteru na ekran

Na ekranie funkcjonalnym maksymalnie jedno zdanie może być wyraźnie „Kukingowe”.
Pozostałe zdania mają być zwykłym, naturalnym polskim.

## 4. Nie tłumacz intencji UX

Nie:
- To pomoc, nie obowiązkowy krok.
- Jedno i drugie jest w porządku.
- Nie musisz się martwić.
- Zawsze możesz wrócić.

Tak:
- Oznacz pole jako `(opcjonalnie)`.
- Dodaj `Pomiń`.
- Dodaj `Wróć`.
- Powiedz, czy stan zapisuje się automatycznie.

## 5. Nie przypisuj emocji

Nie:
- najważniejszy przycisk;
- najmilsza część;
- na to się czeka;
- poczujesz, że…

Tak:
- wyjaśnij skutek działania.

## 6. Nie obiecuj bez gwarancji

Traktuj copy jak kontrakt z produktem.

Jeżeli tekst mówi:
- `nic nie zginie`;
- `jutro będzie inna osoba`;
- `autor dostanie powiadomienie`;
- `zajmie minutę`;
- `tak zobaczą to inni`;

to musi istnieć zachowanie systemu, które to gwarantuje.

## 7. Bez „marketingu prostoty”

Unikaj:
- To wszystko.
- Cztery pola i gotowe.
- Zajmie minutę.
- Klikasz — i jesteś w środku.

Jeśli flow jest proste, pokaże to sam interfejs.

## 8. Bez metajęzyka „po ludzku”

Unikaj jako stałego motywu:
- po ludzku;
- naprawdę;
- człowiek, nie automat;
- zwyczajnie;
- normalnie.

Jeśli ważne jest, że wiadomość czyta właściciel:
`Wiadomość trafia bezpośrednio do osoby prowadzącej Kuking.`

## 9. Brak odniesień do koloru i pozycji

Nie:
`Kliknij zielony przycisk poniżej.`

Tak:
`Kliknij „Zaloguj mnie w Kuking”.`

## 10. Formularze

Kolejność:
1. etykieta;
2. opcjonalnie jeden krótki help;
3. pole;
4. konkretny błąd.

Nie dubluj informacji z etykiety w helpie.

## 11. Walidacja

Nie oceniaj danych:
`Ta liczba porcji jest nierealna.`

Podaj regułę:
`Maksymalna liczba porcji to 999.`

## 12. Błędy

Schemat:
1. co się stało;
2. czy dane zostały zachowane;
3. co zrobić teraz.

Nie:
- uspokajaj psychologicznie;
- żartuj;
- tłumacz implementacji.

## 13. Senior-friendly bez protekcjonalności

Dla osób mniej technicznych:
- krótsze zdania;
- konkretne etykiety;
- jawny skutek działania;
- brak żargonu;
- brak zależności od gestów i koloru;
- duże cele kliknięcia.

Nie:
- `u wnuka`;
- `link od córki`;
- nadmierne tłumaczenie, że użytkownik „nic nie zepsuł”.

## 14. Marka

Zachować:
- `Pokaż, co dziś ugotowałeś`;
- `Ugotowałem`;
- pojedyncze użycia `kuKING`.

Ograniczyć:
- `kuKING` w CTA;
- żarty w auth/settings/errors;
- powtarzanie hasła `Nie musi być ładne — ma być prawdziwe`.

## 15. Słowa alarmowe podczas review

Każde wystąpienie wymaga świadomej decyzji:
- naprawdę
- po ludzku
- spokojnie
- po prostu
- nic nie
- zawsze
- minutę
- od razu
- najważniejszy
- najmilszy
- normalnie
- człowiek
- kuKING
- w porządku

Nie są zakazane mechanicznie. Są sygnałem, że zdanie może próbować „grać tonem” zamiast przekazywać informację.
