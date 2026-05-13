<?php
/**
 * Splynx Ticket Map Dashboard
 * Version: 5.0.0 - Tasks + Tickets on Map
 */

require_once 'config.php';
require_once 'googleMapsApi.php';
global $googleApiKey, $dashboardTitle, $defaultLat, $defaultLng, $geoBoundary;

const TICKET_STORE_PATH = '/dev/shm/splynx_open_tickets.json';
const TASK_STORE_PATH   = '/dev/shm/splynx_open_tasks.json';

$tickets = file_exists(TICKET_STORE_PATH) ? json_decode(file_get_contents(TICKET_STORE_PATH), true) : [];
$tasks   = file_exists(TASK_STORE_PATH)   ? json_decode(file_get_contents(TASK_STORE_PATH), true)   : [];

// Clamp out-of-bounds coordinates to default location (safety net)
// Any marker with coords outside NZ (including 0,0) gets relocated to Wellington
// Flag clamped items so "No Address" filter still works
foreach ($tickets as &$t) {
    $la = (float)($t['lat'] ?? 0); $lo = (float)($t['lng'] ?? 0);
    if ($la < $geoBoundary['lat_min'] || $la > $geoBoundary['lat_max'] ||
        $lo < $geoBoundary['lng_min'] || $lo > $geoBoundary['lng_max']) {
        $t['lat'] = $defaultLat; $t['lng'] = $defaultLng;
        $t['_clamped'] = true;
    }
}
unset($t);

foreach ($tasks as &$tk) {
    $la = (float)($tk['lat'] ?? 0); $lo = (float)($tk['lng'] ?? 0);
    if ($la < $geoBoundary['lat_min'] || $la > $geoBoundary['lat_max'] ||
        $lo < $geoBoundary['lng_min'] || $lo > $geoBoundary['lng_max']) {
        $tk['lat'] = $defaultLat; $tk['lng'] = $defaultLng;
        $tk['_clamped'] = true;
    }
}
unset($tk);

$lastSyncTime = file_exists(TICKET_STORE_PATH) ? filemtime(TICKET_STORE_PATH) : null;
$taskSyncTime = file_exists(TASK_STORE_PATH) ? filemtime(TASK_STORE_PATH) : null;
$syncDisplay = $lastSyncTime ? date("g:i A", $lastSyncTime) : "Never";
$taskSyncDisplay = $taskSyncTime ? date("g:i A", $taskSyncTime) : "Never";

