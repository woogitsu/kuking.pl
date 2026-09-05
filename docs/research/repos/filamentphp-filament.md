# filamentphp/filament — notatka researchowa

**Licencja: MIT** (`LICENSE.md`, copyright Filament). Wolno używać w zamkniętym
produkcie komercyjnym; jedyny warunek to zachowanie noty licencyjnej —
przy instalacji Composerem spełnia się sam (plik zostaje w `vendor/`).
Licencyjnie **nic nie stoi na przeszkodzie**.

Ta notatka **zasila decyzję z issue #20** („Filament na `/admin`?”), więc
kończy się jednoznaczną rekomendacją, a nie listą rozważań.

Snapshot: `git clone --depth 1` z 2026-09-05, gałąź `4.x`, HEAD `5a1bc78`.

---

## 1. Co wynika z licencji

- MIT — pełna swoboda, także przy monetyzacji (`docs/MONETIZATION.md`).
- To jest jedyne repozytorium z całego researchu, którego **kod trafiłby
  realnie do Kuking** (jako zależność, nie kopia).
- Konsekwencja: to jedyna notatka, w której trzeba oceniać nie tylko pomysły,
  ale **koszt utrzymania cudzego kodu w naszym drzewie zależności**.

## 2. Stan faktyczny: czy da się to dziś zainstalować

**Nie.** I to jest najważniejsze zdanie tej notatki.

`packages/support/composer.json:19`:

```json
"livewire/livewire": "^3.7"
```

Nasz `composer.json:14` wymaga `"livewire/livewire": "^4.0"`, a w `composer.lock`
mamy **v4.4.3**. Ograniczenie `^3.7` nie dopuszcza 4.x, więc
`composer require filament/filament` dziś **kończy się konfliktem zależności**.
Sprawdzone w całym repozytorium — żaden pakiet Filamenta nie deklaruje
zgodności z Livewire 4.

Pozostałe wymagania są spełnione:

| Wymaganie Filamenta 4.x | Nasz stan | Wynik |
|---|---|---|
| `php: ^8.2` | PHP 8.4 (`AGENTS.md` sekcja 3) | ✅ |
| `illuminate/contracts: ^11.28\|^12.0\|^13.0` | Laravel 13.30.1 | ✅ |
| `ext-intl` | do sprawdzenia w obrazie Railway `[do weryfikacji]` | ❓ |
| `livewire/livewire: ^3.7` | Livewire 4.4.3 | ❌ **blokada** |

Drogi wyjścia są trzy i wszystkie są złe:

1. **Cofnąć Livewire do 3.x** — łamie `AGENTS.md` sekcja 3 („Livewire 4”)
   i wymaga przepisania komponentów jednoplikowych, które są cechą wersji 4.
   Cofanie stacku produktu, żeby zmieścić panel administracyjny, jest
   odwróceniem priorytetów.
2. **Trzymać Filamenta w osobnej aplikacji** na tej samej bazie — dwa
   wdrożenia, dwie ścieżki uwierzytelniania, dwa miejsca na reguły autoryzacji.
   Sprzeczne z **D-001** (modularny monolit).
3. **Poczekać** na wsparcie Livewire 4 w Filamencie.

Trzecia jest jedyną rozsądną.

## 3. Co realnie byśmy dostali

Warto to nazwać dokładnie, bo za rok, gdy blokada zniknie, ta lista będzie
podstawą decyzji. Wzorcem działającym w praktyce jest laravel.io
(patrz `docs/research/repos/laravelio-laravel.io.md`, sekcja 3) — ten sam
stack, ten sam rozmiar zespołu.

1. **Tabela z filtrami, sortowaniem i wyszukiwaniem po kolumnie**
   (`packages/tables/src/Filters/`: `SelectFilter`, `TernaryFilter`,
   `MultiSelectFilter`, `TrashedFilter`, `QueryBuilder`). Nasza kolejka
   moderacji to dziś jeden widok `resources/views/pages/admin/reports.blade.php`
   i 150 linii kontrolera (`app/Http/Controllers/Admin/ModerationController.php`).
   Filtr „pokaż tylko zgłoszenia dotyczące zdjęć, starsze niż 24 h” to u nas
   nowa metoda i nowy widok; tam — trzy linijki konfiguracji.
2. **Akcje z modalem, wymaganym polem powodu i potwierdzeniem**
   (`packages/actions/src/Action.php`, `->requiresConfirmation()`,
   `->schema([TextInput::make('reason')->required()])`). Dokładnie to, co
   zaleca notatka o laravel.io (R9) i czego wymaga
   `docs/legal/MODERATION_PLAYBOOK.md`: żadnej decyzji bez powodu.
3. **Akcje zbiorcze** (`BulkAction`, `DeleteBulkAction`) — przy fali spamu
   różnica między „odrzuć 40 zgłoszeń” a czterdziestoma kliknięciami.
