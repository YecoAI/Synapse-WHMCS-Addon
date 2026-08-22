<?php

require_once '../../../init.php';
require_once '../../../includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/SynapseHmac.php';

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("Access denied");
}

function synapseLog($message, $level = 'INFO') {
    $settings = getSynapseSettings();
    if ($settings['debug_mode'] === 'on') {
        logActivity("[Synapse {$level}] {$message}");
    }
}

function synapsePlainLicenseKey($raw) {
    $key = trim((string) $raw);
    if ($key === '') {
        return '';
    }
    if (stripos($key, 'SYN-') === 0) {
        return $key;
    }
    if (function_exists('decrypt')) {
        $dec = decrypt($key);
        if (is_string($dec) && stripos(trim($dec), 'SYN-') === 0) {
            return trim($dec);
        }
    }
    return $key;
}

function synapseHmacKey() {
    $settings = getSynapseSettings();
    $license = synapsePlainLicenseKey($settings['license_key'] ?? '');
    return synapseHmacKeyFrom($license);
}

function getSynapseSettings() {
    static $settings = null;
    if ($settings === null) {
        $result = Capsule::table('tbladdonmodules')
            ->where('module', 'synapse')
            ->pluck('value', 'setting');
        $settings = $result;
    }
    return $settings;
}

function validateSignature($body, $signature, $timestamp, $nonce) {
    $settings = getSynapseSettings();
    $dedicated = trim((string) ($settings['callback_secret'] ?? ''));
    if ($dedicated === '') {
        return false;
    }

    if ($timestamp === null || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    if ($nonce === null || strlen($nonce) < 8 || strlen($nonce) > 128) {
        return false;
    }
    if (!is_string($signature) || $signature === '') {
        return false;
    }

    $payload = $timestamp . '.' . $nonce . '.' . $body;
    $secret = synapseHmacKeyFrom($dedicated);
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        return false;
    }

    if (!synapseConsumeNonce($nonce, (int)$timestamp)) {
        return false;
    }
    return 'callback_secret';
}

function synapseConsumeNonce($nonce, $timestamp) {
    $dir = sys_get_temp_dir() . '/synapse-nonces';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $file = $dir . '/' . hash('sha256', $nonce) . '.nonce';
    $fh = @fopen($file, 'x');
    if ($fh === false) {
        return false;
    }
    fwrite($fh, (string)$timestamp);
    fclose($fh);
    return true;
}

function synapseCleanupNonces() {
    $dir = sys_get_temp_dir() . '/synapse-nonces';
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    foreach (glob($dir . '/*.nonce') ?: [] as $old) {
        if ($now - filemtime($old) > 600) {
            @unlink($old);
        }
    }
}

function synapseClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function validateIpWhitelist() {
    $settings = getSynapseSettings();
    $whitelist = $settings['whitelist_ips'] ?? '';

    if (empty($whitelist)) {
        return true;
    }

    $allowed_ips = array_map('trim', explode(',', $whitelist));
    $client_ip = synapseClientIp();
    if (in_array($client_ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
        return true;
    }

    if (in_array($client_ip, $allowed_ips, true)) {
        return true;
    }

    foreach ($allowed_ips as $allowed) {
        if (strpos($allowed, '/') !== false) {
            if (isIpInRange($client_ip, $allowed)) {
                return true;
            }
        }
    }

    return false;
}

function isIpInRange($ip, $cidr) {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return false;
    }
    $subnet = $parts[0];
    $mask = (int)$parts[1];
    $ipBin = inet_pton($ip);
    $subBin = inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false;
    }
    $len = strlen($ipBin);
    $bits = $len * 8;
    if ($mask < 0 || $mask > $bits) {
        return false;
    }
    $fullBytes = intdiv($mask, 8);
    $remain = $mask % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
        return false;
    }
    if ($remain === 0) {
        return true;
    }
    $maskByte = chr((0xFF << (8 - $remain)) & 0xFF);
    return ($ipBin[$fullBytes] & $maskByte) === ($subBin[$fullBytes] & $maskByte);
}

