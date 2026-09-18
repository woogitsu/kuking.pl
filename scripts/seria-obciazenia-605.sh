#!/usr/bin/env bash
# =============================================================================
#  Jedna seria pomiarowa #605 — bramka + generator + próbnik + werdykt
# =============================================================================
#
#  Po co osobny skrypt, skoro to kilka komend: bo te, o których najłatwiej
#  zapomnieć, są tutaj obowiązkowe.
#
#  Ta maszyna jest wspólnym hostem CI pięciu projektów (17 runnerów) i cisza
#  na niej nie nadejdzie przez czekanie — obciążenie jest strukturalne, nie
#  chwilowe. Dlatego seria jest BRAMKOWANA OBCIĄŻENIEM, a nie umawiana na
#  „ciche okno":
#
#    1. stopień startuje dopiero, gdy obce obciążenie utrzyma się pod progiem
#       przez pełne okno spokoju (`scripts/bramka-obciazenia-605.sh`),
#    2. obce obciążenie jest próbkowane PRZEZ CAŁY stopień, nie tylko na
#       początku i końcu,
#    3. stopień, w którym obce obciążenie przebiło próg, dostaje werdykt
#       SKAŻONY i nadaje się wyłącznie do powtórzenia — ale ZOSTAJE zapisany,
#       bo to jest dowód, że bramka działała, a nie że dobierano wyniki.
#
#  Skrypt niczego cudzego nie zatrzymuje ani nie usypia. Czyta /proc i czeka.
#  Serie uruchamia się POJEDYNCZO. Nigdy równolegle.
#
#  Użycie:
#      scripts/seria-obciazenia-605.sh <nazwa> <rps> <sekundy> <katalog> <manifest>
#
#  Progi bramki (zmienne środowiskowe, uzasadnienie w METODA.md §6):
#      PROG_RDZENI (18.0)  PROG_PSI (25.0)  SPOKOJ_S (60)  MAKS_CZEKANIA_S (900)
#
#  Kody wyjścia: 0 = seria zdjęta (werdykt w pliku), 2 = bramka nie doczekała.
# =============================================================================
set -eu

NAZWA="${1:?nazwa serii}"
RPS="${2:?rps}"
CZAS="${3:?czas w sekundach}"
KATALOG="${4:?katalog wyników}"
MANIFEST="${5:?manifest z sesjami}"
KONTENER="${KONTENER:-kuking-b605-app}"
BAZA="${BAZA_POMIARU:-kuking_b605_obciazenie}"
PROG_RDZENI="${PROG_RDZENI:-18.0}"
PROG_PSI="${PROG_PSI:-25.0}"
SPOKOJ_S="${SPOKOJ_S:-60}"
MAKS_CZEKANIA_S="${MAKS_CZEKANIA_S:-900}"

cd "$(dirname "$0")/.."
mkdir -p "$KATALOG"

OTOCZENIE="$KATALOG/otoczenie-$NAZWA.txt"
PROBNIK="$KATALOG/probnik-$NAZWA.jsonl"
WYNIK="$KATALOG/seria-$NAZWA.json"
WERDYKT="$KATALOG/werdykt-$NAZWA.json"

{
  echo "# Otoczenie serii $NAZWA — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "# progi bramki: obce <= $PROG_RDZENI rdzeni, PSI cpu some avg10 <= $PROG_PSI, spokój ${SPOKOJ_S}s"
  echo
  echo '## uptime przed bramką'
  uptime
  echo
  echo '## procesy powyżej 2% CPU (cudze też — one są tu treścią, nie tłem)'
  ps -eo pid,pcpu,pmem,etime,comm --sort=-pcpu | awk 'NR==1 || $2+0>2' | head -25
  echo
  echo '## runnery CI pięciu projektów na tym hoście'
  ps -eo args | grep -c '[R]unner.Listener' | sed 's/^/Runner.Listener (zarejestrowane): /'
  ps -eo args | grep -c '[R]unner.Worker' | sed 's/^/Runner.Worker (aktywne joby): /'
  echo
  echo '## kontenery'
  docker ps --format '{{.Names}} {{.Image}} {{.Status}}'
} > "$OTOCZENIE"

echo "--- bramka: obce <= $PROG_RDZENI rdzeni i PSI <= $PROG_PSI przez ${SPOKOJ_S}s ---" >&2
if ! bash scripts/bramka-obciazenia-605.sh "$PROG_RDZENI" "$PROG_PSI" "$SPOKOJ_S" "$MAKS_CZEKANIA_S" 2>>"$OTOCZENIE"; then
  cat > "$WERDYKT" <<EOF
{
 "seria": "$NAZWA",
 "zadany_rps": $RPS,
 "werdykt": "NIEWYKONANY",
 "powod": "Bramka nie doczekała: obce obciążenie nie zeszło pod $PROG_RDZENI rdzeni przy PSI <= $PROG_PSI na ${SPOKOJ_S}s w ciągu ${MAKS_CZEKANIA_S}s. Szczegóły w otoczenie-$NAZWA.txt.",
 "prog_rdzeni": $PROG_RDZENI,
 "prog_psi": $PROG_PSI
}
EOF
  echo "seria $NAZWA: NIEWYKONANA — bramka nie doczekała" >&2
  exit 2
fi

{ echo; echo '## uptime po otwarciu bramki, tuż przed serią'; uptime; } >> "$OTOCZENIE"

