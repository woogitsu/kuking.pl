#!/usr/bin/env bash
# WYMAGA: WSL, katalogu /home/mateusz/flota i kolejka9.sh pod /mnt/c/...; poza tą maszyną nie zadziała.
# Wznowienie kolejki ODPIETE od sesji. Poprzedni przebieg zginal razem
# z powloka, zostawiajac osierocona bateria hooka w push-run.
set -uo pipefail
F=/home/mateusz/flota

# 1. Sprzatniecie sieroty po martwej kolejce.
for p in $(ps -eo pid,args | grep "[c]heck.sh --szybko" | awk '{print $1}'); do
  kill -TERM "$p" 2>/dev/null && echo "zatrzymano osierocona bateria $p"
done
sleep 3
for p in $(ps -eo pid,args | grep "[c]heck.sh --szybko" | awk '{print $1}'); do
  kill -9 "$p" 2>/dev/null && echo "dobito $p"
done

# 2. Czy cos jeszcze trzyma drzewo robocze.
ps -eo pid,args | grep -E "[p]hpunit.*push-run" | head -3

# 3. Start odpiety: setsid + nohup, zeby przezyl koniec tej powloki.
cd "$F" || exit 1
setsid nohup bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/kolejka9.sh </dev/null >/dev/null 2>&1 &
sleep 4
echo "--- procesy kolejki po starcie ---"
ps -eo pid,ppid,args | grep "[k]olejka9.sh" | head -3
