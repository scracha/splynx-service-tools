<?php
/**
 * Close Open Tickets by Email Address
 * 
 * Usage: php close_tickets_by_email.php <email_address> [--id=<incoming_customer_id>] [--dry-run]
 * 
 * Finds all open (non-closed, non-trashed) tickets from a given email address
 * and closes them via the Splynx API.
 * 
 * If --id is provided, verifies the email matches that incoming_customer_id then
 * closes all open tickets with that ID.
 * 
 * If --id is not provided, checks the local lookup cache first, then searches
 * ticket messages to find the incoming_customer_id associated with the email.
 * 
 * Options:
 *   --id=N      Specify the incoming_customer_id directly (skips search)
 *   --dry-run   Show which tickets would be closed without actually closing them
 */

require_once 'config.php';
require_once 'SplynxApiClient.php';

global $splynxBaseUrl, $apiKey, $apiSecret;

const EMAIL_LOOKUP_PATH = '/dev/shm/splynx_email_lookup.json';

// --- Email-to-ID lookup cache ---
function loadEmailLookup() {
    if (!file_exists(EMAIL_LOOKUP_PATH)) return [];
    $data = json_decode(file_get_contents(EMAIL_LOOKUP_PATH), true);
    return is_array($data) ? $data : [];
}

function saveEmailLookup($lookup) {
    file_put_contents(EMAIL_LOOKUP_PATH, json_encode($lookup, JSON_PRETTY_PRINT));
}

function cacheEmailId($email, $id) {
    $lookup = loadEmailLookup();
    $lookup[strtolower(trim($email))] = (int)$id;
    saveEmailLookup($lookup);
}

function getCachedId($email) {
    $lookup = loadEmailLookup();
    return $lookup[strtolower(trim($email))] ?? null;
}

// --- Parse arguments ---
$dryRun = in_array('--dry-run', $argv);
$incomingId = null;
$email = null;

for ($i = 1; $i < $argc; $i++) {
    if (strpos($argv[$i], '--id=') === 0) {
        $incomingId = (int)substr($argv[$i], 5);
    } elseif (strpos($argv[$i], '--') !== 0) {
        $email = trim($argv[$i]);
    }
}

if (!$email) {
    echo "Usage: php close_tickets_by_email.php <email_address> [--id=<incoming_customer_id>] [--dry-run]\n";
    echo "\nExamples:\n";
    echo "  php close_tickets_by_email.php spammer@example.com --dry-run\n";
    echo "  php close_tickets_by_email.php spammer@example.com --id=41\n";
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "ERROR: '$email' is not a valid email address.\n";
    exit(1);
}

$apiClient = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

