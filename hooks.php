<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/includes/SynapseClient.php';
require_once __DIR__ . '/includes/synapse_shared.php';

function synapseGetDepartmentMode($department_id, $department_name) {
    $config = Capsule::table('synapse_config')
        ->where('department_id', $department_id)
        ->first();
    
    if ($config) {
        if (!$config->enabled) {
            return 'disabled';
        }
        $mode = $config->ai_mode;
        if ($mode === 'observe') {
            return 'copilot';
        }
        return $mode;
    }
    
    Capsule::table('synapse_config')->insertOrIgnore([
        'department_id' => $department_id,
        'department_name' => $department_name,
        'ai_mode' => 'copilot',
        'enabled' => 1
    ]);
    
    return 'copilot';
}

function synapseEnsureQueueTable() {
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    try {
        if (!Capsule::schema()->hasTable('synapse_queue')) {
            Capsule::schema()->create('synapse_queue', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_ticket_id');
                $table->tinyInteger('is_reply')->default(0);
                $table->text('message')->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('event_type', 32)->default('ingest');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('processed_at')->nullable();
                $table->index('status');
                $table->index('whmcs_ticket_id');
            });
        } elseif (!Capsule::schema()->hasColumn('synapse_queue', 'message')) {
            Capsule::schema()->table('synapse_queue', function ($table) {
                $table->text('message')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('synapse_queue', 'reply_id')) {
            Capsule::schema()->table('synapse_queue', function ($table) {
                $table->integer('reply_id')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('synapse_queue', 'event_type')) {
            Capsule::schema()->table('synapse_queue', function ($table) {
                $table->string('event_type', 32)->default('ingest');
            });
        }
        $ready = true;
        return true;
    } catch (Exception $e) {
        $ready = false;
        logActivity("[Synapse Error] Queue table setup failed: " . $e->getMessage());
        return false;
    }
}

function synapseEnqueueTicket($ticket_id, $is_reply = false, $message = null, $reply_id = null) {
    if (!synapseIsEnabled()) {
        return;
    }
    if (!$ticket_id || !synapseEnsureQueueTable()) {
        return;
    }
    try {
        Capsule::table('synapse_queue')->insert([
            'whmcs_ticket_id' => (int)$ticket_id,
            'is_reply' => $is_reply ? 1 : 0,
            'event_type' => 'ingest',
            'message' => is_string($message) ? $message : null,
            'reply_id' => ($reply_id && is_numeric($reply_id)) ? (int) $reply_id : null,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $preview = is_string($message) ? substr(preg_replace('/\s+/', ' ', strip_tags($message)), 0, 80) : '';
        logActivity("[Synapse INFO] Queued " . ($is_reply ? 'customer reply' : 'ticket') . " #{$ticket_id}" . ($preview !== '' ? " body=\"{$preview}\"" : ''));
    } catch (Exception $e) {
        logActivity("[Synapse Error] Failed to queue ticket #{$ticket_id}: " . $e->getMessage());
    }
}

function synapseMarkInternalAction($ticket_id) {
    if (!$ticket_id || !synapseEnsureQueueTable()) {
        return;
    }
    try {
        Capsule::table('synapse_queue')->insert([
            'whmcs_ticket_id' => (int)$ticket_id,
            'is_reply' => 0,
            'event_type' => 'internal',
            'status' => 'skip',
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
    }
}

function synapseIsInternalAction($ticket_id) {
    if (!$ticket_id || !synapseEnsureQueueTable()) {
        return false;
    }
    try {
        return Capsule::table('synapse_queue')
            ->where('whmcs_ticket_id', (int)$ticket_id)
            ->where('event_type', 'internal')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 180))
            ->exists();
    } catch (Exception $e) {
        return false;
    }
}

function synapseTicketIsClosed($ticket) {
    if (!$ticket) {
        return false;
    }
    $status = trim((string) $ticket->status);
    if ($status === '') {
        return false;
    }
    if (strcasecmp($status, 'Closed') === 0) {
        return true;
    }
    try {
        $row = Capsule::table('tblticketstatuses')->where('title', $status)->first();
        if ($row && isset($row->showactive) && (int) $row->showactive === 0) {
            return true;
        }
    } catch (Exception $e) {
    }
    return false;
}

function synapseStaffReplyAuthor($ticket_id) {
    try {
        $row = Capsule::table('tblticketreplies')
            ->where('tid', (int) $ticket_id)
            ->where(function ($q) {
                $q->whereNotNull('admin')->where('admin', '!=', '');
            })
            ->orderBy('id', 'desc')
            ->first();
        if ($row && trim((string) $row->admin) !== '') {
            return trim((string) $row->admin);
        }
    } catch (Exception $e) {
    }
    return '';
}

function synapseHasHumanStaffReply($ticket_id) {
    if (synapseIsInternalAction($ticket_id)) {
        return false;
    }
    return synapseStaffReplyAuthor($ticket_id) !== '';
}

function synapseClosedBy($ticket_id) {
    $author = synapseStaffReplyAuthor($ticket_id);
    if ($author !== '') {
        return 'staff';
    }
    return 'user';
}

function synapseEnqueueLifecycle($ticket_id, $event, $actor = null) {
    if (!synapseIsEnabled()) {
        return;
    }
    if (!$ticket_id || !synapseEnsureQueueTable()) {
        return;
    }
    if ($event !== 'closed' && $event !== 'staff_replied') {
        return;
    }
    if (synapseIsInternalAction($ticket_id)) {
        return;
    }
    try {
        $exists = Capsule::table('synapse_queue')
            ->where('whmcs_ticket_id', (int) $ticket_id)
            ->where('event_type', $event)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        if ($exists) {
            return;
        }
        Capsule::table('synapse_queue')->insert([
            'whmcs_ticket_id' => (int) $ticket_id,
            'is_reply' => 0,
            'event_type' => $event,
            'message' => is_string($actor) && $actor !== '' ? $actor : null,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        logActivity("[Synapse INFO] Queued {$event} for ticket #{$ticket_id}");
    } catch (Exception $e) {
        logActivity("[Synapse Error] Failed to queue {$event} for ticket #{$ticket_id}: " . $e->getMessage());
    }
}

function synapseDeliverLifecycle($job) {
    $ticket_id = (int) $job->whmcs_ticket_id;
    $event = (string) $job->event_type;
    $ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first();
    $client = new SynapseClient();
    $result = $client->notifyLifecycle([
        'whmcs_ticket_id' => $ticket_id,
        'event' => $event,
        'actor' => isset($job->message) ? (string) $job->message : '',
        'whmcs_status' => $ticket ? (string) $ticket->status : '',
        'closed_by' => $event === 'closed' ? synapseClosedBy($ticket_id) : '',
        'timestamp' => date('c'),
    ]);
    $status = is_array($result) ? (string) ($result['status'] ?? '') : '';
    if (in_array($status, ['ok', 'withdrawn', 'noop', 'not_found'], true) || (is_array($result) && array_key_exists('withdrawn', $result))) {
        Capsule::table('synapse_tickets')
            ->where('whmcs_ticket_id', $ticket_id)
            ->update([
                'action_taken' => 'approval_withdrawn',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
}

function synapseSweepStaleApprovals($limit = 100) {
    if (!synapseIsEnabled()) {
        return;
    }
    if (!synapseEnsureQueueTable()) {
        return;
    }
    $limit = max(1, min(200, (int) $limit));
    try {
        Capsule::table('synapse_queue')
            ->where('event_type', 'internal')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - 600))
            ->delete();
        $rows = Capsule::table('synapse_tickets')
            ->where(function ($q) {
                $q->whereNull('action_taken')
                    ->orWhere('action_taken', '!=', 'approval_withdrawn');
            })
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
        if ($rows->isEmpty()) {
            return;
        }
        $ticketIds = [];
        foreach ($rows as $row) {
            $ticketIds[] = (int) $row->whmcs_ticket_id;
        }
        $ticketIds = array_values(array_unique($ticketIds));
        $ticketsById = [];
        if ($ticketIds !== []) {
            foreach (Capsule::table('tbltickets')->whereIn('id', $ticketIds)->get() as $ticket) {
                $ticketsById[(int) $ticket->id] = $ticket;
            }
        }
        $staffAuthors = [];
        if ($ticketIds !== []) {
            $staffReplies = Capsule::table('tblticketreplies')
                ->whereIn('tid', $ticketIds)
                ->where(function ($q) {
                    $q->whereNotNull('admin')->where('admin', '!=', '');
                })
                ->orderBy('id', 'desc')
                ->get(['tid', 'admin']);
            foreach ($staffReplies as $reply) {
                $tid = (int) $reply->tid;
                if (!isset($staffAuthors[$tid])) {
                    $staffAuthors[$tid] = trim((string) $reply->admin);
                }
            }
        }
        $internalTicketIds = [];
        if ($ticketIds !== []) {
            $internalTicketIds = Capsule::table('synapse_queue')
                ->whereIn('whmcs_ticket_id', $ticketIds)
                ->where('event_type', 'internal')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 180))
                ->pluck('whmcs_ticket_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->unique()
                ->flip()
                ->all();
        }
        $events = [];
        $ids = [];
        foreach ($rows as $row) {
            $ticket_id = (int) $row->whmcs_ticket_id;
            $ticket = $ticketsById[$ticket_id] ?? null;
            if (!$ticket) {
                $events[] = [
                    'whmcs_ticket_id' => $ticket_id,
                    'event' => 'closed',
                    'closed' => true,
                    'closed_by' => 'deleted',
                    'timestamp' => date('c'),
                ];
                $ids[] = $ticket_id;
                continue;
            }
            if (synapseTicketIsClosed($ticket)) {
                $author = $staffAuthors[$ticket_id] ?? '';
                $events[] = [
                    'whmcs_ticket_id' => $ticket_id,
                    'event' => 'closed',
                    'actor' => $author,
                    'closed_by' => $author !== '' ? 'staff' : 'user',
                    'whmcs_status' => (string) $ticket->status,
                    'closed' => true,
                    'timestamp' => date('c'),
                ];
                $ids[] = $ticket_id;
                continue;
            }
            $author = $staffAuthors[$ticket_id] ?? '';
            if ($author !== '' && !isset($internalTicketIds[$ticket_id])) {
                $events[] = [
                    'whmcs_ticket_id' => $ticket_id,
                    'event' => 'staff_replied',
                    'actor' => $author,
                    'whmcs_status' => (string) $ticket->status,
                    'has_staff_reply' => true,
                    'staff_author' => $author,
                    'timestamp' => date('c'),
                ];
                $ids[] = $ticket_id;
            }
        }
        if ($events === []) {
            return;
        }
        try {
            $client = new SynapseClient();
            $client->notifyLifecycle(['events' => $events]);
            Capsule::table('synapse_tickets')
                ->whereIn('whmcs_ticket_id', $ids)
                ->update([
                    'action_taken' => 'approval_withdrawn',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
        } catch (Exception $e) {
            foreach ($events as $event) {
                synapseEnqueueLifecycle(
                    $event['whmcs_ticket_id'],
                    $event['event'],
                    $event['actor'] ?? null
                );
            }
        }
    } catch (Exception $e) {
        logActivity("[Synapse Error] Stale approval sweep failed: " . $e->getMessage());
    }
}

function synapseProcessQueue($limit = 10) {
    if (!synapseIsEnabled()) {
        return;
    }
    if (!synapseEnsureQueueTable()) {
        return;
    }
    $limit = max(1, min(25, (int)$limit));
    try {
        Capsule::table('synapse_queue')
            ->where('status', 'processing')
            ->where(function ($q) {
                $q->whereNull('processed_at')
                    ->orWhere('processed_at', '<', date('Y-m-d H:i:s', time() - 300));
            })
            ->update(['status' => 'pending']);
        $jobs = Capsule::table('synapse_queue')
            ->where('status', 'pending')
            ->where('attempts', '<', 5)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
        foreach ($jobs as $job) {
            $claimed = Capsule::table('synapse_queue')
                ->where('id', $job->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'processed_at' => date('Y-m-d H:i:s')
                ]);
            if (!$claimed) {
                continue;
            }
            try {
                $eventType = isset($job->event_type) && $job->event_type ? (string) $job->event_type : 'ingest';
                if ($eventType === 'internal') {
                    Capsule::table('synapse_queue')->where('id', $job->id)->delete();
                    continue;
                }
                if ($eventType === 'closed' || $eventType === 'staff_replied') {
                    synapseDeliverLifecycle($job);
                } else {
                    synapseProcessTicket(
                        (int)$job->whmcs_ticket_id,
                        (bool)$job->is_reply,
                        isset($job->message) ? $job->message : null,
                        isset($job->reply_id) ? $job->reply_id : null
                    );
                }
                Capsule::table('synapse_queue')->where('id', $job->id)->delete();
            } catch (Exception $e) {
                Capsule::table('synapse_queue')->where('id', $job->id)->update([
                    'status' => 'pending',
                    'attempts' => ((int)$job->attempts) + 1,
                    'last_error' => substr($e->getMessage(), 0, 1000),
                    'processed_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
        Capsule::table('synapse_queue')
            ->where('status', 'pending')
            ->where('attempts', '>=', 5)
            ->update(['status' => 'failed']);
    } catch (Exception $e) {
        logActivity("[Synapse Error] Queue processing failed: " . $e->getMessage());
    }
}

function synapseLatestCustomerReply($ticket_id, $reply_id = null) {
    if ($reply_id && is_numeric($reply_id)) {
        $row = Capsule::table('tblticketreplies')
            ->where('id', (int) $reply_id)
            ->where('tid', (int) $ticket_id)
            ->first();
        if ($row && empty($row->admin)) {
            return $row;
        }
    }
    return Capsule::table('tblticketreplies')
        ->where('tid', (int) $ticket_id)
        ->where(function ($q) {
            $q->whereNull('admin')
                ->orWhere('admin', '')
                ->orWhere('admin', 0);
        })
        ->orderBy('id', 'desc')
        ->first();
}

function synapseProcessTicket($ticket_id, $is_reply = false, $queued_message = null, $reply_id = null) {
    if (!synapseIsEnabled()) {
        return;
    }
    
    try {
        $ticket = Capsule::table('tbltickets')
            ->join('tblticketdepartments', 'tbltickets.did', '=', 'tblticketdepartments.id')
            ->join('tblclients', 'tbltickets.userid', '=', 'tblclients.id')
            ->where('tbltickets.id', $ticket_id)
            ->select([
                'tbltickets.*',
                'tblticketdepartments.name as department_name',
                'tblclients.firstname',
                'tblclients.lastname',
                'tblclients.email'
            ])
            ->first();
        
        if (!$ticket) {
            return;
        }
        
        $mode = synapseGetDepartmentMode($ticket->did, $ticket->department_name);
        if ($mode === 'disabled') {
            return;
        }
        
        $ticket_history = [];
        if ($is_reply) {
            $replies = Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->orderBy('date', 'desc')
                ->limit(20)
                ->get()
                ->reverse()
                ->values();
            
            foreach ($replies as $reply) {
                if (!empty($reply->admin)) {
                    continue;
                }
                $ticket_history[] = [
                    'role' => 'customer',
                    'author' => trim($ticket->firstname . ' ' . $ticket->lastname),
                    'message' => $reply->message,
                    'timestamp' => $reply->date
                ];
            }
            foreach ($replies as $reply) {
                if (empty($reply->admin)) {
                    continue;
                }
                $ticket_history[] = [
                    'role' => 'agent',
                    'author' => $reply->admin,
                    'message' => $reply->message,
                    'timestamp' => $reply->date
                ];
            }
        }
        
        $body = '';
        $body_source = 'none';
        if (is_string($queued_message) && trim($queued_message) !== '') {
            $body = $queued_message;
            $body_source = 'hook';
        }
        $latest_message = null;
        if ($is_reply) {
            $latest_message = synapseLatestCustomerReply($ticket_id, $reply_id);
            if ($body === '' && $latest_message) {
                $body = $latest_message->message;
                $body_source = 'db_customer_reply';
            }
        } else {
            $latest_message = Capsule::table('tblticketreplies')
                ->where('tid', $ticket_id)
                ->orderBy('id', 'desc')
                ->first();
            if ($latest_message) {
                $body = $latest_message->message;
                $body_source = 'db_latest_reply';
            }
        }
        if ($body === '') {
            $body = $ticket->message;
            $body_source = 'ticket_message';
        }
        if ($is_reply && $body !== '') {
            $already = false;
            foreach ($ticket_history as $row) {
                if (($row['message'] ?? '') === $body) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $ticket_history[] = [
                    'role' => 'customer',
                    'author' => trim($ticket->firstname . ' ' . $ticket->lastname),
                    'message' => $body,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        if (synapseDebugEnabled()) {
            $body_preview = substr(preg_replace('/\s+/', ' ', strip_tags((string) $body)), 0, 100);
            logActivity("[Synapse DEBUG] Ticket #{$ticket_id} ingest is_reply=" . ($is_reply ? '1' : '0') . " source={$body_source} body=\"{$body_preview}\" history=" . count($ticket_history));
        }
        
        $staff_author = synapseStaffReplyAuthor($ticket_id);
        $client = new SynapseClient();
        $result = $client->ingestTicket([
            'whmcs_ticket_id' => (int)$ticket_id,
            'ticket_id' => null,
            'subject' => $ticket->title,
            'body' => $body,
            'user_name' => trim($ticket->firstname . ' ' . $ticket->lastname),
            'user_email' => $ticket->email,
            'department' => $ticket->department_name,
            'department_id' => (int)$ticket->did,
            'priority' => strtolower($ticket->urgency ?: 'medium'),
            'client_id' => (int)$ticket->userid,
            'mode' => $mode,
            'source' => 'whmcs',
            'timestamp' => date('c'),
            'is_reply' => $is_reply ? true : false,
            'ticket_history' => $ticket_history ?: null,
            'whmcs_status' => (string) $ticket->status,
            'has_staff_reply' => $staff_author !== '',
            'staff_reply_author' => $staff_author,
            'closed_by' => synapseTicketIsClosed($ticket) ? synapseClosedBy($ticket_id) : ''
        ]);
        
        if ($result) {
            $decision = $result['decision'] ?? 'escalated';
            $action = $result['proposedAction'] ?? null;
            if (in_array($decision, ['withdrawn', 'skipped'], true)) {
                $action = 'approval_withdrawn';
                $decision = 'escalated';
            }
            Capsule::table('synapse_tickets')->updateOrInsert(
                ['whmcs_ticket_id' => $ticket_id],
                [
                    'synapse_ticket_id' => $result['ticketId'] ?? $result['ticket_id'] ?? '',
                    'confidence' => $result['confidence'] ?? 0,
                    'action_taken' => $action,
                    'ai_decision' => in_array($decision, ['autopilot', 'copilot', 'escalated'], true) ? $decision : 'escalated',
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );
            logActivity("[Synapse] Ticket #{$ticket_id} processed - Decision: {$decision}, Confidence: " . ($result['confidence'] ?? 0) . "%");
            if (synapseDebugEnabled() && ($result['decision'] ?? '') === 'duplicate') {
                $body_preview = substr(preg_replace('/\s+/', ' ', strip_tags((string) $body)), 0, 100);
                logActivity("[Synapse DEBUG] Ticket #{$ticket_id} duplicate ingest skipped (body=\"{$body_preview}\")");
            }
        } else {
            if (synapseDebugEnabled()) {
                $body_preview = substr(preg_replace('/\s+/', ' ', strip_tags((string) $body)), 0, 100);
                logActivity("[Synapse WARNING] Ticket #{$ticket_id} ingest returned empty response (body=\"{$body_preview}\")");
            } else {
                logActivity("[Synapse WARNING] Ticket #{$ticket_id} ingest returned empty response");
            }
        }
        
    } catch (Exception $e) {
        logActivity("[Synapse Error] Failed to process ticket #{$ticket_id}: " . $e->getMessage());
        throw $e;
    }
}

add_hook('TicketOpen', 1, function($vars) {
    $ticket_id = $vars['ticketid'] ?? $vars['ticketId'] ?? null;
    if (!$ticket_id) {
        return;
    }
    synapseEnqueueTicket($ticket_id, false, $vars['message'] ?? null);
});

add_hook('TicketUserReply', 1, function($vars) {
    $ticket_id = $vars['ticketid'] ?? $vars['ticketId'] ?? null;
    if (!$ticket_id) {
        return;
    }
    $message = $vars['message'] ?? $vars['reply'] ?? $vars['message_text'] ?? $vars['replymessage'] ?? null;
    $reply_id = $vars['replyid'] ?? $vars['reply_id'] ?? null;
    synapseEnqueueTicket($ticket_id, true, $message, $reply_id);
});

add_hook('TicketClose', 1, function($vars) {
    $ticket_id = $vars['ticketid'] ?? $vars['ticketId'] ?? null;
    if (!$ticket_id) {
        return;
    }
    synapseEnqueueLifecycle($ticket_id, 'closed', synapseStaffReplyAuthor($ticket_id));
});

add_hook('TicketStatusChange', 1, function($vars) {
    $ticket_id = $vars['ticketid'] ?? $vars['ticketId'] ?? null;
    $status = $vars['status'] ?? '';
    if (!$ticket_id) {
        return;
    }
    $ticket = (object) ['status' => $status];
    if (synapseTicketIsClosed($ticket)) {
        synapseEnqueueLifecycle($ticket_id, 'closed', synapseStaffReplyAuthor($ticket_id));
    }
});

add_hook('TicketAdminReply', 1, function($vars) {
    $ticket_id = $vars['ticketid'] ?? $vars['ticketId'] ?? null;
    if (!$ticket_id) {
        return;
    }
    if (synapseIsInternalAction($ticket_id)) {
        return;
    }
    $actor = trim((string) ($vars['admin'] ?? $vars['name'] ?? ''));
    synapseEnqueueLifecycle($ticket_id, 'staff_replied', $actor !== '' ? $actor : synapseStaffReplyAuthor($ticket_id));
});

add_hook('AfterCronJob', 1, function($vars) {
    synapseSweepStaleApprovals(100);
    synapseProcessQueue(15);
});

add_hook('AdminAreaPage', 1, function($vars) {
    if ($vars['filename'] !== 'supporttickets') {
        return;
    }
    
    if (!synapseIsEnabled()) {
        return;
    }
    
    $csrfToken = '';
    if (class_exists('WHMCS\Session')) {
        $csrfToken = (string) WHMCS\Session::get('synapseCsrfToken');
        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(16));
            WHMCS\Session::set('synapseCsrfToken', $csrfToken);
        }
    }
    
    return <<<HTML
<script>
window.synapseCsrfToken = '{$csrfToken}';
document.addEventListener('DOMContentLoaded', function() {
    const tickets = document.querySelectorAll('table.datatable tbody tr');
    const ticketMap = {};
    const ids = [];
    
    tickets.forEach(function(row) {
        const ticketLink = row.querySelector('a[href*="supporttickets.php?action=view"]');
        if (!ticketLink) return;
        
        const ticketId = ticketLink.href.match(/id=(\d+)/);
        if (!ticketId) return;
        
        ids.push(ticketId[1]);
        ticketMap[ticketId[1]] = row;
    });
    
    if (ids.length === 0) return;
    
    const currentPath = window.location.pathname;
    const adminDir = currentPath.substring(0, currentPath.lastIndexOf('/'));
    const modulePath = adminDir + '/addonmodules.php?module=synapse&synapse_ajax=tickets_badges';
    const csrfToken = (window.synapseCsrfToken || window.csrfToken || '');
    
    fetch(modulePath + '&ids=' + ids.join(','), {
        credentials: 'same-origin',
        headers: { 'X-Synapse-Token': csrfToken, 'Accept': 'application/json' }
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.badges) return;
            Object.keys(data.badges).forEach(function(ticketId) {
                const synapseData = data.badges[ticketId];
                const row = ticketMap[ticketId];
                if (!row || !synapseData || synapseData.ai_decision === 'escalated') return;
                const statusCell = row.querySelector('td:nth-child(4)');
                if (!statusCell) return;
                const badge = document.createElement('span');
                badge.className = 'label label-' + (synapseData.ai_decision === 'autopilot' ? 'success' : 'warning');
                badge.style.marginLeft = '5px';
                badge.textContent = 'AI';
                badge.title = 'Processed by Synapse AI (' + Math.round(synapseData.confidence * 10) / 10 + '% confidence)';
                statusCell.appendChild(badge);
            });
        })
        .catch(() => {});
});
</script>
HTML;
});

add_hook('TicketDelete', 1, function($vars) {
    $ticket_id = $vars['ticketid'];
    
    if (!$ticket_id) {
        return;
    }
    
    try {
        Capsule::table('synapse_tickets')
            ->where('whmcs_ticket_id', $ticket_id)
            ->delete();
        if (Capsule::schema()->hasTable('synapse_queue')) {
            Capsule::table('synapse_queue')
                ->where('whmcs_ticket_id', $ticket_id)
                ->delete();
        }
    } catch (Exception $e) {
        logActivity("[Synapse] Failed to cleanup deleted ticket #{$ticket_id}: " . $e->getMessage());
    }
});