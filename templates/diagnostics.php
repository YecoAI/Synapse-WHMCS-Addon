<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../includes/SynapseClient.php';

$test_results = [];

if ($_POST && isset($_POST['run_diagnostics'])) {
    $test_results['timestamp'] = date('Y-m-d H:i:s');
    
    try {
        $client = new SynapseClient();
        
        $test_results['connection'] = $client->testConnection();
        
        if ($test_results['connection']['success']) {
            $test_results['config'] = $client->getConfig();
        }
        
        $test_results['database'] = [
            'success' => true,
            'tables_exist' => [
                'synapse_tickets' => Capsule::schema()->hasTable('synapse_tickets'),
                'synapse_config' => Capsule::schema()->hasTable('synapse_config'),
                'synapse_queue' => Capsule::schema()->hasTable('synapse_queue')
            ],
            'record_counts' => [
                'tickets' => Capsule::table('synapse_tickets')->count(),
                'departments' => Capsule::table('synapse_config')->count(),
                'queue_pending' => Capsule::schema()->hasTable('synapse_queue')
                    ? Capsule::table('synapse_queue')->where('status', 'pending')->count()
                    : 0
            ]
        ];
        
        $test_results['php_extensions'] = [
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'json' => extension_loaded('json')
        ];
        
        $test_results['whmcs_config'] = [
            'version' => Capsule::table('tblconfiguration')
                ->where('setting', 'Version')
                ->value('value'),
            'departments_count' => Capsule::table('tblticketdepartments')->count(),
            'active_tickets' => Capsule::table('tbltickets')
                ->whereIn('status', ['Open', 'Customer-Reply', 'Answered'])
                ->count()
        ];
        
    } catch (Exception $e) {
        $test_results['error'] = $e->getMessage();
    }
}
?>

