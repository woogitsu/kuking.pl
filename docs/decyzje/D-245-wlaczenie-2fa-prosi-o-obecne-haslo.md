## D-245 — Włączenie 2FA prosi o obecne hasło, jak jej wyłączenie (#1376, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (triaż nocny, P1) ·
Status: **obowiązuje**

### Co było

`POST /ustawienia/2fa/wlacz` (`TwoFactorSettingsController::confirm()`)
sprawdzał wyłącznie sześciocyfrowy kod z sekretu wygenerowanego chwilę
wcześniej na tym samym ekranie. Kod dowodzi, że **nowy** telefon jest dobrze
ustawiony — nie tego, że sesję obsługuje właściciel konta. Kto przejął ważną
sesję (30 dni), podpinał własny telefon, zabierał kody zapasowe, a właściciel
przy następnym logowaniu stawał przed kodem, którego nie ma. Wyłączenie 2FA
i nowe kody zapasowe o hasło już prosiły; włączenie — nie.

### Reguła

Włączenie 2FA wymaga **obecnego hasła do Kuking** obok kodu z aplikacji.
Zmiana telefonu idzie przez wyłączenie (hasło) i ponowne włączenie (hasło),
więc obejmuje ją ta sama reguła. Hasło jest sprawdzane **przed** kodem: przy
złym haśle kod nie jest ani weryfikowany, ani zużywany, a 2FA zostaje
wyłączona. Trasa dostaje oba limity: `two_factor` (kod) i `confirm_password`
(`Hash::check()` to ta sama wyrocznia co przy wyłączeniu, więc nie ma prawa
mieć luźniejszego limitu).

**Dlaczego pole hasła, a nie middleware `password.confirm` z Laravela.**
Repozytorium nigdzie go nie używa. Każda wrażliwa akcja w ustawieniach
(zmiana hasła i adresu, wyłączenie 2FA, nowe kody, usunięcie konta) prosi
o hasło w tym samym formularzu i sprawdza je `Hash::check()` pod limitem
`confirm_password`. Włączenie idzie tą samą drogą: jedno pole więcej na
ekranie, który człowiek i tak wypełnia, zamiast osobnego ekranu z własnym
oknem ważności.

### Konta bez własnego hasła (Google, #876)

Nie wymyślamy dla nich drugiej drogi: tak samo jak przy wyłączaniu, ekran
mówi, jak ustawić hasło do Kuking przez „Nie pamiętam hasła"
(`two_factor/_password-help`), i ostrzega, żeby nie wpisywać hasła do Google.
Świadomy koszt: dopóki serwis nie wysyła poczty (`Poczta::dziala()`), konto
założone wyłącznie przez Google nie włączy 2FA samo — ekran mówi to wprost
i kieruje do „Napisz do nas". Świeże ponowne logowanie przez Google jako
dowód tożsamości to osobna decyzja, tu niepodjęta.

### Włączenie 2FA gasi poświadczenia sprzed niego (#930)

Po udanym potwierdzeniu (dobre hasło **i** dobry kod) `confirm()` woła
istniejące `User::invalidateSessions()` z wyjątkiem bieżącej sesji — tą samą
drogą co zmiana hasła i „Wyloguj inne urządzenia" (#584). Znika więc każda
inna sesja `database`, rotuje `remember_token` (stare ciasteczka „zapamiętaj
mnie" przestają odtwarzać logowanie) i giną oczekujące linki do logowania.
Wszystkie te poświadczenia powstały bez drugiego składnika; zostawione,
otwierałyby konto bez kodu, a konto moderatora od tej chwili także `/admin`,
bo `moderator.2fa` sprawdza stan konta, nie przebieg logowania. Bieżąca sesja
zostaje, kody zapasowe są pokazane jak dotąd. Złe hasło albo zły kod kończą
się przed tą linią, więc niczego nie odwołują. Nowe ciasteczko pamiętania dla
bieżącej przeglądarki nie jest wystawiane — jak w #584.

📄 `app/Http/Controllers/Settings/TwoFactorSettingsController.php`,
`routes/web.php`, `resources/views/pages/settings/two_factor/enable.blade.php`,
`resources/views/pages/settings/two_factor/_password-help.blade.php`,
`tests/Feature/WlaczenieDwuetapowejWymagaHaslaTest.php`,
`tests/Feature/ZapamietaneLogowanieUniewaznienieTest.php`,
`docs/security/ZAPAMIETANE_LOGOWANIE_584.md`
