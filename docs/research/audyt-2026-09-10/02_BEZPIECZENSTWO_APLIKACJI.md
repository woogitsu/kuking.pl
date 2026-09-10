# Audyt 2 — bezpieczeństwo aplikacji i odporność na nadużycia

**Repozytorium:** `woogitsu/kuking.pl`  
**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Warstwa bezpieczeństwa jest wyraźnie ponad przeciętną dla aplikacji MVP: CSP jest wymuszany z nonce, `unsafe-inline` i `unsafe-eval` zostały usunięte, CSRF nie jest globalnie obchodzony, role/status/e-mail nie są masowo przypisywalne, widoczność mediów przechodzi przez Policy, a limity zapytań są scentralizowane.

Najważniejsza niedomknięta granica jest infrastrukturalno-aplikacyjna: kod sam dokumentuje, że bezpośrednie wejście na origin Railway może ominąć założenie, na którym oparto normalizację `X-Forwarded-For`. Dodatkowo aplikacja ufa `X-Forwarded-Host`, ale nie ma aktywnej listy dozwolonych hostów. Obie rzeczy trzeba zamknąć przed szerokim ruchem.

**Ocena: 8/10 przy założeniu ruchu wyłącznie przez Cloudflare; 6,5/10, jeśli publiczny origin Railway jest osiągalny bez dodatkowej autoryzacji.**

## Co jest zrobione dobrze

- `ApplySecurityHeaders` wymusza CSP z nonce; brak `unsafe-inline` i `unsafe-eval`.
- `frame-ancestors 'none'`, `X-Frame-Options: DENY`, HSTS w produkcji, `nosniff`, Referrer-Policy i Permissions-Policy są ustawiane globalnie.
- `User::$fillable` nie zawiera `role`, `status`, `email`, `email_verified_at`.
- Trasy administracyjne mają osobne middleware moderatora i wymagania 2FA.
- Wyjątki CSRF są ograniczone do raportowania CSP i podpisanej ścieżki RFC 8058 wypisania z mailingu.
- Publiczne media nie są bezpośrednim obiektem R2 — aplikacja sprawdza dostęp do treści nadrzędnej przed przekierowaniem.
- Sweep repo nie znalazł surowego Blade `{!! ... !!}`, `eval`, `unserialize`, `shell_exec`, `exec`, `request()->all()` ani rozlanego surowego SQL.
- Kod świadomie ogranicza PII w logach.

## Ustalenia

### S1 — P0/P1 — bezpośredni origin Railway podważa ochronę `X-Forwarded-For`

**Dowód w kodzie:** komentarz klasy `App\Http\Middleware\NormalizeForwardedFor` wprost stwierdza, że żądanie idące bezpośrednio na `*.up.railway.app` może mieć łańcuch `X-Forwarded-For` złożony wyłącznie z wartości podanej przez klienta. Middleware nie potrafi odróżnić takiego ruchu od ruchu przechodzącego prawidłową ścieżką. W komentarzu wskazano jako brakującą granicę token krawędziowy `X-Kuking-Edge-Token`.

**Skutek:** jeśli publiczny origin jest dostępny, napastnik może zmieniać adres widziany przez aplikację i przez to omijać limity zależne od IP oraz fałszować `ip_hash` w audycie. To szczególnie istotne dla logowania, resetu hasła, rejestracji, formularzy publicznych i nadużyć przed uwierzytelnieniem.

**Priorytet:** P0 jako bramka startu, jeżeli origin Railway jest publicznie osiągalny i przyjmuje ruch bez sekretu/origin lock; P1, jeżeli platforma lub Cloudflare już blokują drogę boczną poza repozytorium.

**Naprawa:** wymusić jedną z granic, której klient nie może podrobić: Cloudflare Authenticated Origin Pulls / mTLS, prywatny ingress, Cloudflare Tunnel albo własny losowy sekret nagłówka dodawany na brzegu i sprawdzany przed zaufaniem nagłówkom proxy. Nie opierać ochrony na samej liczbie wpisów XFF.

### S2 — P1 — brak `TrustHosts` przy zaufanym `X-Forwarded-Host`

