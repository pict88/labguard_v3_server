#!/bin/bash

# ==========================================
# CONFIGURATION - CHANGE THESE VALUES EASILY
# ==========================================
DB_HOST="127.0.0.1"
DB_USER="exam"
DB_PASS="exam"
DB_NAME="Labguard"

ACTIVE_IPS_TXT="/tmp/labsentinel_active_ips.txt"
# ==========================================

for pid in $(pgrep -f "ping_monitor.sh"); do
    if [ $pid != $$ ]; then
        kill -9 $pid 2>/dev/null
    fi
done

check_ip() {
    local TARGET_IP=$1

    local STATUS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -N -B -e "SELECT status FROM internet WHERE ip='$TARGET_IP';" 2>/dev/null)

    if ping -c 1 -W 1 "$TARGET_IP" > /dev/null 2>&1; then
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "INSERT INTO internet (ip, status, time) VALUES ('$TARGET_IP', 'online', NOW()) ON DUPLICATE KEY UPDATE status='online', time=NOW();" 2>/dev/null
    else
        if [ "$STATUS" = "isolated" ]; then
            return
        elif [ "$STATUS" != "offline" ]; then
            mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -D "$DB_NAME" -e "INSERT INTO internet (ip, status, time) VALUES ('$TARGET_IP', 'offline', NOW()) ON DUPLICATE KEY UPDATE status='offline', time=NOW();" 2>/dev/null
        fi
    fi
}

while true; do
    if [ -f "$ACTIVE_IPS_TXT" ]; then
        while IFS= read -r ip; do
            [ -z "$ip" ] && continue
            check_ip "$ip" &
        done < "$ACTIVE_IPS_TXT"
        wait
    fi
    sleep 5
done
