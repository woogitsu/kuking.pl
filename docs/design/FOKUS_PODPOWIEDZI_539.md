# Fokus przycisków w podpowiedziach — #539

Status: poprawka i odbiór lokalny zakończone; CI i wdrożenie tego pakietu jeszcze niepotwierdzone.

W ciemnym motywie jednoczesne najechanie i fokus klawiatury ujawniły kontrast halo 2,622924:1 oraz zewnętrznego pierścienia 1,239146:1. Sam Tab bez najechania nie odtwarzał całego objawu. Nie zmieniono progów pomiaru.

`resources/css/app.css` nadaje wyłącznie `.notice .btn:focus-visible` halo 2 px w kolorze podpowiedzi i pierścień 3 px w kolorze jej tekstu. `scripts/kontrast-notice.mjs` sprawdza rzeczywiste Tab, hover, activeElement, focus-visible, nieprzezroczystość oraz kontrast obu krawędzi pierścienia.

## Wykonane kontrole

- Chromium 153.0.8010.12: 24 konfiguracje, 88 pełnych wierszy pomiarów PASS; szerokości 320/1440, oba motywy, tekst 100/140, oba rodzaje przycisków i zwykły link.
- Prawdziwy zoom 200%, CSS 320×500, tekst 140%: 4/4 próby PASS. Kontrast pierścienia z obu stron: 6,532761:1 w jasnym i 6,981961:1 w ciemnym motywie.
- Pięć kontroli ujemnych prawdziwego CSS z przebudową: trzy istniejące regresje tekstu i dwie przywracające dawny fokus. Nowe negatywy kończą się NOTICE_FOKUS_KONTRAST, a nie awarią środowiska. Kopie poza repo: `/tmp/kuking-notice-qHXtUC/`; odtworzono MD5 i mtime, następnie ponowiono dodatnio.
- Końcowy MD5 app.css: `1fb93e752c2ae474b7985e14878ae887`; końce linii LF.
- PanelSzerokiTelefonTest: 6 testów / 57 asercji PASS; składnia JavaScript i diff --check PASS.
- Niezależny odbiór po połączeniu z nawigacją #492: ciemny motyw, zoom 200%, tekst 140%, pełne Tab 14/14 PASS, także hover + focus-visible. Nie wysłano DELETE.

Użyto osobnej lokalnej bazy `kuking_port_focus539` na porcie 55439, własnych mediów i poczty array. Zrzuty reprezentatywnych prób obejrzano. Nie jest to potwierdzenie całej produkcji ani wszystkich klientów pocztowych.
