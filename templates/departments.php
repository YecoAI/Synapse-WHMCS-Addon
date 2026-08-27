<?php
use WHMCS\Database\Capsule;

if ($_POST && isset($_POST['save_departments'])) {
    try {
        if (function_exists('check_token')) {
            check_token('POST');
        }
        $allowed_modes = ['copilot', 'autopilot'];
        foreach ($_POST['departments'] as $dept_id => $config) {
            $mode = $config['mode'] ?? 'copilot';
            if (!in_array($mode, $allowed_modes, true)) {
                $mode = 'copilot';
            }
            Capsule::table('synapse_config')->updateOrInsert(
                ['department_id' => (int)$dept_id],
                [
                    'department_name' => $config['name'],
                    'ai_mode' => $mode,
                    'enabled' => isset($config['enabled']) ? 1 : 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );
        }
        
        $success_message = "Department configuration saved successfully!";
        
    } catch (Exception $e) {
        $error_message = "Error saving configuration: " . $e->getMessage();
    }
}

$whmcs_departments = Capsule::table('tblticketdepartments')
    ->where('hidden', 0)
    ->orderBy('order', 'asc')
    ->get();

$synapse_config = Capsule::table('synapse_config')
    ->pluck('ai_mode', 'department_id');

$synapse_enabled = Capsule::table('synapse_config')
    ->where('enabled', 1)
    ->pluck('enabled', 'department_id');
?>

<div class="synapse-card">
    <h3>Department Configuration</h3>
    
    <p>Configure AI behavior for each support department. Changes take effect immediately for new tickets.</p>
    
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <?php if (function_exists('generate_token')) { echo generate_token('form'); } ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>AI Mode</th>
                    <th>Status</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($whmcs_departments as $dept): ?>
                    <?php
                    $current_mode = $synapse_config[$dept->id] ?? 'copilot';
                    if ($current_mode === 'observe') {
                        $current_mode = 'copilot';
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($dept->name); ?></strong>
                            <input type="hidden" name="departments[<?php echo (int) $dept->id; ?>][name]" value="<?php echo htmlspecialchars($dept->name); ?>">
                        </td>
                        <td>
                            <select name="departments[<?php echo (int) $dept->id; ?>][mode]" class="form-control" style="width: 120px;">
                                <option value="copilot" <?php echo $current_mode === 'copilot' ? 'selected' : ''; ?>>
                                    Copilot
                                </option>
                                <option value="autopilot" <?php echo $current_mode === 'autopilot' ? 'selected' : ''; ?>>
                                    Autopilot
                                </option>
                            </select>
                        </td>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="departments[<?php echo (int) $dept->id; ?>][enabled]" 
                                       value="1" 
                                       <?php echo ($synapse_enabled[$dept->id] ?? 0) ? 'checked' : ''; ?>>
                                Enabled
                            </label>
                        </td>
                        <td style="font-size: 12px; color: #666;">
                            <div id="mode-desc-<?php echo (int) $dept->id; ?>"></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="alert alert-info" style="margin-top: 20px;">
            <h4>AI Mode Descriptions:</h4>
            <ul style="margin: 10px 0;">
                <li><strong>Copilot:</strong> AI suggests actions that require human approval before execution.</li>
                <li><strong>Autopilot:</strong> AI automatically executes safe actions (reboot, diagnostics, password reset).</li>
            </ul>
            
            <p><strong>Note:</strong> Destructive actions (disk resize, VM deletion) always require human approval regardless of mode.</p>
        </div>
        
        <button type="submit" name="save_departments" class="btn btn-primary">
            Save Department Configuration
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const descriptions = {
        copilot: 'AI will suggest actions that require human approval. Staff must review and approve before execution.',
        autopilot: 'AI will automatically execute safe actions like diagnostics, reboots, and password resets.'
    };
    
    function updateDescriptions() {
        document.querySelectorAll('select[name*="[mode]"]').forEach(function(select) {
            const deptId = select.name.match(/\[(\d+)\]/)[1];
            const descDiv = document.getElementById('mode-desc-' + deptId);
            if (descDiv) {
                descDiv.textContent = descriptions[select.value];
            }
        });
    }
    
    document.querySelectorAll('select[name*="[mode]"]').forEach(function(select) {
        select.addEventListener('change', updateDescriptions);
    });
    
    updateDescriptions();
});
</script>
