<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/includes/version.php';

function synapse_config()
{
    return [
        'name' => 'Synapse AI Autopilot',
        'description' => 'Enterprise AI automation for customer support tickets with VM management integration.',
        'version' => SYNAPSE_ADDON_VERSION,
        'author' => 'YecoAI',
        'language' => 'english',
        'fields' => [
            'license_key' => [
                'FriendlyName' => 'License Key',
                'Type' => 'password',
                'Size' => '40',
                'Description' => 'Your Synapse license key (SYN-XXXX-XXXX-XXXX). HMAC signatures and payload encryption are derived from this key automatically.',
            ],
            'backend_url' => [
                'FriendlyName' => 'Backend URL',
                'Type' => 'text',
                'Size' => '50',
                'Default' => 'https://api-synapse.yecoai.com/api/v1',
                'Description' => 'Synapse API base URL including /api/v1 (e.g. https://api-synapse.yecoai.com/api/v1)',
            ],
            'confidence_threshold' => [
                'FriendlyName' => 'Confidence Threshold (%)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '85',
                'Description' => 'Minimum AI confidence required for automatic actions (50-99)',
            ],
            'auto_close' => [
                'FriendlyName' => 'Auto-Close Resolved Tickets',
                'Type' => 'yesno',
                'Description' => 'Automatically close tickets when AI provides complete solution',
            ],
            'debug_mode' => [
                'FriendlyName' => 'Debug Mode',
                'Type' => 'yesno',
                'Description' => 'Enable detailed logging for troubleshooting',
            ],
            'callback_secret' => [
                'FriendlyName' => 'Callback HMAC Secret',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'Filled automatically from the Synapse backend. Used to sign backend-to-WHMCS callbacks.',
            ],
            'api_admin' => [
                'FriendlyName' => 'WHMCS API Admin Username',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Dedicated admin username used for localAPI calls. Create a least-privilege admin and set it here.',
            ],
            'whitelist_ips' => [
                'FriendlyName' => 'Backend IP Whitelist',
                'Type' => 'textarea',
                'Rows' => '3',
                'Description' => 'Comma-separated list of allowed backend IPs (optional)',
            ],
        ]
    ];
}

function synapse_activate()
{
    $query = "CREATE TABLE IF NOT EXISTS `synapse_tickets` (
        `id` int(10) NOT NULL AUTO_INCREMENT,
        `whmcs_ticket_id` int(10) NOT NULL,
        `synapse_ticket_id` varchar(64) NOT NULL,
        `confidence` decimal(5,2) DEFAULT '0.00',
        `action_taken` varchar(255) DEFAULT NULL,
        `ai_decision` enum('autopilot','copilot','escalated') DEFAULT 'escalated',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `whmcs_ticket_id` (`whmcs_ticket_id`),
        KEY `synapse_ticket_id` (`synapse_ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    full_query($query);
    
    $query = "CREATE TABLE IF NOT EXISTS `synapse_config` (
        `id` int(10) NOT NULL AUTO_INCREMENT,
        `department_id` int(10) NOT NULL,
        `department_name` varchar(255) NOT NULL,
        `ai_mode` enum('observe','copilot','autopilot') DEFAULT 'observe',
        `enabled` tinyint(1) DEFAULT '1',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `department_id` (`department_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    full_query($query);

    $query = "CREATE TABLE IF NOT EXISTS `synapse_queue` (
        `id` int(10) NOT NULL AUTO_INCREMENT,
        `whmcs_ticket_id` int(10) NOT NULL,
        `is_reply` tinyint(1) NOT NULL DEFAULT '0',
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `event_type` varchar(32) NOT NULL DEFAULT 'ingest',
        `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
        `last_error` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `processed_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `whmcs_ticket_id` (`whmcs_ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    full_query($query);

    return [
        'status' => 'success',
        'description' => 'Synapse AI Autopilot activated successfully. Configure your license key to begin.',
    ];
}

function synapse_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Synapse AI Autopilot deactivated. Database tables preserved.',
    ];
}

function synapse_upgrade($vars)
{
    $version = $vars['version'];

    $query = "CREATE TABLE IF NOT EXISTS `synapse_queue` (
        `id` int(10) NOT NULL AUTO_INCREMENT,
        `whmcs_ticket_id` int(10) NOT NULL,
        `is_reply` tinyint(1) NOT NULL DEFAULT '0',
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `event_type` varchar(32) NOT NULL DEFAULT 'ingest',
        `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
        `last_error` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `processed_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `whmcs_ticket_id` (`whmcs_ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    full_query($query);

    if (class_exists('WHMCS\\Database\\Capsule') && Capsule::schema()->hasTable('synapse_queue') && !Capsule::schema()->hasColumn('synapse_queue', 'message')) {
        Capsule::schema()->table('synapse_queue', function ($table) {
            $table->text('message')->nullable();
        });
    }
    if (class_exists('WHMCS\\Database\\Capsule') && Capsule::schema()->hasTable('synapse_queue') && !Capsule::schema()->hasColumn('synapse_queue', 'reply_id')) {
        Capsule::schema()->table('synapse_queue', function ($table) {
            $table->integer('reply_id')->nullable();
        });
    }
    if (class_exists('WHMCS\\Database\\Capsule') && Capsule::schema()->hasTable('synapse_queue') && !Capsule::schema()->hasColumn('synapse_queue', 'event_type')) {
        Capsule::schema()->table('synapse_queue', function ($table) {
            $table->string('event_type', 32)->default('ingest');
        });
    }
    
    return [
        'status' => 'success',
        'description' => "Synapse AI Autopilot upgraded to version {$version}.",
    ];
}

function synapse_cron($vars)
{
    if (!defined('SYNAPSE_CALLBACK_SKIP_MAIN')) {
        define('SYNAPSE_CALLBACK_SKIP_MAIN', true);
    }
    if (file_exists(__DIR__ . '/callback.php')) {
        require_once __DIR__ . '/callback.php';
        if (function_exists('synapseCleanupNonces')) {
            synapseCleanupNonces();
        }
    }
    if (file_exists(__DIR__ . '/hooks.php')) {
        require_once __DIR__ . '/hooks.php';
        if (function_exists('synapseSweepStaleApprovals')) {
            synapseSweepStaleApprovals(100);
        }
        if (function_exists('synapseProcessQueue')) {
            synapseProcessQueue(25);
        }
    }
}

function synapseAdminUrl($modulelink, $tab, $extra = [])
{
    $parts = [];
    $parsed = parse_url((string) $modulelink);
    $path = $parsed['path'] ?? 'addonmodules.php';
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $parts);
    }
    unset($parts['action']);
    $parts['module'] = $parts['module'] ?? 'synapse';
    $parts['synapse_tab'] = $tab;
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[$key] = $value;
    }
    return $path . '?' . http_build_query($parts);
}