4. **Uwierzytelnianie dwuskładnikowe wbudowane w panel**
   (`packages/panels/src/Auth/MultiFactor/`: aplikacja TOTP oraz e-mail,
   `pragmarx/google2fa`). Konto moderatora ma dostęp do danych osobowych
   wszystkich użytkowników — 2FA na tym koncie jest wymogiem rozsądku,
   a napisanie go samemu to tydzień pracy i ryzyko błędu.
5. **Autoryzacja przez nasze Policies**, nie przez własny system:
   `FilamentUser::canAccessPanel()` (`packages/panels/src/Models/Contracts/FilamentUser.php:14`).
   Panel nie wnosi drugiego modelu uprawnień — pytanie „kto może wejść”
   zostaje w `app/Policies/`. To istotne, bo znosi główną obawę
   („cudzy kod decyduje o naszych uprawnieniach”).
6. Widgety i wykresy na pulpicie, powiadomienia w panelu, globalne wyszukiwanie.

## 4. Co to kosztuje

1. **Duża zależność w projekcie, którego zasadą jest „żadnej biblioteki na
   zapas”** (`AGENTS.md` sekcja 3). Filament to 17 pakietów
   (`packages/`: actions, forms, infolists, notifications, panels,
   query-builder, schemas, support, tables, widgets, upgrade…). To nie jest
   argument rozstrzygający — panel administracyjny **jest** nazwanym problemem,
   nie zapasem — ale jest to zależność, której cyklem wydawniczym trzeba żyć.
2. **Własny cykl wersji głównych.** Filament 3 → 4 wymagał osobnego pakietu
   `packages/upgrade`. Migracja panelu to praca, która nie wnosi nic
   użytkownikowi.
3. **Tarcie na UUID.** Migracje opcjonalne
   (`packages/actions/database/migrations/create_imports_table.php:24`) używają
   `foreignId('user_id')->constrained()`, czyli `bigint` wskazującego na
   `users.id`. Nasze `users.id` to UUID (**D-001**/`docs/DATABASE.md`), więc
   te migracje trzeba by publikować i poprawiać ręcznie. Dotyczy wyłącznie
   funkcji importu/eksportu CSV — ale to jest przykład ogólniejszy: pakiet
   zakłada domyślny Laravel, a my mamy schemat świadomie niedomyślny.
4. **Panel bez JavaScriptu nie działa.** To **nie jest** naruszenie **D-007** —
   ta decyzja mówi o rejestracji, logowaniu, publikacji wpisu, przepisu,
   komentarza i „Ugotowałem”, czyli o ścieżkach użytkownika. Panel obsługuje
   jedną lub dwie osoby z zespołu na własnym sprzęcie. **Warto to zapisać
   wprost**, żeby przy wdrożeniu nikt nie odrzucił Filamenta z powodu D-007
   ani nie rozciągnął D-007 na cały serwis.
5. **`docs/UX_50_PLUS.md` nie obowiązuje w panelu.** Odbiorcą panelu nie jest
   nasza grupa docelowa, tylko moderator. To działa na korzyść Filamenta:
   nie musimy sami budować dostępnego interfejsu administracyjnego, bo
   wymagania są tu zwykłe, a nie zaostrzone.

## 5. Wzorce jakościowe warte przeniesienia niezależnie od decyzji

Te rzeczy bierzemy **teraz**, nawet nie instalując Filamenta:

- **Akcja destrukcyjna = modal z wymaganym powodem + opis skutku**
  (nie „Czy na pewno?”, tylko „to uniemożliwi tej osobie logowanie i ukryje
  jej wpisy”). Wzór działający: laravel.io, `UsersTable.php:141`.
- **Widoczność akcji zależna od Policy**, nie od roli sprawdzanej w widoku
  (`->visible(fn () => auth()->user()->can(...))`). U nas dziś dostęp do panelu
  daje `EnsureUserIsModerator` (25 linii), a pojedyncze akcje nie są odrębnie
  autoryzowane `[do weryfikacji w ModerationController]`.
- **Stos jakości** z ich `composer.json`: Larastan 3, Pint, Pest 4 z wtyczką
  przeglądarkową. Nasz `scripts/check.sh` ma Pint i testy; **Larastan
  na `app/Domain` byłby tani** i wyłapałby literówki w nazwach relacji
  — a w kontekście **D-010** (własny runner) mamy gdzie to uruchamiać, bez
  zużywania minut GitHuba.

## 6. Wzorce wydajnościowe