bash scripts/probnik-obciazenia-605.sh "$PROBNIK" "$KONTENER" "$BAZA" 1 &
PID_PROBNIKA=$!
trap 'kill "$PID_PROBNIKA" 2>/dev/null || true' EXIT
sleep 2

OD="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
node scripts/generator-obciazenia-605.mjs seria \
  --manifest "$MANIFEST" --baza http://127.0.0.1:8605 \
  --nazwa "$NAZWA" --rps "$RPS" --czas "$CZAS" --wynik "$WYNIK" > /dev/null
DO="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# POWRÓT DO NORMY — osobne pytanie z #605 („czy i jak szybko system wraca do
# normy"), więc próbnik chodzi jeszcze 60 s PO zdjęciu obciążenia. Te próbki
# są w tym samym pliku; rozpoznasz je po `t` późniejszym niż `do` w werdykcie.
sleep 60
kill "$PID_PROBNIKA" 2>/dev/null || true
wait "$PID_PROBNIKA" 2>/dev/null || true
trap - EXIT

{ echo; echo '## uptime po serii i po 60 s wybiegu'; uptime; } >> "$OTOCZENIE"

# ---------------------------------------------------------------------------
#  WERDYKT. Reguła skażenia jest MOJA i stoi tu jawnie, żeby dało się ją
#  zakwestionować:
#
#    skażony = przebicia w ponad 3 % próbek stopnia
#              ALBO choć jedno przebicie trwające 5 sekund z rzędu.
#
#  Pojedyncza sekunda ponad progiem w przebiegu 180-sekundowym to ziarnistość
#  pomiaru, nie cudza interferencja — gdyby dyskwalifikowała stopień, na tej
#  maszynie nie dałoby się zdjąć NICZEGO, a bramka produkowałaby wyłącznie
#  puste wyniki. Pięć sekund z rzędu albo 3 % przebiegu to już cudzy job,
#  który wystartował w środku.
# ---------------------------------------------------------------------------
python3 - "$PROBNIK" "$WERDYKT" "$NAZWA" "$RPS" "$OD" "$DO" "$PROG_RDZENI" "$PROG_PSI" <<'PY'
import json, sys
probnik, wyjscie, nazwa, rps, od, do, prog_rdzeni, prog_psi = sys.argv[1:9]
prog_rdzeni, prog_psi = float(prog_rdzeni), float(prog_psi)

pod_obciazeniem, wybieg = [], []
for linia in open(probnik, encoding='utf-8'):
    linia = linia.strip()
    if not linia:
        continue
    p = json.loads(linia)
    (pod_obciazeniem if od <= p['t'] <= do else wybieg).append(p)

def przebicie(p):
    # Trzeci warunek jest TWARDY i nie ma dla niego marginesu: jeżeli w trakcie
    # stopnia ruszył runner `kuking`, to nasz własny push wywołał CI w środku
    # pomiaru. To jedyna część hałasu, na którą mamy wpływ, więc jej się nie
    # toleruje — w odróżnieniu od CI cudzych projektów, które jest tłem.
    return (p['rdzenie_obce'] > prog_rdzeni
            or p['psi_cpu_some_avg10'] > prog_psi
            or p.get('runnery_kuking_pracujace', 0) > 0)

flagi = [przebicie(p) for p in pod_obciazeniem]
n = len(flagi) or 1
ile = sum(flagi)
naj = biezaca = 0
for f in flagi:
    biezaca = biezaca + 1 if f else 0
    naj = max(naj, biezaca)

udzial = round(100 * ile / n, 1)
skazona = udzial > 3.0 or naj >= 5

def statystyka(probki, pole):
    if not probki:
        return None
    v = sorted(p[pole] for p in probki)
    return {
        'min': v[0],
        'mediana': v[len(v) // 2],
        'p95': v[min(len(v) - 1, int(0.95 * len(v)))],
        'max': v[-1],
    }

json.dump({
    'seria': nazwa,
    'zadany_rps': int(rps),
    'werdykt': 'SKAZONY' if skazona else 'CZYSTY',
    'regula_skazenia': 'przebicia w ponad 3% próbek albo przebicie trwające co najmniej 5 s z rzędu',
    'prog_rdzeni': prog_rdzeni,
    'prog_psi': prog_psi,
    'probek_pod_obciazeniem': len(pod_obciazeniem),
    'probek_z_przebiciem': ile,
    'udzial_przebic_procent': udzial,
    'najdluzsze_przebicie_s': naj,
    'obce_obciazenie_rdzenie': statystyka(pod_obciazeniem, 'rdzenie_obce'),
    'psi_cpu_some_avg10': statystyka(pod_obciazeniem, 'psi_cpu_some_avg10'),
    'probek_z_pracujacym_runnerem_kuking': sum(
        1 for p in pod_obciazeniem if p.get('runnery_kuking_pracujace', 0) > 0),
    'wlasne_stanowisko_rdzenie': statystyka(pod_obciazeniem, 'rdzenie_stanowiska'),
    'generator_rdzenie': statystyka(pod_obciazeniem, 'generator_rdzenie'),
    'okno_pod_obciazeniem': {'od': od, 'do': do},
    'probek_w_wybiegu_po_zdjeciu_obciazenia': len(wybieg),
}, open(wyjscie, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)

print(f"seria {nazwa}: {'SKAZONY' if skazona else 'CZYSTY'} "
      f"(przebicia {ile}/{n} = {udzial}%, najdłuższe {naj}s)")
PY

echo "seria $NAZWA: $WYNIK / $PROBNIK / $WERDYKT / $OTOCZENIE"
