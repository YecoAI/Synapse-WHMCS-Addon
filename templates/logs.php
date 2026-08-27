<?php
use WHMCS\Database\Capsule;

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 100;
$offset = ($page - 1) * $per_page;

$filter_level = $_GET['level'] ?? '';
$search_query = $_GET['search'] ?? '';

$query = Capsule::table('tblactivitylog')
    ->where('description', 'LIKE', '%Synapse%')
    ->orderBy('date', 'desc');

if ($filter_level) {
    $query->where('description', 'LIKE', "%[Synapse {$filter_level}]%");
}

if ($search_query) {
    $query->where('description', 'LIKE', '%' . $search_query . '%');
}

$total_logs = $query->count();
$logs = $query->offset($offset)->limit($per_page)->get();

$total_pages = ceil($total_logs / $per_page);
?>

<div class="synapse-card">
    <h3>Activity Logs</h3>
    
    <p>View detailed logs of all Synapse AI activities and system events.</p>
    
    <form method="get" action="addonmodules.php" style="margin-bottom: 20px;">
        <input type="hidden" name="module" value="synapse">
        <input type="hidden" name="synapse_tab" value="logs">
        
        <div class="row">
            <div class="col-md-4">
                <select name="level" class="form-control">
                    <option value="">All Levels</option>
                    <option value="INFO" <?php echo $filter_level === 'INFO' ? 'selected' : ''; ?>>Info</option>
                    <option value="ERROR" <?php echo $filter_level === 'ERROR' ? 'selected' : ''; ?>>Error</option>
                    <option value="WARNING" <?php echo $filter_level === 'WARNING' ? 'selected' : ''; ?>>Warning</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" 
                       name="search" 
                       class="form-control" 
                       placeholder="Search logs..." 
                       value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-default">Filter</button>
                <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'logs')); ?>" class="btn btn-default">Reset</a>
            </div>
        </div>
    </form>
    
    <?php if ($logs->count() > 0): ?>
        <table class="table table-striped" style="font-size: 12px;">
            <thead>
                <tr>
                    <th style="width: 120px;">Date</th>
                    <th style="width: 60px;">Level</th>
                    <th>Message</th>
                    <th style="width: 100px;">User/IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $description = $log->description;
                    $level = 'INFO';
                    
                    if (preg_match('/\[Synapse (\w+)\]/', $description, $matches)) {
                        $level = $matches[1];
                        $description = preg_replace('/\[Synapse \w+\]\s*/', '', $description);
                    }
                    ?>
                    <tr>
                        <td><?php echo date('M j, H:i:s', strtotime($log->date)); ?></td>
                        <td>
                            <span class="synapse-status status-<?php 
                                echo $level === 'ERROR' ? 'error' : ($level === 'WARNING' ? 'warning' : 'healthy'); 
                            ?>">
                                <?php echo htmlspecialchars($level); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($description); ?>
                        </td>
                        <td style="font-size: 10px; color: #666;">
                            <?php if (!empty($log->user)): ?>
                                <?php echo htmlspecialchars($log->user); ?><br>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($log->ipaddr ?: 'System'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <nav>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'logs', array_filter([
                                'page' => $i,
                                'level' => $filter_level ?: null,
                                'search' => $search_query ?: null,
                            ]))); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="alert alert-info">
            <strong>No activity logs found.</strong> 
            <?php if ($filter_level || $search_query): ?>
                Try adjusting your filters or <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'logs')); ?>">view all logs</a>.
            <?php else: ?>
                Activity will be logged here as the system processes tickets.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="synapse-card">
    <h4>Log Level Guide</h4>
    
    <div class="row">
        <div class="col-md-4">
            <h5><span class="synapse-status status-healthy">INFO</span></h5>
            <p style="font-size: 12px;">Normal system operations like ticket processing, configuration updates, and successful API calls.</p>
        </div>
        <div class="col-md-4">
            <h5><span class="synapse-status status-warning">WARNING</span></h5>
            <p style="font-size: 12px;">Non-critical issues that don't prevent operation but may need attention, like API timeouts or config issues.</p>
        </div>
        <div class="col-md-4">
            <h5><span class="synapse-status status-error">ERROR</span></h5>
            <p style="font-size: 12px;">Critical errors that prevent normal operation, such as backend connectivity failures or authentication issues.</p>
        </div>
    </div>
    
    <hr>
    
    <h4>Troubleshooting Common Issues</h4>
    
    <div style="font-size: 12px;">
        <p><strong>Backend connection errors:</strong> Check your license key, backend URL, and firewall settings.</p>
        <p><strong>Encryption/decryption errors:</strong> Verify your license key matches the backend configuration.</p>
        <p><strong>High error rates:</strong> Run diagnostics to identify configuration issues.</p>
    </div>
</div>