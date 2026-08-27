<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../includes/SynapseClient.php';

$connection_test = null;
$backend_config = null;
$cacheValid = false;
if (class_exists('WHMCS\Session')) {
    $cached = WHMCS\Session::get('synapseDashboardCache');
    if (is_array($cached) && isset($cached['expires']) && (int) $cached['expires'] > time()) {
        $connection_test = $cached['connection_test'] ?? null;
        $backend_config = $cached['backend_config'] ?? null;
        $cacheValid = is_array($connection_test);
    }
}
if (!$cacheValid) {
    try {
        $client = new SynapseClient();
        $connection_test = $client->testConnection();
        $backend_config = $connection_test['success'] ? $connection_test['config'] : null;
    } catch (Exception $e) {
        $connection_test = ['success' => false, 'error' => $e->getMessage()];
        $backend_config = null;
    }
    if (class_exists('WHMCS\Session')) {
        WHMCS\Session::set('synapseDashboardCache', [
            'connection_test' => $connection_test,
            'backend_config' => $backend_config,
            'expires' => time() + 300,
        ]);
    }
}

$recent_tickets = Capsule::table('synapse_tickets')
    ->join('tbltickets', 'synapse_tickets.whmcs_ticket_id', '=', 'tbltickets.id')
    ->select([
        'synapse_tickets.*',
        'tbltickets.title',
        'tbltickets.status as ticket_status'
    ])
    ->orderBy('synapse_tickets.created_at', 'desc')
    ->limit(8)
    ->get();

$cp_url = 'https://synapsecp.yecoai.com';
?>

<div class="synapse-card">
    <h3>Synapse Control Panel</h3>
    <p>Approvals, autopilot settings, hypervisor integrations, and live audit logs are managed in the Synapse Control Panel.</p>
    <p>
        <a href="<?php echo htmlspecialchars($cp_url); ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
            Open Synapse Control Panel
        </a>
        <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'update')); ?>" class="btn btn-success">
            Check / install addon update
        </a>
        <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'logs')); ?>" class="btn btn-default">
            Activity logs
        </a>
    </p>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="synapse-metric">
            <h3 style="color: <?php echo $connection_test['success'] ? '#28a745' : '#dc3545'; ?>;">
                <?php echo $connection_test['success'] ? 'CONNECTED' : 'ERROR'; ?>
            </h3>
            <p>Backend Connection</p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="synapse-metric">
            <h3 style="color: <?php echo $backend_config && $backend_config['licenseValid'] ? '#28a745' : '#dc3545'; ?>;">
                <?php echo $backend_config && $backend_config['licenseValid'] ? 'VALID' : 'INVALID'; ?>
            </h3>
            <p>License Status</p>
        </div>
    </div>
</div>

<?php if ($backend_config): ?>
    <div class="alert alert-info">
        Mode: <span class="synapse-status status-<?php echo $backend_config['hypervisorConnected'] ? 'healthy' : 'warning'; ?>">
            <?php echo htmlspecialchars(strtoupper((string) $backend_config['mode'])); ?>
        </span> |
        Hypervisor: <span class="synapse-status status-<?php echo $backend_config['hypervisorConnected'] ? 'healthy' : 'error'; ?>">
            <?php echo $backend_config['hypervisorConnected'] ? 'CONNECTED' : 'DISCONNECTED'; ?>
        </span> |
        Plan: <strong><?php echo htmlspecialchars(strtoupper((string) $backend_config['licensePlan'])); ?></strong>
    </div>
<?php elseif (!$connection_test['success']): ?>
    <div class="alert alert-danger">
        <strong>Connection Error:</strong> <?php echo htmlspecialchars($connection_test['error']); ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/update.php'; ?>

<?php if ($recent_tickets->count() > 0): ?>
    <div class="synapse-card">
        <h3>Recent AI Activity</h3>
        <table class="table table-striped" style="font-size: 13px;">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Title</th>
                    <th>AI Decision</th>
                    <th>Confidence</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_tickets as $ticket): ?>
                    <tr>
                        <td>
                            <a href="supporttickets.php?action=view&id=<?php echo (int) $ticket->whmcs_ticket_id; ?>" target="_blank">
                                #<?php echo (int) $ticket->whmcs_ticket_id; ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars(mb_strimwidth((string) $ticket->title, 0, 50, '...')); ?></td>
                        <td>
                            <span class="synapse-status status-<?php echo $ticket->ai_decision === 'autopilot' ? 'healthy' : ($ticket->ai_decision === 'copilot' ? 'warning' : 'error'); ?>">
                                <?php echo htmlspecialchars(strtoupper((string) $ticket->ai_decision)); ?>
                            </span>
                        </td>
                        <td><?php echo number_format((float) $ticket->confidence, 1); ?>%</td>
                        <td><?php echo date('M j, H:i', strtotime($ticket->created_at)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
