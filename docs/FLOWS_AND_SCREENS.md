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

## Mapa ekranów MVP

Public:
- `/`
- `/discover`
- `/search`
- `/@username`
- `/recipes/{slug}`
- `/posts/{id}`
- `/help`
- `/legal/*`

Auth:
- `/login`
- `/register`
- `/forgot-password`
- `/verify-email`

App:
- `/home`
- `/add`
- `/posts/create`
- `/recipes/create`
- `/recipes/{id}/edit`
- `/recipes/{id}/cook`
- `/collections`
- `/notifications`
- `/settings/profile`
- `/settings/accessibility`
- `/settings/privacy`
- `/settings/data`

Admin:
- `/admin/reports`
- `/admin/content`
- `/admin/users`
- `/admin/moderation-actions`
- `/admin/audit`
