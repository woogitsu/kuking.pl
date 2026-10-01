## D-264 — Zawieszone konto może zmienić hasło, wylogować inne urządzenia i przestawić 2FA (audyt B2-04, 25 września 2026)

**Data:** 25 września 2026 · **Decyzja zespołu** wynikająca z audytu B2
(bezpieczeństwo konta) · Status: **obowiązuje** · Uzupełnia D-253

### Co było
`EnsureAccountIsActive` odbijał podczas zawieszenia każdy zapis na ekranie
bezpieczeństwa i 2FA. Konto przejęte przez spamera bywa zawieszane właśnie
za to, co robił napastnik. Właściciel, który odzyskał dostęp resetem, nie mógł
zmienić hasła, wylogować „innych urządzeń” (napastnik zostawał w sesji) ani
wyłączyć albo przestawić 2FA aż do końca kary.

### Decyzja
Trasy `settings.security.password`, `settings.security.logout-others`,
`settings.two_factor.confirm`, `settings.two_factor.disable`
i `settings.two_factor.regenerate` są na liście
`DOZWOLONE_MIMO_ZAWIESZENIA`. Niczego nie publikują, a każda z nich prosi
o obecne hasło w kontrolerze. `OdzyskiwalneDane` dalej nie oddaje haseł
do sesji (`SekretyNieWracajaNaEkranTest`).

### Dowody
`tests/Feature/ZawieszonyZabezpieczaKontoTest.php` — z kontrolą dodatnią, że
komentarz dalej jest odbijany.

### Wycofanie
Usunąć pięć nazw tras z listy w `EnsureAccountIsActive`. Schemat bazy się nie
zmienia.
