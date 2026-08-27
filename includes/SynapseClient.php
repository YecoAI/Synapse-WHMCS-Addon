<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/synapse_shared.php';

class SynapseClient
{
    private $settings;
    private $license_key;
    private $backend_url;
    private $debug_mode;

    public function __construct()
    {
        $this->loadSettings();
    }

    private function loadSettings()
    {
        $this->settings = getSynapseSettings();
        $this->license_key = synapsePlainLicenseKey();
        $this->backend_url = synapseBackendUrl();
        $this->debug_mode = synapseDebugEnabled();

        if (empty($this->license_key) || empty($this->backend_url)) {
            throw new Exception('Synapse not configured - missing license key or backend URL');
        }
    }

    private function log($message, $level = 'INFO')
    {
        synapseLog($message, $level);
    }

    private function encryptPayload($data)
    {
        return synapseEncryptPayload($data, $this->license_key);
    }

    private function makeRequest($endpoint, $data = null, $method = 'GET', $timeout = 30, $decode_json = true)
    {
        $url = $this->backend_url . $endpoint;
        
        $whmcsUrl = '';
        try {
            $whmcsUrl = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
        } catch (Exception $e) {
            $whmcsUrl = '';
        }
        if ($whmcsUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $whmcsUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        $headers = [
            'Authorization: Bearer ' . $this->license_key,
            'Accept: application/json',
            'User-Agent: WHMCS-Synapse/' . (defined('SYNAPSE_ADDON_VERSION') ? SYNAPSE_ADDON_VERSION : '0.9.1'),
            'X-Synapse-WHMCS-URL: ' . rtrim($whmcsUrl, '/')
        ];
        if ($decode_json) {
            array_unshift($headers, 'Content-Type: application/json');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCS-Synapse/' . (defined('SYNAPSE_ADDON_VERSION') ? SYNAPSE_ADDON_VERSION : '0.9.1')
        ]);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL error: {$error}");
        }

        if ($http_code >= 400) {
            $this->log("HTTP {$http_code} error for {$endpoint}", 'ERROR');
            throw new Exception("HTTP {$http_code} error from backend");
        }

        if (!$decode_json) {
            return $response;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from backend');
        }

        return $decoded;
    }

    private function persistCallbackSecret($secret)
    {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return;
        }
        $exists = Capsule::table('tbladdonmodules')
            ->where('module', 'synapse')
            ->where('setting', 'callback_secret')
            ->exists();
        if ($exists) {
            Capsule::table('tbladdonmodules')
                ->where('module', 'synapse')
                ->where('setting', 'callback_secret')
                ->update(['value' => $secret]);
        } else {
            Capsule::table('tbladdonmodules')->insert([
                'module' => 'synapse',
                'setting' => 'callback_secret',
                'value' => $secret,
            ]);
        }
        $this->settings['callback_secret'] = $secret;
    }

    public function licenseKey()
    {
        return $this->license_key;
    }

    public function testConnection()
    {
        try {
            $this->log("Testing connection to backend: {$this->backend_url}");
            
            $response = $this->makeRequest('/whmcs/config');
            $this->persistCallbackSecret($response['callbackSecret'] ?? '');
            $this->log("Connection test successful");
            return [
                'success' => true,
                'config' => $response,
                'backend_url' => $this->backend_url
            ];
            
        } catch (Exception $e) {
            $this->log("Connection test failed: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function ingestTicket($ticket_data)
    {
        try {
            $this->log("Ingesting ticket #{$ticket_data['whmcs_ticket_id']}");
            
            $encrypted_payload = $this->encryptPayload($ticket_data);
            
            $response = $this->makeRequest('/whmcs/ingest', [
                'encrypted' => $encrypted_payload
            ], 'POST');
            
            $this->log("Ticket #{$ticket_data['whmcs_ticket_id']} processed successfully - Decision: {$response['decision']}");
            
            return $response;
            
        } catch (Exception $e) {
            $this->log("Failed to ingest ticket #{$ticket_data['whmcs_ticket_id']}: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function notifyLifecycle($payload)
    {
        try {
            $ticketId = $payload['whmcs_ticket_id'] ?? '';
            $event = $payload['event'] ?? 'lifecycle';
            $this->log("Notifying lifecycle {$event} for ticket #{$ticketId}");

            $encrypted_payload = $this->encryptPayload($payload);

            $response = $this->makeRequest('/whmcs/lifecycle', [
                'encrypted' => $encrypted_payload
            ], 'POST', 20);

            $this->log("Lifecycle {$event} for ticket #{$ticketId} status=" . ($response['status'] ?? 'unknown') . " withdrawn=" . ($response['withdrawn'] ?? 0));

            return $response;

        } catch (Exception $e) {
            $this->log("Failed to notify lifecycle for ticket #" . ($payload['whmcs_ticket_id'] ?? '') . ": " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function getConfig()
    {
        try {
            $response = $this->makeRequest('/whmcs/config');
            $this->persistCallbackSecret($response['callbackSecret'] ?? '');
            return $response;
        } catch (Exception $e) {
            $this->log("Failed to get config: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function getClientVMs($client_id)
    {
        try {
            $response = $this->makeRequest('/whmcs/client-vms?client_id=' . intval($client_id));
            return $response['vms'] ?? [];
        } catch (Exception $e) {
            $this->log("Failed to get VMs for client {$client_id}: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }

    public function confirmReply($synapse_ticket_id, $whmcs_ticket_id, $closed = false)
    {
        try {
            $this->log("Confirming reply for ticket #{$whmcs_ticket_id}");
            
            $response = $this->makeRequest('/whmcs/reply-callback', [
                'synapse_ticket_id' => $synapse_ticket_id,
                'whmcs_ticket_id' => (int)$whmcs_ticket_id,
                'replied' => true,
                'closed' => $closed
            ], 'POST');
            
            return $response;
            
        } catch (Exception $e) {
            $this->log("Failed to confirm reply for ticket #{$whmcs_ticket_id}: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function getAddonUpdate()
    {
        $this->log("Checking addon update");
        return $this->makeRequest('/whmcs/addon/update');
    }

    public function downloadAddonZip()
    {
        $this->log("Downloading addon package");
        $url = $this->backend_url . '/whmcs/addon/download';
        $whmcsUrl = '';
        try {
            $whmcsUrl = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
        } catch (Exception $e) {
            $whmcsUrl = '';
        }
        if ($whmcsUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $whmcsUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->license_key,
                'Accept: application/octet-stream',
                'User-Agent: WHMCS-Synapse/' . (defined('SYNAPSE_ADDON_VERSION') ? SYNAPSE_ADDON_VERSION : '0.9.1'),
                'X-Synapse-WHMCS-URL: ' . rtrim($whmcsUrl, '/'),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCS-Synapse/' . (defined('SYNAPSE_ADDON_VERSION') ? SYNAPSE_ADDON_VERSION : '0.9.1'),
            CURLOPT_HEADERFUNCTION => function ($handle, $headerLine) use (&$responseHeaders) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new Exception("cURL error: {$error}");
        }
        if ($http_code >= 400) {
            $this->log("HTTP {$http_code} error for /whmcs/addon/download", 'ERROR');
            throw new Exception("HTTP {$http_code} error from backend");
        }
        return [
            'body' => $response,
            'headers' => $responseHeaders,
        ];
    }
}