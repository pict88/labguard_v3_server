#!/bin/bash

# ==========================================
# CONFIGURATION - CHANGE THESE VALUES EASILY
# ==========================================
# Uncomment the line that matches your current operating system:

# For Kali Linux (Root User):
BASE_DIR="/root/Desktop/SERVER"

# For Fedora Linux (Standard User 'pict'):
# BASE_DIR="/home/pict/Desktop/SERVER"

ACTIVE_IPS_TXT="/tmp/labsentinel_active_ips.txt"
# ==========================================

case "$1" in
    start)
        echo "[>] Starting mysql server"
        sudo systemctl start mysql
 
        # 1. Initialize Database, User, and Tables
        echo "[>] Verifying MySQL Database..."
        sudo mysql -e "
            CREATE DATABASE IF NOT EXISTS Labguard;
            CREATE USER IF NOT EXISTS 'exam'@'localhost' IDENTIFIED BY 'exam';
            GRANT ALL PRIVILEGES ON Labguard.* TO 'exam'@'localhost';
            FLUSH PRIVILEGES;
            
            USE Labguard;
            CREATE TABLE IF NOT EXISTS internet ( ip VARCHAR(50) PRIMARY KEY, status VARCHAR(20), time DATETIME );
            CREATE TABLE IF NOT EXISTS usb ( ip VARCHAR(50) PRIMARY KEY, status VARCHAR(20), device VARCHAR(100), time DATETIME );
        "
        
        # 2. Kill existing processes and clear the setup state
        pkill -f "php -S 0.0.0.0:8000"
        pkill -f "ping_monitor.sh"
        rm -f "$ACTIVE_IPS_TXT" 2>/dev/null

        # 3. Start the PHP server
        nohup php -S 0.0.0.0:8000 -t "$BASE_DIR" > /dev/null 2>&1 &
        echo "[>] PHP Web Server started on http://localhost:8000"
        echo ""
        echo "[+] Labguard is LIVE. Open http://localhost:8000 to configure active labs."
        ;;
        
    stop)
        echo "[>] Stopping Labguard Exam Environment..."
        
        pkill -f "php -S 0.0.0.0:8000"
        pkill -9 -f "ping_monitor.sh"
        
        rm -f "$ACTIVE_IPS_TXT" 2>/dev/null
        
        echo "[+] Labguard processes have stopped."
        ;;
        
    *)
        echo "Usage: ./labguard.sh {start|stop}"
        exit 1
        ;;
esac
