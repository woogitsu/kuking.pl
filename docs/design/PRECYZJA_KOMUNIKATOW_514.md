# Precyzja komunikatów — #514

14 września 2026. Implementacja i opisane niżej kontrole lokalne zakończone. Pełny hook, CI i odbiór produkcji Alfa 0.21 pozostają do potwierdzenia.

## Potwierdzone problemy

- Instrukcje OAuth obiecują jedno kliknięcie mimo przekierowania i możliwego potwierdzenia u dostawcy. D-069 i D-098 opisują mechanizm, nie gwarantują liczby kroków.
- Powiadomienie o pierwszym wpisie zawiera tezę o typowej retencji bez dowodu; wcześniejszy audyt copy także ją wskazuje.
- Podsumowanie automatu zawsze mówi o ostatniej dobie, choć komenda obsługuje `--godzin`. Liczy również zgłoszenia rozpatrzone; zdanie o widoczności wszystkich treści i niewiedzy autorów nie opisuje pewnego aktualnego stanu.

## Zakres

Zmiana istniejących tekstów oraz przekazanie okresu z komendy do wiadomości. Bez zmian odbiorców, harmonogramu, zapytań moderacji, OAuth, formularzy, CSRF ani danych użytkowników. Zachowujemy prawdziwą instrukcję wypisania z listu tygodniowego.

Plan regresji obejmuje rzeczywiste rendery, aktywne i odłączone tożsamości, licznik okresu oraz stary zakolejkowany obiekt. Wyniki i kontrole ujemne zostaną dopisane po wykonaniu; ten dokument nie jest potwierdzeniem zakończenia testów.


## Podsumowanie moderacji — wykonane kontrole

﻿# Odbiór zakresu automatu #514

Zmiana: komenda przekazuje rzeczywisty znormalizowany okres do powiadomienia. Nowy konstruktor zachowuje trzyargumentowe wywołanie (24 godziny), a dawna serializacja bez nowej właściwości otrzymuje neutralny opis okresu. Temat brzmi „Kuking: nowe oznaczenia automatu (N)”. Treść opisuje działanie automatu bez zapewniania o obecnej widoczności ani wiedzy autorów. Zapytania, odbiorcy i kolejka pozostają bez zmian.

Fixture `tests/Fixtures/podsumowanie-automatu-ce82638.b64` zapisano przez serialize rzeczywistej klasy canonical przed zmianą źródła (PHP8.4/vendor native); zawiera trzy pierwotne pola prywatne, liczby2/3 i etykietę Sygnal kontrolny. Nie skonstruowano jej z nowej klasy.

## Zakończone kontrole

Native `/tmp/kuking-final-20260913`, baza `kuking_mail_test_20260913`, PostgreSQLport55439, APP_BASE_PATH jawny. Pint trzech plików PASS.

Przed korektą tematu: 23 testy /355 asercji PASS. Rodziny: PodsumowanieAutomatuPrecyzjaTest, StandardoweWiadomosciMarkiTest, ModeracjaModelemTest. Po korekcie tematu i nowej asercji: **23 testy /363 asercje PASS**, ponownie taki sam wynik po czwartym negatywie. Log końcowej sesji: `output/automat514-final.log`; helper z dokładnymi poleceniami `output/run514-final.py`.

Pierwsza sesja miała błąd fixture: kilka zgłoszeń automatu do jednego wpisu naruszało prawdziwy indeks unikalności. Poprawiono fixture na osobny wpis dla każdego zgłoszenia; nie zmieniono indeksu ani aplikacji. Początkowy BOM testu również usunięto przed wynikami powyżej.

## Rzeczywiste negatywy

Pierwsze trzy wykonane przed korektą tematu; wynik i MD5 potwierdzone wyjściem narzędzia w rozmowie, bez osobnego pełnego logu tej sesji. Helper `output/run514.py` zawiera wykonane operacje.

1. W prawdziwej klasie usunięto domyślne null nowego pola: stary payload oblał z „must not be accessed”. Przywrócony MD5 klasy `67634ddb77862332fce88b4b6e16a768`.
2. W prawdziwej komendzie pominięto przekazanie godzin: test oblał `OKRES_LISTU`. Przywrócony MD5 komendy `e2255af2a5f8164f700457a5333c0f1e`.
3. W prawdziwej klasie przywrócono fałszywe zapewnienie: test oblał `MECHANIZM_AUTOMATU`. MD5 klasy po przywróceniu jak w1.
4. Po korekcie tematu zastąpiono rzeczywiste użycie zmiennego okresu stałą „W ciągu ostatniej doby”: trzy testy oblały z `OKRES_LISTU`; pełny log w automat514-final.log. Przywrócony MD5 klasy **3697002380a80ff38e48a36f17709fa5**.

