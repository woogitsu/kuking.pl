# User flows i ekrany

## Rejestracja

```text
Start
→ Załóż konto
→ email
→ hasło
→ username
→ weryfikacja email
→ zainteresowania
→ opcjonalne follow
→ feed
```

Nie wymagać telefonu w MVP.

## Wpis

```text
+ Dodaj
→ Dodaj zdjęcie
→ podgląd
→ Napisz kilka słów
→ widoczność
→ Opublikuj
→ pokaż opublikowany wpis
```

Cel: poniżej 60 sekund, jeśli zdjęcie jest gotowe.

## Przepis

```text
+ Dodaj
→ Pełny przepis
→ 1/3 informacje
→ 2/3 składniki
→ 3/3 przygotowanie
→ Podgląd
→ Opublikuj
```

Autosave na każdym kroku.

## Ugotowałem

```text
Przepis
→ Ugotowałem
→ zdjęcie
→ uwaga
→ zrobię ponownie?
→ actual time
→ Publikuj
→ autor otrzymuje notification
```

## Profil

Chronologiczne archiwum.

Zakładki:
- Wszystko;
- Przepisy;
- Ugotowane.

Archiwum po miesiącach/roku może dawać nostalgiczny efekt starego fotobloga.

## Search

```text
Szukaj
→ fraza
→ Przepisy | Ludzie
→ proste filtry
```

## Eksport

```text
Ustawienia
→ Twoje dane
→ Pobierz swoje dane
→ background job
→ plik ZIP
```

Docelowo:
- JSON;
- zdjęcia;
- czytelny HTML;
- indeks.

## Usunięcie

```text
Ustawienia
→ Twoje dane
→ Usuń konto
→ wyjaśnienie
→ potwierdzenie
→ proces zgodny z retention
```

## Zgłoszenie i odpowiedź (DSA art. 16)

Zalogowany, zgłoszenie „naszych zasad”:

```text
Treść
→ Zgłoś
→ powód + kilka słów
→ Wyślij
→ karta sprawy z numerem (/zgloszenia/{id})
→ powiadomienie „Mamy Twoje zgłoszenie”
→ (moderator rozstrzyga)
→ powiadomienie „decyzja” + pouczenie na karcie sprawy
```

Bez konta, zgłoszenie treści niezgodnej z prawem:

```text
Stopka
→ Zgłoś nielegalną treść
→ adres + uzasadnienie + dobra wiara
→ Wyślij
→ ekran z numerem sprawy + list potwierdzający
→ (moderator rozstrzyga)
→ list z decyzją, pouczeniem i podpisanym linkiem do odwołania
```

Zgłaszający nigdy nie dowiaduje się, kogo i jak ukarano — patrz
`docs/MODERATION.md`, „Co dostaje ZGŁASZAJĄCY”.

## Mapa ekranów MVP

> **Poprawione 12 września 2026.** Ta mapa miała 29 adresów, angielskich
> i nigdy nieistniejących — znalazł to `docs/AUDYT_2026-09.md` (B3, #5 i #6):
> z 29 pozycji istniało 5, a cztery ekrany administracyjne nie miały żadnego
> odpowiednika w kodzie. Adresy niżej pochodzą z `php artisan route:list`,
> nie z zgadywania nazw.

Public:
- `/`
- `/odkryj`
- `/szukaj`
- `/@username`
- `/przepisy/{recipe}`
- `/wpisy/{post}`
- `/pomoc`
- `/prywatnosc`, `/regulamin`, `/zasady` — dokumenty prawne (nie ma wspólnego prefiksu `/legal/*`)

Auth:
- `/login`
- `/register`
- `/nie-pamietam-hasla`
- `/potwierdz-email`

App:
- `/home`
- `/dodaj`
- `/dodaj/zdjecie`
- `/dodaj/przepis`
- `/przepisy/{recipe}/edycja`
- `/przepisy/{recipe}/gotuj`
- `/zeszyt`
- `/powiadomienia`
- `/zgloszenia`, `/zgloszenia/{report}` — własne zgłoszenia i karta sprawy
- `/ustawienia/profil`
- `/ustawienia/zdjecie` — zdjęcie profilowe (osobny, krótki ekran; skróty prowadzą tu z własnego profilu)
- `/ustawienia/czytelnosc`
- `/ustawienia/prywatnosc`
- `/ustawienia/urodziny` — dzień i miesiąc urodzin, bez roku; „Usuń datę” (#1755)
- `/ustawienia/twoje-dane`

Admin:
- `/admin/zgloszenia`
- `/admin/z-urzedu/{typ}/{id}` — „Zdejmij z urzędu”: treść bez zgłoszenia (wpis, przepis, komentarz; G31, D-251). Wejście przyciskiem przy treści.
- `/admin/odwolania`
- `/admin/bez-odpowiedzi`
- `/admin/kuking-na-dzis`
- `/admin/tagi-promowane`
- `/admin/uzytkownicy`
- `/admin/wiadomosci`
- `/admin/sygnaly`
- `/admin/kolaz-powitalny`
