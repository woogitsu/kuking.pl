# Źródła, metodologia i ograniczenia audytu Kuking.pl

**Data:** 10.09.2026

## Zakres źródeł

Audyt wykorzystał:

1. prywatne repozytorium GitHub `woogitsu/kuking.pl` przez konektor GitHub,
2. aktualny `main`, zamrożony do pełnego przekroju na `cee15a56fa82985d852b2724a880e425cb83dd9d`,
3. delta-review jednego późniejszego commita `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`,
4. otwarte issues i komentarze w repo,
5. dokumentację architektury, produktu, decyzji, legal i infrastruktury,
6. oficjalne źródła internetowe dla standardów, prawa i aktualnych wersji zależności.

## Najważniejsze źródła zewnętrzne

### RODO

- EUR-Lex, Rozporządzenie (UE) 2016/679: art. 7 (warunki zgody), art. 28 (procesorzy), art. 30 (rejestr czynności przetwarzania).
- https://eur-lex.europa.eu/eli/reg/2016/679/oj

### DSA / Polska — stan sprawdzony 10.09.2026

- UKE, 04.09.2026, komunikat o uchwaleniu przez Sejm ustawy wdrażającej DSA i roli Prezesa UKE jako koordynatora usług cyfrowych:
  https://www.uke.gov.pl/uslugi-cyfrowe/aktualnosci/sejm-uchwalil-ustawe-wdrazajaca-przepisy-aktu-o-uslugach-cyfrowych-prezes-uke-koordynatorem-ds-uslug-cyfrowych%2C26.html
- Prezydent RP, 09.01.2026, informacja o wecie wcześniejszej ustawy:
  https://www.prezydent.pl/aktualnosci/wydarzenia/prezydent-podpisal-osiem-ustaw-trzy-zawetowal%2C112911

W czasie audytu nie znalazłem oficjalnego źródła potwierdzającego, że nowa ustawa z września 2026 została już podpisana i ogłoszona. Status należy sprawdzić ponownie w dniu decyzji o starcie.

### WCAG / PWA

- W3C WCAG 2.2, kryterium 1.3.4 Orientation.
- https://www.w3.org/WAI/WCAG22/Understanding/orientation.html
- W3C Web App Manifest — `orientation`.
- https://www.w3.org/TR/appmanifest/

### SEO / sitemap

- Google Search Central — limity sitemap: do 50 000 URL oraz 50 MB nieskompresowanego pliku.
- https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap

### GitHub Actions / supply chain

- GitHub Docs — Secure use reference: pełny commit SHA jest jedynym immutable sposobem przypięcia akcji.
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions
- GitHub Docs — Dependabot configuration options (`ignore`, `update-types`).
- https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference

### Aktualność stacku

Sprawdzono w dniu audytu oficjalne rejestry/źródła:

- Packagist `laravel/framework`,
- npm `vite`,
- npm `playwright`.

Wniosek: zależności aplikacji są świeże; audyt nie wykazał problemu typu „projekt stoi na starej generacji frameworka”.

## Metodologia

Każde ustalenie było kwalifikowane jako:

- **błąd/ryzyko potwierdzone kodem**, jeśli wynika bezpośrednio z implementacji,
- **rozjazd dokumentacja–kod**, jeśli źródła w repo mówią różne rzeczy,
- **bramka operacyjna**, jeśli repo nie może dowieść konfiguracji panelu lub stanu usługi zewnętrznej,
- **ryzyko produktowe/UX**, gdy wynika z przepływu użytkownika, dokumentacji produktu lub realnego testu grupy docelowej,
- **aktualny fakt zewnętrzny**, jeśli został sprawdzony w sieci w dniu audytu.

Priorytety:

- P0 — blocker startu / utrata danych / poważne ryzyko prawne lub bezpieczeństwa,
- P1 — wysokie ryzyko głównej ścieżki,
- P2 — istotne, ale może poczekać po ograniczonej becie,
- P3 — porządek/usprawnienie.

## Ważne ograniczenia

Nie miałem bezpośredniego dostępu do paneli:

- Railway,
- Cloudflare,
- EmailLabs,
- zewnętrznego uptime monitora,
- umów/DPA poza repo,
- rzeczywistej bazy produkcyjnej.

Dlatego stwierdzenia typu „R2 jest niegotowe”, „monitor nie działa” lub „brak DPA” są formułowane ostrożnie: jeśli repo/issue mówi, że bramka jest otwarta, raport mówi **brak dowodu zamknięcia / wymaga weryfikacji**, a nie udaje odczytu panelu, którego nie wykonano.

## Ograniczenie dotyczące GitHub Actions

Konektorowy odczyt `fetch_commit_workflow_runs` użyty podczas audytu filtruje runy do zdarzeń `pull_request`. Pusty wynik nie pozwala więc wnioskować o braku runu wywołanego pushem. Pierwotne nadmierne wnioskowanie zostało skorygowane w raporcie 05 i raporcie końcowym.

## Ograniczenie dotyczące zmiany `main`

Repo było aktywnie rozwijane w trakcie audytu. Aby uniknąć niespójnego snapshotu:

- pełny audyt wykonano na `cee15a56`,
- po zakończeniu sprawdzono `main`,
- doszedł jeden commit `e3cf6ab5`,
- jego diff został przejrzany,
- wpływ na ustalenia został opisany w raportach 06 i 14.

## Zasada interpretacji

Najważniejsza zasada tego audytu:

> **„zaimplementowane” nie znaczy „działa na produkcji”, a „procedura istnieje” nie znaczy „odtworzenie zostało wykonane”.**

Ta różnica jest główną osią rekomendacji końcowej.
