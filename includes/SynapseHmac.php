<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

function synapseHmacKeyFrom($secret)
{
    return hash('sha256', 'synapse-hmac-v1::' . trim((string) $secret), true);
}

function synapseAddonPackageHmac($zipData, $license)
{
    $key = hash('sha256', 'synapse-addon-v1::' . trim((string) $license), true);
    return hash_hmac('sha256', $zipData, $key);
}
