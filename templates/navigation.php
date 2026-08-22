<?php
$current_tab = $synapse_tab ?? 'overview';
$nav_items = [
    'overview' => 'Addon',
    'update' => 'Update',
    'departments' => 'Department Rules',
    'tickets' => 'AI Tickets',
    'diagnostics' => 'Diagnostics',
    'logs' => 'Activity Logs',
];
$cp_url = 'https://synapsecp.yecoai.com';
?>

<div class="synapse-nav">
    <a href="<?php echo htmlspecialchars($cp_url); ?>" target="_blank" rel="noopener noreferrer">
        Control Panel
    </a>
    <?php foreach ($nav_items as $tab => $label): ?>
        <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, $tab)); ?>"
           class="<?php echo $current_tab === $tab ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($label); ?>
        </a>
    <?php endforeach; ?>
    
    <div style="float: right;">
        <span style="color: #666; font-size: 12px;">
            Synapse AI Autopilot v<?php echo defined('SYNAPSE_ADDON_VERSION') ? htmlspecialchars(SYNAPSE_ADDON_VERSION) : '0.9.0'; ?> | 
            License: <?php echo htmlspecialchars(substr($license_key, 0, 8) . '...'); ?>
        </span>
    </div>
    <div style="clear: both;"></div>
</div>
