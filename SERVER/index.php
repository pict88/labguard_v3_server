<?php
// LabSentinel Monitor - Integrated PHP & MySQL Dashboard

// ==========================================
// CONFIGURATION - CHANGE THESE VALUES EASILY
// ==========================================
// For Kali Linux (Root User):
$BASE_DIR = "/root/Desktop/SERVER"; 

// For Fedora Linux (Standard User 'pict'):
// $BASE_DIR = "/home/pict/Desktop/SERVER"; 

$LOG_FILE_PATH   = $BASE_DIR . "/Logs.log";
$PING_SCRIPT     = $BASE_DIR . "/ping_monitor.sh";
$ACTIVE_IPS_TXT  = "/tmp/labsentinel_active_ips.txt";
$ACTIVE_LABS_TXT = "/tmp/labsentinel_active_labs.json"; 

$DB_HOST = '127.0.0.1'; // Leave as 127.0.0.1 for Fedora socket compatibility
$DB_USER = 'exam';
$DB_PASS = 'exam';
$DB_NAME = 'Labguard';

// HARDCODED LAB INFRASTRUCTURE (Subnet, Starting IP, Total PCs)
$LAB_CONFIG = [
    "A1-102"  => ["subnet" => "192.168.4", "start" => 2,   "count" => 20],
    "A1-202"  => ["subnet" => "192.168.4", "start" => 22,  "count" => 20],
    "A1-203"  => ["subnet" => "192.168.4", "start" => 42,  "count" => 20],
    "A1-204"  => ["subnet" => "192.168.4", "start" => 62,  "count" => 20],
    "A1-208"  => ["subnet" => "192.168.4", "start" => 82,  "count" => 20],
    "A1-209"  => ["subnet" => "192.168.4", "start" => 102, "count" => 20],
    "A1-216"  => ["subnet" => "192.168.4", "start" => 122, "count" => 25],
    "A1-302A" => ["subnet" => "192.168.4", "start" => 147, "count" => 20],
    "A1-302B" => ["subnet" => "192.168.4", "start" => 167, "count" => 20],
    "A1-303"  => ["subnet" => "192.168.4", "start" => 187, "count" => 20],
    "A1-306"  => ["subnet" => "192.168.4", "start" => 207, "count" => 20],
    "A1-307"  => ["subnet" => "192.168.4", "start" => 227, "count" => 20],
    "A1-314"  => ["subnet" => "192.168.5", "start" => 2,   "count" => 25], 
    "A2-302"  => ["subnet" => "192.168.5", "start" => 27,  "count" => 20],
    "A2-303"  => ["subnet" => "192.168.5", "start" => 47,  "count" => 20]
];
// ==========================================

// Parse session data
$session_name = "Real-time Telemetry Monitor";
$active_labs_array = [];
if (file_exists($ACTIVE_LABS_TXT)) {
    $saved_labs = json_decode(file_get_contents($ACTIVE_LABS_TXT), true);
    if (isset($saved_labs['session_name'])) {
        $session_name = $saved_labs['session_name'];
        $active_labs_array = $saved_labs['labs'];
    } else if (is_array($saved_labs)) {
        $active_labs_array = $saved_labs;
    }
}

function getLabName($ip, $config) {
    if (!$ip) return "Unknown Lab";
    $ip_num = ip2long(explode('/', $ip)[0]);
    foreach($config as $lab => $data) {
        $start_ip = ip2long($data['subnet'].".".$data['start']);
        $end_ip = ip2long($data['subnet'].".".($data['start'] + $data['count'] - 1));
        if ($ip_num >= $start_ip && $ip_num <= $end_ip) { return $lab; }
    }
    return "Unknown Lab";
}

