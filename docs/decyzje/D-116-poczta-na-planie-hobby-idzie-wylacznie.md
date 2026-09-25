## D-116 · Poczta na planie Hobby idzie wyłącznie przez API HTTPS — runbookowi nie wolno pokazywać SMTP jako drogi domyślnej

**Data:** 11 września 2026 · Status: **obowiązuje** · Incydent z 9 września

**Railway blokuje ruch SMTP na planach Free, Trial i Hobby.** Awaria jest CICHA:
zadanie wisi w `RUNNING` bez końca, w logach zero błędu, rejestracja się udaje,
a list nie dochodzi nigdzie.

`DEPLOYMENT_RUNBOOK.md` uczył w **trzech** miejscach konfiguracji SMTP — na planie
Hobby, który sam zaleca w KROK 0.2 i KROK 16. Zmiennych wariantu, który działa
(`MAIL_MAILER=emaillabs` + `EMAILLABS_APP_KEY` / `EMAILLABS_SECRET_KEY` /
`EMAILLABS_SMTP_ACCOUNT`), nie wymieniał wcale. Do tego `POCZTA_URUCHOMIENIE.md`
§6 „Rekomendacja" przeczyło §2A tego samego pliku — a §6 jest ostatnim rozdziałem,
więc czyta się go jak podsumowanie.

**Skutek odwrócenia tej decyzji:** serwis, w którym rejestracja się udaje, a listy
nie dochodzą. To nie jest hipoteza — to jest opis 9 września.

Zmienne SMTP zostają uśpione w `railway.ts` jako droga na plan Pro. **Każde
miejsce, które je wymienia, musi mówić, że na Hobby nie działają.**