$priorityMap = ['urgent', 'high', 'normal', 'low'];
$priorityOrder = ['urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];

usort($tickets, function($a, $b) use ($priorityOrder) {
    $pA = $priorityOrder[strtolower($a['priority'] ?? 'normal')] ?? 5;
    $pB = $priorityOrder[strtolower($b['priority'] ?? 'normal')] ?? 5;
    return ($pA !== $pB) ? $pA <=> $pB : $b['ticket_id'] <=> $a['ticket_id'];
});

$agents = array_unique(array_column($tickets, 'assigned_to')); sort($agents);
$statuses = array_unique(array_column($tickets, 'status_label')); sort($statuses);
$types = array_unique(array_column($tickets, 'type_label')); sort($types);
$routers = array_unique(array_filter(array_column($tickets, 'router_name'))); sort($routers);
$rawPriorities = array_unique(array_column($tickets, 'priority'));
usort($rawPriorities, function($a, $b) use ($priorityMap) {
    $posA = array_search(strtolower($a), $priorityMap);
    $posB = array_search(strtolower($b), $priorityMap);
    return ($posA === false ? 99 : $posA) <=> ($posB === false ? 99 : $posB);
});

$typeConfig = [
    'Order service request' => ['icon' => 'flight',          'color' => '#8b5cf6'],
    'Service change'        => ['icon' => 'local_atm',       'color' => '#10b981'],
    'Problem'               => ['icon' => 'local_taxi',      'color' => '#f59e0b'],
    'FAULT'                 => ['icon' => 'report',          'color' => '#ef4444'],
    'Accounts'              => ['icon' => 'account_balance', 'color' => '#0ea5e9'],
    'Feature Request'       => ['icon' => 'rate_review',     'color' => '#3b82f6'],
    'Installation'          => ['icon' => 'home',            'color' => '#ec4899'],
    'Question'              => ['icon' => 'emoji_people',    'color' => '#f97316'],
    'Incident'              => ['icon' => 'agriculture',     'color' => '#f43f5e'],
    'default'               => ['icon' => 'push_pin',        'color' => '#64748b']
];

// Project icon/color config for task markers
$projectConfig = [
    'Tower Work'              => ['icon' => 'cell_tower',     'color' => '#ef4444'],
    'Customer Faults'         => ['icon' => 'report_problem', 'color' => '#f59e0b'],
    'Customer Signal Faults'  => ['icon' => 'signal_wifi_bad','color' => '#f97316'],
    'Customer Swapout'        => ['icon' => 'swap_horiz',     'color' => '#8b5cf6'],
    'Customer - New Router'   => ['icon' => 'router',         'color' => '#10b981'],
    'Disconnect/Terminate'    => ['icon' => 'cancel',         'color' => '#64748b'],
    'Override migration'      => ['icon' => 'sync_alt',       'color' => '#0ea5e9'],
    'Rangitumau Repairs'      => ['icon' => 'build',          'color' => '#ec4899'],
    'No Project'              => ['icon' => 'task_alt',       'color' => '#94a3b8'],
    'default'                 => ['icon' => 'task_alt',       'color' => '#d97706'],
];

// --- Task data prep ---
$taskPriorityOrder = ['urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];
usort($tasks, function($a, $b) use ($taskPriorityOrder) {
    $pA = $taskPriorityOrder[strtolower($a['priority'] ?? 'normal')] ?? 5;
    $pB = $taskPriorityOrder[strtolower($b['priority'] ?? 'normal')] ?? 5;
    return ($pA !== $pB) ? $pA <=> $pB : ($b['task_id'] ?? 0) <=> ($a['task_id'] ?? 0);
});

$taskAssignees = array_unique(array_filter(array_column($tasks, 'assignee'))); sort($taskAssignees);
$taskProjects = array_unique(array_filter(array_column($tasks, 'project'))); sort($taskProjects);
$taskLocations = array_unique(array_filter(array_column($tasks, 'location'))); sort($taskLocations);
$taskPriorities = array_unique(array_column($tasks, 'priority'));
usort($taskPriorities, function($a, $b) use ($priorityMap) {
    $posA = array_search(strtolower($a), $priorityMap);
    $posB = array_search(strtolower($b), $priorityMap);
    return ($posA === false ? 99 : $posA) <=> ($posB === false ? 99 : $posB);
});

// All items now have coordinates (clamped to default if needed), so all go on the map
$mapTickets = $tickets;
$mapTasks = $tasks;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($dashboardTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        #map { height: 100%; width: 100%; }
        .sidebar-scroll { flex: 1; overflow-y: auto; scroll-behavior: smooth; }
        .filter-scroll { flex: 1; overflow-y: auto; scroll-behavior: smooth; }
        .multi-select-box { max-height: 100px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 0.5rem; background: white; padding: 0.4rem; }
        .ticket-card.selected { background-color: #eff6ff; border-left-width: 8px !important; }
        .ticket-card.flash-highlight { background-color: #fef08a !important; }
        .task-card.selected { background-color: #fffbeb; border-left-width: 8px !important; }
        .task-card.flash-highlight { background-color: #fef08a !important; }
        .filter-group.minimized .multi-select-box, .filter-group.minimized .toggle-btn { display: none; }
        .filter-section.minimized { display: none; }
        @media print { .no-print { display: none !important; } }
        .map-search-container { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 5; width: 350px; }
        .panel-collapsed { width: 0 !important; min-width: 0 !important; overflow: hidden; padding: 0 !important; }
        .panel-toggle-btn { position: absolute; top: 50%; transform: translateY(-50%); z-index: 20; background: #1e293b; border: 2px solid #475569; padding: 0.5rem 0.25rem; cursor: pointer; box-shadow: 2px 0 8px rgba(0,0,0,0.2); }
        .panel-toggle-btn:hover { background: #334155; }
        .panel-toggle-btn .material-icons { color: white; }
        .left-toggle { left: 0; border-left: none; border-radius: 0 0.5rem 0.5rem 0; }
        .right-toggle { right: 0; border-right: none; border-radius: 0.5rem 0 0 0.5rem; }
        @media (max-width: 1024px) {
            .filter-panel { position: absolute; left: 0; top: 0; height: 100%; z-index: 15; }
            .list-panel { position: absolute; right: 0; top: 0; height: 100%; z-index: 15; }
        }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">

    <header class="bg-slate-900 text-white p-4 shadow-lg flex justify-between items-center z-10 no-print">
        <div class="flex items-center space-x-3">
            <div class="p-2 bg-blue-600 rounded-lg font-bold">WB</div>
            <div>
                <h1 class="text-lg font-bold"><?php echo htmlspecialchars($dashboardTitle); ?></h1>
                <div class="text-[10px] text-slate-400 font-bold">TICKETS: <span class="text-emerald-400 uppercase"><?php echo $syncDisplay; ?></span> &middot; TASKS: <span class="text-amber-400 uppercase"><?php echo $taskSyncDisplay; ?></span></div>
            </div>
        </div>
        
        <div class="hidden md:flex items-center bg-slate-800 px-4 py-2 rounded-full border border-slate-700 space-x-4">
            <div class="flex items-center space-x-2">
                <label class="flex items-center cursor-pointer"><input type="checkbox" id="showTicketsToggle" checked onchange="onTicketToggle()" class="w-4 h-4 accent-emerald-500 mr-1"><span class="text-[10px] font-bold text-emerald-400 uppercase">Tickets</span></label>
                <label class="flex items-center cursor-pointer"><input type="checkbox" id="showTasksToggle" checked onchange="onTaskToggle()" class="w-4 h-4 accent-amber-500 mr-1"><span class="text-[10px] font-bold text-amber-400 uppercase">Tasks</span></label>
            </div>
            <div class="h-4 w-px bg-slate-600"></div>
            <div class="flex items-center">
                <span class="material-icons text-emerald-400 text-sm mr-2">location_on</span>
                <span class="text-xs font-bold tracking-widest uppercase">Visible: <span id="headerMarkerCount" class="text-emerald-400 ml-1">0</span></span>
            </div>
            <div class="h-4 w-px bg-slate-600"></div>
            <div class="flex items-center">
                <span class="material-icons text-red-400 text-sm mr-2">location_off</span>
                <span class="text-xs font-bold tracking-widest uppercase mr-2">No Address: <span id="headerNoAddressCount" class="text-red-400 ml-1">0</span></span>
                <input type="checkbox" id="noAddressOnlyToggle" class="w-4 h-4 cursor-pointer accent-red-500" onchange="applyFilters()">
            </div>
        </div>

        <div class="flex items-center space-x-2">
            <button onclick="fitToVisibleMarkers()" class="bg-purple-600 px-3 py-2 rounded text-xs font-bold shadow-md" title="Zoom to visible tickets">FIT MAP</button>
            <button onclick="getDirections()" class="bg-blue-600 px-3 py-2 rounded text-xs font-bold shadow-md">NAVIGATE</button>
            <button onclick="printSelected()" class="bg-emerald-600 px-3 py-2 rounded text-xs font-bold shadow-md">PRINT (<span id="selectedCount">0</span>)</button>
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <!-- Left toggle button -->
        <button class="panel-toggle-btn left-toggle no-print" onclick="toggleFilterPanel()" id="filterToggle" title="Toggle filters">
            <span class="material-icons" id="filterToggleIcon">chevron_left</span>
        </button>
        
        <!-- LEFT PANEL: Filters -->
        <aside class="w-72 bg-white shadow-2xl z-10 flex flex-col no-print transition-all duration-300 filter-panel" id="filterPanel">
            <!-- Layer Tabs -->
            <div class="flex border-b bg-slate-50">
                <button onclick="switchLayer('tickets')" id="tabTickets" class="flex-1 py-3 text-xs font-black uppercase tracking-wider border-b-2 border-blue-600 text-blue-600 bg-white">
                    <span class="material-icons text-[14px] align-middle mr-1">confirmation_number</span>Tickets <span class="text-[9px] font-bold bg-blue-100 px-1.5 py-0.5 rounded ml-1"><?php echo count($tickets); ?></span>
                </button>
                <button onclick="switchLayer('tasks')" id="tabTasks" class="flex-1 py-3 text-xs font-black uppercase tracking-wider border-b-2 border-transparent text-slate-400 hover:text-amber-600">
                    <span class="material-icons text-[14px] align-middle mr-1">task_alt</span>Tasks <span class="text-[9px] font-bold bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded ml-1"><?php echo count($tasks); ?></span>
                </button>
            </div>

            <div class="filter-scroll">
            <!-- TICKET FILTERS -->
            <div class="p-4 bg-slate-50 border-b layer-filters" id="ticketFilters">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-[10px] font-black text-slate-400 uppercase">Filters</h2>
                </div>

                <!-- Sort Controls -->
                <div class="mb-3 p-2 bg-white rounded border border-slate-200 filter-section">
                    <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Sort By</label>
                    <select id="sortBy" onchange="applySorting()" class="w-full text-xs p-1.5 border rounded">
                        <option value="priority">Priority (Default)</option>
                        <option value="created_newest">Created (Newest First)</option>
                        <option value="created_oldest">Created (Oldest First)</option>
                        <option value="customer_name">Customer Name (A-Z)</option>
                        <option value="ticket_id">Ticket ID (Highest First)</option>
                    </select>
                </div>

                <!-- Date Filter -->
                <div class="mb-3 p-2 bg-white rounded border border-slate-200 filter-section">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase">Hide Tickets Before</label>
                        <input type="checkbox" id="dateFilterEnabled" onchange="applyFilters()" class="w-4 h-4 cursor-pointer accent-blue-600">
                    </div>
                    <input type="date" id="dateFilterValue" onchange="applyFilters()" class="w-full text-xs p-1.5 border rounded" disabled>
                    <p class="text-[9px] text-slate-400 mt-1">Default: 30 days ago</p>
                </div>

                <div id="filterContainer" class="space-y-3">
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Personnel <span id="agentCount" class="text-blue-600"></span></span><button onclick="toggleGroup('agent-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box" id="agentFilterBox">
                            <?php foreach ($agents as $a): ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($a); ?>" checked class="mr-2 agent-checkbox filter-cb"><?php echo htmlspecialchars($a); ?></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Priority <span id="priorityCount" class="text-blue-600"></span></span><button onclick="toggleGroup('priority-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box" id="priorityFilterBox">
                            <?php foreach ($rawPriorities as $p): 
                                $c = (strtolower($p) == 'urgent') ? 'text-red-600 font-bold' : ((strtolower($p) == 'high') ? 'text-orange-500 font-bold' : 'text-slate-600');
                            ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($p); ?>" checked class="mr-2 priority-checkbox filter-cb"><span class="<?php echo $c; ?>"><?php echo ucfirst(htmlspecialchars($p)); ?></span></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Ticket Type <span id="typeCount" class="text-blue-600"></span></span><button onclick="toggleGroup('type-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box" id="typeFilterBox">
                            <?php foreach ($types as $tType): 
                                $cfg = $typeConfig[$tType] ?? $typeConfig['default'];
                            ?>
                            <label class="flex items-center text-[11px] p-0.5 cursor-pointer hover:bg-slate-50">
                                <input type="checkbox" value="<?php echo htmlspecialchars($tType); ?>" checked class="mr-2 type-checkbox filter-cb">
                                <span class="material-icons text-[14px] mr-1" style="color:<?php echo $cfg['color']; ?>"><?php echo $cfg['icon']; ?></span>
                                <?php echo htmlspecialchars($tType); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Status <span id="statusCount" class="text-blue-600"></span></span><button onclick="toggleGroup('status-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box" id="statusFilterBox">
                            <?php foreach ($statuses as $s): ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($s); ?>" checked class="mr-2 status-checkbox filter-cb"><?php echo htmlspecialchars($s); ?></label><?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <input type="text" id="tSearch" placeholder="Search customer or subject..." class="w-full mt-3 p-2 border rounded text-xs outline-none focus:ring-2 focus:ring-blue-500">
                
                <!-- Router Filter (searchable multi-select) -->
                <div class="mt-3 p-2 bg-white rounded border border-slate-200 filter-section">
                    <div class="flex justify-between mb-1">
                        <span class="text-[10px] font-bold text-slate-500 uppercase">Router</span>
                        <button onclick="toggleGroup('router-checkbox')" class="text-[9px] text-blue-600 font-bold">Toggle All</button>
                    </div>
                    <input type="text" id="routerSearch" placeholder="Type to filter routers..." class="w-full text-xs p-1.5 border rounded mb-1 outline-none focus:ring-2 focus:ring-blue-500" oninput="filterRouterList()">
                    <div class="multi-select-box" id="routerFilterBox" style="max-height:120px;">
                        <?php foreach ($routers as $r): ?>
                        <label class="flex items-center text-[11px] p-0.5 router-option"><input type="checkbox" value="<?php echo htmlspecialchars($r); ?>" checked class="mr-2 router-checkbox filter-cb"><?php echo htmlspecialchars($r); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TASK FILTERS (hidden by default) -->
            <div class="p-4 bg-slate-50 border-b layer-filters hidden" id="taskFilters">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-[10px] font-black text-amber-600 uppercase">Task Filters</h2>
                </div>

                <div class="mb-3 p-2 bg-white rounded border border-slate-200 task-filter-section">
                    <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Sort By</label>
                    <select id="taskSortBy" onchange="applyTaskSorting()" class="w-full text-xs p-1.5 border rounded">
                        <option value="priority">Priority (Default)</option>
                        <option value="scheduled_newest">Scheduled (Newest First)</option>
                        <option value="scheduled_oldest">Scheduled (Oldest First)</option>
                        <option value="customer_name">Customer Name (A-Z)</option>
                        <option value="task_id">Task ID (Highest First)</option>
                    </select>
                </div>

                <!-- Task Date Filter -->
                <div class="mb-3 p-2 bg-white rounded border border-slate-200 task-filter-section">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[10px] font-bold text-slate-500 uppercase">Hide Tasks with Status change before</label>
                        <input type="checkbox" id="taskDateFilterEnabled" onchange="applyTaskFilters()" class="w-4 h-4 cursor-pointer accent-amber-600">
                    </div>
                    <input type="date" id="taskDateFilterValue" onchange="applyTaskFilters()" class="w-full text-xs p-1.5 border rounded" disabled>
                    <p class="text-[9px] text-slate-400 mt-1">Default: 30 days ago</p>
                </div>

                <div id="taskFilterContainer" class="space-y-3">
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Project</span><button onclick="toggleGroup('task-project-checkbox')" class="toggle-btn text-[9px] text-amber-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($taskProjects as $proj): 
                                $pcfg = $projectConfig[$proj] ?? $projectConfig['default'];
                            ?>
                            <label class="flex items-center text-[11px] p-0.5 cursor-pointer hover:bg-slate-50">
                                <input type="checkbox" value="<?php echo htmlspecialchars($proj); ?>" checked class="mr-2 task-project-checkbox task-filter-cb">
                                <span class="material-icons text-[14px] mr-1" style="color:<?php echo $pcfg['color']; ?>"><?php echo $pcfg['icon']; ?></span>
                                <?php echo htmlspecialchars($proj); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Assignee</span><button onclick="toggleGroup('task-assignee-checkbox')" class="toggle-btn text-[9px] text-amber-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($taskAssignees as $a): ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($a); ?>" checked class="mr-2 task-assignee-checkbox task-filter-cb"><?php echo htmlspecialchars($a); ?></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Priority</span><button onclick="toggleGroup('task-priority-checkbox')" class="toggle-btn text-[9px] text-amber-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($taskPriorities as $p): 
                                $c = (strtolower($p) == 'urgent') ? 'text-red-600 font-bold' : ((strtolower($p) == 'high') ? 'text-orange-500 font-bold' : 'text-slate-600');
                            ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($p); ?>" checked class="mr-2 task-priority-checkbox task-filter-cb"><span class="<?php echo $c; ?>"><?php echo ucfirst(htmlspecialchars($p)); ?></span></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Location</span><button onclick="toggleGroup('task-location-checkbox')" class="toggle-btn text-[9px] text-amber-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($taskLocations as $loc): ?>
                            <label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($loc); ?>" checked class="mr-2 task-location-checkbox task-filter-cb"><?php echo htmlspecialchars($loc); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <input type="text" id="taskSearch" placeholder="Search task title or customer..." class="w-full mt-3 p-2 border rounded text-xs outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            </div><!-- end filter-scroll -->
        </aside>

        <!-- CENTER: Map -->
        <main class="flex-1 relative">
            <div class="map-search-container no-print">
                <div class="flex bg-white rounded-lg shadow-xl border border-slate-300 overflow-hidden p-1">
                    <input type="text" id="mapSearchInput" placeholder="Enter address or lat, lng..." 
                           class="flex-1 px-3 py-2 text-sm outline-none" onkeypress="if(event.key === 'Enter') searchMapLocation()">
                    <button onclick="searchMapLocation()" class="bg-slate-800 text-white px-3 flex items-center justify-center rounded-md hover:bg-slate-700">
                        <span class="material-icons text-sm">search</span>
                    </button>
                </div>
            </div>
            <div id="map"></div>
        </main>

        <!-- Right toggle button -->
        <button class="panel-toggle-btn right-toggle no-print" onclick="toggleListPanel()" id="listToggle" title="Toggle list">
            <span class="material-icons" id="listToggleIcon">chevron_right</span>
        </button>

        <!-- RIGHT PANEL: Ticket/Task List -->
        <aside class="w-96 bg-white shadow-2xl z-10 flex flex-col no-print transition-all duration-300 list-panel" id="listPanel">
            <div class="sidebar-scroll" id="ticketList">
                <?php foreach ($tickets as $t): 
                    $baseUrl = rtrim($t['ui_url'] ?? '', '/');
                    $p = strtolower($t['priority'] ?? 'normal');
                    $pColor = ($p === 'urgent') ? 'border-red-600' : (($p === 'high') ? 'border-orange-500' : 'border-slate-300');
                    $hasAddress = empty($t['no_address']) && empty($t['_clamped']);
                    
                    // Smart date formatting
                    $createdDate = 'N/A';
                    if (isset($t['created_at'])) {
                        $createdTimestamp = strtotime($t['created_at']);
                        $now = time();
                        $daysDiff = floor(($now - $createdTimestamp) / (60 * 60 * 24));
                        $monthsDiff = (date('Y', $now) - date('Y', $createdTimestamp)) * 12 + (date('n', $now) - date('n', $createdTimestamp));
                        
                        if ($monthsDiff >= 12) {
                            // Older than 12 months: show date with 2-digit year, no time
                            $createdDate = date("d M, 'y", $createdTimestamp);
                        } elseif ($daysDiff > 5) {
                            // Older than 5 days: show date only, no time
                            $createdDate = date("d M", $createdTimestamp);
                        } else {
                            // 5 days or less: show date and time
                            $createdDate = date("d M, g:i A", $createdTimestamp);
                        }
                    }
                ?>
                <div class="ticket-card p-4 border-b hover:bg-slate-50 cursor-pointer border-l-4 transition-all <?php echo $pColor; ?>"
                     id="card-<?php echo $t['ticket_id']; ?>"
                     data-agent="<?php echo htmlspecialchars($t['assigned_to']); ?>"
                     data-status="<?php echo htmlspecialchars($t['status_label']); ?>"
                     data-type="<?php echo htmlspecialchars($t['type_label']); ?>"
                     data-priority="<?php echo htmlspecialchars($t['priority']); ?>"
                     data-has-address="<?php echo $hasAddress ? '1' : '0'; ?>"
                     data-created-at="<?php echo htmlspecialchars($t['created_at'] ?? ''); ?>"
                     data-router="<?php echo htmlspecialchars($t['router_name'] ?? ''); ?>"
                     data-customer-name="<?php echo htmlspecialchars($t['customer_name'] ?? ''); ?>"
                     onclick="handleSidebarCardClick('<?php echo $t['ticket_id']; ?>', event)">
                    
                    <div class="flex justify-between items-start mb-1">
                        <div class="flex items-center gap-2">
                            <a href="<?php echo $baseUrl; ?>/admin/tickets/opened--view?id=<?php echo $t['ticket_id']; ?>" 
                               target="_blank" onclick="event.stopPropagation();" 
                               class="text-[10px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded uppercase">#<?php echo $t['ticket_id']; ?></a>
                            <span class="text-[9px] font-black uppercase tracking-tighter opacity-70"><?php echo $p; ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] text-slate-600 font-bold uppercase italic"><?php echo $createdDate; ?></span>
                            <input type="checkbox" id="check-<?php echo $t['ticket_id']; ?>" class="w-4 h-4 cursor-pointer" onclick="event.stopPropagation(); toggleHighlight('<?php echo $t['ticket_id']; ?>')">
                        </div>
                    </div>

                    <div class="font-bold text-sm text-slate-800 leading-snug truncate mb-0.5"><?php echo htmlspecialchars($t['subject']); ?></div>
                    <a href="<?php echo $baseUrl; ?>/admin/customers/view?id=<?php echo $t['customer_id']; ?>" 
                       target="_blank" onclick="event.stopPropagation();"
                       class="text-sm font-black text-blue-700 hover:underline block truncate mb-1">
                        <?php echo htmlspecialchars($t['customer_name']); ?>
                    </a>

                    <div class="space-y-2 mt-2">
						<div class="flex items-center text-slate-600 text-[11px]">
							<span class="material-icons text-[14px] mr-1.5 text-slate-400">person</span>
							<span class="font-bold"><?php echo htmlspecialchars($t['assigned_to'] ?: 'Unassigned'); ?></span>
						</div>
						<div class="flex items-start text-slate-600">
							<span class="material-icons text-[16px] mr-1.5 text-blue-500 mt-0.5">location_on</span>
							<span class="text-sm leading-tight font-medium"><?php echo htmlspecialchars($t['service_address']); ?></span>
						</div>
						<div class="flex items-center text-slate-900">
							<span class="material-icons text-[16px] mr-1.5 text-emerald-600">phone</span>
							<?php if (!empty($t['customer_phone']) && $t['customer_phone'] !== 'N/A'): ?>
								<a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $t['customer_phone']); ?>" 
								   class="text-sm font-black hover:text-blue-600 underline decoration-slate-300 underline-offset-2">
									<?php echo htmlspecialchars($t['customer_phone']); ?>
								</a>
							<?php else: ?>
								<span class="text-sm font-bold text-slate-400">No Phone</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
                <?php endforeach; ?>
            </div>

            <!-- TASK LIST (hidden by default) -->
            <div class="sidebar-scroll hidden" id="taskList">
                <?php foreach ($tasks as $tk): 
                    $baseUrl = rtrim($tk['ui_url'] ?? '', '/');
                    $p = strtolower($tk['priority'] ?? 'normal');
                    $pColor = ($p === 'urgent') ? 'border-red-600' : (($p === 'high') ? 'border-orange-500' : 'border-amber-400');
                    $hasAddress = empty($tk['no_address']) && empty($tk['_clamped']);
                    
                    $schedDate = 'No Date';
                    $displayDate = $tk['last_status_changed'] ?? $tk['created_at'] ?? null;
                    if (!empty($displayDate)) {
                        $ts = strtotime($displayDate);
                        $now = time();
                        $daysDiff = floor(($now - $ts) / (60 * 60 * 24));
                        if ($daysDiff > 5) {
                            $schedDate = date("d M", $ts);
                        } else {
                            $schedDate = date("d M, g:i A", $ts);
                        }
                    }
                ?>
                <div class="task-card p-4 border-b hover:bg-amber-50 cursor-pointer border-l-4 transition-all <?php echo $pColor; ?>"
                     id="task-card-<?php echo $tk['task_id']; ?>"
                     data-assignee="<?php echo htmlspecialchars($tk['assignee']); ?>"
                     data-priority="<?php echo htmlspecialchars($tk['priority']); ?>"
                     data-project="<?php echo htmlspecialchars($tk['project'] ?? ''); ?>"
                     data-location="<?php echo htmlspecialchars($tk['location'] ?? ''); ?>"
                     data-has-address="<?php echo $hasAddress ? '1' : '0'; ?>"
                     data-scheduled="<?php echo htmlspecialchars($tk['last_status_changed'] ?? $tk['created_at'] ?? ''); ?>"
                     data-customer-name="<?php echo htmlspecialchars($tk['customer_name'] ?? ''); ?>"
                     onclick="handleTaskCardClick('<?php echo $tk['task_id']; ?>', event)">
                    
                    <div class="flex justify-between items-start mb-1">
                        <div class="flex items-center gap-2">
                            <a href="<?php echo $baseUrl; ?>/admin/scheduling/tasks--view?id=<?php echo $tk['task_id']; ?>" 
                               target="_blank" onclick="event.stopPropagation();" 
                               class="text-[10px] font-bold text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded uppercase">
                               <span class="material-icons text-[10px] align-middle">task_alt</span> #<?php echo $tk['task_id']; ?></a>
                            <span class="text-[9px] font-black uppercase tracking-tighter opacity-70"><?php echo $p; ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] text-slate-600 font-bold uppercase italic"><?php echo $schedDate; ?></span>
                            <input type="checkbox" id="task-check-<?php echo $tk['task_id']; ?>" class="w-4 h-4 cursor-pointer" onclick="event.stopPropagation(); toggleTaskHighlight('<?php echo $tk['task_id']; ?>')">
                        </div>
                    </div>

                    <div class="font-bold text-sm text-slate-800 leading-snug truncate mb-0.5"><?php echo htmlspecialchars($tk['title']); ?></div>
                    
                    <?php if ($tk['related_customer_id']): ?>
                    <a href="<?php echo $baseUrl; ?>/admin/customers/view?id=<?php echo $tk['related_customer_id']; ?>" 
                       target="_blank" onclick="event.stopPropagation();"
                       class="text-sm font-black text-amber-700 hover:underline block truncate mb-1">
                        <?php echo htmlspecialchars($tk['customer_name']); ?>
                    </a>
                    <?php else: ?>
                    <span class="text-sm text-slate-400 block mb-1">No linked customer</span>
                    <?php endif; ?>

                    <div class="space-y-2 mt-2">
                        <div class="flex items-center text-slate-600 text-[11px]">
                            <span class="material-icons text-[14px] mr-1.5 text-slate-400">person</span>
                            <span class="font-bold"><?php echo htmlspecialchars($tk['assignee'] ?: 'Unassigned'); ?></span>
                        </div>
                        <div class="flex items-start text-slate-600">
                            <span class="material-icons text-[16px] mr-1.5 text-amber-500 mt-0.5">location_on</span>
                            <span class="text-sm leading-tight font-medium"><?php echo htmlspecialchars($tk['address']); ?></span>
                        </div>
                        <div class="flex items-center text-slate-900">
                            <span class="material-icons text-[16px] mr-1.5 text-emerald-600">phone</span>
                            <?php if (!empty($tk['customer_phone']) && $tk['customer_phone'] !== 'N/A'): ?>
                                <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $tk['customer_phone']); ?>" 
                                   class="text-sm font-black hover:text-blue-600 underline decoration-slate-300 underline-offset-2">
                                    <?php echo htmlspecialchars($tk['customer_phone']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-sm font-bold text-slate-400">No Phone</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>

    <script>
        let map; let markers = {}; let taskMarkers = {}; let selectedIds = new Set(); let selectedTaskIds = new Set(); let searchMarker = null; let markerCluster = null;
        let activeLayer = 'tickets';
        const tickets = <?php echo json_encode(array_values($mapTickets)); ?>;
        const tasks = <?php echo json_encode(array_values($mapTasks)); ?>;
        const typeConfig = <?php echo json_encode($typeConfig); ?>;
        const projectConfig = <?php echo json_encode($projectConfig); ?>;

        function initMap() {
            // Restore filters from localStorage before applying
            loadFilters();
            
            // Set default date to 30 days ago
            const defaultDate = new Date();
            defaultDate.setDate(defaultDate.getDate() - 30);
            const dateInput = document.getElementById('dateFilterValue');
            if (dateInput && !dateInput.value) {
                dateInput.value = defaultDate.toISOString().split('T')[0];
            }
            
            // Enable/disable date input based on checkbox
            const dateCheckbox = document.getElementById('dateFilterEnabled');
            if (dateCheckbox) {
                dateCheckbox.addEventListener('change', function() {
                    dateInput.disabled = !this.checked;
                });
            }

            // Task date filter — same 30-day default
            const taskDateInput = document.getElementById('taskDateFilterValue');
            if (taskDateInput && !taskDateInput.value) {
                taskDateInput.value = defaultDate.toISOString().split('T')[0];
            }
            const taskDateCheckbox = document.getElementById('taskDateFilterEnabled');
            if (taskDateCheckbox) {
                taskDateCheckbox.addEventListener('change', function() {
                    taskDateInput.disabled = !this.checked;
                });
            }

            map = new google.maps.Map(document.getElementById("map"), { 
                zoom: 11, center: { lat: <?php echo $defaultLat; ?>, lng: <?php echo $defaultLng; ?> }, mapTypeId: 'roadmap',
                styles: [{ featureType: "poi", elementType: "labels", stylers: [{ visibility: "off" }] }]
            });
            const bounds = new google.maps.LatLngBounds();
            const markerArray = [];

            // Offset stacked markers so they don't overlap
            const usedPositions = {};
            function offsetPosition(lat, lng) {
                const key = lat.toFixed(6) + ',' + lng.toFixed(6);
                if (!usedPositions[key]) { usedPositions[key] = 0; }
                const count = usedPositions[key]++;
                if (count === 0) return { lat, lng };
                // Spiral offset: each duplicate gets placed in a small circle
                const angle = (count * 137.5) * Math.PI / 180; // golden angle
                const radius = 0.0002 * Math.ceil(count / 6);  // ~20m per ring
                return { lat: lat + radius * Math.cos(angle), lng: lng + radius * Math.sin(angle) };
            }
            
            tickets.forEach(t => {
                const pos = offsetPosition(parseFloat(t.lat), parseFloat(t.lng));
                const cfg = typeConfig[t.type_label] || typeConfig['default'];
                const baseUrl = (t.ui_url || '').replace(/\/$/, '');
                const cleanPhone = (t.customer_phone || '').replace(/[^0-9+]/g, '');
                
                const dateObj = t.created_at ? new Date(t.created_at) : null;
                const now = new Date();
                const daysDiff = dateObj ? Math.floor((now - dateObj) / (1000 * 60 * 60 * 24)) : 0;
                const monthsDiff = dateObj ? (now.getFullYear() - dateObj.getFullYear()) * 12 + (now.getMonth() - dateObj.getMonth()) : 0;
                
                let formattedDate = 'N/A';
                if (dateObj) {
                    if (monthsDiff >= 12) {
                        // Older than 12 months: show date with 2-digit year, no time
                        formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });
                    } else if (daysDiff > 5) {
                        // Older than 5 days: show date only, no time
                        formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
                    } else {
                        // 5 days or less: show date and time
                        formattedDate = dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                    }
                }

                const phoneHtml = (t.customer_phone && t.customer_phone !== 'N/A') 
                    ? `<div class="flex items-center text-slate-900 mt-1">
                         <span class="material-icons text-[14px] mr-1 text-emerald-600">phone</span>
                         <a href="tel:${cleanPhone}" class="text-xs font-black underline hover:text-blue-600">${t.customer_phone}</a>
                       </div>`
                    : '';

                const marker = new google.maps.Marker({
                    position: pos, map: map,
                    label: { fontFamily: 'Material Icons', text: cfg.icon, color: 'white', fontSize: '14px' },
                    icon: { 
                        path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z", 
                        fillColor: cfg.color, fillOpacity: 1, strokeWeight: 1, strokeColor: '#ffffff', scale: 1.8, labelOrigin: new google.maps.Point(12, 9) 
                    }
                });

               const info = new google.maps.InfoWindow({ content: `
					<div class="p-2 min-w-[240px] font-sans">
                        <div class="flex justify-between items-center mb-1">
						    <div class="text-[10px] font-black text-slate-400 uppercase">${t.priority} PRIORITY</div>
                            <div class="text-[9px] text-slate-600 font-bold italic uppercase">${formattedDate}</div>
                        </div>
						<a href="${baseUrl}/admin/tickets/opened--view?id=${t.ticket_id}" target="_blank" class="text-base font-bold text-blue-600 hover:underline block mb-0.5">${t.subject}</a>
						<a href="${baseUrl}/admin/customers/view?id=${t.customer_id}" target="_blank" class="text-sm font-black text-slate-900 hover:underline block mb-2">${t.customer_name}</a>
						<div class="space-y-1.5 mb-3">
							<div class="text-[11px] text-slate-600"><b>Agent:</b> ${t.assigned_to || 'Unassigned'}</div>
							<div class="text-xs text-slate-800"><b>Address:</b> ${t.service_address}</div>
                            ${phoneHtml}
						</div>
						<button onclick="toggleHighlight('${t.ticket_id}')" class="w-full text-[10px] bg-blue-600 text-white font-black py-2 rounded uppercase shadow-sm hover:bg-blue-700">
							Select for Dispatch
						</button>
					</div>` 
                });

                marker.addListener("click", () => {
                    closeAllInfoWindows();
                    info.open(map, marker);
                    scrollSidebarTo(t.ticket_id);
                });

                markers[t.ticket_id] = { marker, pos, ticket: t, infoWindow: info };
                markerArray.push(marker);
                bounds.extend(pos);
            });

            // --- TASK MARKERS ---
            tasks.forEach(tk => {
                const pos = offsetPosition(parseFloat(tk.lat), parseFloat(tk.lng));
                const baseUrl = (tk.ui_url || '').replace(/\/$/, '');
                const cleanPhone = (tk.customer_phone || '').replace(/[^0-9+]/g, '');
                const pcfg = projectConfig[tk.project] || projectConfig['default'];
                
                const schedDate = (tk.last_status_changed || tk.created_at) ? new Date(tk.last_status_changed || tk.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'No Date';
                
                const phoneHtml = (tk.customer_phone && tk.customer_phone !== 'N/A') 
                    ? `<div class="flex items-center text-slate-900 mt-1">
                         <span class="material-icons text-[14px] mr-1 text-emerald-600">phone</span>
                         <a href="tel:${cleanPhone}" class="text-xs font-black underline hover:text-blue-600">${tk.customer_phone}</a>
                       </div>`
                    : '';

                const customerHtml = tk.related_customer_id 
                    ? `<a href="${baseUrl}/admin/customers/view?id=${tk.related_customer_id}" target="_blank" class="text-sm font-black text-slate-900 hover:underline block mb-2">${tk.customer_name}</a>`
                    : '<span class="text-sm text-slate-400 block mb-2">No linked customer</span>';

                const marker = new google.maps.Marker({
                    position: pos, map: map,
                    label: { fontFamily: 'Material Icons', text: pcfg.icon, color: 'white', fontSize: '14px' },
                    icon: { 
                        path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z", 
                        fillColor: pcfg.color, fillOpacity: 1, strokeWeight: 2, strokeColor: '#ffffff', scale: 1.8, labelOrigin: new google.maps.Point(12, 9) 
                    }
                });

                const info = new google.maps.InfoWindow({ content: `
                    <div class="p-2 min-w-[240px] font-sans">
                        <div class="flex justify-between items-center mb-1">
                            <div class="text-[10px] font-black uppercase" style="color:${pcfg.color}">${tk.priority} PRIORITY &middot; ${tk.project}</div>
                            <div class="text-[9px] text-slate-600 font-bold italic uppercase">${schedDate}</div>
                        </div>
                        <a href="${baseUrl}/admin/scheduling/tasks--view?id=${tk.task_id}" target="_blank" class="text-base font-bold text-amber-700 hover:underline block mb-0.5">${tk.title}</a>
                        ${customerHtml}
                        <div class="space-y-1.5 mb-3">
                            <div class="text-[11px] text-slate-600"><b>Assignee:</b> ${tk.assignee || 'Unassigned'}</div>
                            <div class="text-xs text-slate-800"><b>Address:</b> ${tk.address}</div>
                            ${phoneHtml}
                        </div>
                        <button onclick="toggleTaskHighlight('${tk.task_id}')" class="w-full text-[10px] text-white font-black py-2 rounded uppercase shadow-sm" style="background:${pcfg.color}">
                            Select for Dispatch
                        </button>
                    </div>` 
                });

                marker.addListener("click", () => {
                    closeAllInfoWindows();
                    info.open(map, marker);
                    scrollTaskSidebarTo(tk.task_id);
                });

                taskMarkers[tk.task_id] = { marker, pos, task: tk, infoWindow: info };
                markerArray.push(marker);
                bounds.extend(pos);
            });

            // Initialize marker clustering
            if (typeof markerClusterer !== 'undefined' && markerClusterer.MarkerClusterer) {
                markerCluster = new markerClusterer.MarkerClusterer({ 
                    map, 
                    markers: markerArray,
                    renderer: {
                        render: ({ count, position }) => {
                            return new google.maps.Marker({
                                position,
                                icon: {
                                    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`
                                        <svg xmlns="http://www.w3.org/2000/svg" width="50" height="50">
                                            <circle cx="25" cy="25" r="22" fill="#3b82f6" stroke="white" stroke-width="3"/>
                                            <text x="25" y="32" text-anchor="middle" fill="white" font-family="Arial" font-size="16" font-weight="bold">${count}</text>
                                        </svg>
                                    `)}`,
                                    scaledSize: new google.maps.Size(50, 50)
                                },
                                label: undefined,
                                zIndex: Number(google.maps.Marker.MAX_ZINDEX) + count
                            });
                        }
                    }
                });
            }
            
            if (tickets.length) map.fitBounds(bounds);
            applyFilters();
        }

        function saveFilters() {
            const preferences = {
                agents: Array.from(document.querySelectorAll('.agent-checkbox:checked')).map(cb => cb.value),
                statuses: Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb => cb.value),
                types: Array.from(document.querySelectorAll('.type-checkbox:checked')).map(cb => cb.value),
                priorities: Array.from(document.querySelectorAll('.priority-checkbox:checked')).map(cb => cb.value),
                noAddressOnly: document.getElementById('noAddressOnlyToggle').checked,
                sortBy: document.getElementById('sortBy') ? document.getElementById('sortBy').value : 'priority',
                dateFilterEnabled: document.getElementById('dateFilterEnabled').checked,
                dateFilterValue: document.getElementById('dateFilterValue').value,
                routerFilter: Array.from(document.querySelectorAll('.router-checkbox:checked')).map(cb => cb.value),
                // Task filters
                taskAssignees: Array.from(document.querySelectorAll('.task-assignee-checkbox:checked')).map(cb => cb.value),
                taskPriorities: Array.from(document.querySelectorAll('.task-priority-checkbox:checked')).map(cb => cb.value),
                taskProjects: Array.from(document.querySelectorAll('.task-project-checkbox:checked')).map(cb => cb.value),
                taskLocations: Array.from(document.querySelectorAll('.task-location-checkbox:checked')).map(cb => cb.value),
                taskSortBy: document.getElementById('taskSortBy') ? document.getElementById('taskSortBy').value : 'priority',
                taskDateFilterEnabled: document.getElementById('taskDateFilterEnabled').checked,
                taskDateFilterValue: document.getElementById('taskDateFilterValue').value,
                showTickets: document.getElementById('showTicketsToggle').checked,
                showTasks: document.getElementById('showTasksToggle').checked
            };
            localStorage.setItem('ticketMapFilters', JSON.stringify(preferences));
        }

        function loadFilters() {
            const saved = localStorage.getItem('ticketMapFilters');
            if (!saved) return;
            const prefs = JSON.parse(saved);
            
            const setBoxes = (cls, values) => {
                if (!values) return;
                document.querySelectorAll('.' + cls).forEach(cb => {
                    cb.checked = values.includes(cb.value);
                });
            };

            setBoxes('agent-checkbox', prefs.agents);
            setBoxes('status-checkbox', prefs.statuses);
            setBoxes('type-checkbox', prefs.types);
            setBoxes('priority-checkbox', prefs.priorities);
            
            if (prefs.noAddressOnly !== undefined) {
                document.getElementById('noAddressOnlyToggle').checked = prefs.noAddressOnly;
            }
            
            if (prefs.sortBy && document.getElementById('sortBy')) {
                document.getElementById('sortBy').value = prefs.sortBy;
            }
            
            if (prefs.dateFilterEnabled !== undefined) {
                document.getElementById('dateFilterEnabled').checked = prefs.dateFilterEnabled;
            }
            
            if (prefs.dateFilterValue) {
                document.getElementById('dateFilterValue').value = prefs.dateFilterValue;
            }
            
            if (prefs.routerFilter) {
                setBoxes('router-checkbox', prefs.routerFilter);
            }
            
            // Update date input disabled state
            const dateCheckbox = document.getElementById('dateFilterEnabled');
            const dateInput = document.getElementById('dateFilterValue');
            if (dateCheckbox && dateInput) {
                dateInput.disabled = !dateCheckbox.checked;
            }

            // Task filters
            setBoxes('task-assignee-checkbox', prefs.taskAssignees);
            setBoxes('task-priority-checkbox', prefs.taskPriorities);
            setBoxes('task-project-checkbox', prefs.taskProjects);
            setBoxes('task-location-checkbox', prefs.taskLocations);

            if (prefs.taskSortBy && document.getElementById('taskSortBy')) {
                document.getElementById('taskSortBy').value = prefs.taskSortBy;
            }

            if (prefs.taskDateFilterEnabled !== undefined) {
                document.getElementById('taskDateFilterEnabled').checked = prefs.taskDateFilterEnabled;
            }

            if (prefs.taskDateFilterValue) {
                document.getElementById('taskDateFilterValue').value = prefs.taskDateFilterValue;
            }

            const taskDateCheckbox = document.getElementById('taskDateFilterEnabled');
            const taskDateInput = document.getElementById('taskDateFilterValue');
            if (taskDateCheckbox && taskDateInput) {
                taskDateInput.disabled = !taskDateCheckbox.checked;
            }

            if (prefs.showTickets !== undefined) {
                document.getElementById('showTicketsToggle').checked = prefs.showTickets;
            }
            if (prefs.showTasks !== undefined) {
                document.getElementById('showTasksToggle').checked = prefs.showTasks;
            }
        }

        function searchMapLocation() {
            const input = document.getElementById('mapSearchInput').value;
            if (!input) return;
            const coordsRegex = /^(-?\d+(\.\d+)?),\s*(-?\d+(\.\d+)?)$/;
            const match = input.match(coordsRegex);
            if (match) {
                placeSearchMarker({ lat: parseFloat(match[1]), lng: parseFloat(match[3]) });
            } else {
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode({ address: input, region: '<?php echo $geocodeRegion ?? "nz"; ?>' }, (results, status) => {
                    if (status === "OK" && results.length > 0) {
                        placeSearchMarker(results[0].geometry.location);
                    } else {
                        console.error('Geocode failed:', status);
                        alert('Address not found: ' + status);
                    }
                });
            }
        }

        function placeSearchMarker(location) {
            if (searchMarker) searchMarker.setMap(null);
            map.panTo(location); map.setZoom(17);
            searchMarker = new google.maps.Marker({ position: location, map: map, animation: google.maps.Animation.DROP });
        }

        function toggleHighlight(id) {
            const card = document.getElementById(`card-${id}`);
            const checkbox = document.getElementById(`check-${id}`);
            const m = markers[id];
            if (selectedIds.has(id)) {
                selectedIds.delete(id);
                if(card) card.classList.remove('selected');
                if(checkbox) checkbox.checked = false;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#ffffff', strokeWeight: 1 } });
            } else {
                selectedIds.add(id);
                if(card) card.classList.add('selected');
                if(checkbox) checkbox.checked = true;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#3b82f6', strokeWeight: 4 } });
            }
            document.getElementById('selectedCount').innerText = selectedIds.size + selectedTaskIds.size;
        }

        function applyFilters() {
            const search = document.getElementById('tSearch').value.toLowerCase();
            const selRouters = Array.from(document.querySelectorAll('.router-checkbox:checked')).map(cb => cb.value);
            const allRouters = document.querySelectorAll('.router-checkbox').length;
            const routerFilterActive = selRouters.length < allRouters;
            const selAgents = Array.from(document.querySelectorAll('.agent-checkbox:checked')).map(cb => cb.value);
            const selStatuses = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb => cb.value);
            const selTypes = Array.from(document.querySelectorAll('.type-checkbox:checked')).map(cb => cb.value);
            const selPriorities = Array.from(document.querySelectorAll('.priority-checkbox:checked')).map(cb => cb.value);
            const noAddressToggle = document.getElementById('noAddressOnlyToggle').checked;
            const showTickets = document.getElementById('showTicketsToggle').checked;
            const showTasks = document.getElementById('showTasksToggle').checked;
            
            // Date filter
            const dateFilterEnabled = document.getElementById('dateFilterEnabled').checked;
            const dateFilterValue = document.getElementById('dateFilterValue').value;
            let filterDate = null;
            if (dateFilterEnabled && dateFilterValue) {
                filterDate = new Date(dateFilterValue);
                filterDate.setHours(0, 0, 0, 0);
            }
            
            let visibleMarkers = 0;
            let noAddressCount = 0;

            document.querySelectorAll('.ticket-card').forEach(card => {
                const ticketId = card.id.replace('card-', '');
                const m = markers[ticketId];
                const hasAddr = card.dataset.hasAddress === '1';
                
                // Check if ticket passes its own filters (ignoring layer toggle)
                let passesFilters = selAgents.includes(card.dataset.agent) && selStatuses.includes(card.dataset.status) && 
                              selTypes.includes(card.dataset.type) && selPriorities.includes(card.dataset.priority) &&
                              card.innerText.toLowerCase().includes(search);
                
                // Router filter
                if (passesFilters && routerFilterActive) {
                    const cardRouter = card.dataset.router || '';
                    if (!selRouters.includes(cardRouter)) passesFilters = false;
                }
                
                if (passesFilters && dateFilterEnabled && filterDate && m && m.ticket.created_at) {
                    const ticketDate = new Date(m.ticket.created_at);
                    ticketDate.setHours(0, 0, 0, 0);
                    if (ticketDate < filterDate) passesFilters = false;
                }

                // Count all no-address tickets that pass filters
                if (passesFilters && !hasAddr) noAddressCount++;

                // Visibility: show if (tickets toggle on AND has address) OR (no-address toggle on AND no address)
                let visible = false;
                if (passesFilters) {
                    if (hasAddr && showTickets) visible = true;
                    if (!hasAddr && noAddressToggle) visible = true;
                }

                card.style.display = visible ? 'block' : 'none';
                if(m) {
                    m.marker.setVisible(visible);
                    m.marker.setMap(visible ? map : null);
                }
                
                if (visible && hasAddr) visibleMarkers++;
            });

            // Tasks: apply task filters, then handle no-address OR logic
            // First hide all task markers if tasks toggle is off
            Object.values(taskMarkers).forEach(m => {
                if (!showTasks && !noAddressToggle) {
                    m.marker.setVisible(false);
                    m.marker.setMap(null);
                }
            });

            // Apply task-specific filters
            applyTaskFiltersInternal(showTasks, noAddressToggle);

            // Count no-address tasks and visible task markers
            document.querySelectorAll('.task-card').forEach(card => {
                const hasAddr = card.dataset.hasAddress === '1';
                if (!hasAddr && card.style.display !== 'none') noAddressCount++;
            });
            Object.values(taskMarkers).forEach(m => {
                if (m.marker.getVisible()) visibleMarkers++;
            });

            rebuildCluster();

            document.getElementById('headerMarkerCount').innerText = visibleMarkers;
            document.getElementById('headerNoAddressCount').innerText = noAddressCount;
            
            updateFilterCounts();
            saveFilters();
        }

        function updateVisibleCounts() {
            let visibleMarkers = 0;
            let noAddressCount = 0;
            Object.values(markers).forEach(m => {
                if (m.marker.getVisible()) visibleMarkers++;
            });
            Object.values(taskMarkers).forEach(m => {
                if (m.marker.getVisible()) visibleMarkers++;
            });
            document.querySelectorAll('.ticket-card').forEach(card => {
                if (card.dataset.hasAddress !== '1' && card.style.display !== 'none') noAddressCount++;
            });
            document.querySelectorAll('.task-card').forEach(card => {
                if (card.dataset.hasAddress !== '1' && card.style.display !== 'none') noAddressCount++;
            });
            document.getElementById('headerMarkerCount').innerText = visibleMarkers;
            document.getElementById('headerNoAddressCount').innerText = noAddressCount;
        }

        function scrollSidebarTo(id) {
            const card = document.getElementById(`card-${id}`);
            if (card) {
                if (activeLayer !== 'tickets') switchLayer('tickets');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('flash-highlight');
                setTimeout(() => card.classList.remove('flash-highlight'), 1500);
            }
        }

        function handleSidebarCardClick(id, event) {
            const m = markers[id];
            if (m) { map.panTo(m.pos); map.setZoom(16); closeAllInfoWindows(); m.infoWindow.open(map, m.marker); }
        }

        function toggleAllFilters() {
            const container = document.getElementById('filterContainer');
            const btn = document.getElementById('globalToggle');
            const isHidden = container.classList.toggle('hidden');
            
            // Also toggle filter-section elements (Sort By and Date Filter)
            document.querySelectorAll('.filter-section').forEach(section => {
                section.classList.toggle('minimized', isHidden);
            });
            
            document.querySelectorAll('.filter-group').forEach(g => g.classList.toggle('minimized', isHidden));
            btn.innerText = isHidden ? 'RESTORE FILTERS' : 'MINIMISE FILTERS';
        }

        function closeAllInfoWindows() { Object.values(markers).forEach(m => m.infoWindow.close()); Object.values(taskMarkers).forEach(m => m.infoWindow.close()); }

        function toggleGroup(cls) {
            const cbs = document.querySelectorAll('.' + cls);
            const allChecked = Array.from(cbs).every(c => c.checked);
            cbs.forEach(c => c.checked = !allChecked);
            if (cls.startsWith('task-')) {
                applyTaskFilters();
            } else {
                applyFilters();
            }
        }

        function filterRouterList() {
            const search = document.getElementById('routerSearch').value.toLowerCase();
            document.querySelectorAll('.router-option').forEach(label => {
                const text = label.textContent.toLowerCase();
                label.style.display = text.includes(search) ? '' : 'none';
            });
        }

        function getDirections() {
            const selArr = Array.from(selectedIds);
            const selTaskArr = Array.from(selectedTaskIds);
            if (!selArr.length && !selTaskArr.length) return alert("Select tickets or tasks.");
            
            // Get coordinates for all selected items
            const coords = [
                ...selArr.map(id => ({ id: id, lat: markers[id].ticket.lat, lng: markers[id].ticket.lng, name: markers[id].ticket.customer_name })),
                ...selTaskArr.map(id => ({ id: 'T'+id, lat: taskMarkers[id].task.lat, lng: taskMarkers[id].task.lng, name: taskMarkers[id].task.title }))
            ];
            
            // Simple route optimization: sort by proximity (greedy nearest neighbor)
            const optimized = [coords[0]];
            const remaining = coords.slice(1);
            
            while (remaining.length > 0) {
                const last = optimized[optimized.length - 1];
                let nearestIdx = 0;
                let minDist = Infinity;
                
                remaining.forEach((coord, idx) => {
                    const dist = Math.sqrt(
                        Math.pow(coord.lat - last.lat, 2) + 
                        Math.pow(coord.lng - last.lng, 2)
                    );
                    if (dist < minDist) {
                        minDist = dist;
                        nearestIdx = idx;
                    }
                });
                
                optimized.push(remaining[nearestIdx]);
                remaining.splice(nearestIdx, 1);
            }
            
            // Build Google Maps URL with optimized route
            const dest = optimized[optimized.length - 1];
            let url = `https://www.google.com/maps/dir/?api=1&destination=${dest.lat},${dest.lng}`;
            
            if (optimized.length > 1) {
                const wps = optimized.slice(0, -1).map(c => `${c.lat},${c.lng}`);
                url += `&waypoints=${encodeURIComponent(wps.join('|'))}`;
            }
            
            // Show optimization message
            if (selArr.length > 2) {
                const msg = `Route optimized for ${selArr.length} stops:\n\n` + 
                           optimized.map((c, i) => `${i + 1}. ${c.name}`).join('\n');
                if (confirm(msg + '\n\nOpen in Google Maps?')) {
                    window.open(url, '_blank');
                }
            } else {
                window.open(url, '_blank');
            }
        }

        function printSelected() {
            if (!selectedIds.size && !selectedTaskIds.size) return alert("Select tickets or tasks.");
            const win = window.open('', '_blank');
            let content = '<h2>Dispatch List</h2><table border="1" style="border-collapse:collapse; width:100%"><tr><th>Type</th><th>ID</th><th>Customer</th><th>Subject/Title</th><th>Address</th><th>Date</th></tr>';
            selectedIds.forEach(id => {
                const t = markers[id].ticket;
                content += `<tr><td>Ticket</td><td>#${t.ticket_id}</td><td><b>${t.customer_name}</b><br>${t.customer_phone}</td><td>${t.subject}</td><td>${t.service_address}</td><td>${t.created_at}</td></tr>`;
            });
            selectedTaskIds.forEach(id => {
                const tk = taskMarkers[id].task;
                content += `<tr style="background:#fffbeb"><td>Task</td><td>#${tk.task_id}</td><td><b>${tk.customer_name}</b><br>${tk.customer_phone}</td><td>${tk.title}</td><td>${tk.address}</td><td>${tk.scheduled_from || 'Unscheduled'}</td></tr>`;
            });
            win.document.write(content + '</table>');
            win.document.close(); win.print();
        }

        function toggleFilterPanel() {
            const panel = document.getElementById('filterPanel');
            const icon = document.getElementById('filterToggleIcon');
            const isCollapsed = panel.classList.toggle('panel-collapsed');
            icon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
        }

        function toggleListPanel() {
            const panel = document.getElementById('listPanel');
            const icon = document.getElementById('listToggleIcon');
            const isCollapsed = panel.classList.toggle('panel-collapsed');
            icon.textContent = isCollapsed ? 'chevron_left' : 'chevron_right';
        }

        function fitToVisibleMarkers() {
            const bounds = new google.maps.LatLngBounds();
            let hasVisibleMarkers = false;
            
            Object.values(markers).forEach(m => {
                if (m.marker.getVisible()) { bounds.extend(m.pos); hasVisibleMarkers = true; }
            });
            Object.values(taskMarkers).forEach(m => {
                if (m.marker.getVisible()) { bounds.extend(m.pos); hasVisibleMarkers = true; }
            });
            
            if (hasVisibleMarkers) {
                map.fitBounds(bounds);
            } else {
                alert('No visible markers to fit.');
            }
        }

        function updateFilterCounts() {
            const countByAgent = {};
            const countByPriority = {};
            const countByType = {};
            const countByStatus = {};
            
            document.querySelectorAll('.ticket-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const agent = card.dataset.agent;
                    const priority = card.dataset.priority;
                    const type = card.dataset.type;
                    const status = card.dataset.status;
                    
                    countByAgent[agent] = (countByAgent[agent] || 0) + 1;
                    countByPriority[priority] = (countByPriority[priority] || 0) + 1;
                    countByType[type] = (countByType[type] || 0) + 1;
                    countByStatus[status] = (countByStatus[status] || 0) + 1;
                }
            });
            
            // Update agent labels
            document.querySelectorAll('.agent-checkbox').forEach(cb => {
                const count = countByAgent[cb.value] || 0;
                const label = cb.parentElement;
                const existingCount = label.querySelector('.count-badge');
                if (existingCount) existingCount.remove();
                if (count > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'count-badge ml-auto text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold';
                    badge.textContent = count;
                    label.appendChild(badge);
                }
            });
            
            // Update priority labels
            document.querySelectorAll('.priority-checkbox').forEach(cb => {
                const count = countByPriority[cb.value] || 0;
                const label = cb.parentElement;
                const existingCount = label.querySelector('.count-badge');
                if (existingCount) existingCount.remove();
                if (count > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'count-badge ml-auto text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold';
                    badge.textContent = count;
                    label.appendChild(badge);
                }
            });
            
            // Update type labels
            document.querySelectorAll('.type-checkbox').forEach(cb => {
                const count = countByType[cb.value] || 0;
                const label = cb.parentElement;
                const existingCount = label.querySelector('.count-badge');
                if (existingCount) existingCount.remove();
                if (count > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'count-badge ml-auto text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold';
                    badge.textContent = count;
                    label.appendChild(badge);
                }
            });
            
            // Update status labels
            document.querySelectorAll('.status-checkbox').forEach(cb => {
                const count = countByStatus[cb.value] || 0;
                const label = cb.parentElement;
                const existingCount = label.querySelector('.count-badge');
                if (existingCount) existingCount.remove();
                if (count > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'count-badge ml-auto text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold';
                    badge.textContent = count;
                    label.appendChild(badge);
                }
            });
        }

        function applySorting() {
            const sortBy = document.getElementById('sortBy').value;
            const ticketList = document.getElementById('ticketList');
            const cards = Array.from(ticketList.querySelectorAll('.ticket-card'));
            
            cards.sort((a, b) => {
                const idA = a.id.replace('card-', '');
                const idB = b.id.replace('card-', '');
                
                switch(sortBy) {
                    case 'priority':
                        const priorityOrder = { urgent: 1, high: 2, normal: 3, low: 4 };
                        const priorityA = a.dataset.priority.toLowerCase();
                        const priorityB = b.dataset.priority.toLowerCase();
                        const pA = priorityOrder[priorityA] || 5;
                        const pB = priorityOrder[priorityB] || 5;
                        return pA !== pB ? pA - pB : parseInt(idB) - parseInt(idA);
                    
                    case 'created_newest':
                        const dateA = a.dataset.createdAt ? new Date(a.dataset.createdAt) : new Date(0);
                        const dateB = b.dataset.createdAt ? new Date(b.dataset.createdAt) : new Date(0);
                        return dateB - dateA;
                    
                    case 'created_oldest':
                        const dateA2 = a.dataset.createdAt ? new Date(a.dataset.createdAt) : new Date(0);
                        const dateB2 = b.dataset.createdAt ? new Date(b.dataset.createdAt) : new Date(0);
                        return dateA2 - dateB2;
                    
                    case 'customer_name':
                        const nameA = a.dataset.customerName || '';
                        const nameB = b.dataset.customerName || '';
                        return nameA.localeCompare(nameB);
                    
                    case 'ticket_id':
                        return parseInt(idB) - parseInt(idA);
                    
                    default:
                        return 0;
                }
            });
            
            // Re-append cards in sorted order
            cards.forEach(card => ticketList.appendChild(card));
        }

        // --- HEADER TOGGLE HANDLERS ---
        function onTicketToggle() {
            const ticketsOn = document.getElementById('showTicketsToggle').checked;
            const tasksOn = document.getElementById('showTasksToggle').checked;
            if (ticketsOn) {
                switchLayer('tickets');
            } else if (tasksOn) {
                switchLayer('tasks');
            }
            applyFilters();
        }

        function onTaskToggle() {
            const ticketsOn = document.getElementById('showTicketsToggle').checked;
            const tasksOn = document.getElementById('showTasksToggle').checked;
            if (tasksOn) {
                switchLayer('tasks');
            } else if (ticketsOn) {
                switchLayer('tickets');
            }
            applyFilters();
        }

        // --- LAYER SWITCHING ---
        function switchLayer(layer) {
            activeLayer = layer;
            const tabTickets = document.getElementById('tabTickets');
            const tabTasks = document.getElementById('tabTasks');
            const ticketFilters = document.getElementById('ticketFilters');
            const taskFilters = document.getElementById('taskFilters');
            const ticketList = document.getElementById('ticketList');
            const taskList = document.getElementById('taskList');

            if (layer === 'tickets') {
                tabTickets.classList.add('border-blue-600', 'text-blue-600', 'bg-white');
                tabTickets.classList.remove('border-transparent', 'text-slate-400');
                tabTasks.classList.remove('border-amber-600', 'text-amber-600', 'bg-white');
                tabTasks.classList.add('border-transparent', 'text-slate-400');
                ticketFilters.classList.remove('hidden');
                taskFilters.classList.add('hidden');
                ticketList.classList.remove('hidden');
                taskList.classList.add('hidden');
            } else {
                tabTasks.classList.add('border-amber-600', 'text-amber-600', 'bg-white');
                tabTasks.classList.remove('border-transparent', 'text-slate-400');
                tabTickets.classList.remove('border-blue-600', 'text-blue-600', 'bg-white');
                tabTickets.classList.add('border-transparent', 'text-slate-400');
                taskFilters.classList.remove('hidden');
                ticketFilters.classList.add('hidden');
                taskList.classList.remove('hidden');
                ticketList.classList.add('hidden');
                // Re-apply task filters to ensure cards are visible
                applyTaskFilters();
            }
        }

        // --- TASK FILTERING ---
        function applyTaskFilters() {
            const showTasks = document.getElementById('showTasksToggle').checked;
            const noAddressToggle = document.getElementById('noAddressOnlyToggle').checked;
            applyTaskFiltersInternal(showTasks, noAddressToggle);
            rebuildCluster();
            updateVisibleCounts();
            saveFilters();
        }

        function applyTaskFiltersInternal(showTasks, noAddressToggle) {
            const search = document.getElementById('taskSearch').value.toLowerCase();
            const selAssignees = Array.from(document.querySelectorAll('.task-assignee-checkbox:checked')).map(cb => cb.value);
            const selPriorities = Array.from(document.querySelectorAll('.task-priority-checkbox:checked')).map(cb => cb.value);
            const selProjects = Array.from(document.querySelectorAll('.task-project-checkbox:checked')).map(cb => cb.value);
            const selLocations = Array.from(document.querySelectorAll('.task-location-checkbox:checked')).map(cb => cb.value);

            const taskDateEnabled = document.getElementById('taskDateFilterEnabled').checked;
            const taskDateValue = document.getElementById('taskDateFilterValue').value;
            let taskFilterDate = null;
            if (taskDateEnabled && taskDateValue) {
                taskFilterDate = new Date(taskDateValue);
                taskFilterDate.setHours(0, 0, 0, 0);
            }

            document.querySelectorAll('.task-card').forEach(card => {
                const taskId = card.id.replace('task-card-', '');
                const m = taskMarkers[taskId];
                const hasAddr = card.dataset.hasAddress === '1';

                let passesFilters = selAssignees.includes(card.dataset.assignee) &&
                              selPriorities.includes(card.dataset.priority) &&
                              (selProjects.includes(card.dataset.project) || !card.dataset.project) &&
                              (selLocations.includes(card.dataset.location) || !card.dataset.location) &&
                              card.innerText.toLowerCase().includes(search);

                if (passesFilters && taskDateEnabled && taskFilterDate && card.dataset.scheduled) {
                    const taskDate = new Date(card.dataset.scheduled);
                    taskDate.setHours(0, 0, 0, 0);
                    if (taskDate < taskFilterDate) passesFilters = false;
                }

                let visible = false;
                if (passesFilters) {
                    if (hasAddr && showTasks) visible = true;
                    if (!hasAddr && noAddressToggle) visible = true;
                }

                card.style.display = visible ? 'block' : 'none';
                if (m) {
                    m.marker.setVisible(visible);
                    m.marker.setMap(visible ? map : null);
                }
            });
        }

        function toggleTaskHighlight(id) {
            const card = document.getElementById(`task-card-${id}`);
            const checkbox = document.getElementById(`task-check-${id}`);
            const m = taskMarkers[id];
            if (selectedTaskIds.has(id)) {
                selectedTaskIds.delete(id);
                if(card) card.classList.remove('selected');
                if(checkbox) checkbox.checked = false;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#ffffff', strokeWeight: 2 } });
            } else {
                selectedTaskIds.add(id);
                if(card) card.classList.add('selected');
                if(checkbox) checkbox.checked = true;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#d97706', strokeWeight: 4 } });
            }
            document.getElementById('selectedCount').innerText = selectedIds.size + selectedTaskIds.size;
        }

        function handleTaskCardClick(id, event) {
            const m = taskMarkers[id];
            if (m) { map.panTo(m.pos); map.setZoom(16); closeAllInfoWindows(); m.infoWindow.open(map, m.marker); }
        }

        function scrollTaskSidebarTo(id) {
            const card = document.getElementById(`task-card-${id}`);
            if (card) {
                if (activeLayer !== 'tasks') switchLayer('tasks');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('flash-highlight');
                setTimeout(() => card.classList.remove('flash-highlight'), 1500);
            }
        }

        function toggleAllTaskFilters() {
            const container = document.getElementById('taskFilterContainer');
            const btn = document.getElementById('taskGlobalToggle');
            const isHidden = container.classList.toggle('hidden');
            document.querySelectorAll('.task-filter-section').forEach(s => s.classList.toggle('minimized', isHidden));
            btn.innerText = isHidden ? 'RESTORE FILTERS' : 'MINIMISE FILTERS';
        }

        function applyTaskSorting() {
            const sortBy = document.getElementById('taskSortBy').value;
            const taskList = document.getElementById('taskList');
            const cards = Array.from(taskList.querySelectorAll('.task-card'));
            
            cards.sort((a, b) => {
                const idA = a.id.replace('task-card-', '');
                const idB = b.id.replace('task-card-', '');
                
                switch(sortBy) {
                    case 'priority':
                        const po = { urgent: 1, high: 2, normal: 3, low: 4 };
                        return (po[a.dataset.priority.toLowerCase()] || 5) - (po[b.dataset.priority.toLowerCase()] || 5) || parseInt(idB) - parseInt(idA);
                    case 'scheduled_newest':
                        return (b.dataset.scheduled ? new Date(b.dataset.scheduled) : new Date(0)) - (a.dataset.scheduled ? new Date(a.dataset.scheduled) : new Date(0));
                    case 'scheduled_oldest':
                        return (a.dataset.scheduled ? new Date(a.dataset.scheduled) : new Date(0)) - (b.dataset.scheduled ? new Date(b.dataset.scheduled) : new Date(0));
                    case 'customer_name':
                        return (a.dataset.customerName || '').localeCompare(b.dataset.customerName || '');
                    case 'task_id':
                        return parseInt(idB) - parseInt(idA);
                    default: return 0;
                }
            });
            cards.forEach(card => taskList.appendChild(card));
            saveFilters();
        }

        function rebuildCluster() {
            if (!markerCluster) return;
            markerCluster.clearMarkers();
            const visible = [];
            Object.values(markers).forEach(m => {
                if (m.marker.getVisible()) {
                    m.marker.setMap(map);
                    visible.push(m.marker);
                }
            });
            Object.values(taskMarkers).forEach(m => {
                if (m.marker.getVisible()) {
                    m.marker.setMap(map);
                    visible.push(m.marker);
                }
            });
            markerCluster.addMarkers(visible);
        }

        document.querySelectorAll('.filter-cb').forEach(cb => cb.addEventListener('change', applyFilters));
        document.getElementById('tSearch').addEventListener('input', function() {
            const el = this;
            const pos = el.selectionStart;
            clearTimeout(el._debounce);
            el._debounce = setTimeout(() => {
                applyFilters();
                el.focus();
                el.selectionStart = el.selectionEnd = pos;
            }, 150);
        });
        document.querySelectorAll('.task-filter-cb').forEach(cb => cb.addEventListener('change', applyTaskFilters));
        document.getElementById('taskSearch').addEventListener('input', applyTaskFilters);
        const sortByEl = document.getElementById('sortBy');
        if (sortByEl) {
            sortByEl.addEventListener('change', () => { applySorting(); saveFilters(); });
        }
    </script>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo $googleApiKey; ?>&libraries=geocoding&callback=initMap"></script>
</body>
</html>