// 1. API Endpoint: Status Polling
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    $conn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if (!$conn) { echo json_encode(['error' => mysqli_connect_error()]); exit; }
    $sql = "SELECT i.ip AS ip_address, i.status AS internet_status, i.time AS internet_time, COALESCE(u.device, 'None') AS usb_dev_id, COALESCE(u.status, 'idle') AS usb_status, COALESCE(u.time, i.time) AS usb_time FROM internet i LEFT JOIN usb u ON i.ip = u.ip";
    $result = @mysqli_query($conn, $sql);
    $workstations = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['lab_room'] = getLabName($row['ip_address'], $LAB_CONFIG);
            $workstations[] = $row;
        }
    }
    mysqli_close($conn);
    echo json_encode($workstations);
    exit;
}

// 2. API Endpoint: Initialization / Setup
if (isset($_GET['api']) && $_GET['api'] === 'setup' && isset($_POST['labs'])) {
    header('Content-Type: application/json');
    $selected_labs = json_decode($_POST['labs'], true);
    $custom_name = isset($_POST['session_name']) && !empty(trim($_POST['session_name'])) ? trim($_POST['session_name']) : 'Active Session';
    $active_ips = [];
    foreach ($selected_labs as $lab_key) {
        if (isset($LAB_CONFIG[$lab_key])) {
            $conf = $LAB_CONFIG[$lab_key];
            for ($i = 0; $i < $conf['count']; $i++) {
                $active_ips[] = $conf['subnet'] . "." . ($conf['start'] + $i);
            }
        }
    }
    if (count($active_ips) > 0) {
        @file_put_contents($ACTIVE_IPS_TXT, implode("\n", $active_ips));
        @file_put_contents($ACTIVE_LABS_TXT, json_encode(['session_name' => $custom_name, 'labs' => $selected_labs])); 
        exec("pkill -f 'ping_monitor.sh'");
        exec("nohup bash {$PING_SCRIPT} > /dev/null 2>&1 &");
        echo json_encode(['success' => true, 'total_ips' => count($active_ips)]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No valid labs selected']);
    }
    exit;
}

// 3. NEW API Endpoint: Rename Active Session
if (isset($_GET['api']) && $_GET['api'] === 'rename_session' && isset($_POST['session_name'])) {
    header('Content-Type: application/json');
    $new_name = trim($_POST['session_name']);
    if (file_exists($ACTIVE_LABS_TXT)) {
        $saved = json_decode(file_get_contents($ACTIVE_LABS_TXT), true);
        if(is_array($saved)){
            $saved['session_name'] = $new_name;
            @file_put_contents($ACTIVE_LABS_TXT, json_encode($saved));
            echo json_encode(['success' => true, 'name' => $new_name]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Session configuration missing']);
    exit;
}

// 4. API Endpoint: Export Logs (Dynamic Naming)
if (isset($_GET['api']) && $_GET['api'] === 'export') {
    header('Content-Type: application/json');
    $export_dir = $BASE_DIR . "/exports";
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    
    // Sanitize session name for the filename
    $safe_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $session_name);
    if (empty($safe_name)) { $safe_name = "Session"; }
    
    $filename = "LabSentinel_" . $safe_name . "_" . date("Ymd_His") . ".log";
    $target = $export_dir . "/" . $filename;
    
    if (file_exists($LOG_FILE_PATH)) {
        copy($LOG_FILE_PATH, $target);
        echo json_encode(['success' => true, 'path' => $target]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No logs found to export.']);
    }
    exit;
}

// 5. API Endpoint: Clear Session
if (isset($_GET['api']) && $_GET['api'] === 'clear') {
    header('Content-Type: application/json');
    $conn = @mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if (!$conn) { echo json_encode(['success' => false, 'error' => 'DB Connection Failed']); exit; }
    @mysqli_query($conn, "TRUNCATE TABLE usb");
    @mysqli_query($conn, "TRUNCATE TABLE internet");
    if (file_exists($LOG_FILE_PATH)) { @file_put_contents($LOG_FILE_PATH, ''); }
    exec("pkill -f 'ping_monitor.sh'");
    @unlink($ACTIVE_IPS_TXT);
    @unlink($ACTIVE_LABS_TXT);
    echo json_encode(['success' => true]);
    exit;
}

// 6. API Endpoint: Log Viewer Page
if (isset($_GET['api']) && $_GET['api'] === 'logs' && isset($_GET['ip'])) {
    $requestedIp = preg_replace('/[^0-9.]/', '', $_GET['ip']);
    $filteredLogs = [];
    if (file_exists($LOG_FILE_PATH)) {
        $handle = @fopen($LOG_FILE_PATH, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, "[ReqIP: " . $requestedIp . "]") !== false) { $filteredLogs[] = trim($line); }
            }
            fclose($handle);
        }
    } else { $filteredLogs[] = "[-] System Error: Master log file not found at " . $LOG_FILE_PATH; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Logs Captured From: <?= htmlspecialchars($requestedIp) ?></title>
<link href="https://fonts.googleapis.com/css?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root { --bg-deep: #090d16; --bg-surface: #111827; --border-subtle: #1f293d; --accent-indigo: #6366f1; --text-main: #f3f4f6; --text-muted: #9ca3af; }
    body { background: var(--bg-deep); color: var(--text-main); font-family: 'Plus Jakarta Sans', sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; }
    .audit-header { background: var(--bg-surface); padding: 20px 32px; border-bottom: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: space-between; }
    .audit-title { font-size: 1.25rem; font-weight: 700; color: #ffffff; font-family: 'JetBrains Mono', monospace; display: flex; align-items: center; gap: 10px; }
    .audit-title span { color: var(--accent-indigo); background: rgba(99, 102, 241, 0.1); padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(99, 102, 241, 0.2); }
    .table-container { padding: 24px 32px; overflow-y: auto; flex-grow: 1; }
    .soc-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; background: var(--bg-surface); border-radius: 12px; border: 1px solid var(--border-subtle); overflow: hidden; }
    .soc-table th { background: #161f33; color: var(--text-muted); font-weight: 700; font-size: 0.7rem; padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); position: sticky; top: 0; }
    .soc-table td { padding: 14px 20px; border-bottom: 1px solid var(--border-subtle); color: #e5e7eb; font-size: 0.85rem; font-family: 'JetBrains Mono', monospace; }
</style>
</head>
<body>
<div class="audit-header"><div class="audit-title">Logs Captured From: <span><?= htmlspecialchars($requestedIp) ?></span></div></div>
<div class="table-container" id="table-container">
    <table class="soc-table">
        <thead><tr><th>Timestamp</th><th>Details</th></tr></thead>
        <tbody id="log-table-body">
            <?php foreach($filteredLogs as $log) { echo "<tr><td colspan='2'>".htmlspecialchars($log)."</td></tr>"; } ?>
        </tbody>
    </table>
</div>
</body>
</html>
<?php exit; } ?>

<!-- MAIN DASHBOARD UI -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LabSentinel Infrastructure Monitor</title>
<link href="https://fonts.googleapis.com/css?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
    :root { --bg-main: #f4f6f9; --surface: #ffffff; --text-primary: #1f2937; --text-secondary: #6b7280; --border-color: #e5e7eb; --primary: #4f46e5; --accent-glow: rgba(79, 70, 229, 0.15); }
    body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; background: var(--bg-main); color: var(--text-primary); padding: 24px; margin: 0; -webkit-font-smoothing: antialiased; }
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    
    .brand-header { display: flex; align-items: center; gap: 12px; }
    .brand-icon { width: 38px; height: 38px; background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px var(--accent-glow); position: relative; overflow: hidden; }
    .brand-icon::after { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); animation: shine 4s infinite; }
    @keyframes shine { 0% { left: -100%; } 20% { left: 100%; } 100% { left: 100%; } }
    .brand-title-group { display: flex; flex-direction: column; }
    h2 { margin: 0; color: var(--text-primary); font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.4rem; letter-spacing: -0.03em; }
    
    .system-status-badge { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; background: #e0e7ff; color: #4338ca; padding: 6px 12px; border-radius: 20px; font-weight: 700; border: 1px solid #c7d2fe; display: flex; align-items: center; gap: 6px; }
    .system-status-badge::before { content: ''; width: 8px; height: 8px; background: #4f46e5; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #4f46e5; animation: pulse-dot 2s infinite; }
    @keyframes pulse-dot { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.85); } 100% { opacity: 1; transform: scale(1); } }
    
    .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .metric-card { background: var(--surface); border-radius: 14px; padding: 18px 20px; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 8px; position: relative; overflow: hidden; transition: transform 0.2s; }
    .metric-card:hover { transform: translateY(-2px); }
    .metric-header-row { display: flex; justify-content: space-between; align-items: center; }
    .metric-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; font-family: 'Plus Jakarta Sans', sans-serif; }
    .metric-value { font-size: 1.8rem; font-weight: 800; font-family: 'JetBrains Mono', monospace; line-height: 1; color: #111827; }

    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; position: relative; }
    .status-dot::after { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; border-radius: 50%; animation: live-pulse 1.8s infinite; opacity: 0.7; }
    @keyframes live-pulse { 0% { transform: scale(1); opacity: 0.8; } 50% { transform: scale(1.8); opacity: 0; } 100% { transform: scale(1); opacity: 0; } }

    .card-total { border-color: rgba(99, 102, 241, 0.3); } .card-total .metric-title { color: #6366f1; } .card-total .status-dot, .card-total .status-dot::after { background-color: #6366f1; }
    .card-online { border-color: rgba(16, 185, 129, 0.3); } .card-online .metric-title { color: #059669; } .card-online .status-dot, .card-online .status-dot::after { background-color: #10b981; }
    .card-isolated { border-color: rgba(245, 158, 11, 0.3); } .card-isolated .metric-title { color: #d97706; } .card-isolated .status-dot, .card-isolated .status-dot::after { background-color: #f59e0b; }
    .card-offline { border-color: rgba(239, 68, 68, 0.3); } .card-offline .metric-title { color: #dc2626; } .card-offline .status-dot, .card-offline .status-dot::after { background-color: #ef4444; }
    .card-alerts { border-color: rgba(244, 63, 94, 0.3); } .card-alerts .metric-title { color: #e11d48; } .card-alerts .status-dot, .card-alerts .status-dot::after { background-color: #f43f5e; }

    .toolbar { display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 12px 20px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); flex-wrap: wrap; gap: 16px; }
    .toolbar-left { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .toolbar-right { display: flex; align-items: center; width: 100%; max-width: 300px; }
    
    /* INPUT & DROPDOWN STYLING - UPDATED TO FIX VERTICAL STACKING */
    .search-input { border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f9fafb; color: #1f2937; outline: none; transition: all 0.2s ease; width: 100%; }
    .filter-select { border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f9fafb; color: #1f2937; outline: none; transition: all 0.2s ease; min-width: 160px; }
    .search-input:focus, .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); background-color: #fff; }
    
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; font-size: 0.9rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 6px; }
    .btn-secondary:hover { background: #e2e8f0; }

    .clear-btn { background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 10px; padding: 10px 16px; font-size: 0.9rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 6px; }
    .clear-btn:hover { background-color: #fee2e2; border-color: #f87171; }

    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
    .workstation-card { background: var(--surface); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.04); transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
    .workstation-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    
    .device-ip-header { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dashed var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
    .ip-display { font-family: 'JetBrains Mono', monospace; letter-spacing: -0.5px; }
    .ip-prefix { color: var(--text-secondary); font-weight: 600; } 
    .ip-host { color: #4f46e5; font-weight: 700; background: #e0e7ff; padding: 2px 6px; border-radius: 4px; }
    
    .ip-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .lab-badge { background: #eef2ff; color: #4f46e5; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: 0.3px; }

    .log-btn { background: #f3f4f6; border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; font-weight: 700; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; white-space: nowrap; }
    .log-btn:hover { background: #e5e7eb; color: #111827; border-color: #d1d5db; }

    .module-box { padding: 12px 14px; border-radius: 12px; color: #ffffff; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: inset 0 1px 0 rgba(255,255,255,0.2); }
    .module-box:last-child { margin-bottom: 0; }
    .module-left { display: flex; align-items: center; gap: 12px; }
    .module-icon { width: 20px; height: 20px; flex-shrink: 0; fill: currentColor; }
    .module-details { display: flex; flex-direction: column; line-height: 1.3; }
    .module-status-text { font-size: 0.85rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; text-transform: uppercase; letter-spacing: 0.4px; }
    .module-subtext { font-size: 0.72rem; opacity: 0.9; margin-top: 1px; font-family: 'JetBrains Mono', monospace; }
    .module-time { font-size: 0.75rem; font-family: 'JetBrains Mono', monospace; opacity: 0.95; font-weight: 600; background: rgba(0,0,0,0.15); padding: 3px 6px; border-radius: 6px; }

    .status-green { background-color: #059669 !important; } 
    .status-yellow { background-color: #d97706 !important; } 
    .status-red { background-color: #dc2626 !important; }
    
    .empty-state { grid-column: 1/-1; text-align: center; padding: 50px 20px; background: var(--surface); border: 1px dashed var(--border-color); border-radius: 16px; color: var(--text-secondary); font-weight: 500; }

    /* Modal & Scrollbar Styles */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1000; }
    
    /* MODAL WINDOW SCROLLING ENABLED */
    .modal-content { background: var(--surface); padding: 24px 32px; border-radius: 16px; width: 90%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); max-height: 85vh; overflow-y: auto; }
    
    /* CUSTOM SCROLLBAR FOR MODALS */
    .modal-content::-webkit-scrollbar { width: 8px; }
    .modal-content::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
    .modal-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .modal-content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    .modal-content h3 { margin-top: 0; font-family: 'Syne', sans-serif; color: var(--text-primary); }
    
    /* LAB GRID STYLED TO 2 COLUMNS AND COMPACT */
    .lab-checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 16px 0; }
    .lab-option { display: flex; align-items: center; gap: 10px; font-weight: 600; cursor: pointer; color: var(--text-primary); background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; transition: 0.2s; font-size: 0.85rem;}
    .lab-option:hover { background: #f1f5f9; border-color: #cbd5e1; }
    
    .btn-primary { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 700; cursor: pointer; width: 100%; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1rem; }

    .cheat-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; }
    .cheat-table th { background: #f8fafc; color: #64748b; font-weight: 700; padding: 12px; border-bottom: 2px solid #e2e8f0; }
    .cheat-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #1f2937; }
</style>
</head>
<body>

<!-- SETUP MODAL -->
<?php if (!file_exists($ACTIVE_IPS_TXT)): ?>
<div class="modal-overlay" id="setup-modal">
    <div class="modal-content">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="brand-icon" style="margin: 0 auto 16px auto; width: 56px; height: 56px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
            </div>
            <h3>Configure Lab Session</h3>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 8px;">Select active labs and provide a session name.</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="font-weight: 700; font-size: 0.85rem; color: var(--text-secondary); display:block; margin-bottom: 8px;">SESSION NAME</label>
            <input type="text" id="session-name-input" class="search-input" style="width: 100%; box-sizing: border-box; padding: 12px; font-size: 1rem;" placeholder="e.g., Final Exams - Morning Shift">
        </div>

        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <label style="font-weight: 700; font-size: 0.85rem; color: var(--text-secondary);">TARGET LABS</label>
            <button class="btn-secondary" style="margin: 0; padding: 6px 12px; font-size: 0.75rem;" onclick="toggleAllLabs()">Toggle All</button>
        </div>

        <div class="lab-checkbox-grid" id="lab-checkboxes">
            <?php foreach(array_keys($LAB_CONFIG) as $lab): ?>
                <label class="lab-option">
                    <input type="checkbox" value="<?= $lab ?>" class="lab-cb"> 
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #64748b;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    <?= $lab ?>
                </label>
            <?php endforeach; ?>
        </div>
        <button class="btn-primary" style="margin-top: 10px;" onclick="startMonitor()">Initialize Environment</button>
    </div>
</div>
<?php endif; ?>

<!-- NETWORK MAP MODAL -->
<div class="modal-overlay" id="cheat-sheet-modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
            <h3>Network Map Reference</h3>
            <button class="btn-secondary" onclick="document.getElementById('cheat-sheet-modal').style.display='none'" style="margin:0; padding: 6px 12px;">Close</button>
        </div>
        <table class="cheat-table">
            <thead>
                <tr>
                    <th>Lab Room</th>
                    <th>Subnet</th>
                    <th>IP Range</th>
                    <th>Total PCs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($LAB_CONFIG as $lab => $conf): 
                    $is_active = in_array($lab, $active_labs_array);
                    $row_style = $is_active ? 'background: #ecfdf5; border-left: 4px solid #10b981;' : 'opacity: 0.5;';
                    $end_ip = $conf['start'] + $conf['count'] - 1;
                ?>
                <tr style="<?= $row_style ?>">
                    <td style="font-weight:bold; color:<?= $is_active ? '#059669' : '#64748b' ?>;">
                        <?= $lab ?> <?= $is_active ? '<span style="font-size:0.8em">✓ ACTIVE</span>' : '' ?>
                    </td>
                    <td><?= $conf['subnet'] ?>.x</td>
                    <td>.<?= $conf['start'] ?> - .<?= $end_ip ?></td>
                    <td><?= $conf['count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="top-bar">
    <div class="brand-header">
        <div class="brand-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <circle cx="12" cy="11" r="3"/>
            </svg>
        </div>
        <div class="brand-title-group">
            <h2>LabSentinel</h2>
            <!-- EDITABLE SESSION NAME -->
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; display: flex; align-items: center; gap: 6px;">
                <span id="display-session-name"><?= htmlspecialchars($session_name) ?></span>
                <button onclick="renameSession()" style="background: none; border: none; cursor: pointer; padding: 2px; color: #64748b; display: flex; align-items: center; border-radius: 4px;" title="Rename Session" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='none'">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>
            </div>
        </div>
    </div>
    <div class="system-status-badge">
        <span id="timer-text">NEXT UPDATE: --s</span>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card card-total">
        <div class="metric-header-row"><span class="metric-title">Total</span><span class="status-dot"></span></div>
        <span class="metric-value" id="sum-total">0</span>
    </div>
    <div class="metric-card card-online">
        <div class="metric-header-row"><span class="metric-title">Online</span><span class="status-dot"></span></div>
        <span class="metric-value" id="sum-online">0</span>
    </div>
    <div class="metric-card card-isolated">
        <div class="metric-header-row"><span class="metric-title">Isolated</span><span class="status-dot"></span></div>
        <span class="metric-value" id="sum-isolated">0</span>
    </div>
    <div class="metric-card card-offline">
        <div class="metric-header-row"><span class="metric-title">Offline</span><span class="status-dot"></span></div>
        <span class="metric-value" id="sum-offline">0</span>
    </div>
    <div class="metric-card card-alerts">
        <div class="metric-header-row"><span class="metric-title">USB Alerts</span><span class="status-dot"></span></div>
        <span class="metric-value" id="sum-alerts">0</span>
    </div>
</div>

<div class="toolbar">
    <div class="toolbar-left">
        <select id="filter-select" class="filter-select" onchange="filterWorkstations()">
            <option value="all">All Workstations</option>
            <option value="online">Online Only</option>
            <option value="isolated">Isolated Only</option>
            <option value="offline">Offline Only</option>
            <option value="usb-alerts">Active USB Alerts</option>
        </select>
        
        <select id="lab-filter-select" class="filter-select" onchange="filterWorkstations()">
            <option value="all">All Active Labs</option>
            <?php 
            foreach($active_labs_array as $lab) {
                echo "<option value=\"" . htmlspecialchars($lab) . "\">" . htmlspecialchars($lab) . "</option>";
            }
            ?>
        </select>
        
        <button class="btn-secondary" style="background:#eff6ff; color:#1e40af; border-color:#bfdbfe;" onclick="document.getElementById('cheat-sheet-modal').style.display='flex'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            Network Map
        </button>

        <button class="btn-secondary" style="background:#f0fdf4; color:#166534; border-color:#bbf7d0;" onclick="exportLogs()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Export Logs
        </button>

        <button class="clear-btn" onclick="clearSession()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            Wipe Session
        </button>
    </div>
    <div class="toolbar-right">
        <input type="text" id="search-input" class="search-input" placeholder="Search IP or device..." onkeyup="filterWorkstations()">
    </div>
</div>

<div id="workstation-grid" class="grid"></div>

<script>
const POLLING_INTERVAL_SECONDS = 5;
let cachedWorkstations = [];
let currentCountdown = POLLING_INTERVAL_SECONDS;

function toggleAllLabs() {
    const cbs = document.querySelectorAll('.lab-cb');
    const allChecked = Array.from(cbs).every(cb => cb.checked);
    cbs.forEach(cb => cb.checked = !allChecked);
}

function startMonitor() {
    const selected = Array.from(document.querySelectorAll('.lab-cb:checked')).map(cb => cb.value);
    const sessionName = document.getElementById('session-name-input').value.trim();
    if(selected.length === 0) { alert("Please select at least one lab."); return; }
    
    const formData = new FormData();
    formData.append('labs', JSON.stringify(selected));
    formData.append('session_name', sessionName);
    
    fetch('?api=setup', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                location.reload(); 
            } else {
                alert("Setup Failed: " + data.error);
            }
        });
}

function renameSession() {
    let currentName = document.getElementById('display-session-name').innerText;
    let newName = prompt("Enter new session name:", currentName);
    if (newName !== null && newName.trim() !== "") {
        const formData = new FormData();
        formData.append('session_name', newName.trim());
        fetch('?api=rename_session', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('display-session-name').innerText = data.name;
                } else {
                    alert("Rename failed: " + data.error);
                }
            });
    }
}

function exportLogs() {
    fetch('?api=export')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                alert("Logs successfully exported and saved to:\n\n" + data.path);
            } else {
                alert("Export Failed: " + data.error);
            }
        })
        .catch(err => alert("Error exporting logs: " + err));
}

function getStatusClass(status) {
    switch(status.toLowerCase()) {
        case 'online': case 'idle': case 'no-usb': return 'status-green';
        case 'isolated': case 'blocked': case 'block': return 'status-yellow';
        case 'offline': case 'authorized': case 'authorised': case 'authenticated': return 'status-red';
        default: return 'status-green';
    }
}

function formatIPAddress(fullIP) {
    let cleanIP = fullIP.split('/')[0].trim();
    let parts = cleanIP.split('.');
    if (parts.length === 4) {
        let prefix = parts.slice(0, 3).join('.');
        let host = parts[3];
        return `<span class="ip-prefix">${prefix}.</span><span class="ip-host">${host}</span>`;
    }
    return cleanIP;
}

function extractCleanIP(fullIP) {
    return fullIP.split('/')[0].trim();
}

function ipToNum(ip) {
    return ip.split('/')[0].trim().split('.').reduce((acc, octet) => ((acc << 8) + parseInt(octet, 10)), 0) >>> 0;
}

function getInternetIconSVG() {
    return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2 .9 2 2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>`;
}

function getUSBIconSVG() {
    return `<svg class="module-icon" viewBox="0 0 24 24"><path d="M15 7v4h1v2h-3V5h2l-3-4-3 4h2v8H8v-2h1V7H6v6c0 1.1.9 2 2 2h3v3H9v2h6v-2h-2v-3h3c1.1 0 2-.9 2-2V7h-2z"/></svg>`;
}

function getMonitorIconSVG() {
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #64748b; margin-top: -1px;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>`;
}

function updateDashboard(workstations) {
    cachedWorkstations = workstations;
    cachedWorkstations.sort((a,b)=>ipToNum(a.ip_address)-ipToNum(b.ip_address));
    
    let total = cachedWorkstations.length;
    let online = 0, isolated = 0, offline = 0, alerts = 0;
    
    cachedWorkstations.forEach(ws => {
        if (ws.internet_status === 'online') online++;
        if (ws.internet_status === 'isolated') isolated++;
        if (ws.internet_status === 'offline') offline++;
        if (ws.usb_status !== 'idle' && ws.usb_status !== 'no-usb') alerts++;
    });
    
    document.getElementById('sum-total').innerText = total;
    document.getElementById('sum-online').innerText = online;
    document.getElementById('sum-isolated').innerText = isolated;
    document.getElementById('sum-offline').innerText = offline;
    document.getElementById('sum-alerts').innerText = alerts;
    filterWorkstations();
}

function renderGrid(workstations) {
    const grid = document.getElementById('workstation-grid');
    if (workstations.length === 0) {
        grid.innerHTML = '<div class="empty-state">No matching workstations found matching your criteria.</div>';
        return;
    }
    let gridHTML = '';
    workstations.forEach(ws => {
        const netClass = getStatusClass(ws.internet_status);
        const usbClass = getStatusClass(ws.usb_status);
        const formattedIP = formatIPAddress(ws.ip_address);
        const cleanIP = extractCleanIP(ws.ip_address);

        gridHTML += `
        <div class="workstation-card">
            <div class="device-ip-header">
                <div class="ip-group">
                    ${getMonitorIconSVG()}
                    <span class="ip-display">${formattedIP}</span>
                    <span class="lab-badge">${ws.lab_room}</span>
                </div>
                <div>
                    <a class="log-btn" href="?api=logs&ip=${cleanIP}" target="_blank">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        View Logs
                    </a>
                </div>
            </div>
            <div class="module-box ${netClass}">
                <div class="module-left">
                    ${getInternetIconSVG()}
                    <div class="module-details">
                        <span class="module-status-text">${ws.internet_status}</span>
                        <span class="module-subtext">Network Status</span>
                    </div>
                </div>
                <div class="module-time">${ws.internet_time}</div>
            </div>
            <div class="module-box ${usbClass}">
                <div class="module-left">
                    ${getUSBIconSVG()}
                    <div class="module-details">
                        <span class="module-status-text">${ws.usb_status}</span>
                        <span class="module-subtext">ID: ${ws.usb_dev_id}</span>
                    </div>
                </div>
                <div class="module-time">${ws.usb_time}</div>
            </div>
        </div>`;
    });
    grid.innerHTML = gridHTML;
}

function filterWorkstations() {
    const statFilter = document.getElementById('filter-select').value;
    const labFilter = document.getElementById('lab-filter-select').value;
    const search = document.getElementById('search-input').value.toLowerCase();
    
    const filtered = cachedWorkstations.filter(ws => {
        let mStat = (statFilter === 'all') || (ws.internet_status === statFilter);
        let mLab = (labFilter === 'all') || (ws.lab_room === labFilter);
        let mSearch = !search || ws.ip_address.toLowerCase().includes(search) || ws.usb_dev_id.toLowerCase().includes(search);
        
        if (statFilter === 'usb-alerts') {
            mStat = (ws.usb_status !== 'idle' && ws.usb_status !== 'no-usb');
        }
        
        return mStat && mLab && mSearch;
    });
    renderGrid(filtered);
}

function clearSession() {
    if (confirm("Are you sure you want to clear the current session? This will wipe the active dashboard state.")) {
        fetch('?api=clear')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload(); 
                } else {
                    alert("Database Error: " + data.error);
                }
            })
            .catch(err => console.error('Error clearing session:', err));
    }
}

function updateTimerDisplay() {
    let timerEl = document.getElementById('timer-text');
    if (timerEl) {
        timerEl.innerText = `NEXT UPDATE: ${currentCountdown}s`;
    }
}

function pollTelemetry() {
    if(!document.getElementById('setup-modal') || document.getElementById('setup-modal').style.display === 'none') {
        fetch('?api=status').then(res => res.json()).then(data => { if(!data.error) updateDashboard(data); });
    }
}

window.onload = function() {
    pollTelemetry();
    updateTimerDisplay();
    
    setInterval(() => {
        currentCountdown--;
        if (currentCountdown <= 0) {
            pollTelemetry();
            currentCountdown = POLLING_INTERVAL_SECONDS;
        }
        updateTimerDisplay();
    }, 1000);
};
</script>
</body>
</html>
