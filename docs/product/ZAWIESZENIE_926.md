# Zawieszenie konta: czynności prywatne (#926)

Decyzja: [D-253 w `docs/DECISIONS.md`](../DECISIONS.md) — tam stanowisko
i uzasadnienie; ten plik opisuje zakres i weryfikację.

## Decyzja właściciela, 20 września 2026

Właściciel wybrał wariant 2: „dopuść zeszyt, odhaczanie i reset;
blokuj komentarze i obserwowanie”. To jawna zmiana zakresu kary,
nie wniosek wyprowadzony z testu dokumentującego wcześniejszą wadę.

Odrzucony wariant 1 ukrywał wszystkie akcje i zachowywał pełną blokadę
zapisu. Kosztował mniej: warunki w widokach i testy renderowania.
Wariant 2 wymaga dodatkowo wyjątków middleware, kontroli prywatności
zeszytu, wyłączenia powiadomienia przy prywatnym zapisie osoby zawieszonej
i testów rzeczywistych skutków każdej dopuszczonej trasy.

## Zakres

| Czynność podczas zawieszenia | Wynik |
|---|---|
| Czytanie dostępnych treści, nawigacja krokami | Dostępne |
| Nowy komentarz i odpowiedź pod wpisem | Formularz pyta `PostPolicy::comment`; zapis nadal blokowany |
| Obserwowanie osoby: profil, obie listy relacji, tablica | Brak formularza, zamiast niego zdanie o własnym zawieszeniu (profil, listy); zapis nadal blokowany |
| Wycofanie obserwowania | Formularz pyta `UserPolicy::unfollow`; zapis nadal blokowany |
| Utworzenie własnego prywatnego zeszytu | Dostępne |
| Dodanie przepisu lub wpisu do własnego prywatnego zeszytu | Dostępne; zapis przepisu nie powiadamia autora |
| Utworzenie publicznego zeszytu lub dodanie do niego treści | Niedostępne; prywatny wyjątek nie jest drogą publikacji |
| Wyjęcie zapisanej treści lub usunięcie własnego niedomyślnego zeszytu | Dostępne; nie tworzy nowej publikacji |
| Odhaczenie i cofnięcie odhaczenia | Dostępne, sesja bieżącej przeglądarki |
| Reset postępu | Dostępny po potwierdzeniu; tylko oznaczenia bieżącego przepisu |
| Publikacja wpisu, przepisu, „Ugotowałem” | Blokada bez zmian |
| Zmiana hasła, wylogowanie innych urządzeń, włączenie, wyłączenie i nowe kody 2FA | Dostępne (D-259, audyt B2-04) |

Lista wyjątków `EnsureAccountIsActive` zawiera dokładne nazwy tras,
nie wzorzec `collections.*` ani wyjątek dla wszystkich POST-ów.
`CollectionPolicy` oraz akcje zapisu pilnują właściciela i prywatności.
Usuwanie zapisów pozostaje ograniczone do zeszytów zalogowanej osoby.
Nie zmieniamy widoczności istniejących zeszytów ani zapisów.

Wyjątki nie przepuszczają kont zbanowanych, usuwanych ani wymazanych.
Blokady między osobami i polityki widoczności treści nadal obowiązują.
Limity zapytań oraz CSRF pozostają włączone. Reset to jedna trasa
`cooking.restart` („Zacznij od początku”, wspólna z kontami aktywnymi);
korzysta z limitu `cooking_krok` i `RecipePolicy::view`, tak jak odhaczanie.
Kasuje tylko odhaczenia bieżącego przepisu w sesji tej osoby.

Profil pyta `UserPolicy::follow` i `unfollow` wprost. Listy relacji
i tablica nie pytają Policy dla każdej osoby: blokady wycina już ich
zapytanie, więc widok sprawdza tylko stan obu kont (pole w modelu, bez
zapytania na wiersz). Zapis zawsze przechodzi przez Policy na świeżo.

Gdy przycisku nie ma, komunikat mówi, czyje konto jest zawieszone:
zawieszony oglądający widzi zdanie o własnym koncie, aktywny oglądający
zawieszonego właściciela — zdanie o tamtym koncie. Przy blokadzie profil
daje 403, a listy tej osoby nie pokazują.

## Granica integracji z #902/#910/#903

Nie zmieniono `RecipePolicy::cook`, zaproszenia „Ugotowałem”, składników
ani minutnika. Zmiana `cooking.blade.php` dotyczy wyłącznie części
odhaczania: przycisk „Zacznij od początku” (`cooking.restart`) nie jest
już chowany przed zawieszonymi. Osobna trasa `cooking.reset` z wcześniejszej
wersji tej gałęzi została usunięta przy scalaniu z main — ma być jeden
reset, nie dwa. Baza `4c811cc7` nie zawiera
poprawek gałęzi `flota/gotowanie`; należy je zachować przy scalaniu.

## Weryfikacja i wycofanie

Testy decyzji: `tests/Feature/ZawieszoneKontoPrywatneCzynnosciTest.php`.
Reset (`cooking.restart`) jest także w macierzy pięciu ról
`KazdaTrasaZIdentyfikatoremPodPolicyTest`.

Brak migracji. Wycofanie to odwrócenie commitów tej poprawki. Nie usuwa
prywatnych zeszytów ani zapisanych pozycji; wraca wcześniejsza blokada
operacji podczas zawieszenia. Oznaczenia kroków pozostają w sesji.
