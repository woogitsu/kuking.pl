#!/usr/bin/env bash
echo "=== uslugi systemd runnerow ==="
systemctl list-units --type=service --all --no-pager --plain 2>/dev/null | grep -i "actions.runner" | awk '{print $1, $3, $4}'
echo
echo "=== ktore procesy Runner.Listener zyja ==="
ps -eo pid,etime,args | grep "[R]unner.Listener" | sed 's/ run --startuptype service//' | awk '{print $1, $2, $NF}'
echo
echo "=== ktore AKTUALNIE wykonuja zadanie (Runner.Worker) ==="
ps -eo args | grep "[R]unner.Worker" | grep -oE "actions-runner-[a-z0-9-]+" | sort | uniq -c
