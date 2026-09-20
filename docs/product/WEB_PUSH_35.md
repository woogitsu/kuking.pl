# Web Push #35 — fundament limitów, kanał nieaktywny

Stan: 20 września 2026. Ten etap nie uruchamia Web Push i nie zbiera zgód
ani subskrypcji. Nie zmienia `NotifyUser`, powiadomień w aplikacji ani poczty.

## Decyzja właściciela

20 września 2026, w zadaniu `gpt/pwa-push`, właściciel wybrał:
**„Maksymalnie 1 zbiorczy push dziennie”** na osobę. Limit dotyczy lokalnej
doby. Cisza 21:00–08:00 pochodzi z `RETENTION_LOOPS.md` i issue #35.

## Wykonane

- `PushDeliveryWindow::nextAllowedAt()` przyjmuje moment i jawną strefę IANA.
  Oddaje ten sam moment w dzień albo najbliższą godzinę 08:00 w nocy,
  zawsze jako UTC. Liczy kalendarzowo, także w noc zmiany czasu.
- `PushDailyBudget::reserve()` odmawia w ciszy, na zamkniętym/usuniętym
  koncie oraz po wykorzystaniu doby. Konto i czas czyta pod blokadą.
  Klucz główny PostgreSQL dodatkowo chroni przed podwójną rezerwacją.
- Przy zmianie strefy poprzedni moment również jest przeliczany w nowej
  strefie. Zmiana nazwy daty podczas podróży nie daje dodatkowej rezerwacji
  w tej samej lokalnej dobie.
- Nocna odmowa nie zużywa limitu, nie usuwa ani nie oznacza powiadomień
  w serwisie jako przeczytane. Rezerwacja nie jest dowodem wysłania.

## Granica tego etapu

To część etapu 1, nie ukończone #35. Nie ma jeszcze trwałej kolejki
odkładającej zdarzenia, grupowania, ustawień typów, globalnego wyłącznika,
wyboru strefy przez człowieka, pytania o zgodę ani VAPID. Brak transportu
jest konstrukcyjny: nie ma providera, subskrypcji, joba wysyłającego ani
obsługi `push` w service workerze. Samo wdrożenie tych klas niczego nie wyśle.

Nie należy podłączać rezerwacji bezpośrednio do `NotifyUser`: zużyłoby to
limit na pojedyncze zdarzenie zamiast na gotowy zbiorczy komunikat.

## Warunki kolejnego etapu

1. Zapisać strefę odbiorcy i jawne preferencje z drogą w ustawieniach:
   typy oraz „wyłącz wszystkie”. Brak zgody/strefy ma blokować kanał.
2. Trwale kolejkować zdarzenia i grupować je w jedną paczkę; w ciszy
   ustawiać termin z `nextAllowedAt()`, nie usuwać zdarzenia. Po wyczerpaniu
   doby zachować paczkę na następny dozwolony dzień. Wysyłający ponownie
   sprawdza aktualne zgody, blokady, widoczność treści i ciszę.
3. Rezerwować budżet dopiero dla niepustej, gotowej paczki, tuż przed
   transportem. `false` nie znaczy „zdarzenie obsłużone”. Rezerwacja nie
   upoważnia do wysłania po przerwie procesu, kiedy zaczęła się cisza.
4. Nie zwalniać rezerwacji po niejednoznacznym wyniku sieci: trzeba najpierw
   rozstrzygnąć idempotencję i retry. Nie zakładać dokładnie jednokrotnego
   doręczenia przez zewnętrznego dostawcę.
5. Dołączyć retencję, eksport i anonimizację danych kanału oraz testy dwóch
   równoległych workerów, następnie subskrypcje/VAPID, obsługę błędów
   i odbiór fizycznych telefonów. Dopiero wtedy uruchomić kanał.

Nie rozstrzygnięto jeszcze godziny zbiorczej wysyłki dziennej: pierwszy
dopuszczalny moment zmniejsza opóźnienie, stała godzina zbiera więcej zdarzeń
kosztem czekania. Nie utrwalamy żadnego wariantu testem ani harmonogramem.

Schemat i wycofanie: `docs/DATABASE.md`, `push_daily_reservations`.
