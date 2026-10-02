# Powiadomienia, przegląd tygodnia, push, poczta

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### notifications
In-app.

**`type varchar(80) NOT NULL`** — rodzaj powiadomienia (`cooked_event.created`,
`comment.created`, `follow.created`, `moderation.decision`…). **Bez CHECK-a
w bazie**: zamknięta lista stoi stałymi `TYPE_*` w `App\Models\Notification`,
a nowy typ dochodzi razem z kodem, który go wysyła i tłumaczy na zdanie po
polsku — CHECK kazałby do tego dokładać migrację i nie chroniłby przed jedyną
realną pomyłką, czyli typem bez tłumaczenia. Ta kolumna **rozstrzyga o retencji**: typy z
`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA` żyją do terminu
odwołania, a nie 3 miesiące (patrz niżej). `data jsonb` niesie resztę —
identyfikatory treści i to, co trzeba pokazać w zdaniu.

**`push_wyslano_at timestamptz NULL`** (D-303, migracja
`2026_09_26_100000_utworz_powiadomienia_push`) — kiedy to powiadomienie
NAPRAWDĘ poszło pushem, czyli transport przyjął wiadomość na WSZYSTKIE
urządzenia tej grupy (issue #1960). `NULL` = tylko w serwisie, albo jeszcze
czeka (koniec ciszy nocnej / limitu, rezerwacja w toku, albo trwała porażka
transportu — patrz `push_proba_at` niżej). Kilka powiadomień zgrupowanych
w jednym pushu dostaje **ten sam** znacznik, więc liczba RÓŻNYCH wartości
w dobie odbiorcy to liczba wysłanych pushy — po niej liczy się dzienny limit
(`WyslijPowiadomieniePush`). Dodana bez wartości domyślnej, czyli bez
przepisywania tabeli. Rollback: kolumna znika razem z tabelami pushu (opis
przy `push_subscriptions`).

**`push_proba_at timestamptz NULL`** (issue #1960, ta sama migracja) —
kiedy `WyslijPowiadomieniePush` ZAREZERWOWAŁO tę grupę powiadomień do
wysyłki, niezależnie od tego, czy transport się udał. Bariera przed dublem:
dopóki jest ustawione, żadne INNE (świeżo zdarzeniowe) zadanie tego samego
odbiorcy nie wybierze tej samej grupy jeszcze raz — ponowienie po błędzie
transportu dostaje listę powiadomień wprost od poprzedniej próby, nie przez
ponowne zapytanie „co czeka". Do 26 września 2026 tej kolumny nie było,
a `push_wyslano_at` pełniło OBIE role naraz (rezerwacji i potwierdzenia) —
błąd transportu albo trwała porażka po wyczerpaniu prób zostawiały
`push_wyslano_at` ustawiony na kłamstwo. Dziś `push_proba_at` może być
ustawione, gdy `push_wyslano_at` jest puste (rezerwacja w toku albo trwała
porażka — mierzalne zapytaniem `push_proba_at IS NOT NULL AND
push_wyslano_at IS NULL`), ale nie odwrotnie.

**`push_grupa_id uuid NULL`** (#1992, migracja
`2026_09_26_200000_zakoncz_rezerwacje_push`) — jeden UUID dla wszystkich
powiadomień objętych tą samą rezerwacją. Dwie osobne grupy mogą mieć
identyczny `push_proba_at` (np. przy zamrożonym zegarze), więc limit liczy
różne UUID, a nie różne znaczniki czasu. Historyczne wiersze bez UUID liczą
się każdy osobno: może to ostrożnie odłożyć wysyłkę, ale nie przepuścić
nadmiaru. Nullable bez defaultu, bez przepisywania tabeli.

**`push_zakonczono_at timestamptz NULL`** (#1992, migracja
`2026_09_26_200000_zakoncz_rezerwacje_push`) — koniec wszystkich prób
transportu bez pełnego sukcesu. Dopóki pole jest puste, `push_proba_at`
rezerwuje slot limitu także dla równoległego zadania i przez zmianę doby
(najwyżej 48 godzin). Udany transport rozlicza slot według
`push_wyslano_at`. Trwała porażka zajmuje slot do końca doby zakończenia,
potem go zwalnia; grupa nie wraca do świeżego wyboru, bo `push_proba_at`
pozostaje. Ponowienie starsze niż 48 godzin kończy się bez wysyłki.
Zachowujemy ostrożny rachunek także przy częściowym dostarczeniu na jedno
z urządzeń: zakończona grupa zajmuje slot w dobie zakończenia nawet wtedy,
gdy pełne `push_wyslano_at` nadal jest puste. Kolumna jest nullable bez
defaultu, więc dodanie nie przepisuje tabeli. Rollback odmawia, gdy są
grupy albo zakończone rezerwacje: oba znaczniki są potrzebne do
prawidłowego rachunku. Wtedy wycofujemy kod i osobno rozstrzygamy dane.

**`push_wynik varchar(32) NULL`** (#2053, migracja
`2026_09_28_200000_add_push_wynik_to_notifications`) — DLACZEGO rezerwacja
zamknęła się bez wysyłki. `push_zakonczono_at` stawiała zarówno trwała
porażka transportu, jak i świadome anulowanie ponowienia (#2052), więc czujka
nie umiała odróżnić awarii od anulowania. Zamknięta lista
(`App\Domain\Notifications\Push\KodZamknieciaPush`), pilnowana CHECK-iem
`notifications_push_wynik_check`: `NULL` albo — tylko przy niepustym
`push_zakonczono_at` — `porazka_transportu` (wyczerpane próby; alarmuje),
`anulowano` (przeczytane, niewidoczne, konto bez dostępu, rezerwacja ponad
48 h; nie alarmuje), `zamknieto_recznie` (operator rozliczył grupę według
`docs/infra/WEB_PUSH_TRWALE_PORAZKI_2053.md`). Zamknięcia sprzed migracji
zostają bez kodu i nie alarmują. Stany rezerwacji, które liczy
`kuking:sprawdz-push` (`StanWysylkiPush`): **w toku** (bez zamknięcia,
świeższa niż `push_osierocenie_minut` albo z ponowieniem w `jobs` niosącym ID
tego powiadomienia — samo zadanie odbiorcy nie wystarcza, #1992),
**utracone ponowienie** (bez zamknięcia, starsza niż próg, bez takiego
ponowienia; nie liczone przy wyłączonym kanale),
**trwała porażka** (`porazka_transportu`). Częściowy indeks
`notifications_push_nierozliczone_idx` na `(push_proba_at)` `WHERE
push_proba_at IS NOT NULL AND push_wyslano_at IS NULL AND (push_zakonczono_at
IS NULL OR push_wynik = 'porazka_transportu')` trzyma tylko te wiersze, które
czujka ogląda. Kolumna nullable bez defaultu (bez przepisywania tabeli), CHECK
jako `NOT VALID` + `VALIDATE`, indeks `CONCURRENTLY` (AGENTS.md §6).
Rollback NIE odmawia: kod nie jest decyzją człowieka o jego danych; po
`down()` i ponownym `migrate` zamknięte grupy wracają bez kodu, czyli jako
„zamknięte, nie alarmują” — ginie tylko diagnostyka czujki, żaden push nie
idzie drugi raz (`push_proba_at` i `push_zakonczono_at` zostają).

**Retencja:** `config('kuking.notifications.retention_months')` — **3 miesiące**
od `created_at`, **niezależnie od `read_at`** (wariant A z `docs/decyzje/ADR_RETENCJE.md`
§6: jeden wiek dla wszystkich; wariant B trzymałby bezterminowo powiadomienia,
których nikt nigdy nie otworzy). Tę samą liczbę widzi człowiek w polityce
prywatności — `resources/legal/polityka-prywatnosci.md`, wiersz „Powiadomienia
w serwisie".

*(Stał tu dłuższy okres, przepisany z ADR §5.2 jako rekomendacja agenta
z pierwszej wersji tego dokumentu. Kod i polityka prywatności mówiły wtedy to,
co mówią teraz, więc poprawiliśmy dokument, nie kod — D-038. Audyt zewnętrzny,
pozycja G13. Liczba stoi w tej sekcji RAZ i pilnuje tego
`tests/Feature/ZeszytBezKluczaGlownegoTest`.)*

**WYJĄTEK, którego nie wolno przeoczyć przy zmianie tej liczby.** Trzy miesiące
są KRÓTSZE niż sześć miesięcy, przez które ma działać prawo do odwołania od
decyzji moderacyjnej (DSA art. 20 ust. 1, `ModerationAction::appealDeadline()`).
Powiadomienie o decyzji niesie **jedyny w serwisie link „Odwołaj się"**, więc
typy z `App\Models\Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`
nie są kasowane według tej liczby — ich termin wylicza się z powiązanej decyzji.
Ta lista jest **zamkniętą stałą w kodzie, nie w configu**, celowo: zmiana ma
przechodzić przez code review, nie przez zmienną środowiskową (ten sam wzorzec
co `AuditLogEntry::NIGDY_NIE_KASUJ`).

Egzekwuje `kuking:sprzataj-powiadomienia`
(`App\Domain\Compliance\PrzedawnionePowiadomienia`), harmonogram codziennie
o 04:20. Zwykłe powiadomienia: `DELETE` partiami z budżetem na przebieg
(`UsuwanieWPartiach`, #1657, opis przy `product_signals`) — wiersz nie ma
odpowiednika w storage.
Powiadomienie moderacyjne, którego `delete()` się nie uda, zostaje w bazie
(następny przebieg próbuje ponownie), ale przebieg kończy się porażką: raport
liczy je w `nieudaneModeracyjne`, komenda zwraca kod ≠ 0, a zadanie
w harmonogramie rzuca wyjątek (#1342, `RetencjaPowiadomienCzesciowaPorazkaTest`).

### weekly_digest_sends

Trwały klucz idempotencji tygodniowego podsumowania: **jeden list na parę
(osoba, tydzień)**, pilnowany przez bazę. Migracja
`2026_09_10_400000_create_weekly_digest_sends_table`, audyt 10.09.2026
QUEUE-01 / MAIL-02 / RACE-04, **D-077**.

| Kolumna | Uwagi |
|---|---|
| `user_id` | Kogo dotyczy. `cascadeOnDelete` — druga linia, nie pierwsza: kont z Kuking się nie kasuje, tylko anonimizuje (D-022). |
| `week_start` | **DATA PONIEDZIAŁKU** tygodnia, za który poszedł list, liczona w strefie człowieka (`App\Support\Czas::poczatekTygodniaData()`). Nie numer tygodnia ISO. |
| `reserved_at` | Kiedy zajęto klucz. **Nie** „kiedy list doszedł" — tego Kuking nie wie i nie ma się dowiadywać (otwarta sprawa #204: żadnego śledzenia doręczeń ani otwarć). |

```sql
CREATE TABLE weekly_digest_sends (
    user_id     uuid  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    week_start  date  NOT NULL,
    reserved_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, week_start)
);

ALTER TABLE weekly_digest_sends
ADD CONSTRAINT weekly_digest_sends_week_start_monday_check
CHECK (extract(isodow from week_start) = 1);
```

#### Jeden wiersz na osobę, nie historia wysyłek (#2280, 30.09.2026)

Bariera potrzebuje tylko bieżącego tygodnia, a polityka prywatności mówi
o „zapisie ostatniej wysyłki, nie historii”. Dlatego `OdbiorcyDigestu::zarezerwuj()`
w tej samej transakcji co nowy wiersz kasuje starsze rezerwacje tej osoby
(`week_start < nowy tydzień`), a `EraseAccountData` kasuje wszystkie
rezerwacje konta i zeruje `users.weekly_digest_sent_at` (kont się nie kasuje,
więc `ON DELETE CASCADE` tu nie działa, D-022). Wiersze sprzed poprawki znikają
przy następnej wysyłce tej osoby albo przy wymazaniu konta. Schemat bez zmian,
bez migracji. Test: `RezerwacjePodsumowaniaNieSaHistoriaTest`.

#### Po co, skoro jest już `users.weekly_digest_sent_at`

Bo tamten znacznik jest **porównaniem w PHP**, a nie barierą. Do 10 września
komenda wysyłkowa robiła dla każdej osoby: `Mail::queue()` → budżet → sygnał,
a znacznik stawiała **jednym zapytaniem po całej pętli**. Awaria pomiędzy
zostawiała do sześćdziesięciu listów w kolejce i **zero** śladu w bazie, więc
następny przebieg pisał do tych samych osób drugi raz.
`withoutOverlapping()` z harmonogramu tego nie łapie: chroni przed dwoma
przebiegami JEDNOCZEŚNIE, nie przed kolejnym przebiegiem PO awarii.

Kolejność jest teraz odwrotna: **wiersz rezerwacji, potem list.** `INSERT`
i znacznik `weekly_digest_sent_at` idą w JEDNEJ transakcji
(`OdbiorcyDigestu::zarezerwuj()`), a `Mail::queue()` wykonuje się tylko wtedy,
gdy `INSERT` się udał. Konflikt unikalności znaczy „ta osoba ma ten okres
obsłużony" i wtedy po prostu ją pomijamy — bez błędu i bez listu.

Obie warstwy są potrzebne i mówią różne rzeczy:

- `weekly_digest_sends` → **najwyżej jeden list na tydzień kalendarzowy**,
  bez luki między odczytem a zapisem (czyli także przy dwóch przebiegach
  równolegle — RACE-04);
- `users.weekly_digest_sent_at` → **nie częściej niż raz na siedem dni**
  plus kolejność „kto czeka najdłużej"; sam tydzień kalendarzowy pozwoliłby
  na list w niedzielę i w poniedziałek.

Kalendarzowy tydzień nikogo nie opóźnia: dzień `x` i dzień `x + 7` zawsze
mają różne poniedziałki, więc bariera nie blokuje wysyłki, na którą odstęp
już pozwala.

#### Dlaczego data poniedziałku, a nie numer tygodnia ISO

Numer tygodnia sam z siebie nie jest identyfikatorem: `2026-12-28` należy do
tygodnia 1 **roku 2027**, więc numer wymagałby pary (rok ISO, tydzień) —
a klucz idempotencji zapisany niepełny przestaje być unikalny. Data
poniedziałku to jedna kolumna `date`: porównywalna, sortowalna, czytelna
w zrzucie bazy i zgodna z tym, co w PostgreSQL znaczy `date_trunc('week', …)`.

Strefa nie jest ozdobą: poniedziałek UTC zaczyna się w Polsce w niedzielę
o 22:00, więc przebieg uruchomiony w poniedziałek nad ranem trafiałby do
tygodnia poprzedniego. Stąd `Czas`, nie `now()`.

CHECK „to musi być poniedziałek" pilnuje, żeby klucz nadal ZNACZYŁ tydzień.
Data ze środka tygodnia dałaby tej samej osobie dwa różne, oba wolne klucze
w jednym tygodniu — czyli dwa listy przy nietkniętym `UNIQUE`.

#### Co się dzieje, gdy rezerwacja się udała, a wysyłka padła

**Rezerwacja zostaje i ta osoba nie dostaje listu za ten tydzień.** To jest
wybrana strona pomyłki, nie przeoczenie: po wyjściu z `Mail::queue()` nie da
się odróżnić „wiadomość nie weszła do kolejki" od „weszła, a proces padł
sekundę później", więc nie można mieć naraz „nikt nie dostanie dwa razy"
i „nikt nie zostanie pominięty". Uzasadnienie wyboru: **D-077**.

#### Czego tu świadomie nie ma

Treści listu, adresu, liczników, śladu doręczenia. Wiersz mówi wyłącznie
„ta osoba ma ten tydzień obsłużony" — ta sama klasa faktu co
`users.weekly_digest_sent_at`, więc bez nowej kategorii danych osobowych.
Nie ma też retencji ani komendy sprzątającej: przy przepustowości 420 osób
tygodniowo (D-057) to około 22 tysiące wierszy po dwóch kolumnach na rok.

**Rollback.** `down()` kasuje tabelę. Nie ginie ani jedno słowo od człowieka
i nie ginie pamięć o wysyłce (`users.weekly_digest_sent_at` zostaje) — ale
**ginie bariera**, a to trzeba nazwać wprost: po wycofaniu jedyną ochroną
przed drugim listem zostaje porównanie w PHP, czyli dokładnie ten mechanizm,
którego luka jest powodem tej migracji. Dlatego rollback robi się
**wyłącznie razem z `KUKING_DIGEST_WLACZONY=false`**, nigdy „przy okazji".
Kolejność: **najpierw kod, potem migracja** — nowy kod bez tabeli pada na
pierwszej osobie i nie wysyła nikomu nic (kierunek awarii bezpieczny, ale
wysyłka staje).

Pilnuje tego `tests/Feature/DigestNieWysylaDwaRazyTest.php` (awaria w połowie
przebiegu, bariera bez znacznika odstępu, kontrola dodatnia, następny
tydzień, brak zgody, oba ograniczenia bazy osobno).

### push_subscriptions + ustawienia_powiadomien_zewnetrznych

Web Push i ustawienia kanałów POZA serwisem (issue #35, **D-303**). Migracja
`2026_09_26_100000_utworz_powiadomienia_push`. Powiadomień w serwisie
(`notifications`, lista pod dzwonkiem) te tabele nie dotyczą i nie mają
dotyczyć — AGENTS.md §1.

**`push_subscriptions`** — jedna przeglądarka, której człowiek sam, kliknięciem
na `/ustawienia/powiadomienia`, pozwolił pokazywać powiadomienia. Wiersz
powstaje wyłącznie przez `ZapiszSubskrypcjePush` (model ma puste `$fillable`).

| Kolumna | Uwagi |
|---|---|
| `user_id` | Czyje urządzenie. `cascadeOnDelete` — druga linia: kont się nie kasuje, tylko anonimizuje (D-022), więc wiersze kasuje jawnie `EraseAccountData`. |
| `endpoint` | Adres usługi push przydzielony przeglądarce (Google FCM, Mozilla, Apple, Windows). **Poświadczenie**: kto ma go razem z kluczami, może pisać na ten ekran — dlatego nie wychodzi w paczce RODO ani w logach. `CHECK` wymaga `https://` i długości ≤ 2048; host musi być na liście `kuking.push.dozwolone_hosty` (sprawdza PHP — lista bywa uzupełniana bez migracji; bez niej serwer wysyłałby POST pod dowolny adres, czyli SSRF). **Unikalny globalnie** (`UNIQUE (md5(endpoint))` — indeks na haszu, bo adresy bywają dłuższe niż limit wpisu B-drzewa): jedna przeglądarka = jeden wiersz, niezależnie od konta; po zmianie konta na wspólnym komputerze wiersz przechodzi na nowe konto. |
| `klucz_p256dh` | Klucz publiczny przeglądarki (P-256, base64url) do szyfrowania treści. Usługa push przenosi treść, ale jej nie czyta. |
| `klucz_auth` | Sekret uwierzytelniania szyfrowania od przeglądarki (base64url). Ukryty w serializacji modelu (`$hidden`). |
| `kodowanie` | `aes128gcm` (RFC 8291, domyślne) albo `aesgcm` (starsze przeglądarki) — `CHECK`. |
| `created_at` | Kiedy włączono push na tym urządzeniu. **Push nie niesie niczego starszego** niż najstarsza subskrypcja konta — włączenie nie wysyła zaległości. |

**Kasowanie wiersza:** odpowiedź usługi push 404/410 (subskrypcja wygasła —
od razu, bez ponawiania), „Wyłącz na tym urządzeniu", „Wyłącz na wszystkich
urządzeniach", przekroczenie `push_maks_urzadzen` (znika najstarsze),
wymazanie konta.

**`ustawienia_powiadomien_zewnetrznych`** — cisza nocna i dzienny limit
WYBRANE przez człowieka. **Brak wiersza = wartości domyślne**
z `kuking.notifications.zewnetrzne` (21–8, 1 dziennie), więc zmiana domyślnych
nie wymaga przepisywania danych.

| Kolumna | Uwagi |
|---|---|
| `user_id` | Klucz główny i obcy do `users`, `cascadeOnDelete` (druga linia; wiersz kasuje `EraseAccountData`). |
| `cisza_od`, `cisza_do` | Pełne godziny 0–23 w strefie `kuking.strefa` (Europe/Warsaw) — `CHECK`. `od > do` przechodzi przez północ (21 → 8), równe = bez ciszy. W ciszy push jest ODKŁADANY do jej końca, nigdy kasowany. |
| `dzienny_limit` | Najwyżej tyle pushy na lokalną dobę, 1–10 (`CHECK`; formularz daje zamkniętą listę `limity_do_wyboru`). Nadmiar czeka do rana następnej doby i idzie JEDNYM pushem. |

**Rollback:** `down()` **odmawia**, gdy `ustawienia_powiadomien_zewnetrznych`
ma choć jeden wiersz (D-088): po ponownym `migrate` tabela wróciłaby pusta,
czyli z domyślnym 21–8 zamiast godzin wybranych przez człowieka. Komunikat
mówi, jak zrobić kopię i wyczyścić tabelę ręcznie. Same subskrypcje wycofania
nie blokują — ich utrata gasi push (człowiek dostaje MNIEJ, nie więcej),
a w serwisie nic nie ginie. Test: `UstawieniaPowiadomienTest`.

### mail_failures

Listy, które **nie wyszły i już nie wyjdą**, migracja
`2026_09_10_500000_create_mail_failures_table` (**D-062**, issue #234).
Do 10 września 2026 odmowa dostawcy kończyła się tak: trzy próby workera
(`--tries=3 --backoff=10,60,300`, czyli około sześciu minut), wiersz
w `failed_jobs` — i cisza. Adresat nie dowiadywał się nigdy, właściciel
tylko wtedy, gdy sam z siebie zajrzał w `php artisan queue:failed`. Kolejka
pusta, `/health` zielony: **awaria wyglądała identycznie jak sukces**,
a dotyczyło to potwierdzeń rejestracji, przypomnień hasła i logowania linkiem.

**Osobna tabela, nie `failed_jobs`.** Tamta trzyma wszystkie nieudane
zadania (zdjęcia, eksporty, analizy), nie ma miejsca na kategorię odmowy
(„wyczerpany limit" ≠ „zły adres"), znika przy `queue:retry`/`queue:flush`
oraz automatycznie po 30 dniach (`queue:prune-failed --hours=720`,
codziennie o 05:20 — decyzja właściciela z 25.09.2026, `docs/DECISIONS.md`,
sekcja „TOKEN W BAZIE LEŻY WYŁĄCZNIE JAKO SKRÓT”) i nie da się w niej
niczego odhaczyć. Ta tabela **nie dubluje** tamtej —
wskazuje na nią kolumną `failed_job_uuid`.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()`. |
| `failed_job_uuid` | Wskaźnik na `failed_jobs.uuid`, **UNIKALNY** (jedno przepadnięcie = jeden wiersz, także gdy zdarzenie `JobFailed` dojdzie dwa razy). **Bez klucza obcego świadomie:** `queue:retry` kasuje tamten wiersz, a ten ma zostać. `NULL` przy wysyłce bez kolejki (tryb `sync`, konsola, testy). |
| `powod` | Kategoria z `App\Poczta\PowodOdmowy`: `limit_dobowy` \| `przejsciowa` \| `trwala` \| `nieznana`, CHECK `mail_failures_powod_check`. Ustala ją transport w chwili odmowy, bo tylko on widzi kod HTTP dostawcy. |
| `status_http` | Kod odpowiedzi dostawcy, CHECK `mail_failures_status_http_check` (100–599). `NULL`, gdy nie odpowiedział w ogóle (zerwane połączenie). |
| `rodzaj` | `displayName` z payloadu, czyli **klasa powiadomienia** (`App\Notifications\PotwierdzenieAdresu`). To ona mówi, CO przepadło. |
| `kolejka` | `high` \| `default` \| `low`. |
| `prob` | Ile prób wykonał worker (na produkcji 3, w trybie `sync` 1), CHECK `mail_failures_prob_check`. |
| `user_id` | **KTO CZEKAŁ NA LIST**, `nullOnDelete()`. Najważniejsza kolumna dla właściciela: w grupie 50+ osoba bez potwierdzenia nie napisze reklamacji, tylko odejdzie. Ustalane „best effort" z payloadu — `NULL` jest poprawnym wynikiem. Wymazanie konta (`EraseAccountData`) jawnie ustawia `NULL` — kaskada klucza nie zadziała, bo kont się nie kasuje (D-022; audyt B5 pkt 9). |
| `komunikat` | Powód po redakcji (`App\Poczta\BezpiecznyKomunikat`): jedna linia, bez adresów e-mail, przycięta. |
| `failed_at` | Kiedy list przepadł. Zapisane wprost, nie jako `created_at` — wiersz opisuje zdarzenie, nie encję (stąd brak `timestampsTz()`). |
| `zauwazony_at` | „Właściciel to przeczytał" (`kuking:nieudane-listy --odhacz`). Dopóki `NULL`, `/health` zgłasza `degraded`. Jedyna kolumna, którą się tu aktualizuje. CHECK `mail_failures_zauwazony_po_awarii_check`: nie może być wcześniejsze niż `failed_at`. |

**Czego tu świadomie NIE MA: adresu odbiorcy, tematu ani treści listu.**
Wszystko to jest w payloadzie zadania, który pokazuje `php artisan
queue:failed`; druga kopia adresu w bazie to druga rzecz do skasowania przy
żądaniu RODO (AGENTS.md §7). Nie ma też kolumny „powiadomiono właściciela" —
alarmu pocztą o awarii poczty świadomie nie wysyłamy (D-062 §3).

Indeksy (oba **częściowe**, bo oba zapytania i tak filtrują):
`mail_failures_nieodhaczone_idx (failed_at DESC) WHERE zauwazony_at IS NULL`
— jedyne zapytanie chodzące w żądaniu HTTP (`/health`), oraz
`mail_failures_adresat_idx (user_id, rodzaj, failed_at DESC) WHERE user_id IS
NOT NULL` — pod ekran „Potwierdź adres e-mail".

**Retencja:** `kuking.poczta.retencja_dni` (domyślnie 90) i **tylko dla
wierszy ODHACZONYCH** — sprząta je `App\Poczta\ZapiszNieudanyList` przy
okazji zapisu następnej awarii, bez osobnego zadania w harmonogramie (jedno
`DELETE` na zdarzenie, które w zdrowym tygodniu nie zachodzi ani razu).
**Nieodhaczonych nie kasuje nic i nigdy**: to jedyne miejsce, w którym
istnieje wiedza o tym, że komuś nie doszedł list, a wiek jej nie unieważnia.
Wiersz nie niesie danych osobowych, więc nie jest to termin z RODO, tylko
higiena.

**Rollback:** `php artisan migrate:rollback --step=1` — `down()`
**ODMAWIA**, jeśli w tabeli leży choć jeden nieodhaczony wiersz, i mówi, co
zrobić (przeczytać, odhaczyć, powtórzyć). Powód: skasowanie tabeli razem
z taką informacją byłoby powtórzeniem dokładnie tej usterki, którą ta
migracja naprawia. Wiersze odhaczone giną razem z tabelą i to jest
w porządku — właściciel je przeczytał, a `failed_jobs` i panel dostawcy
zostają. **Kolejność wycofywania: NAJPIERW KOD, POTEM MIGRACJA** — inaczej
`/health`, `kuking:nieudane-listy` i słuchacz kolejki stoją przy
nieistniejącej tabeli (sonda zgłasza wtedy `slad_listow_niesprawdzalny`,
a słuchacz zapisuje porażkę do dziennika i milczy dalej, żeby nie zabrać
`failed_jobs` ostatniego zapisu).

### przypomnienia_dobowe

Znaczniki „ten list już dziś wyszedł", migracja
`2026_09_24_140000_utworz_przypomnienia_dobowe` (issue #1333). Do niej
`kuking:pilnuj-terminow-odwolan` obiecywał jeden list na dobę tylko
w komentarzu: każde wywołanie z zaległym odwołaniem (ręczne ponowienie,
restart, zdublowany harmonogram) kolejkowało kolejny. `withoutOverlapping()`
chroni tylko przed przebiegami NARAZ, a `Cache::add()` nie wystarcza, bo
`docker/entrypoint.sh` czyści cache przy każdym starcie kontenera.

| Kolumna | Opis |
|---|---|
| `rodzaj varchar(64) NOT NULL` | Rodzaj listu (`termin-odwolania`). CHECK `przypomnienia_dobowe_rodzaj_niepusty_check`: niepusty. |
| `doba date NOT NULL` | Doba **UTC** — ta sama co dobowy sufit poczty (`DziennyBudzetListow`). |
| `odbiorca char(64) NOT NULL` | SHA-256 adresu (małe litery, bez spacji), **nie adres**. CHECK `przypomnienia_dobowe_odbiorca_sha256_check`: `^[0-9a-f]{64}$`. Nowy adres alarmowy = nowy klucz = list tego samego dnia. |
| `created_at timestamptz NOT NULL DEFAULT now()` | Kiedy zarezerwowano. |

**Klucz główny `(rodzaj, doba, odbiorca)` jest rezerwacją:**
`PrzypomnienieDobowe::zarezerwuj()` robi `insertOrIgnore` PRZED kolejkowaniem
listu, więc z dwóch równoległych przebiegów wysyła tylko ten, który wiersz
wstawił. Nieudane wstawienie listu do kolejki usuwa wiersz (`zwolnij()`)
i komenda kończy się błędem — kolejny przebieg tego samego dnia próbuje
ponownie. Wiersze starsze niż 30 dni kasuje `zarezerwuj()` przy okazji.
Pilnuje tego `tests/Feature/TerminOdwolaniaJedenListNaDobeTest.php`.

**Rollback:** `php artisan migrate:rollback --step=1` zrzuca tabelę **bez
odmowy** — wiersz jest znacznikiem deduplikacji, nie decyzją człowieka
(D-088 nie dotyczy). Kosztem jest najwyżej jeden powtórzony list tego dnia.
**Kolejność wycofywania: NAJPIERW KOD, POTEM MIGRACJA** — kod z tej zmiany bez
tabeli kończy komendę błędem i nie wysyła przypomnienia.