function synapseAdminIdentity() {
    static $identity = null;
    if ($identity !== null) {
        return $identity;
    }
    $settings = getSynapseSettings();
    $configured = trim((string) ($settings['api_admin'] ?? ''));
    if ($configured === '') {
        throw new Exception('api_admin is not configured');
    }
    $row = Capsule::table('tbladmins')
        ->where('disabled', 0)
        ->where('username', $configured)
        ->first(['username', 'firstname', 'lastname', 'email']);
    if (!$row || trim((string) $row->username) === '') {
        throw new Exception('Configured api_admin is not an active WHMCS admin');
    }
    $name = trim((string) $row->firstname . ' ' . (string) $row->lastname);
    if ($name === '') {
        $name = (string) $row->username;
    }
    $identity = [
        'username' => (string) $row->username,
        'name' => $name,
        'email' => (string) $row->email,
    ];
    return $identity;
}

function synapseAdminUsername() {
    return synapseAdminIdentity()['username'];
}

function synapseLocalAPI($command, $values) {
    return localAPI($command, $values, synapseAdminUsername());
}

function synapseNormalizeList($value) {
    if (!is_array($value) || $value === []) {
        return [];
    }
    if (isset($value['id']) || isset($value['pid']) || isset($value['productid']) || isset($value['value']) || isset($value['ticketid'])) {
        return [$value];
    }
    return array_values($value);
}