Każda mutacja: backup copy2 w `/tmp/514-negative-*.bak` poza repo, `finally` przywraca bajty i mtime, porównanie MD5/mtime oraz dodatni rerun. W czwartym negatywie dodatkowo dokładna równość bajtów. Źródła canonical synchronizowano wyłącznie przed mutacjami; mutacje dotyczyły native. Native zwolniono drugiemu agentowi po dodatnim przebiegu.

Brak pełnegoPHP, hooka, wysyłek, produkcyjnych operacji, commitów i pushowania. Dowód dotyczy wyłącznie wskazanych rodzin; nie stanowi dowodu wdrożenia #514.


## Logowanie i pierwszy wpis — wykonane kontrole

55 testów / 337 asercji: PASS po przywróceniu źródeł. Pint czterech plików: PASS.
Obejmują cztery kombinacje dostępności Google/Facebooka, starszy komponent
Google, instrukcje i własny e-mail, powiązane i niepowiązane konto,
odłączenie Facebooka, trzy rzeczywiste komunikaty kontrolera oraz FIRST_POST.

Jedenaście rzeczywistych negatywów przywracało starą obietnicę w każdym
zmienianym miejscu. Każdy oblał asercję treści; po każdym odtworzono kopię
poza repo z kontrolą MD5 i mtime_ns. Końcowy dodatni przebieg: PASS.
Lokalne pełne logi: output/copy514-final-evidence; oryginał
/tmp/copy514-complete-1didrq6w. Przywrócone pliki:

| Źródło | MD5 po odtworzeniu |
|---|---|
| `resources/views/components/wejscia-zewnetrzne.blade.php` | `9d208486db2ec70024398ebb3c385deb` |
| `resources/views/components/wejdz-google.blade.php` | `003bb47601ba64b1096433bc65744206` |
| `resources/views/auth/google-link.blade.php` | `2c1350dd90f305afc3b565bd73e25335` |
| `resources/views/auth/facebook-bez-adresu.blade.php` | `94a81b7c3b103affccbbef53db7e1fb2` |
| `resources/views/mail/proba-wejscia-kontem-facebooka.blade.php` | `f9fd6adad880042d2b603756105671c6` |
| `resources/views/pages/settings/security.blade.php` | `08dabf2ee5bf27754a8510aa3df57331` |
| `resources/views/pages/notifications.blade.php` | `c1737b8959f14a1da9b3af5be267291b` |
| `app/Http/Controllers/Auth/FacebookLoginController.php` | `fdc2509889f7826c0dd40be231c0f673` |

## Niezależny przegląd

Trzeci agent odczytał końcowy diff i nie zgłosił blokad: bez zmian OAuth,
CSRF i routingu, zgodność liczonego i wyświetlanego okresu, stary payload
odtwarzany bez zgadywania okresu. To review kodu, nie dodatkowy wynik testów.
Nowe testy nie wykonują pełnego transportu kolejki; jej mechanizm pozostaje
bez zmian.


## Dostępność i ogląd

Końcowy lokalny build Vite: PASS. `dostepnosc.mjs`: kod0, axe44/44,
układ49/49, zero naruszeń, overflow, rozjazdów i zasłoniętego fokusu.
Ogląd rzeczywistego zrzutu logowania320px potwierdził pełny nowy nagłówek
i instrukcję przekierowania w karcie. MailMessage48godzin wyrenderowano
bez wysyłki; pomiar320/768px nie wykazał overflow. Obejrzano zrzut320px:
pełny okres, liczniki, przycisk i opis automatu pozostają czytelne.

Są to rendery Chromium, nie test rzeczywistych klientów poczty. Lokalny
zapasowy adres CTA pochodzi z APP_URL środowiska testowego. Nie wysyłano
wiadomości ani nie zmieniano produkcyjnych danych. Wariant font200% axe
nie zastępuje rzeczywistego zoomu; ten osobny pomiar wykonuje port w CI.
