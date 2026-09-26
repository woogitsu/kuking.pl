## D-115 · Skala tekstu schodzi do 70% — bo ustawienie czytelności działające w jedną stronę jest ustawieniem połowicznym

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Zastępuje
**system-v3.1 D-111** w zakresie dolnej granicy · Status: **obowiązuje**

### Co się zmienia

CHECK na `users.text_scale` przechodzi z `BETWEEN 90 AND 140` na
`BETWEEN 70 AND 140`. Dochodzą trzy rozmiary: 90% „Trochę mniejszy" (16,2 px),
80% „Mały" (14,4 px), 70% „Bardzo mały" (12,6 px).

**Domyślna skala zostaje 100%, czyli `--text-body` = 18 px.** Nic jej nie rusza.

### Dlaczego to nie łamie zasady „tekst ≥ 18 px"

Zasada z `AGENTS.md` opisuje, **co człowiek widzi, zanim czegokolwiek dotknie**
— czyli domyślny wygląd serwisu. Niżej schodzi wyłącznie ten, kto sam tak
ustawi, i tylko na swoim koncie.

Kuking jest robiony dla grupy 50+, ale „dla 50+" nie znaczy „nieczytelny dla
reszty". Zgłosił to właściciel — trzydziestokilkulatek czytający własny
produkt, dla którego 18 px jest za duże.

### Stosunek do system-v3.1 D-111

Tamten wpis mówi: *„`90%` istnieje dla osób, którym 18 px jest za duże na małym
telefonie. Schodzi do 16.2 px, czyli nigdy poniżej progu, który dla tekstu
podstawowego jest powszechnie przyjęty."* **To zostaje prawdą o 90% i przestaje
być dolną granicą.** Decyzja właściciela jest późniejsza i wygrywa.

Czego ta zmiana NIE rusza: górnej granicy ani tezy z system-v3.1 D-111, że
układ trzymamy do 150%. Zmniejszanie tekstu nie zagraża układowi — zagraża mu
powiększanie, a tam nic się nie zmieniło.

### Cztery miejsca, które muszą się zgadzać

`kuking.text.scales`, `resources/css/tokens.css`, `resources/css/app.css`
(podgląd w ustawieniach) i CHECK w migracji. **Rozjazd między nimi już raz się
zdarzył i milczał:** 8 września konfiguracja oferowała 140, arkusz znał 150,
a CHECK nie pozwalał 150 powstać — skutkiem czego „Bardzo duży" zapisywał się
na koncie i NIE ROBIŁ NIC. Pilnuje tego `SkalaTekstuDzialaTest`, od 11 września
razem z podpisami (`kuking.text.scale_labels`).

### Rollback odmawia (D-088)

Zwężenie CHECK-a wymagałoby podniesienia skali kontom, które świadomie wybrały
mniejszą. Po `down()` prawie zawsze idzie kolejny `migrate`, CHECK wraca i nie
ma błędu do zauważenia — człowiek zobaczyłby większe litery i nie dowiedziałby
się dlaczego.

### Co musiałoby się stać, żeby to zmienić

Pomiar pokazałby, że ludzie ustawiają 70% przez pomyłkę i potem nie umieją
wrócić. Wtedy znika najmniejszy stopień, a nie całe ustawienie.