// --- 1. Resolve incoming_customer_id ---
if ($incomingId) {
    // Verify the email matches by checking a ticket message with this ID
    echo "Verifying incoming_customer_id=$incomingId matches '$email'...\n";
    
    $messages = $apiClient->get('admin/support/ticket-messages', [
        'main_attributes' => ['incoming_customer_id' => $incomingId],
        'limit' => 5,
    ]);
    
    $verified = false;
    if ($messages && is_array($messages) && !isset($messages['error'])) {
        foreach ($messages as $msg) {
            if (strcasecmp(trim($msg['mail_to'] ?? ''), $email) === 0) {
                $verified = true;
                break;
            }
        }
    }
    
    if (!$verified) {
        echo "WARNING: Could not verify that incoming_customer_id=$incomingId matches '$email'.\n";
        echo "Proceed anyway? (yes/no): ";
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'yes') {
            echo "Aborted.\n";
            exit(0);
        }
    } else {
        echo "  ✓ Verified: ID $incomingId = $email\n";
    }
    
    // Cache the mapping
    cacheEmailId($email, $incomingId);
    
} else {
    // Check local cache first
    $cachedId = getCachedId($email);
    if ($cachedId) {
        echo "Found in cache: '$email' = incoming_customer_id=$cachedId\n";
        echo "Verifying cache is still valid...\n";
        
        // Sanity check: confirm a recent ticket with this ID still has this email
        $messages = $apiClient->get('admin/support/ticket-messages', [
            'main_attributes' => ['incoming_customer_id' => $cachedId, 'author_type' => 'customer'],
            'limit' => 3,
        ]);
        
        $stillValid = false;
        if ($messages && is_array($messages) && !isset($messages['error'])) {
            foreach ($messages as $msg) {
                if (strcasecmp(trim($msg['mail_to'] ?? ''), $email) === 0) {
                    $stillValid = true;
                    break;
                }
            }
        }
        
        if ($stillValid) {
            $incomingId = $cachedId;
            echo "  ✓ Cache verified\n";
        } else {
            echo "  ✗ Cache stale — email no longer matches ID $cachedId. Re-searching...\n";
            // Fall through to full search below
        }
    }
    
    if (!$incomingId) {
        // Search ticket messages to find the incoming_customer_id for this email
        echo "Searching for incoming_customer_id matching '$email'...\n";
        echo "(This may take a moment — scanning ticket messages)\n\n";
        
        $pageSize = 100;
        $offset = 0;
        $fetching = true;
        $checked = 0;
        
        while ($fetching && !$incomingId) {
            $tickets = $apiClient->get('admin/support/tickets', [
                'main_attributes' => ['customer_id' => 0],
                'limit' => $pageSize,
                'offset' => $offset,
            ]);
            
            if (!$tickets || !is_array($tickets) || empty($tickets) || isset($tickets['error'])) {
                $fetching = false;
                break;
            }
            
            foreach ($tickets as $ticket) {
                if (($ticket['closed'] ?? 0) == 1) continue;
                if (($ticket['trash'] ?? 0) == 1) continue;
                
                $tId = $ticket['id'];
                $checked++;
                
                $messages = $apiClient->get('admin/support/ticket-messages', [
                    'main_attributes' => ['ticket_id' => $tId, 'author_type' => 'customer'],
                    'limit' => 3,
                ]);
                
                if ($messages && is_array($messages) && !isset($messages['error'])) {
                    foreach ($messages as $msg) {
                        $msgEmail = trim($msg['mail_to'] ?? '');
                        if (!empty($msgEmail) && strcasecmp($msgEmail, $email) === 0) {
                            $incomingId = (int)($msg['incoming_customer_id'] ?? $ticket['incoming_customer_id'] ?? 0);
                            if ($incomingId) {
                                echo "  ✓ Found: incoming_customer_id=$incomingId (from ticket #$tId)\n";
                                $fetching = false;
                                break 2;
                            }
                        }
                    }
                }
                
                if ($checked % 10 === 0) {
                    echo "  Checked $checked tickets...\r";
                }
            }
            
            if (count($tickets) < $pageSize) {
                $fetching = false;
            }
            $offset += $pageSize;
        }
        
        if (!$incomingId) {
            echo "\nNo tickets found with messages from: $email (checked $checked tickets)\n";
            exit(0);
        }
        
        // Cache the mapping for next time
        cacheEmailId($email, $incomingId);
    }
}

// --- 2. Find all open tickets with this incoming_customer_id ---
echo "\nFinding open tickets with incoming_customer_id=$incomingId...\n";

$ticketsToClose = [];
$pageSize = 500;
$offset = 0;
$fetching = true;

while ($fetching) {
    $tickets = $apiClient->get('admin/support/tickets', [
        'main_attributes' => ['incoming_customer_id' => $incomingId],
        'limit' => $pageSize,
        'offset' => $offset,
    ]);
    
    if (!$tickets || !is_array($tickets) || empty($tickets) || isset($tickets['error'])) {
        $fetching = false;
        break;
    }
    
    foreach ($tickets as $ticket) {
        if (($ticket['closed'] ?? 0) == 1) continue;
        if (($ticket['trash'] ?? 0) == 1) continue;
        
        $ticketsToClose[] = [
            'id' => $ticket['id'],
            'subject' => $ticket['subject'] ?? 'No Subject',
        ];
    }
    
    if (count($tickets) < $pageSize) {
        $fetching = false;
    }
    $offset += $pageSize;
}

if (empty($ticketsToClose)) {
    echo "No open tickets found for incoming_customer_id=$incomingId\n";
    exit(0);
}

echo "\nFound " . count($ticketsToClose) . " open ticket(s):\n";
echo str_repeat('-', 60) . "\n";
foreach ($ticketsToClose as $t) {
    echo "  #{$t['id']} - {$t['subject']}\n";
}
echo str_repeat('-', 60) . "\n\n";

if ($dryRun) {
    echo "[DRY RUN] No tickets were closed.\n";
    echo "To close these tickets, run without --dry-run\n";
    exit(0);
}

// --- 3. Confirm and close ---
echo "Close all " . count($ticketsToClose) . " ticket(s)? (yes/no): ";
$confirm = trim(fgets(STDIN));
if (strtolower($confirm) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

$closed = 0;
$failed = 0;

foreach ($ticketsToClose as $t) {
    $result = $apiClient->put('admin/support/tickets/' . $t['id'], [
        'closed' => 1,
    ]);

    if ($result) {
        echo "  ✓ Closed #{$t['id']} - {$t['subject']}\n";
        $closed++;
    } else {
        echo "  ✗ FAILED #{$t['id']} - {$t['subject']}\n";
        $failed++;
    }
}

echo "\nDone. Closed: $closed, Failed: $failed\n";
