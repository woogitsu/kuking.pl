# Kolejka przeniesienia wariantów zabezpieczonego dowodu (#2437)

Ta procedura dotyczy wyłącznie **syntetycznego pomiaru i diagnostyki stanu**.
Nie otwieraj ani nie kopiuj materiału dowodowego w celu sprawdzenia kolejki.
Decyzję moderatora, blokadę konta i oryginał zdjęcia pozostaw bez zmian.

## Kontrakt

`ZabezpieczDowodCsam` zapisuje `media.status = secured`, rejestr
`zabezpieczenia_dowodow` i zadanie `PrzeniesPubliczneWariantyDowodu` w **tej
samej transakcji PostgreSQL**. `queue.default=database`, połączenie kolejki
identyczne z `DB_CONNECTION`, `after_commit=false`. Nie używaj tu
`afterCommit()`: po zatwierdzeniu stanu, a przed zapisem joba, mógłby nastąpić
restart albo błąd enqueue. Przy błędzie INSERT do `jobs` cała decyzja jest
wycofana. Worker na drugim połączeniu widzi stan i job dopiero po commicie.

Po udanym kopiowaniu do prywatnego dysku i usunięciu publicznych wariantów
worker stawia w `media.metadata` znacznik `warianty_dowodu_przeniesione_at`.
Pusty znacznik albo `null` nie oznacza zakończenia. Stary `r2_legacy` jest
publicznym bucketem, który może trzymać oryginał i warianty na jednym dysku:
gdy wariant tam pozostaje, job zgłasza błąd i **nie stawia znacznika**. Nie
usuwa oryginału ani nie przenosi samodzielnie historycznego zdjęcia między
bucketami. Wspólny dysk lokalny/prywatny nie jest przez samą nazwę uznawany
za publiczny.
Brak tego znacznika ponad 15 minut po `secured_at` zapala niekrytyczne
`/health` (`checks.kolejka.error=warianty_dowodu_zalegle`), nawet jeśli
`jobs` i `failed_jobs` są puste. Alarm zawiera tylko kod i ogólny komunikat,
bez UUID, adresu pliku, treści albo tokenu. Kod HTTP zostaje 200, aby Railway
nie restartował usługi z powodu zaległego workera.

## Rozróżnienie przyczyny

W izolowanym, uprawnionym dostępie do bazy sprawdź **liczby**, nie pobieraj
payloadu do dziennika ani komunikatora. Odczytaj UUID konkretnego wiersza
`media` z wewnętrznej sprawy moderacyjnej; nie umieszczaj go w alarmie.

```sql
SELECT d.secured_at, m.status,
       nullif(btrim(m.metadata ->> 'warianty_dowodu_przeniesione_at'), '')
           IS NOT NULL AS przeniesiono
FROM zabezpieczenia_dowodow d
JOIN media m ON m.id = d.target_id
WHERE d.target_type = 'media' AND d.target_id = :media_id;

SELECT count(*) FROM jobs
WHERE queue = 'media' AND payload LIKE '%' || :media_id || '%';

SELECT count(*) FROM failed_jobs
WHERE queue = 'media' AND payload LIKE '%' || :media_id || '%';
```

- **`jobs > 0`**: zlecenie istnieje. Sprawdź proces `media` i opóźnienie
  kolejki (`kuking:sprawdz-kolejke`); nie dokładaj drugiego joba.
- **`failed_jobs > 0`**: job istniał i zawiódł. Odczytaj jego klasę i
  bezpieczny opis przez `/admin/kolejka`; ponawiaj tylko ten jeden po
  usunięciu przyczyny, bez zbiorowego retry. Dla `r2_legacy` ten błąd może
  oznaczać, że wariant i oryginał nadal leżą w jednym publicznym buckecie;
  nie kasuj ich ręcznie bez osobnej bezpiecznej migracji i sprawdzenia kopii.
- **obie liczby zero, znacznik nieobecny**: nie ma trwałego zadania, choć stan
  dowodu istnieje (np. historyczny stan sprzed poprawki albo ręczne
  wyczyszczenie kolejki). Uprawniony operator po potwierdzeniu `secured`
  zleca **tylko** `PrzeniesPubliczneWariantyDowodu` z tym UUID. Job jest
  idempotentny: najpierw potwierdza kopię, potem usuwa wariant publiczny.
  Nie ponawiaj akcji moderatora: powieliłaby decyzję lub została odrzucona.

Po pojedynczym ponowieniu sprawdź, że znacznik się pojawił, publiczne
warianty zniknęły, oryginał i mapa prywatnych kopii zostały, a czyszczenie
CDN ma osobny job. Dawniej wydany podpisany URL i cache CDN mają własne
ograniczenia opisane w `docs/flota/CSAM_JEDNA_KARTKA.md`; sam znacznik nie
potwierdza wyczyszczenia CDN.

## Kontrola testowa

`tests/Dwa/ZabezpieczenieDowoduIKolejkaWJednejTransakcjiTest.php` używa
prawdziwej kolejki i dwóch backendów PostgreSQL 18. Wyzwalacz testowy odmawia
zapisu wyłącznie dla syntetycznego UUID. Test zakłada go przed akcją, a po
sprawdzeniu jawnie usuwa w bloku `finally`.
`scripts/kontrola-negatywna-2437.py` zamienia dispatch na `afterCommit()`;
oczekuje konkretnego błędu `CSAM_OUTBOX_JOB_IN_TRANSACTION`, odtwarza
dokładne bajty/mtime i ponownie wymaga zielonego testu.
