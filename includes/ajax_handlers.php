<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

function synapseRequireAdmin()
{
    $adminId = 0;
    if (class_exists('WHMCS\\Authentication\\CurrentUser')) {
        try {
            $current = new WHMCS\Authentication\CurrentUser();
            if (method_exists($current, 'admin')) {
                $admin = $current->admin();
                if ($admin && isset($admin->id)) {
                    $adminId = (int) $admin->id;
                }
            }
            if ($adminId < 1 && method_exists($current, 'isAuthenticatedAdmin') && $current->isAuthenticatedAdmin()) {
                $adminId = 1;
            }
        } catch (Throwable $e) {
            $adminId = 0;
        }
    }
    if ($adminId < 1 && class_exists('WHMCS\\Session')) {
        $adminId = (int) WHMCS\Session::get('adminid');
    }
    if ($adminId < 1 && isset($_SESSION['adminid'])) {
        $adminId = (int) $_SESSION['adminid'];
    }
    if ($adminId < 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
        exit;
    }

    $provided = $_SERVER['HTTP_X_SYNAPSE_TOKEN'] ?? $_POST['synapse_token'] ?? '';
    $expected = '';
    if (class_exists('WHMCS\\Session')) {
        $expected = (string) WHMCS\Session::get('synapseCsrfToken');
    }
    if ($expected === '' && isset($_SESSION['synapseCsrfToken'])) {
        $expected = (string) $_SESSION['synapseCsrfToken'];
    }
    if ($expected === '' || !hash_equals($expected, (string) $provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

function synapseHandleAjaxRequest($action)
{
    switch ($action) {
        case 'ticket_status':
            return synapseAjaxTicketStatus();
        case 'tickets_badges':
            return synapseAjaxTicketsBadges();
        case 'test_connection':
            return synapseAjaxTestConnection();
        case 'department_stats':
            return synapseAjaxDepartmentStats();
        case 'check_update':
            return synapseAjaxCheckUpdate();
        case 'apply_update':
            return synapseAjaxApplyUpdate();
        default:
            throw new Exception('Unknown action');
    }
}

function synapseRunAjaxAndExit($action)
{
    header('Content-Type: application/json');
    try {
        echo json_encode(synapseHandleAjaxRequest($action));
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

function synapseAjaxTicketStatus()
{
    $ticket_id = $_GET['id'] ?? '';

    if (!$ticket_id || !is_numeric($ticket_id)) {
        throw new Exception('Invalid ticket ID');
    }

    $synapse_data = Capsule::table('synapse_tickets')
        ->where('whmcs_ticket_id', $ticket_id)
        ->first();

    return [
        'success' => true,
        'synapse_data' => $synapse_data ? [
            'synapse_ticket_id' => $synapse_data->synapse_ticket_id,
            'confidence' => round((float) $synapse_data->confidence, 1),
            'ai_decision' => $synapse_data->ai_decision,
            'action_taken' => $synapse_data->action_taken,
            'processed_at' => $synapse_data->created_at,
        ] : null,
    ];
}

function synapseAjaxTicketsBadges()
{
    $ids = $_GET['ids'] ?? $_POST['ids'] ?? '';
    if (is_string($ids)) {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
    } elseif (is_array($ids)) {
        $ids = array_filter(array_map('intval', $ids));
    } else {
        $ids = [];
    }
    $ids = array_values(array_unique($ids));
    $ids = array_slice($ids, 0, 200);
    if ($ids === []) {
        return [
            'success' => true,
            'badges' => [],
        ];
    }

    $rows = Capsule::table('synapse_tickets')
        ->whereIn('whmcs_ticket_id', $ids)
        ->get();

    $badges = [];
    foreach ($rows as $row) {
        $badges[(string) (int) $row->whmcs_ticket_id] = [
            'confidence' => round((float) $row->confidence, 1),
            'ai_decision' => $row->ai_decision,
            'action_taken' => $row->action_taken,
        ];
    }

    return [
        'success' => true,
        'badges' => $badges,
    ];
}

function synapseAjaxTestConnection()
{
    require_once __DIR__ . '/SynapseClient.php';

    $client = new SynapseClient();
    $result = $client->testConnection();

    return [
        'success' => $result['success'],
        'message' => $result['success'] ? 'Connection successful' : $result['error'],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

function synapseAjaxDepartmentStats()
{
    $stats = [];

    $departments = Capsule::table('tblticketdepartments')
        ->where('hidden', 0)
        ->get();

    foreach ($departments as $dept) {
        $synapse_config = Capsule::table('synapse_config')
            ->where('department_id', $dept->id)
            ->first();

        $ticket_count = Capsule::table('synapse_tickets')
            ->join('tbltickets', 'synapse_tickets.whmcs_ticket_id', '=', 'tbltickets.id')
            ->where('tbltickets.did', $dept->id)
            ->count();

        $stats[] = [
            'id' => $dept->id,
            'name' => $dept->name,
            'ai_mode' => $synapse_config->ai_mode ?? 'observe',
            'enabled' => $synapse_config->enabled ?? 0,
            'processed_tickets' => $ticket_count,
        ];
    }

    return [
        'success' => true,
        'departments' => $stats,
    ];
}

function synapseAjaxCheckUpdate()
{
    require_once __DIR__ . '/SynapseClient.php';
    require_once __DIR__ . '/version.php';
    $client = new SynapseClient();
    $info = $client->getAddonUpdate();
    $latest = (string) ($info['latestVersion'] ?? '');
    $current = SYNAPSE_ADDON_VERSION;

    return [
        'success' => true,
        'currentVersion' => $current,
        'latestVersion' => $latest,
        'updateAvailable' => $latest !== '' && version_compare($latest, $current, '>'),
        'sha256' => $info['sha256'] ?? '',
        'size' => $info['size'] ?? 0,
        'changelog' => $info['changelog'] ?? '',
    ];
}

function synapseSafeAddonPath($relative, $root)
{
    $relative = str_replace('\\', '/', (string) $relative);
    if ($relative === '' || strpos($relative, '..') !== false || $relative[0] === '/') {
        return null;
    }
    $target = $root . '/' . $relative;
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return null;
    }
    $parent = dirname($target);
    if (!is_dir($parent)) {
        return $target;
    }
    $parentReal = realpath($parent);
    if ($parentReal === false || strpos($parentReal, $rootReal) !== 0) {
        return null;
    }
    return $target;
}

function synapseAjaxApplyUpdate()
{
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP zip extension is required to install updates');
    }
    $root = dirname(__DIR__);
    $probe = $root . '/.synapse_write_test_' . bin2hex(random_bytes(4));
    $writable = @file_put_contents($probe, 'ok') !== false;
    @unlink($probe);
    if (!$writable) {
        $phpUser = function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : get_current_user();
        throw new Exception(
            "Addon directory is not writable by PHP (running as '{$phpUser}'). "
            . "Run: chown -R {$phpUser}:{$phpUser} " . $root
        );
    }
    require_once __DIR__ . '/SynapseClient.php';
    require_once __DIR__ . '/SynapseHmac.php';
    require_once __DIR__ . '/version.php';
    $client = new SynapseClient();
    $info = $client->getAddonUpdate();
    $latest = (string) ($info['latestVersion'] ?? '');
    $expectedHash = strtolower((string) ($info['sha256'] ?? ''));
    $expectedHmac = strtolower((string) ($info['hmac'] ?? ''));
    if ($latest === '' || $expectedHash === '' || $expectedHmac === '') {
        throw new Exception('Update metadata is incomplete');
    }
    if (!version_compare($latest, SYNAPSE_ADDON_VERSION, '>')) {
        return [
            'success' => true,
            'updated' => false,
            'message' => 'Already on the latest version',
            'version' => SYNAPSE_ADDON_VERSION,
        ];
    }
    $download = $client->downloadAddonZip();
    $zipData = $download['body'];
    $actualHash = hash('sha256', $zipData);
    if (!hash_equals($expectedHash, $actualHash)) {
        throw new Exception('Downloaded package checksum mismatch');
    }
    $actualHmac = strtolower(synapseAddonPackageHmac($zipData, $client->licenseKey()));
    if (!hash_equals($expectedHmac, $actualHmac)) {
        throw new Exception('Downloaded package signature mismatch');
    }
    $contentHmacHeader = strtolower((string) ($download['headers']['content-hmac'] ?? ''));
    if ($contentHmacHeader !== '' && !hash_equals($contentHmacHeader, $actualHmac)) {
        throw new Exception('Download Content-Hmac mismatch');
    }
    $tmpZip = tempnam(sys_get_temp_dir(), 'synapse-addon-');
    if ($tmpZip === false || file_put_contents($tmpZip, $zipData) === false) {
        throw new Exception('Unable to store update package');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        throw new Exception('Unable to open update package');
    }
    $allowedExtensions = ['php', 'css', 'js', 'md'];
    $written = 0;
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || substr($name, -1) === '/') {
                continue;
            }
            $base = basename($name);
            if ($base === '.git' || strpos($name, '.git/') !== false) {
                continue;
            }
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new Exception('Update package contains a disallowed file type');
            }
            $target = synapseSafeAddonPath($name, $root);
            if ($target === null) {
                throw new Exception('Update package contains an invalid path');
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('Unable to create directory for update');
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                throw new Exception('Unable to read a file from the update package');
            }
            if ($base === 'callback.php' && file_exists($target)) {
                $existingHash = hash_file('sha256', $target);
                $newHash = hash('sha256', $contents);
                if (!hash_equals($existingHash, $newHash)) {
                    throw new Exception('Refusing to overwrite callback.php with different content');
                }
            }
            if (file_put_contents($target, $contents) === false) {
                throw new Exception('Unable to write updated files');
            }
            $written++;
        }
    } finally {
        $zip->close();
        @unlink($tmpZip);
    }
    if ($written < 1) {
        throw new Exception('Update package was empty');
    }
    if (file_exists($root . '/synapse.php')) {
        require_once $root . '/synapse.php';
        if (function_exists('synapse_upgrade')) {
            synapse_upgrade(['version' => $latest]);
        }
    }

    return [
        'success' => true,
        'updated' => true,
        'files' => $written,
        'version' => $latest,
    ];
}
