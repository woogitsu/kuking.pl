# Kuking.pl — plan audytu wielodyscyplinarnego

**Data:** 2026-09-10  
**Repozytorium:** `woogitsu/kuking.pl`  
**Gałąź:** `main`  
**Commit bazowy:** `cee15a56fa82985d852b2724a880e425cb83dd9d`

## Cel

Przeprowadzić audyt produktu i implementacji Kuking.pl jako społeczności kulinarnej dla osób 50+, ze szczególnym naciskiem na grupę 60–75 i migrację użytkowników z Garnek.pl. Każdy obszar kończy się osobnym raportem Markdown. Ustalenia istniejące już w repozytorium są rozdzielane od nowych, aby nie przedstawiać otwartych issue jako nowych odkryć.

## Kolejność

1. Architektura i jakość kodu.
2. Bezpieczeństwo aplikacyjne i abuse resistance.
3. Baza danych i integralność danych.
4. Wydajność, skalowalność i media.
5. Testy, CI/CD i jakość procesu wydawniczego.
6. UX/UI, dostępność i użyteczność 50+/60+.
7. Produkt, onboarding, cold start i migracja społeczności z Garnek.pl.
8. SEO, discoverability, PWA i udostępnianie.
9. Moderacja, bezpieczeństwo społeczności i procedury odwoławcze.
10. Prywatność, RODO, DSA, cookies i regulaminy.
11. Infrastruktura, backup, recovery, monitoring i operacje.
12. Zależności, supply chain i aktualizacje stacku.
13. Treści, copy, lokalizacja i spójność informacji.
14. Raport końcowy: priorytety P0–P3, bramki startowe i kolejność wdrożeń.

## Skala istotności

- **P0 / krytyczne:** blokuje publiczny start albo może spowodować utratę danych, przejęcie konta, naruszenie prawa lub poważny incydent bezpieczeństwa.
- **P1 / wysokie:** realnie obniża konwersję, retencję, dostępność lub niezawodność głównej ścieżki; naprawić przed szerokim ruchem.
- **P2 / średnie:** istotne, ale nie blokuje ograniczonej bety; zaplanować po P0/P1.
- **P3 / niskie:** usprawnienie, porządek techniczny lub obserwacja bez pilnej akcji.

## Zasada dowodowa

Każde ustalenie powinno wskazywać konkretny plik, ścieżkę, konfigurację, issue albo zachowanie. Jeżeli czegoś nie da się udowodnić z repozytorium (np. ustawienia panelu Cloudflare/Railway/EmailLabs), jest to oznaczone jako **bramka operacyjna do weryfikacji**, a nie jako pewny błąd kodu.


## Zmiana `main` w trakcie audytu

Pełny przekrój repozytorium został zamrożony na `cee15a56fa82985d852b2724a880e425cb83dd9d`. Przed raportem końcowym `main` przesunął się o jeden commit do `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`. Delta została osobno przejrzana; dotyczy wejścia/rejestracji, login-link, copy, kolorów stanów i testów. Ustalenie UX2 zostało zaktualizowane z P1 do P2; pozostałe raporty nie zostały przez tę deltę unieważnione.
