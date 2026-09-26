# Bezpieczeństwo Kuking.pl — jak zgłosić problem

Jeśli znajdziesz w Kuking lukę, przez którą ktoś może zobaczyć, zmienić albo
usunąć nie swoje dane, **napisz do nas prywatnie**. Nie zakładaj issue, nie
otwieraj PR-a i nie pisz o tym w komentarzu, dopóki nie wdrożymy poprawki.
Repozytorium jest prywatne, ale ma współpracowników i agentów AI. Opis luki
w issue trafia do każdego z nich, zanim powstanie poprawka.

## Gdzie pisać

**kontakt@kuking.pl** — w temacie wpisz „Bezpieczeństwo:” i jedno zdanie o problemie.

To ten sam adres, który podaje polityka prywatności
(`resources/legal/polityka-prywatnosci.md`). Nie ma osobnej skrzynki, bo
drugi adres bez właściciela, który go czyta, byłby gorszy niż jeden czytany.

Jeśli problem dotyczy **danych osobowych**, które już komuś wyciekły, dopisz
to w temacie. Takie zgłoszenie uruchamia inne terminy — patrz „Dla opiekunów” niżej.

## Co warto dołączyć

- adres strony albo trasę, na której to widać,
- kroki, po których problem da się powtórzyć,
- co udało się zobaczyć albo zmienić — na **własnym** koncie albo koncie
  testowym, nie na cudzym,
- datę i godzinę, żeby dało się to znaleźć w dzienniku serwera.

## Czego prosimy nie robić

- nie otwieraj, nie pobieraj i nie zmieniaj cudzych treści ponad to, co jest
  niezbędne, żeby pokazać problem — a jeśli do czegoś już zajrzałeś, nie zachowuj tego,
- nie testuj obciążenia ani odmowy usługi na `kuking.pl` — to serwis ludzi,
  którzy z niego korzystają, nie środowisko testowe,
- nie wysyłaj masowo listów, zgłoszeń ani zaproszeń przez formularze serwisu,
- nie próbuj socjotechniki na użytkownikach ani na nas.

## Co możesz od nas dostać

- odpowiedź człowieka, nie automatu,
- informację, czy potwierdziliśmy problem i kiedy poprawka trafiła na produkcję,
- wzmiankę w podziękowaniach, jeśli chcesz — albo pełną anonimowość, jeśli wolisz.

Nie mamy programu nagród za zgłoszenia. Nie podajemy tu też terminu odpowiedzi
w dniach — to jeszcze musi ustalić właściciel projektu. Wolimy nie obiecywać
terminu, którego nikt nie pilnuje.

## Zakres

Dotyczy serwisu `kuking.pl` razem z subdomenami, które obsługujemy,
i kodu w tym repozytorium. Nie dotyczy usług dostawców (Railway, Cloudflare,
EmailLabs, Google, Facebook) — lukę w nich zgłoś bezpośrednio u nich.
Jeśli przez ich usługę widać nasze dane, napisz także do nas.

---

## Dla opiekunów i agentów pracujących w repozytorium

- **Luki nie opisujemy w publicznym miejscu przed wdrożeniem poprawki.**
  Dotyczy to też tytułu i opisu PR-a. Opis PR-a mówi, *co* poprawiono, a nie
  *jak* tego nadużyć, dopóki poprawka nie jest na produkcji.
- **Bugfix = test regresyjny** (`AGENTS.md`), a dla strażnika bezpieczeństwa
  także kontrola ujemna: test, który czerwienieje po zdjęciu zabezpieczenia.
- **Wyciek danych osobowych** to naruszenie w rozumieniu art. 33 RODO.
  Administrator ma **72 godziny od stwierdzenia** na zgłoszenie do UODO.
  Decyzję o zgłoszeniu podejmuje właściciel projektu, nie agent. Zadanie agenta:
  ustalić fakty (co, od kiedy, ilu kont dotyczy) i zapisać je z datą.
- Zasady ochrony danych i autoryzacji: `AGENTS.md` §7. Ostatnie przeglądy:
  `docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md` i `docs/AUDYT_PELNY_2026_09_15.md`.
- W repozytorium **nie ma pliku CODEOWNERS** i to świadomy wybór.
  Przegląd SPEC-u odrzucił go razem z obowiązkowym ręcznym zatwierdzaniem
  (`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`, R4 §7). Zanim go dodasz,
  zmień najpierw tamtą decyzję.
