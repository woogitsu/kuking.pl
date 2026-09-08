# Moderacja

UGC oznacza moderację od pierwszej publicznej wersji.

## Powody zgłoszeń

- spam;
- scam/phishing;
- podszywanie;
- nękanie;
- hate;
- treści seksualne;
- dane osobowe;
- naruszenie praw autorskich;
- niebezpieczna porada;
- nieletni;
- reklama bez oznaczenia.

## UX zgłoszenia

Wyraźny tekst:
**Zgłoś**

Nie tylko ikonka flagi.

## Statusy

```text
open
→ triage
→ reviewing
→ resolved / rejected
```

## Akcje

- no action;
- ograniczenie widoczności (`hide`);
- **przywrócenie ukrytej treści (`unhide`)** — patrz niżej;
- usunięcie;
- ostrzeżenie;
- czasowe ograniczenie;
- ban.

Nie każda akcja ma sens dla każdego celu — macierz `ModerationAction::DOZWOLONE`
rozstrzyga to jawnie, a formularz pokazuje tylko decyzje możliwe dla danego
zgłoszenia. „Ugotowałem" nie ma `hide`, bo `cooked_events` nie ma kolumny
`status`: przycisk istniał i nie robił nic.

### Przywracanie treści (issue #65)

Ukrycie **musi** dać się cofnąć z poziomu serwisu. Podręcznik moderacji sam
każe ukrywać tymczasowo („najpierw ukryć, dać szansę poprawy" przy prawach
autorskich), a bez przycisku jedyną drogą powrotu był `UPDATE` w produkcyjnej
bazie — operacja zakazana bez zgody właściciela (AGENTS.md §6).

- Przywrócenie zapisuje wiersz w `moderation_actions` (akcja `unhide`)
  i wymaga powodu — cofnięcie kary też zostawia ślad.
- Treść wraca do statusu **sprzed ukrycia**, nie na sztywno do `published`.
  Ukryty szkic po przywróceniu jest dalej szkicem (`moderation_actions.previous_status`,
  patrz `docs/DATABASE.md`).
- Autor dostaje powiadomienie tym samym mechanizmem co przy każdej innej decyzji.

## Copyright

Źródło przepisu:
- własny;
- rodzinny;
- adaptacja;
- zewnętrzny.

Nie publikować pełnych cudzych treści bez praw.

## Food safety

Wrażliwe:
- grzyby;
- wekowanie/botulizm;
- surowe mięso;
- żywienie niemowląt;
- alergie.

Możliwe działania:
- neutralne warningi;
- flagi;
- szybka ścieżka moderacji.

## Odwołania (issue #10, DSA art. 17 i 20)

Odwołanie, po którym nic nie da się zmienić, nie jest odwołaniem. Ścieżka
działa w produkcie, nie tylko na papierze.

**Gdzie się składa**

| Kto | Droga |
|---|---|
| Osoba aktywna albo zawieszona | Przycisk „Odwołanie od tej decyzji" w powiadomieniu → `/odwolanie/{decyzja}` |
| Osoba **zablokowana** | `/odwolanie` — formularz **przed logowaniem**, zamknięty loginem i hasłem. Nie loguje nikogo i nie zdejmuje blokady; służy tylko do przypisania sprawy do konta. Link jest na ekranie logowania. |
| Kto zapomniał hasła | Adres kontaktowy z `config('kuking.community.contact_email')` — droga zapasowa, wypisana na obu formularzach |

Formularz całkiem otwarty odrzucono: przy jednym moderatorze byłby gotowym
celem spamu. Sam adres e-mail odrzucono: odwołania nie ma wtedy w logu, terminu
nie da się pilnować, a odpowiedź nie trafia do produktu.

**Ile razy** — raz od jednej decyzji (`UNIQUE (moderation_action_id)`).
Nowe okoliczności idą adresem e-mail.

**Terminy** (`config('kuking.moderation')`, zgodne z
`docs/legal/MODERATION_PLAYBOOK.md`):

- **sześć miesięcy od decyzji** na złożenie odwołania — to jest twarda
  podłoga wymagana przez DSA (art. 20 ust. 1). `appeal_days` z konfiguracji
  (`KUKING_APPEAL_DAYS`, domyślnie 180 dni) może ten termin tylko wydłużyć,
  nigdy skrócić: `ModerationAction::appealDeadline()` bierze większą z dwóch
  wartości, więc realny termin to zawsze co najmniej sześć miesięcy —
  egzekwowane. Do 7 IX 2026 stało tu 14 dni — liczba wzięta z rozsądku
  operacyjnego, nie z przepisu; podręcznik
  (`docs/legal/MODERATION_PLAYBOOK.md` pkt 3) odnotowuje tę samą poprawkę;
- 7 dni roboczych na odpowiedź — pokazywane moderatorowi w kolejce, z
  oznaczeniem spraw po terminie;
- 24 godziny karencji, zanim ten sam moderator **podtrzyma** własną decyzję.
  Cofnąć własną decyzję może od razu.

**Jak moderator zamyka sprawę** — `/admin/odwolania`: widzi słowa
odwołującego się, decyzję wraz z powodem oraz dokładnie tę wiadomość, którą ta
osoba wtedy dostała. Wybiera „podtrzymuję" albo „cofam" i **musi** napisać
uzasadnienie. Cofnięcie realnie przywraca treść albo odblokowuje konto.

**Jak odpowiedź dociera** — powiadomieniem typu moderacyjnego. Osoba
zablokowana czyta je na ekranie logowania (`LoginController`), bo do serwisu
nie wejdzie.
