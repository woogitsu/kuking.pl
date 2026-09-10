# Kuking.pl — komplet audytu 2026-09-10

Repozytorium: `woogitsu/kuking.pl`

## Najpierw przeczytaj

1. `28_RAPORT_KONCOWY_TRZECIA_WARSTWA.md` — **aktualny raport nadrzędny**; scala bramki startowe oraz nowe inwarianty mediów, autoryzacji, social, integracji i rollbacków.
2. `21_RAPORT_KONCOWY_DRUGA_WARSTWA.md` — współbieżność auth, idempotencja, poczta i lifecycle danych.
3. `14_RAPORT_KONCOWY_PRIORYTETY_I_BRAMKI.md` — raport końcowy pierwszej warstwy.
4. `00_PLAN_DZIALANIA.md` — pierwotny zakres i metodologia.
5. `99_ZRODLA_METODOLOGIA_I_OGRANICZENIA.md` — źródła zewnętrzne, snapshoty i granice dowodu.

## Pierwsza warstwa — audyty przekrojowe

- `01_ARCHITEKTURA_I_JAKOSC_KODU.md`
- `02_BEZPIECZENSTWO_APLIKACJI.md`
- `03_BAZA_DANYCH_I_INTEGRALNOSC.md`
- `04_WYDAJNOSC_SKALOWALNOSC_MEDIA.md`
- `05_TESTY_CI_CD_RELEASE.md`
- `06_UX_UI_DOSTEPNOSC_50_75.md`
- `07_PRODUKT_ONBOARDING_COLD_START_GARNEK.md`
- `08_SEO_PWA_UDOSTEPNIANIE.md`
- `09_MODERACJA_TRUST_SAFETY.md`
- `10_RODO_DSA_PRAWO_I_PRYWATNOSC.md`
- `11_INFRASTRUKTURA_BACKUPY_MONITORING.md`
- `12_ZALEZNOSCI_SUPPLY_CHAIN.md`
- `13_TRESCI_COPY_LOKALIZACJA_SPOJNOSC.md`
- `14_RAPORT_KONCOWY_PRIORYTETY_I_BRAMKI.md`

## Druga warstwa — awarie częściowe i współbieżność

- `15_AUTH_ODZYSKIWANIE_2FA_SESJE.md`
- `16_SCHEDULER_KOLEJKI_IDEMPOTENCJA.md`
- `17_POCZTA_DIGEST_DOSTARCZALNOSC.md`
- `18_LIFECYCLE_DANYCH_EXPORT_USUNIECIE.md`
- `19_FEED_SEARCH_WIDOCZNOSC_QUERY_CORRECTNESS.md`
- `20_RACE_CONDITIONS_AWARIE_CZESCIOWE.md`
- `21_RAPORT_KONCOWY_DRUGA_WARSTWA.md`

## Trzecia warstwa — uprawnienia, media, infrastrukturalne inwarianty

- `22_AUTORYZACJA_IDOR_I_ZAKRES_UPRAWNIEN.md`
- `23_MEDIA_UPLOAD_STORAGE_I_AWARIE.md`
- `24_CACHE_SESJE_RATE_LIMITING.md`
- `25_INTEGRACJE_ZEWNETRZNE_TURNSTILE_CLOUDFLARE_R2.md`
- `26_MIGRACJE_ROLLBACK_I_BEZPIECZNE_WDROZENIA.md`
- `27_SCENARIUSZE_NADUZYC_I_INWARIANTY_SPOLECZNOSCIOWE.md`
- `28_RAPORT_KONCOWY_TRZECIA_WARSTWA.md`
- `29_DELTA_MAIN_I_KOREKTA_REPORTER_LIFECYCLE.md`

## Snapshoty

- pierwszy pełny przekrój: `cee15a56fa82985d852b2724a880e425cb83dd9d`;
- druga warstwa: `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`;
- po drugiej warstwie sprawdzono również `d2503edc49a005e48ea4c69688453c3069853207`;
- trzecia warstwa kodu: `fd164ad3a91185d1969a109fd692a269ad9710e3`;
- finalny delta-check `main`: `47dbb6cc9afb4a4000291d655cfc2b9061e6daf8`.

Repo było aktywnie rozwijane podczas audytu. Finalny compare `fd164ad3… → 47dbb6cc…` objął dwa commity. Nie zmieniły plików źródłowych odpowiedzialnych za nowe P1 trzeciej warstwy. Repo dodało natomiast `SPRAWDZENIE.md`, które słusznie skorygowało wcześniejszy P0 reporter lifecycle; korekta została niezależnie potwierdzona w kodzie i naniesiona na raporty 09, 10, 14 i 28.

## Najważniejsze nowe P1 po drugiej i trzeciej warstwie

Druga warstwa: race zmiany e-maila, race wystawiania magic-link, nieatomowy budżet poczty i brak idempotencji digestu.

Trzecia warstwa: race `media cleanup↔attach`, koszt autoryzacji zdjęć, race `block↔follow` oraz utrata `delete_scope` przy rollbacku.

## Werdykt startowy

- development/staging: **GO**;
- mała kontrolowana alpha: **CONDITIONAL GO** po najważniejszych bramkach storage/backup/mail/auth;
- publiczna beta: **NO-GO** do zamknięcia P0 + kodowych P1;
- szeroka kampania Garnek.pl: **NO-GO** dodatkowo do zbudowania realnego cold-startu i pomiaru retencji.

## Ważne korekty audytu

- nie używać starego założenia 384 MB workera: pomiar dla 50 MP wynosił ok. 452 MB RSS przy docelowym limicie 1024 MB;
- pusty wynik określonego konektora GitHub Actions nie dowodzi braku push-runów;
- `Schedule::call()` jest celowy przy wyłączonym `proc_open`;
- PostgreSQL-backed cache/session/queue nie są powodem do automatycznego wdrożenia Redisa;
- Turnstile fail-open przy awarii dostawcy jest świadomą decyzją availability i wymaga obserwowalności, nie automatycznego zakazu.