<div class="synapse-card">
    <h3>System Diagnostics</h3>
    
    <p>Run comprehensive tests to verify your Synapse installation and configuration.</p>
    
    <form method="post">
        <button type="submit" name="run_diagnostics" class="btn btn-primary">
            Run Full Diagnostics
        </button>
    </form>
    
    <?php if (!empty($test_results)): ?>
        <hr>
        
        <h4>Diagnostic Results <small>(<?php echo $test_results['timestamp']; ?>)</small></h4>
        
        <?php if (isset($test_results['error'])): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> <?php echo htmlspecialchars($test_results['error']); ?>
            </div>
        <?php endif; ?>
        
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4>Backend Connection 
                    <span class="synapse-status status-<?php echo $test_results['connection']['success'] ? 'healthy' : 'error'; ?>">
                        <?php echo $test_results['connection']['success'] ? 'PASS' : 'FAIL'; ?>
                    </span>
                </h4>
            </div>
            <div class="panel-body">
                <?php if ($test_results['connection']['success']): ?>
                    <p><strong>✓ Successfully connected to backend</strong></p>
                    
                    <?php if (isset($test_results['config'])): ?>
                        <ul>
                            <li>Backend URL: <code><?php echo htmlspecialchars($test_results['connection']['backend_url']); ?></code></li>
                            <li>License Valid: <strong><?php echo $test_results['config']['licenseValid'] ? 'Yes' : 'No'; ?></strong></li>
                            <li>License Plan: <strong><?php echo htmlspecialchars($test_results['config']['licensePlan']); ?></strong></li>
                            <li>AI Mode: <strong><?php echo htmlspecialchars($test_results['config']['mode']); ?></strong></li>
                            <li>Hypervisor Connected: <strong><?php echo $test_results['config']['hypervisorConnected'] ? 'Yes' : 'No'; ?></strong></li>
                        </ul>
                        
                        <?php if (!$test_results['config']['hypervisorConnected']): ?>
                            <div class="alert alert-warning">
                                <strong>Note:</strong> No hypervisor connected. Autopilot can still send replies. Connect Proxmox, VirtFusion, or Virtualizor in the Synapse Control Panel to execute VM actions.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p><strong>✗ Connection failed:</strong> <?php echo htmlspecialchars($test_results['connection']['error']); ?></p>
                    
                    <div class="alert alert-info">
                        <strong>Troubleshooting Tips:</strong>
                        <ul style="margin: 5px 0;">
                            <li>Verify your backend URL is correct and accessible</li>
                            <li>Check your license key format (SYN-XXXX-XXXX-XXXX)</li>
                            <li>Ensure your WHMCS server can reach the backend (firewall, DNS)</li>
                            <li>Verify SSL certificate if using HTTPS</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4>Database Configuration 
                    <span class="synapse-status status-<?php echo $test_results['database']['success'] ? 'healthy' : 'error'; ?>">
                        <?php echo $test_results['database']['success'] ? 'PASS' : 'FAIL'; ?>
                    </span>
                </h4>
            </div>
            <div class="panel-body">
                <ul>
                    <li>synapse_tickets table: <strong><?php echo $test_results['database']['tables_exist']['synapse_tickets'] ? 'Exists' : 'Missing'; ?></strong></li>
                    <li>synapse_config table: <strong><?php echo $test_results['database']['tables_exist']['synapse_config'] ? 'Exists' : 'Missing'; ?></strong></li>
                    <li>synapse_queue table: <strong><?php echo $test_results['database']['tables_exist']['synapse_queue'] ? 'Exists' : 'Missing'; ?></strong></li>
                    <li>Processed tickets: <strong><?php echo number_format($test_results['database']['record_counts']['tickets']); ?></strong></li>
                    <li>Queue pending: <strong><?php echo number_format($test_results['database']['record_counts']['queue_pending']); ?></strong></li>
                    <li>Configured departments: <strong><?php echo number_format($test_results['database']['record_counts']['departments']); ?></strong></li>
                </ul>
            </div>
        </div>
        
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4>PHP Extensions 
                    <span class="synapse-status status-<?php echo array_sum($test_results['php_extensions']) === count($test_results['php_extensions']) ? 'healthy' : 'error'; ?>">
                        <?php echo array_sum($test_results['php_extensions']) === count($test_results['php_extensions']) ? 'PASS' : 'FAIL'; ?>
                    </span>
                </h4>
            </div>
            <div class="panel-body">
                <ul>
                    <?php foreach ($test_results['php_extensions'] as $ext => $loaded): ?>
                        <li><?php echo $ext; ?>: <strong><?php echo $loaded ? 'Loaded' : 'Missing'; ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4>WHMCS Environment</h4>
            </div>
            <div class="panel-body">
                <ul>
                    <li>WHMCS Version: <strong><?php echo htmlspecialchars($test_results['whmcs_config']['version']); ?></strong></li>
                    <li>Support Departments: <strong><?php echo number_format($test_results['whmcs_config']['departments_count']); ?></strong></li>
                    <li>Active Tickets: <strong><?php echo number_format($test_results['whmcs_config']['active_tickets']); ?></strong></li>
                </ul>
            </div>
        </div>
        
        <div class="alert alert-info">
            <h4>Need Help?</h4>
            <p>If you're experiencing issues, you can generate a support package to send to our team:</p>
            
            <button type="button" class="btn btn-sm btn-default" onclick="generateSupportPackage()">
                Generate Support Package
            </button>
            
            <div id="support-package" style="display: none; margin-top: 10px;">
                <textarea class="form-control" rows="10" readonly onclick="this.select()"><?php
                    echo base64_encode(json_encode([
                        'timestamp' => date('c'),
                        'whmcs_version' => $test_results['whmcs_config']['version'] ?? 'unknown',
                        'php_version' => PHP_VERSION,
                        'addon_version' => defined('SYNAPSE_ADDON_VERSION') ? SYNAPSE_ADDON_VERSION : '0.9.0',
                        'test_results' => $test_results,
                        'server_info' => [
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? ''
                        ]
                    ], JSON_PRETTY_PRINT));
                ?></textarea>
                <p><small>Copy the above data and include it in your support request.</small></p>
            </div>
        </div>
        
    <?php endif; ?>
</div>

<script>
function generateSupportPackage() {
    document.getElementById('support-package').style.display = 'block';
}
</script>