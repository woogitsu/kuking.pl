# Stary bucket `r2_legacy` — migracja bez kasowania i bramka przed czyszczeniem

> Stan: 26 września 2026. Powiązane: #617 (DR zdjęć R2), #120 (odbiór R2),
> #1031 (przenosiny nie przestawiają wiersza bez pliku),
> `DR_ZDJEC_R2.md`, `KOPIE_I_ODTWORZENIE.md` §8 pytanie 8.
>
> **Ten runbook niczego nie kasuje.** Czyszczenie starego bucketu jest
> osobną, jawną decyzją właściciela, podejmowaną dopiero po przejściu
> bramki z §4 — i nie ma tu do niej polecenia.

## 1. Dlaczego stary bucket jest dla części zdjęć JEDYNĄ kopią

Zdjęcia zapisane przed rozdzieleniem bucketów mają w bazie
`disk = 'r2_legacy'` (i zwykle `variants_disk = 'r2_legacy'`) — ustawiła to
migracja `2026_09_06_180000_point_existing_media_at_legacy_disk`. Ich pliki
leżą wyłącznie w starym, jednym buckecie (`AWS_LEGACY_BUCKET`):

- nowe buckety ich nie mają, dopóki nie przejdą przez `kuking:przenies-zdjecia`;
- **migawka kopii zdjęć ich nie obejmuje** — kopiuje `AWS_BUCKET`
  i `AWS_PUBLIC_BUCKET` (`DR_ZDJEC_R2.md` §5);
- R2 nie cofa poprawnego `DELETE` (`DR_ZDJEC_R2.md` §1).

Pomyłkowe wyczyszczenie, przemianowanie albo odebranie tokenu starego
bucketu to więc dla tych kont bezpowrotna utrata zdjęć
(`KOPIE_I_ODTWORZENIE.md`, scenariusz c3).

## 2. Pomiar — ile zdjęć zależy od starego bucketu

Tylko odczyt, bez zgody na nic (same `SELECT` i `exists()`):

```bash
railway ssh -- php artisan kuking:zaleznosc-od-starego-bucketu
railway ssh -- php artisan kuking:zaleznosc-od-starego-bucketu --pliki   # też pliki gotowych zdjęć
```

| Wynik | Znaczenie |
|---|---|
| kod `0`, „Żaden wiersz media nie wskazuje starego bucketu” | pierwszy warunek bramki z §4 spełniony — **tylko pierwszy** |
| kod `1`, „STARY BUCKET JEST JEDYNĄ KOPIĄ N …” | nie ruszaj starego bucketu; przejdź §3 |
| kod `1`, „NIE SERWUJĄ SIĘ” | `AWS_LEGACY_BUCKET` jest puste, a wiersze na niego wskazują — te zdjęcia dziś się nie wyświetlają; ustaw zmienną (`DEPLOYMENT_RUNBOOK.md` §2.1) |
| `UTRACONE media <id>` przy `--pliki` | wiersz wskazuje stary bucket, a pliku tam nie ma — **nie ma go nigdzie** |

