<?php
use WHMCS\Database\Capsule;

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$filter_decision = $_GET['decision'] ?? '';
$filter_confidence = $_GET['confidence'] ?? '';

$query = Capsule::table('synapse_tickets')
    ->join('tbltickets', 'synapse_tickets.whmcs_ticket_id', '=', 'tbltickets.id')
    ->join('tblticketdepartments', 'tbltickets.did', '=', 'tblticketdepartments.id')
    ->join('tblclients', 'tbltickets.userid', '=', 'tblclients.id')
    ->select([
        'synapse_tickets.*',
        'tbltickets.title',
        'tbltickets.status as ticket_status',
        'tbltickets.urgency',
        'tblticketdepartments.name as department_name',
        'tblclients.firstname',
        'tblclients.lastname'
    ]);

if ($filter_decision) {
    $query->where('synapse_tickets.ai_decision', $filter_decision);
}

if ($filter_confidence) {
    switch ($filter_confidence) {
        case 'high':
            $query->where('synapse_tickets.confidence', '>=', 90);
            break;
        case 'medium':
            $query->whereBetween('synapse_tickets.confidence', [70, 89]);
            break;
        case 'low':
            $query->where('synapse_tickets.confidence', '<', 70);
            break;
    }
}

$total_tickets = $query->count();
$tickets = $query->orderBy('synapse_tickets.created_at', 'desc')
    ->offset($offset)
    ->limit($per_page)
    ->get();

$total_pages = ceil($total_tickets / $per_page);

$statsRow = Capsule::table('synapse_tickets')
    ->selectRaw("SUM(CASE WHEN ai_decision = 'autopilot' THEN 1 ELSE 0 END) as autopilot, SUM(CASE WHEN ai_decision = 'copilot' THEN 1 ELSE 0 END) as copilot, SUM(CASE WHEN ai_decision = 'escalated' THEN 1 ELSE 0 END) as escalated, COUNT(*) as total")
    ->first();
$stats = [
    'autopilot' => (int) ($statsRow->autopilot ?? 0),
    'copilot' => (int) ($statsRow->copilot ?? 0),
    'escalated' => (int) ($statsRow->escalated ?? 0),
    'total' => (int) ($statsRow->total ?? 0),
];
?>

<div class="synapse-card">
    <h3>AI Processed Tickets</h3>
    
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-md-3">
            <div class="synapse-metric">
                <h3 style="color: #28a745;"><?php echo number_format($stats['autopilot']); ?></h3>
                <p>Auto-Resolved</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="synapse-metric">
                <h3 style="color: #ffc107;"><?php echo number_format($stats['copilot']); ?></h3>
                <p>Human Approved</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="synapse-metric">
                <h3 style="color: #dc3545;"><?php echo number_format($stats['escalated']); ?></h3>
                <p>Escalated</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="synapse-metric">
                <h3><?php echo number_format($stats['total']); ?></h3>
                <p>Total Processed</p>
            </div>
        </div>
    </div>
    
    <form method="get" action="addonmodules.php" style="margin-bottom: 20px;">
        <input type="hidden" name="module" value="synapse">
        <input type="hidden" name="synapse_tab" value="tickets">
        
        <div class="row">
            <div class="col-md-4">
                <select name="decision" class="form-control">
                    <option value="">All Decisions</option>
                    <option value="autopilot" <?php echo $filter_decision === 'autopilot' ? 'selected' : ''; ?>>Autopilot</option>
                    <option value="copilot" <?php echo $filter_decision === 'copilot' ? 'selected' : ''; ?>>Copilot</option>
                    <option value="escalated" <?php echo $filter_decision === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                </select>
            </div>
            <div class="col-md-4">
                <select name="confidence" class="form-control">
                    <option value="">All Confidence Levels</option>
                    <option value="high" <?php echo $filter_confidence === 'high' ? 'selected' : ''; ?>>High (90%+)</option>
                    <option value="medium" <?php echo $filter_confidence === 'medium' ? 'selected' : ''; ?>>Medium (70-89%)</option>
                    <option value="low" <?php echo $filter_confidence === 'low' ? 'selected' : ''; ?>>Low (&lt;70%)</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-default">Filter</button>
                <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'tickets')); ?>" class="btn btn-default">Reset</a>
            </div>
        </div>
    </form>
    
    <?php if ($tickets->count() > 0): ?>
        <table class="table table-striped" style="font-size: 13px;">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Client</th>
                    <th>Title</th>
                    <th>Department</th>
                    <th>AI Decision</th>
                    <th>Confidence</th>
                    <th>Action</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td>
                            <a href="supporttickets.php?action=view&id=<?php echo (int) $ticket->whmcs_ticket_id; ?>" target="_blank">
                                #<?php echo (int) $ticket->whmcs_ticket_id; ?>
                            </a>
                        </td>
                        <td>
                            <?php echo htmlspecialchars(trim($ticket->firstname . ' ' . $ticket->lastname)); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars(mb_strimwidth($ticket->title, 0, 40, '...')); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($ticket->department_name); ?>
                        </td>
                        <td>
                            <span class="synapse-status status-<?php 
                                echo $ticket->ai_decision === 'autopilot' ? 'healthy' : 
                                    ($ticket->ai_decision === 'copilot' ? 'warning' : 'error'); 
                            ?>">
                                <?php echo strtoupper($ticket->ai_decision); ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight: bold; color: <?php 
                                echo $ticket->confidence >= 90 ? '#28a745' : 
                                    ($ticket->confidence >= 70 ? '#ffc107' : '#dc3545'); 
                            ?>;">
                                <?php echo number_format($ticket->confidence, 1); ?>%
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($ticket->action_taken ?: 'None'); ?>
                        </td>
                        <td>
                            <span class="label label-<?php 
                                echo $ticket->ticket_status === 'Closed' ? 'success' : 
                                    ($ticket->ticket_status === 'Answered' ? 'info' : 'default'); 
                            ?>">
                                <?php echo htmlspecialchars($ticket->ticket_status); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo date('M j, H:i', strtotime($ticket->created_at)); ?>
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
                            <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'tickets', array_filter([
                                'page' => $i,
                                'decision' => $filter_decision ?: null,
                                'confidence' => $filter_confidence ?: null,
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
            <strong>No AI processed tickets found.</strong> 
            <?php if ($filter_decision || $filter_confidence): ?>
                Try adjusting your filters or <a href="<?php echo htmlspecialchars(synapseAdminUrl($modulelink, 'tickets')); ?>">view all tickets</a>.
            <?php else: ?>
                Tickets will appear here once the AI system processes them.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="synapse-card">
    <h4>Understanding AI Decisions</h4>
    
    <div class="row">
        <div class="col-md-4">
            <h5><span class="synapse-status status-healthy">AUTOPILOT</span></h5>
            <p style="font-size: 12px;">AI automatically executed safe actions like diagnostics, reboots, or password resets. Human review not required.</p>
        </div>
        <div class="col-md-4">
            <h5><span class="synapse-status status-warning">COPILOT</span></h5>
            <p style="font-size: 12px;">AI suggested actions that required human approval. Staff reviewed and approved the solution before execution.</p>
        </div>
        <div class="col-md-4">
            <h5><span class="synapse-status status-error">ESCALATED</span></h5>
            <p style="font-size: 12px;">AI determined the issue requires human expertise or the confidence level was too low for automatic action.</p>
        </div>
    </div>
</div>