function synapseProductCustomFields($product) {
    $fields = [];
    $raw = $product['customfields']['customfield'] ?? $product['customfields'] ?? [];
    foreach (synapseNormalizeList($raw) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = (string) ($field['name'] ?? $field['translated_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $fields[$name] = (string) ($field['value'] ?? '');
    }
    return $fields;
}

function synapseProductVmId($product, $fields) {
    foreach ($fields as $name => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $key = strtolower(trim((string) $name));
        if (str_contains($key, 'vmid') || str_contains($key, 'vm id') || str_contains($key, 'proxmox') || str_contains($key, 'virtfusion') || str_contains($key, 'virtualizor') || $key === 'vpsid' || $key === 'vps id' || $key === 'server id' || $key === 'ctid') {
            return $value;
        }
    }
    return '';
}

function handlePing() {
    synapseLog("Ping received from backend");
    return [
        'status' => 'success',
        'message' => 'Synapse addon is active',
        'version' => SYNAPSE_ADDON_VERSION,
        'timestamp' => time()
    ];
}

function handleReplyPost($data) {
    $ticket_id = $data['whmcs_ticket_id'] ?? null;
    $reply = $data['reply'] ?? '';
    $close = $data['close'] ?? false;
    
    if (!is_numeric($ticket_id) || !$reply) {
        throw new Exception('Missing required fields: whmcs_ticket_id, reply');
    }
    $ticket_id = (int)$ticket_id;
    
    synapseLog("Posting reply to ticket {$ticket_id}");
    
    if (function_exists('synapseMarkInternalAction')) {
        synapseMarkInternalAction($ticket_id);
    }

    $admin = synapseAdminIdentity();
    $result = synapseLocalAPI('AddTicketReply', [
        'ticketid' => $ticket_id,
        'message' => $reply,
        'status' => $close ? 'Closed' : 'Answered',
        'adminusername' => $admin['username'],
        'name' => $admin['name'],
        'email' => $admin['email'],
    ]);
    
    if ($result['result'] !== 'success') {
        throw new Exception("Failed to post reply: " . ($result['message'] ?? 'Unknown error'));
    }
    
    synapseLog("Reply posted successfully to ticket {$ticket_id}" . ($close ? " (closed)" : ""));
    
    return [
        'status' => 'success',
        'ticket_id' => $ticket_id,
        'closed' => $close
    ];
}

function handleTicketClose($data) {
    $ticket_id = $data['whmcs_ticket_id'] ?? null;
    
    if (!is_numeric($ticket_id)) {
        throw new Exception('Missing required field: whmcs_ticket_id');
    }
    $ticket_id = (int)$ticket_id;
    
    synapseLog("Closing ticket {$ticket_id}");
    
    if (function_exists('synapseMarkInternalAction')) {
        synapseMarkInternalAction($ticket_id);
    }

    $result = synapseLocalAPI('UpdateTicket', [
        'ticketid' => $ticket_id,
        'status' => 'Closed'
    ]);
    
    if ($result['result'] !== 'success') {
        throw new Exception("Failed to close ticket: " . ($result['message'] ?? 'Unknown error'));
    }
    
    synapseLog("Ticket {$ticket_id} closed successfully");
    
    return [
        'status' => 'success',
        'ticket_id' => $ticket_id
    ];
}

function handleTicketEscalate($data) {
    $ticket_id = $data['whmcs_ticket_id'] ?? null;
    $reason = $data['reason'] ?? 'Escalated by AI system';
    
    if (!is_numeric($ticket_id)) {
        throw new Exception('Missing required field: whmcs_ticket_id');
    }
    $ticket_id = (int)$ticket_id;
    
    synapseLog("Escalating ticket {$ticket_id}: {$reason}");
    
    $result = synapseLocalAPI('UpdateTicket', [
        'ticketid' => $ticket_id,
        'status' => 'On Hold'
    ]);
    
    if ($result['result'] !== 'success') {
        throw new Exception("Failed to update ticket status: " . ($result['message'] ?? 'Unknown error'));
    }
    
    $note_result = synapseLocalAPI('AddTicketNote', [
        'ticketid' => $ticket_id,
        'message' => "Escalated by Synapse AI: {$reason}",
        'admin' => 'Synapse AI'
    ]);
    
    synapseLog("Ticket {$ticket_id} escalated successfully");
    
    return [
        'status' => 'success',
        'ticket_id' => $ticket_id,
        'reason' => $reason
    ];
}

function handleTicketsInspect($data) {
    $ids = $data['ticket_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $out = [];
    foreach (array_slice($ids, 0, 200) as $id) {
        if (!is_numeric($id)) {
            continue;
        }
        $id = (int) $id;
        $ticket = Capsule::table('tbltickets')->where('id', $id)->first();
        if (!$ticket) {
            $out[] = [
                'whmcs_ticket_id' => $id,
                'missing' => true,
                'closed' => false,
                'has_staff_reply' => false
            ];
            continue;
        }
        $closed = function_exists('synapseTicketIsClosed')
            ? synapseTicketIsClosed($ticket)
            : (strcasecmp((string) $ticket->status, 'Closed') === 0);
        $author = function_exists('synapseStaffReplyAuthor')
            ? synapseStaffReplyAuthor($id)
            : '';
        if ($author === '') {
            $reply = Capsule::table('tblticketreplies')
                ->where('tid', $id)
                ->where(function ($q) {
                    $q->whereNotNull('admin')->where('admin', '!=', '');
                })
                ->orderBy('id', 'desc')
                ->first();
            $author = $reply ? trim((string) $reply->admin) : '';
        }
        $out[] = [
            'whmcs_ticket_id' => $id,
            'status' => (string) $ticket->status,
            'whmcs_status' => (string) $ticket->status,
            'closed' => $closed ? true : false,
            'has_staff_reply' => $author !== '',
            'staff_author' => $author,
            'actor' => $author,
            'closed_by' => $closed ? (function_exists('synapseClosedBy') ? synapseClosedBy($id) : ($author !== '' ? 'staff' : 'user')) : ''
        ];
    }
    return [
        'status' => 'success',
        'tickets' => $out
    ];
}

function handleClientVms($data) {
    $client_id = $data['client_id'] ?? null;
    
    if (!is_numeric($client_id)) {
        throw new Exception('Missing required field: client_id');
    }
    $client_id = (int)$client_id;
    
    synapseLog("Fetching VMs for client {$client_id}");
    
    $result = synapseLocalAPI('GetClientsProducts', [
        'clientid' => $client_id
    ]);
    
    if ($result['result'] !== 'success') {
        throw new Exception("Failed to fetch client products: " . ($result['message'] ?? 'Unknown error'));
    }
    
    $skip = ['terminated', 'cancelled', 'fraud'];
    $vms = [];
    $products = synapseNormalizeList($result['products']['product'] ?? $result['products'] ?? []);
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $status = strtolower((string) ($product['status'] ?? ''));
        if (in_array($status, $skip, true)) {
            continue;
        }
        $fields = synapseProductCustomFields($product);
        $assigned = trim((string) ($product['assignedips'] ?? ''));
        $serviceId = $product['id'] ?? null;
        $hypervisorId = synapseProductVmId($product, $fields);
        $vms[] = [
            'id' => $serviceId,
            'service_id' => $serviceId,
            'vm_id' => $hypervisorId !== '' ? $hypervisorId : null,
            'name' => $product['name'] ?? $product['productname'] ?? '',
            'domain' => $product['domain'] ?? '',
            'status' => $product['status'] ?? 'Unknown',
            'billing_status' => $product['status'] ?? 'Unknown',
            'product_type' => $product['producttype'] ?? 'other',
            'dedicatedip' => $product['dedicatedip'] ?? '',
            'assignedips' => $assigned,
            'hostname' => $product['domain'] ?? '',
            'customfields' => $fields
        ];
    }
    
    synapseLog("Found " . count($vms) . " VMs for client {$client_id}");
    foreach ($vms as $idx => $vm) {
        $sid = $vm['service_id'] ?? $vm['id'] ?? '?';
        $vid = $vm['vm_id'] ?? 'none';
        $dip = $vm['dedicatedip'] ?? '';
        synapseLog("  VM[{$idx}] service={$sid} hypervisor_id={$vid} domain=" . ($vm['domain'] ?? '') . " ip={$dip}");
    }
    
    return [
        'status' => 'success',
        'client_id' => $client_id,
        'vms' => $vms
    ];
}

function handleCompanyInfo() {
    synapseLog("Fetching company info");
    
    $company = Capsule::table('tblconfiguration')
        ->whereIn('setting', ['CompanyName', 'Email', 'Domain', 'SystemURL'])
        ->pluck('value', 'setting');
    
    return [
        'status' => 'success',
        'company' => [
            'name' => $company['CompanyName'] ?? '',
            'email' => $company['Email'] ?? '',
            'domain' => $company['Domain'] ?? '',
            'url' => $company['SystemURL'] ?? ''
        ]
    ];
}

function handleClientContext($data) {
    $client_id = $data['client_id'] ?? null;
    
    if (!is_numeric($client_id)) {
        throw new Exception('Missing required field: client_id');
    }
    $client_id = (int)$client_id;
    
    synapseLog("Fetching context for client {$client_id}");
    
    $client_result = synapseLocalAPI('GetClientsDetails', ['clientid' => $client_id]);
    $products_result = synapseLocalAPI('GetClientsProducts', ['clientid' => $client_id]);
    
    if ($client_result['result'] !== 'success') {
        throw new Exception("Failed to fetch client details");
    }
    
    $context = [
        'client' => [
            'id' => $client_id,
            'name' => ($client_result['firstname'] ?? '') . ' ' . ($client_result['lastname'] ?? ''),
            'email' => $client_result['email'] ?? '',
            'company' => $client_result['companyname'] ?? '',
            'status' => $client_result['status'] ?? 'Unknown'
        ],
        'products' => [],
        'recent_tickets' => []
    ];
    
    if ($products_result['result'] === 'success') {
        foreach (synapseNormalizeList($products_result['products']['product'] ?? $products_result['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $fields = synapseProductCustomFields($product);
            $context['products'][] = [
                'id' => $product['id'] ?? null,
                'name' => $product['productname'] ?? $product['name'] ?? '',
                'domain' => $product['domain'] ?? '',
                'status' => $product['status'] ?? 'Unknown',
                'dedicatedip' => $product['dedicatedip'] ?? '',
                'assignedips' => $product['assignedips'] ?? '',
                'vm_id' => synapseProductVmId($product, $fields)
            ];
        }
    }
    
    $tickets = Capsule::table('tbltickets')
        ->where('userid', $client_id)
        ->orderBy('date', 'desc')
        ->limit(5)
        ->get(['id', 'title', 'status', 'date']);
    
    foreach ($tickets as $ticket) {
        $context['recent_tickets'][] = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'date' => $ticket->date
        ];
    }
    
    return [
        'status' => 'success',
        'context' => $context
    ];
}

function handleOrderActivate($data) {
    $hmacSource = $GLOBALS['synapse_hmac_source'] ?? '';
    if ($hmacSource !== 'callback_secret') {
        throw new Exception('order.activate requires a dedicated callback secret');
    }
    $order_id = $data['order_id'] ?? null;
    
    if (!is_numeric($order_id)) {
        throw new Exception('Missing required field: order_id');
    }
    $order_id = (int)$order_id;
    
    synapseLog("Activating order {$order_id}");
    
    $accept_result = synapseLocalAPI('AcceptOrder', ['orderid' => $order_id]);
    
    if ($accept_result['result'] !== 'success') {
        throw new Exception("Failed to accept order: " . ($accept_result['message'] ?? 'Unknown error'));
    }
    
    synapseLog("Order {$order_id} accepted and activated successfully");
    
    return [
        'status' => 'success',
        'order_id' => $order_id
    ];
}

if (!defined('SYNAPSE_CALLBACK_SKIP_MAIN')) {

header('Content-Type: application/json');

if (
    isset($_GET['drain'])
    && $_GET['drain'] === '1'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed');
    }
    
    if (!validateIpWhitelist()) {
        throw new Exception('IP address not whitelisted');
    }

    $signature = $_SERVER['HTTP_X_SYNAPSE_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_SYNAPSE_TIMESTAMP'] ?? null;
    $nonce = $_SERVER['HTTP_X_SYNAPSE_NONCE'] ?? null;
    $body = file_get_contents('php://input');

    $hmacSource = validateSignature($body, $signature, $timestamp, $nonce);
    if ($hmacSource === false) {
        throw new Exception('Invalid signature');
    }
    $GLOBALS['synapse_hmac_source'] = $hmacSource;
    
    $data = json_decode($body, true);
    if (!$data) {
        throw new Exception('Invalid JSON payload');
    }
    
    $event = $data['event'] ?? '';
    unset($data['event']);
    
    synapseLog("Processing event: {$event}");
    
    switch ($event) {
        case 'ping':
            $response = handlePing();
            break;
        case 'reply.post':
            $response = handleReplyPost($data);
            break;
        case 'ticket.close':
            $response = handleTicketClose($data);
            break;
        case 'ticket.escalate':
            $response = handleTicketEscalate($data);
            break;
        case 'tickets.inspect':
            $response = handleTicketsInspect($data);
            break;
        case 'client.vms':
            $response = handleClientVms($data);
            break;
        case 'company.info':
            $response = handleCompanyInfo();
            break;
        case 'client.context':
            $response = handleClientContext($data);
            break;
        case 'order.activate':
            $response = handleOrderActivate($data);
            break;
        case 'queue.drain':
            require_once __DIR__ . '/hooks.php';
            ignore_user_abort(true);
            set_time_limit(60);
            synapseProcessQueue(5);
            $response = ['status' => 'drained'];
            break;
        default:
            throw new Exception("Unknown event: {$event}");
    }
    
    http_response_code(200);
    echo json_encode($response);
    
} catch (Exception $e) {
    synapseLog("Error processing callback: " . $e->getMessage(), 'ERROR');
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

}