Raport podaje identyfikatory wierszy, nie klucze obiektów ani komunikaty
wyjątków (#973). Liczba bez `--pliki` nie mówi, czy pliki istnieją —
komenda pisze to wprost.

**Wynik wpisz w §5 z datą.** To zastępuje pytanie 8 z
`KOPIE_I_ODTWORZENIE.md` (`tinker` na produkcji).

## 3. Migracja — kopiuj, sprawdź, dopiero potem przestaw

Kolejność jest treścią (`PrzeniesZdjeciaDoNowychBucketow`): plik trafia do
nowego bucketu, komenda SPRAWDZA, że tam jest, i dopiero wtedy zmienia
wiersz. **Oryginałów w starym buckecie nie kasuje.**

1. **Migawka starego bucketu, zanim cokolwiek ruszysz.** To jedyny moment,
   w którym nie ma innej kopii. Ten sam wzorzec co `DR_ZDJEC_R2.md` §5 —
   `copy`, nigdy `sync`, token zapisu kopii poza aplikacją:

   ```bash
   # zrodlo_stary: token TYLKO DO ODCZYTU AWS_LEGACY_BUCKET
   # kopia:        token zapisu kuking-zdjecia-kopia (§3 DR_ZDJEC_R2.md)
   rclone copy "zrodlo_stary:<AWS_LEGACY_BUCKET>" \
     "kopia:kuking-zdjecia-kopia/stary-bucket-$(date -u +%F)/" --immutable --checksum
   ```

   `[do potwierdzenia w dokumentacji rclone]` — jak w `DR_ZDJEC_R2.md` §5.
   Ten katalog ma inny układ niż katalogi `migawka-*` (z podkatalogami
   `oryginaly` i `warianty`), więc `kuking:sprawdz-kopie-zdjec` go nie
   sprawdzi. Porównaj liczbę i łączny
   rozmiar obiektów (`rclone size`) ze źródłem i zapisz w §5.
   Kopia podlega tej samej regule lifecycle co migawki (31 dni,
   `DR_ZDJEC_R2.md` §2): zdjęcie usunięte przez człowieka nie może żyć
   w kopii bez końca.

2. **Próba na sucho:**
   `railway ssh -- php artisan kuking:przenies-zdjecia --dry-run --limit=50`
   — nic nie kopiuje i nie zmienia; pokazuje, co by się stało.

3. **Przenosiny partiami** (`--limit`, domyślnie 200). Każdy przebieg
   wypisuje „Następna partia: `--po=<uuid>`” — podaj ją w kolejnym.
   Przebieg bez `--po` zaczyna od początku i zbiera wiersze pominięte
   wcześniej (brak pliku zostawia wiersz przy `r2_legacy` — #1031).

4. **Sprawdzenie po przenosinach:**
   `railway ssh -- php artisan kuking:sprawdz-zdjecia-po-przenosinach`
   — wiersze przestawione, których pliku nie ma w nowym buckecie
   (DO ODZYSKANIA ze starego / UTRACONE). Ma dać zero obu.

5. **Pomiar z §2 jeszcze raz**, aż da kod `0`.

## 4. Bramka przed jakąkolwiek zmianą starego bucketu

Wszystkie punkty, każdy z datą w §5. Brak jednego = bramka zamknięta.

| # | Warunek | Czym sprawdzić |
|---|---|---|
| 1 | żaden wiersz nie wskazuje starego bucketu | `kuking:zaleznosc-od-starego-bucketu` → kod `0` |
| 2 | żaden przestawiony wiersz nie wskazuje pliku, którego nie ma | `kuking:sprawdz-zdjecia-po-przenosinach` → UTRACONE 0, DO ODZYSKANIA 0 |
| 3 | nowe buckety są w migawce kopii i migawka jest sprawdzona | `kuking:sprawdz-kopie-zdjec --prefiks=migawka-…/` → kod `0` (`DR_ZDJEC_R2.md` §6) |
| 4 | istnieje migawka STAREGO bucketu z §3 krok 1, zgodna co do liczby i rozmiaru | `rclone size` źródła i kopii |
| 5 | minęło co najmniej jedno pełne okno retencji migawki (31 dni) od punktu 1 | data |

Dopiero po tej bramce właściciel decyduje — osobnym wpisem w
`docs/DECISIONS.md` — co ze starym bucketem zrobić. Pierwszy, odwracalny
krok to zdjęcie z niego domeny publicznej (#120), nie kasowanie obiektów.

**Usunięcie danych przez człowieka działa w każdej fazie:** `KasujZdjecie`
kasuje także kopię ze starego bucketu (`dyskiDoWyczyszczenia()`), więc
przeniesione zdjęcie usunięte przez autora nie zostaje w `r2_legacy`.

## 5. Wynik — do wypełnienia przez właściciela

| Data | Krok | Wynik | Kto |
|---|---|---|---|
| | §2 pomiar (liczba wierszy, `--pliki`: utracone) | | |
| | §3.1 migawka starego bucketu (obiektów / bajtów: źródło = kopia?) | | |
| | §3.3 przenosiny zakończone (ostatni przebieg: „Nie ma zdjęć do przeniesienia”) | | |
| | §3.4 sprawdzenie po przenosinach | | |
| | §4 bramka — wszystkie punkty | | |