function synapseAdminAjaxUrl($modulelink, $action, $extra = [])
{
    $parts = [];
    $parsed = parse_url((string) $modulelink);
    $path = $parsed['path'] ?? 'addonmodules.php';
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $parts);
    }
    unset($parts['action']);
    $parts['module'] = $parts['module'] ?? 'synapse';
    $parts['synapse_ajax'] = $action;
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $parts[$key] = $value;
    }
    return $path . '?' . http_build_query($parts);
}

function synapse_output($vars)
{
    $ajaxAction = (string) ($_REQUEST['synapse_ajax'] ?? '');
    if ($ajaxAction !== '') {
        require_once __DIR__ . '/includes/ajax_handlers.php';
        synapseRequireAdmin();
        synapseRunAjaxAndExit($ajaxAction);
    }

    $license_key = $vars['license_key'];
    $backend_url = rtrim($vars['backend_url'], '/');
    $debug_mode = $vars['debug_mode'];
    
    if (empty($license_key) || empty($backend_url)) {
        echo '<div class="alert alert-warning">
            <strong>Configuration Required:</strong> Please configure your license key and backend URL.
        </div>';
        return;
    }

    $modulelink = $vars['modulelink'];
    $synapse_tab = (string) ($_REQUEST['synapse_tab'] ?? $_GET['synapse_tab'] ?? 'overview');
    $allowed_tabs = ['overview', 'update', 'departments', 'tickets', 'diagnostics', 'logs'];
    if (!in_array($synapse_tab, $allowed_tabs, true)) {
        $synapse_tab = 'overview';
    }
    $csrfToken = '';
    if (class_exists('WHMCS\\Session')) {
        $csrfToken = (string) WHMCS\Session::get('synapseCsrfToken');
    }
    if ($csrfToken === '' && !empty($_SESSION['synapseCsrfToken'])) {
        $csrfToken = (string) $_SESSION['synapseCsrfToken'];
    }
    if ($csrfToken === '') {
        $csrfToken = bin2hex(random_bytes(16));
    }
    if (class_exists('WHMCS\\Session')) {
        WHMCS\Session::set('synapseCsrfToken', $csrfToken);
    }
    $_SESSION['synapseCsrfToken'] = $csrfToken;
    echo '<script>window.synapseCsrfToken = ' . json_encode($csrfToken) . ';</script>';
    
    echo '<div class="synapse-admin-panel">';
    
    include __DIR__ . '/templates/navigation.php';
    
    switch ($synapse_tab) {
        case 'update':
            include __DIR__ . '/templates/update.php';
            break;
        case 'departments':
            include __DIR__ . '/templates/departments.php';
            break;
        case 'diagnostics':
            include __DIR__ . '/templates/diagnostics.php';
            break;
        case 'tickets':
            include __DIR__ . '/templates/tickets.php';
            break;
        case 'logs':
            include __DIR__ . '/templates/logs.php';
            break;
        default:
            include __DIR__ . '/templates/dashboard.php';
    }
    
    echo '</div>';
    
    echo '<style>
        .synapse-admin-panel { margin-top: 20px; }
        .synapse-nav { border-bottom: 1px solid #ddd; margin-bottom: 20px; }
        .synapse-nav a { 
            display: inline-block; 
            padding: 10px 15px; 
            margin-right: 5px; 
            text-decoration: none;
            border-bottom: 2px solid transparent;
        }
        .synapse-nav a.active { 
            border-bottom-color: #0073aa; 
            font-weight: bold; 
        }
        .synapse-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .synapse-metric {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .synapse-metric h3 { margin: 0 0 5px 0; font-size: 24px; }
        .synapse-metric p { margin: 0; color: #666; }
        .synapse-status { 
            padding: 3px 8px; 
            border-radius: 3px; 
            font-size: 11px; 
            font-weight: bold; 
        }
        .status-healthy { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-error { background: #f8d7da; color: #721c24; }
    </style>';
}