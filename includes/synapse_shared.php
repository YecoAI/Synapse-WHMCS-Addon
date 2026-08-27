<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

use WHMCS\Database\Capsule;

function synapseLog($message, $level = 'INFO')
{
    $settings = getSynapseSettings();
    if ($settings['debug_mode'] === 'on') {
        logActivity("[Synapse {$level}] {$message}");
    }
}

function getSynapseSettings()
{
    static $settings = null;
    if ($settings === null) {
        $result = Capsule::table('tbladdonmodules')
            ->where('module', 'synapse')
            ->pluck('value', 'setting');
        $settings = $result;
    }
    return $settings;
}

function synapsePlainLicenseKey($raw = null)
{
    if ($raw === null) {
        $settings = getSynapseSettings();
        $raw = $settings['license_key'] ?? '';
    }
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

function synapseHmacKey()
{
    return synapseHmacKeyFrom(synapsePlainLicenseKey());
}

function synapseDebugEnabled()
{
    static $on = null;
    if ($on === null) {
        $on = getSynapseSettings()['debug_mode'] === 'on';
    }
    return $on;
}

function synapseIsEnabled()
{
    return trim((string) synapsePlainLicenseKey()) !== '';
}

function synapseBackendUrl()
{
    $settings = getSynapseSettings();
    $url = rtrim(trim((string) ($settings['backend_url'] ?? '')), '/');
    if ($url === '') {
        return '';
    }
    if (!preg_match('#/api/v1$#i', $url)) {
        $url .= '/api/v1';
    }
    return $url;
}

function synapseEncryptPayload($data, $licenseKey)
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!extension_loaded('openssl')) {
        throw new Exception('OpenSSL extension required for encryption');
    }

    $key = hash('sha256', 'synapse-encryption-v1::' . $licenseKey, true);
    $nonce = random_bytes(12);

    $encrypted = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

    if ($encrypted === false) {
        throw new Exception('Encryption failed');
    }

    return base64_encode($nonce . $encrypted . $tag);
}
