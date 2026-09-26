## D-011 · Deploy odłożony, praca idzie w kodzie

**Data:** 5 września 2026 · **Decyzja właściciela** ·
Status: **NIEAKTUALNE — serwis JEST na produkcji (zmierzone 7 września 2026)**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ramka ostrzegawcza mówi,
> że `docs/infra/DEPLOYMENT_RUNBOOK.md` §2.3 i §7 „dalej KAŻĄ" utworzyć
> `cdn.kuking.pl` z regułą „Cache Everything". To zostało naprawione:
> `docs/infra/DEPLOYMENT_RUNBOOK.md:227-247` niesie wycofanie rozdziału i
> zdanie „**Zamiast tego kroku: nic.**", stara instrukcja zjechała do bloku
> opisanego jako „do czytania, NIE do wykonywania", a
> `tests/Feature/RunbookNieKazeTworzycDomenyZdjecTest.php` pilnuje tego
> maszynowo. Zostawione bez adnotacji ostrzeżenie szkodzi dokładnie tak, jak
> ten wpis to opisuje: następna osoba „naprawi" rzecz już naprawioną. Sam
> status NIEAKTUALNE potwierdzony 20 września 2026 — `GET
> https://kuking.pl/health` odpowiada 200.

> **UWAGA, TA DECYZJA JUŻ NIE OPISUJE RZECZYWISTOŚCI.** `https://kuking.pl`
> odpowiada HTTP/2 200 z `server: cloudflare` i pełnym zestawem nagłówków
> bezpieczeństwa tej aplikacji (własne CSP z nonce, `kuking-session`).
> Zgłoszenia właściciela z 6 września — ciemny motyw na telefonie i strona
> „Za dużo prób" przy dodawaniu zdjęcia — pochodzą więc z produkcji, nie
> z lokalnego środowiska.
>
> **Dlaczego to jest zapisane, a nie po prostu skasowane:** ta nieaktualność
> ma konsekwencje. Agent analizujący storage oparł na niej wniosek, że
> `cdn.kuking.pl` „na pewno jeszcze nie istnieje", a `docs/infra/
> DEPLOYMENT_RUNBOOK.md` §2.3 i §7 dalej KAŻĄ tę domenę utworzyć razem
> z regułą „Cache Everything" na 30 dni — czyli odtworzyć lukę zamkniętą
> przez D-020. Patrz ostrzeżenie dopisane w runbooku.
>
> Zmierzone przy okazji: `cdn.kuking.pl` dziś **nie odpowiada** (tak samo jak
> nieistniejący `www.kuking.pl`), więc luka z issue #120 najprawdopodobniej
> nie jest otwarta — ale potwierdzić to musi właściciel z panelu Cloudflare,
> bo pomiar z kontenera roboczego nie odróżnia „host nie istnieje" od
> „proxy nie przepuściło".
>
> Właściciel powinien zamknąć albo przepisać tę decyzję i issue #3.

Pierwszy deploy (#3) czeka. Praca skupia się na funkcjach, które nie wymagają
produkcji.

**Zablokowane przez tę decyzję:** #3 (deploy), #33 (Sentry, PostHog, uptime),
#9 (restore drill), #7 w części dotyczącej realnego ruchu, #15 (testy
z użytkownikami — potrzebują strony pod adresem).

**Zmiana wymaga:** decyzji właściciela o założeniu kont.

📄 `infra/DEPLOYMENT_RUNBOOK.md` · issue #3
