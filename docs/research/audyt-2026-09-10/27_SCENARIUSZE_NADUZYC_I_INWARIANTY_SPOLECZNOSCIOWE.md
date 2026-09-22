# Kuking.pl — audyt scenariuszy nadużyć i inwariantów społecznościowych

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026

## Werdykt

Warstwa blokad jest dobrze uwzględniona przy **odczycie** — Policy i query scopes często traktują block jako bezwarunkową granicę. Trzecia warstwa pokazuje jednak, że ten sam inwariant nie jest atomowy przy **zapisie**. Celowo ścigając dwa requesty można doprowadzić do równoczesnego istnienia `block` i `follow` między tą samą parą kont.

To jest P1 Trust & Safety, bo dotyczy mechanizmu, którego użytkownik używa właśnie po to, aby zerwać relację z drugą osobą.

## SOCIAL-01 — race `FollowUser` ↔ `BlockUser` może odtworzyć follow po blokadzie — P1

### Kod

`app/Domain/Social/Actions/FollowUser.php`:

1. sprawdza `hasBlockRelationWith()`;
2. sprawdza `isFollowing()`;
3. wykonuje `following()->attach()`;
4. wysyła powiadomienie o obserwowaniu.

Nie ma wspólnej transakcji/locka obejmującego sprawdzenie i insert.

`app/Domain/Social/Actions/BlockUser.php` wykonuje w transakcji:

- `Block::firstOrCreate(...)`;
- usuwa follows w obu kierunkach;
- zapisuje audit log.

Problem: obie akcje nie blokują tej samej pary użytkowników.

### Dopuszczony interleaving

```text
FOLLOW: sprawdza -> brak blokady
FOLLOW: sprawdza -> brak follow
BLOCK: INSERT block
BLOCK: DELETE follows A->B i B->A
BLOCK: COMMIT
FOLLOW: INSERT follow A->B
FOLLOW: dispatch follow notification
```

Stan końcowy: w bazie istnieje **blokada i follow jednocześnie**.

### Dlaczego to jest security/trust, a nie tylko „double click”

Zablokowane konto może celowo ścigać żądania. Skutek może obejmować:

- powiadomienie o follow po wykonaniu blokady;
- ukrytą relację follow, która może zacząć wpływać na produkt po późniejszym unblock lub przy zmianie query;
- złamanie kontraktu `UnblockUser`, który celowo nie przywraca relacji obserwowania;
- trudniejszą analizę incydentu, bo DB zawiera sprzeczne stany.

### Naprawa

Obie operacje muszą serializować się po tej samej parze kont.

Najprościej bez nowej infrastruktury:

1. rozpocząć transakcję;
2. zablokować oba rekordy `users` w **deterministycznej kolejności UUID** (`FOR UPDATE`);
3. ponownie sprawdzić block/follow;
4. wykonać zmianę;
5. powiadomienie dispatchować po commit.

Alternatywa: PostgreSQL advisory lock z kanonicznym kluczem pary. Nie potrzeba Redis.

### Test zamykający

Dwa prawdziwe połączenia PG, zsynchronizowane barrierą. Powtarzać race wiele razy. Dozwolone stany końcowe:

- follow bez block;
- block bez follow.

**Niedozwolone:** block + follow jednocześnie.

## SOCIAL-02 — dwa równoległe follow mogą dać UNIQUE exception/500 — P2

Tabela `follows` ma złożony primary key, co jest poprawną ochroną integralności. `FollowUser` stosuje jednak `isFollowing() → attach()`. Dwa requesty mogą oba zobaczyć false; drugi insert przegrywa na PK.

Nie ma utraty danych, ale użytkownik może zobaczyć 500 po podwójnym kliknięciu / wolnej sieci.

**Naprawa:** po wdrożeniu pair lock z SOCIAL-01 problem znika. Dodatkowo insert można obsłużyć idempotentnie (`insertOrIgnore`/catch unique), ale nie może to zastąpić ponownego sprawdzenia blokady pod lockiem.

## SOCIAL-03 — block jest poprawnie respektowany w wielu Policy — pozytywne

Komentarze, kolekcje, wpisy/przepisy i inne powierzchnie wielokrotnie sprawdzają relację blokady w obie strony. To dobra zasada i nie należy jej osłabiać, aby „naprawić” race. Naprawa ma być w zapisie, nie w obchodzeniu odczytu.

## SOCIAL-04 — `UnblockUser` celowo nie odtwarza follow — pozytywne, ale wymaga spójnego stanu

`UnblockUser` usuwa tylko block. Komentarz kodu słusznie wyjaśnia, że automatyczne ponowne obserwowanie byłoby niespodzianką prywatności. Tym bardziej DB nie może zawierać follow, który przetrwał race pod blokadą.

## ABUSE-01 — Turnstile i rate limiting tworzą warstwy, ale provider fail-open wymaga alarmu — P2

Przy awarii Cloudflare formularz może przejść na samych limiterach. To świadoma decyzja dostępności. Abuse runbook powinien jednak mieć sygnał „Turnstile unresolved rate” i możliwość szybkiego zaostrzenia konkretnych publicznych endpointów bez deployu, jeśli trwa atak.

## ABUSE-02 — media authorization kosztuje atakującego niewiele, bazę dużo — P1/P2 odsyłacz

Publiczna trasa `/zdjecia/{media}/{wariant}` musi obsługiwać duży legalny ruch. Dla znanego UUID pojedynczy request może generować kilka query parent lookup. To wzmacnia znaczenie MEDIA-03: rate limit nie może być niski, więc koszt serwerowy jednego poprawnego żądania powinien być możliwie mały.

## Macierz inwariantów do testów concurrency

| Inwariant | Niedozwolony stan |
|---|---|
| block vs follow | jednocześnie block + follow w dowolnym kierunku |
| pending email cancel | anulowana zmiana e-mail kończy się mimo resetu hasła |
| login link | 500/inna odpowiedź tylko dla istniejącego konta |
| media cleanup | przypięta treść wskazuje na skasowane media |
| digest | ten sam user + period zakolejkowany więcej niż raz |
| export | dwa aktywne eksporty tego samego użytkownika |
| mail budget | licznik przekracza limit wskutek równoległych rezerwacji |

## Priorytet

SOCIAL-01 zamknąć przed publiczną betą. W społeczności osób 50–75 funkcja „Zablokuj” musi być absolutnie przewidywalna; nie może zależeć od kolejności dwóch requestów w milisekundach.
