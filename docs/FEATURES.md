# MVP / V1 / V2

## MVP

### Konto i profil
- email + hasło;
- reset hasła;
- weryfikacja;
- username;
- display name;
- avatar;
- bio;
- większy tekst;
- archiwum;
- eksport;
- usunięcie konta.

### Social
- follow;
- unfollow;
- block;
- chronologiczny feed;
- komentarze;
- odpowiedzi;
- in-app notifications;
- „Podziel się” — wysłanie publicznego przepisu albo wpisu poza serwis:
  arkusz systemowy na telefonie (`navigator.share`), a pod spodem jawna
  lista WhatsApp / e-mail / Facebook plus adres do skopiowania. Przycisk
  stoi wyłącznie przy treści widocznej dla kogoś bez konta. Messengera jako
  osobnego linku nie ma — wymaga własnej aplikacji na Facebooku
  (`docs/DECISIONS.md`, D-044).

Wspomnienia: na stronie głównej jeden własny wpis z tego samego dnia sprzed
roku lub więcej, z cichym podpisem („Rok temu, 6 września"). Blok pojawia się
tylko wtedy, gdy jest co pokazać. Całość wyłącza jeden przełącznik
w `/ustawienia/prywatnosc`, a pojedyncze wspomnienie chowa przycisk przy nim.

Urodziny (#1755): opcjonalny dzień i miesiąc bez roku w `/ustawienia/urodziny`.
W dniu urodzin (strefa Europe/Warsaw, 29.02 → 28.02 w latach nieprzestępnych)
na `/home` jedno zdanie z życzeniami od gospodarza, z wyłącznikiem przy dacie.

### Wpis
- zdjęcie lub kilka zdjęć;
- tekst;
- widoczność;
- edycja;
- usunięcie.

### Przepis
Kreator 3 kroków:
1. o przepisie;
2. składniki;
3. przygotowanie.

Autosave szkicu.

Pole źródła (`recipes.source_url`) — „skąd jest ten przepis". Opcjonalne.

Tryb gotowania: `/przepisy/{przepis}/gotuj`, wielkie kroki na cały ekran,
odhaczanie kroków, minutnik kroku. Ekran nie gaśnie (Wake Lock, z degradacją
tam, gdzie przeglądarka go nie ma).

Przy odhaczonych krokach można wybrać „Zacznij od początku”. Dopiero
potwierdzenie usuwa odhaczenia bieżącego przepisu; wyjście i anulowanie
zachowują postęp. Inne przepisy oraz historia wykonań pozostają bez zmian (#903).

### Ugotowałem
- zdjęcie;
- uwaga;
- would make again;
- actual time;
- perceived difficulty.

### Kolekcje
- zapisz;
- własne foldery.

### Search
- ludzie;
- przepisy;
- fraza/składnik;
- proste filtry.

### Trust
- report;
- block;
- moderator panel;
- audit;
- rate limits.

### Web
- publiczne profile;
- publiczne przepisy;
- Recipe schema;
- sitemap;
- PWA.

---

## V1

- grupy / fotofora;
- Moja wersja — fork przepisu;
- planner;
- lista zakupów;
- rodzinna książka;
- Q&A;
- Web Push;
- wyzwania społecznościowe.

## V2

- OCR starych zeszytów;
- import URL/PDF/zdjęcie;
- pantry;
- „co ugotuję z tego, co mam”;
- zamienniki;
- skalowanie porcji;
- nutrition;
- koszt;
- native apps, jeśli PWA potwierdzi retencję.

## Nie wcześnie

- DM;
- live chat;
- live video;
- marketplace;
- payouts;
- punkty za liczbę postów;
- masowy import cudzych treści.

Wysoki koszt moderacji i spam nie są potrzebne do udowodnienia wartości Kuking.