Bez znaczenia w naszej skali — panel obsługuje jedną osobę. Jedyna rzecz warta
odnotowania to `packages/tables` z paginacją i leniwym ładowaniem relacji
z pudełka; ręcznie napisany panel zwykle robi N+1 na liście zgłoszeń
(u nas: `reports` → `target` polimorficzny → autor → profil).
**To jest realny argument za panelem gotowym**: nasz własny panel będzie miał
ten problem i nikt go nie zauważy, bo kolejka ma 20 pozycji.

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| Filament jako framework do **części publicznej** serwisu | Panel administracyjny to nie to samo co ekran dla użytkownika 50+; publiczne widoki zostają w Blade + Livewire z pełną kontrolą nad HTML-em i **D-007** |
| Cofnięcie Livewire do 3.x, żeby zmieścić panel | Odwrócenie priorytetów: stack produktu podporządkowany narzędziu wewnętrznemu |
| Osobna aplikacja z panelem na tej samej bazie | Sprzeczne z **D-001**; dwa miejsca na reguły autoryzacji to gwarancja rozjazdu |
| `filament/spatie-laravel-media-library-plugin` | **D-003**: mamy własny model `media` z cyklem moderacyjnym; wtyczka zakłada MediaLibrary |
| Import/eksport CSV (`packages/actions` + trzy migracje) | Niepotrzebne, a wnosi tarcie na UUID (sekcja 4.3). Eksport danych użytkownika mamy własny (`data_exports`) |
| Ustawienia produktu edytowalne w panelu (`spatie-laravel-settings-plugin`) | Trzeci raz w tym researchu ten sam wniosek (Fresns `configs`, Tandoor `Automation`, Discourse `site_settings`): próg zmienia się w `config/kuking.php`, z testem i historią w gicie |

## 8. Rekomendacje dla Kuking — odpowiedź na issue #20

| # | Rekomendacja | Nasz plik | Waga |
|---|---|---|---|
| R1 | **Decyzja dla #20: `LATER`, z powodu technicznym, nie światopoglądowym.** Filament 4.x wymaga Livewire `^3.7`, my mamy 4.4.3 — dziś nie da się go zainstalować | `composer.json:14`, `packages/support/composer.json:19` w ich repo | **rozstrzygające** |
| R2 | Zapisać w issue #20 **warunek powrotu do tematu**: wydanie Filamenta deklarujące `livewire/livewire: ^4.0`. Sprawdzenie to jedna komenda: `composer require filament/filament --dry-run` | issue #20, `docs/DECISIONS.md` (wpis do dopisania przez właściciela) | P1 |
| R3 | Do tego czasu **rozwijać własny panel minimalnie** — tylko kolejka zgłoszeń, decyzja z powodem, historia. Nie budować własnych filtrów, akcji zbiorczych ani pulpitu: to jest praca, którą Filament wykona za darmo, gdy blokada zniknie | `app/Http/Controllers/Admin/ModerationController.php`, `resources/views/pages/admin/` | P1 — chroni przed zbudowaniem czegoś, co zaraz wyrzucimy |
| R4 | Przenieść **teraz** dwa wzorce niezależne od pakietu: modal z wymaganym powodem i opisem skutku oraz autoryzację pojedynczych akcji przez Policy | `app/Http/Controllers/Admin/ModerationController.php`, `app/Policies/` | P1 |
| R5 | 2FA na kontach moderatora i admina — jeśli Filament wejdzie, dostajemy je z pakietem; jeśli nie, trzeba to zaplanować osobno, bo konto moderatora widzi dane osobowe wszystkich | `docs/legal/SECURITY_BASELINE.md`, `app/Models/User.php` | P1 przed pierwszym zaproszeniem do alfy |
| R6 | Zapisać wprost w `docs/MODERATION.md`, że **D-007 nie obejmuje panelu**, a `docs/UX_50_PLUS.md` też nie — inaczej przy każdej dyskusji o panelu wraca ten sam spór | `docs/MODERATION.md` | P2 |
| R7 | Larastan na `app/Domain` w `scripts/check.sh` i na runnerze z **D-010** | `scripts/check.sh`, `composer.json` | P2 |
| R8 | Sprawdzić N+1 na liście zgłoszeń we własnym panelu (`reports` → cel polimorficzny → autor → profil) | `app/Http/Controllers/Admin/ModerationController.php` | P2 |
| R9 | Potwierdzić obecność `ext-intl` w obrazie na Railway — będzie potrzebne, gdy Filament wejdzie | `Dockerfile`, `docs/infra/` | P2 |

### Uwaga dla issue #20

Rekomendacja `LATER` **nie jest** odłożeniem decyzji „bo trudna”. Blokada jest
sprawdzalna jedną komendą i ma jednoznaczny warunek zniknięcia. Do tego czasu
najgorszym możliwym ruchem byłoby zbudowanie własnego rozbudowanego panelu —
bo to jest jedyna praca w tym projekcie, którą gotowy pakiet MIT wykona lepiej,
a którą trzeba by wtedy wyrzucić. Własny panel ma zostać **celowo ubogi**:
kolejka, decyzja, powód, historia.