**Dowód:** `bootstrap/app.php` wywołuje `trustProxies(... HEADER_X_FORWARDED_HOST ...)`, a komentarz w tym samym pliku mówi wprost, że „właściwym zamknięciem tego jest middleware `TrustHosts`”. Wyszukiwanie repo nie znajduje aktywnej konfiguracji `TrustHosts` / `trustHosts`.

**Ryzyko:** Laravel wykorzystuje host żądania do budowy bezwzględnych URL-i podczas requestu. OWASP klasyfikuje brak walidacji hosta jako powierzchnię m.in. do password-reset poisoning i manipulowania redirectami. Nie twierdzę, że konkretny flow resetu Kuking jest dziś exploitable bez testu runtime — ale granica zaufania jest formalnie otwarta.

**Naprawa:** dodać `trustHosts` z jawną listą `kuking.pl`, `www.kuking.pl` (jeśli używane) oraz dokładnie tymi hostami technicznymi, które są wymagane przez healthcheck/preview. Osobno sprawdzić, czy `X-Forwarded-Host` jest w ogóle potrzebny; jeśli nie — usunąć go z bitmaski zaufanych nagłówków.

### S3 — P1 — brak twardego dowodu, że produkcyjny R2 spełnia model bezpieczeństwa

**Dowód:** otwarte issue #120. Kod ma rozdzielone buckety i trasę autoryzującą media, lecz repo samo wymaga realnego testu: oryginał 403/404 publicznie, wariant dostępny właściwą drogą, brak `incoming/` w publicznym buckecie, re-encoding i usunięty EXIF.

**Skutek:** bezpieczeństwo zdjęć obejmuje nie tylko kod. Jeden błędny przełącznik `r2.dev` lub domena podpięta do złego bucketu może ujawnić oryginały z GPS mimo poprawnych Policy w Laravelu.

**Naprawa:** nie otwierać publicznej produkcji przed przejściem realnej bramki #120 i zapisaniem wyniku z datą.

### S4 — P2 — CSP dopuszcza dowolny obraz po HTTPS

**Dowód:** `img-src 'self' data: blob: https:`.

**Ocena:** nie jest to bezpośrednia luka XSS; obrazów nie wolno traktować jak skryptów. Jest jednak szersze niż faktycznie potrzebny model „obrazy Kuking + Turnstile/zasoby własne” i może utrudnić wykrywanie niezamierzonego zewnętrznego trackingu lub przyszłego SSRF-like proxy pattern po stronie klienta.

**Naprawa:** po ustabilizowaniu domen storage zawęzić `img-src` do `'self'`, `data:`, `blob:` i konkretnych domen, o ile nie koliduje to z architekturą podpisanych adresów R2. To hardening, nie bramka startu.

## Zewnętrzna weryfikacja standardu

- Dokumentacja Laravela: framework domyślnie odpowiada niezależnie od wartości `Host`; dla środowisk, gdzie serwer WWW nie filtruje hostów, zalecane jest `TrustHosts`/`trustHosts`.
- OWASP WSTG: niezwalidowany `Host` może prowadzić m.in. do redirect/cache poisoning i password-reset poisoning.
- OWASP Forgot Password Cheat Sheet: adres resetu nie powinien być budowany na niezaufanym `Host`; host powinien być stały albo walidowany listą zaufanych domen.

## Testy, które należy dodać / wykonać

1. Request z `Host: attacker.invalid` i `X-Forwarded-Host: attacker.invalid` → 400/odrzucenie.
2. Request bezpośrednio na origin z arbitralnym `X-Forwarded-For` → odrzucenie przed throttlem.
3. Reset hasła/rejestracja/weryfikacja e-mail → link zawsze na kanonicznym `https://kuking.pl`, niezależnie od nagłówków żądania.
4. Realny test R2 z #120, w tym EXIF/GPS.

## Źródła zewnętrzne

- Laravel, „Configuring Trusted Hosts”: https://laravel.com/docs/12.x/requests#configuring-trusted-hosts (mechanizm pozostaje aktualny dla współczesnego bootstrapa Laravela).
- OWASP WSTG, Testing for Host Header Injection: https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/07-Input_Validation_Testing/17-Testing_for_Host_Header_Injection
- OWASP Forgot Password Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
