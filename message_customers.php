<?php
require_once 'config.php';
require_once 'SplynxApiClient.php';

// Receive customer data from batch_lookup.php
$customersData = isset($_POST['customers_data']) ? json_decode($_POST['customers_data'], true) : [];

// Handle form submission
$statusMessages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_send'])) {
    $api = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);
    
    $smsMessage = $_POST['sms_message'] ?? '';
    $emailSubject = $_POST['email_subject'] ?? '';
    $emailMessage = $_POST['email_message'] ?? '';
    
    $selectedPhones = $_POST['selected_phones'] ?? [];
    $selectedEmails = $_POST['selected_emails'] ?? [];
    
    // Send SMS messages
    $smsSuccessCount = 0;
    $smsFailCount = 0;
    if (!empty($smsMessage) && !empty($selectedPhones)) {
        // Extract unique customer IDs for SMS
        $smsCustomerIds = [];
        foreach ($selectedPhones as $phoneData) {
            list($customerId, $phone) = explode('|', $phoneData);
            if (!in_array($customerId, $smsCustomerIds)) {
                $smsCustomerIds[] = (int)$customerId;
            }
        }
        
        // Send to each customer individually
        foreach ($smsCustomerIds as $customerId) {
            $payload = [
                'id' => $customerId,
                'document_type' => 'empty',
                'type' => 'sms',
                'subject' => 'SMS Notification',  // Required even for SMS
                'message' => $smsMessage
            ];
            
            error_log("SMS Payload for customer $customerId: " . json_encode($payload));
            $result = $api->post("admin/customers/send-documents", $payload);
            error_log("SMS Result for customer $customerId: " . json_encode($result));
            
            if ($result && !isset($result['error'])) {
                $smsSuccessCount++;
            } else {
                $smsFailCount++;
                $errorMsg = isset($result['error']) ? json_encode($result['error']) : 'Unknown error';
                error_log("SMS Error for customer $customerId: " . $errorMsg);
            }
        }
        
        $statusMessages[] = "SMS: Successfully sent to $smsSuccessCount customer(s)" . ($smsFailCount > 0 ? ", failed: $smsFailCount" : "");
    }
    
    // Send Email messages
    $emailSuccessCount = 0;
    $emailFailCount = 0;
    if (!empty($emailMessage) && !empty($selectedEmails)) {
        // Extract unique customer IDs for Email
        $emailCustomerIds = [];
        foreach ($selectedEmails as $emailData) {
            list($customerId, $email) = explode('|', $emailData);
            if (!in_array($customerId, $emailCustomerIds)) {
                $emailCustomerIds[] = (int)$customerId;
            }
        }
        
        // Send to each customer individually
        foreach ($emailCustomerIds as $customerId) {
            $payload = [
                'id' => $customerId,
                'document_type' => 'empty',
                'type' => 'mail',
                'subject' => $emailSubject,
                'message' => $emailMessage
            ];
            
            error_log("Email Payload for customer $customerId: " . json_encode($payload));
            $result = $api->post("admin/customers/send-documents", $payload);
            error_log("Email Result for customer $customerId: " . json_encode($result));
            
            if ($result && !isset($result['error'])) {
                $emailSuccessCount++;
            } else {
                $emailFailCount++;
                $errorMsg = isset($result['error']) ? json_encode($result['error']) : 'Unknown error';
                error_log("Email Error for customer $customerId: " . $errorMsg);
            }
        }
        
        $statusMessages[] = "Email: Successfully sent to $emailSuccessCount customer(s)" . ($emailFailCount > 0 ? ", failed: $emailFailCount" : "");
    }
    
    if (empty($selectedPhones) && empty($selectedEmails)) {
        $statusMessages[] = "No recipients selected. Please select at least one phone number or email address.";
    }
}

/**
 * Validates if a phone number is a valid New Zealand mobile number
 */
function isValidNZMobile($phone) {
    // Remove all non-numeric characters
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    
    // NZ mobile numbers: 021, 022, 027, 028, 029 (10 digits total)
    // Can also be in international format: +6421, +6422, +6427, +6428, +6429
    if (preg_match('/^(021|022|027|028|029)\d{6,7}$/', $cleaned)) {
        return true;
    }
    if (preg_match('/^64(21|22|27|28|29)\d{6,7}$/', $cleaned)) {
        return true;
    }
    return false;
}

/**
 * Validates if a string is a valid email address
 */
function isValidEmail($email) {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Parses comma-separated values and returns an array
 */
function parseCommaSeparated($value) {
    if (empty($value)) return [];
    return array_map('trim', explode(',', $value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message Customers</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 p-4 sm:p-8">
    <div class="max-w-7xl mx-auto bg-white p-6 rounded-xl shadow-md">
        <h1 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-3">Message Customers</h1>
        
        <?php if (!empty($statusMessages)): ?>
            <div class="mb-6 space-y-2">
                <?php foreach ($statusMessages as $msg): ?>
                    <div class="bg-green-100 text-green-800 p-3 rounded-lg font-medium"><?php echo htmlspecialchars($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($customersData)): ?>
            <div class="bg-yellow-100 text-yellow-800 p-4 rounded-lg">
                No customer data received. Please return to the batch lookup page.
            </div>
        <?php else: ?>
            <form method="POST" id="messageForm">
                <!-- Hidden field to preserve customer data -->
                <input type="hidden" name="customers_data" value="<?php echo htmlspecialchars(json_encode($customersData)); ?>">
                
                <!-- Customer Table -->
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-3">Recipients (<?php echo count($customersData); ?> customers)</h2>
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone Numbers</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email Addresses</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact 2</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($customersData as $customer): ?>
                                    <?php
                                    $customerId = $customer['customer_id'] ?? '';
                                    $customerName = $customer['customer_name'] ?? 'Unknown';
                                    $customerPhones = parseCommaSeparated($customer['customer_phone'] ?? '');
                                    $customerEmails = parseCommaSeparated($customer['customer_email'] ?? '');
                                    $contact2Name = $customer['contact_2_name'] ?? '';
                                    $contact2Phone = $customer['contact_2_phone'] ?? '';
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            <div class="font-medium"><?php echo htmlspecialchars($customerName); ?></div>
                                            <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($customerId); ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <?php if (!empty($customerPhones)): ?>
                                                <div class="space-y-1">
                                                    <?php foreach ($customerPhones as $phone): ?>
                                                        <?php if (isValidNZMobile($phone)): ?>
                                                            <label class="flex items-center space-x-2 cursor-pointer">
                                                                <input type="checkbox" name="selected_phones[]" 
                                                                       value="<?php echo htmlspecialchars($customerId . '|' . $phone); ?>"
                                                                       class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                                                <span class="text-gray-900"><?php echo htmlspecialchars($phone); ?></span>
                                                                <span class="text-xs text-green-600 font-medium">(NZ Mobile)</span>
                                                            </label>
                                                        <?php else: ?>
                                                            <div class="text-gray-500"><?php echo htmlspecialchars($phone); ?></div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">No phone</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <?php if (!empty($customerEmails)): ?>
                                                <div class="space-y-1">
                                                    <?php foreach ($customerEmails as $email): ?>
                                                        <?php if (isValidEmail($email)): ?>
                                                            <label class="flex items-center space-x-2 cursor-pointer">
                                                                <input type="checkbox" name="selected_emails[]" 
                                                                       value="<?php echo htmlspecialchars($customerId . '|' . $email); ?>"
                                                                       class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                                                <span class="text-gray-900"><?php echo htmlspecialchars($email); ?></span>
                                                            </label>
                                                        <?php else: ?>
                                                            <div class="text-gray-500"><?php echo htmlspecialchars($email); ?></div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">No email</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600">
                                            <?php if (!empty($contact2Name)): ?>
                                                <div class="font-medium"><?php echo htmlspecialchars($contact2Name); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($contact2Phone)): ?>
                                                <div class="text-xs"><?php echo htmlspecialchars($contact2Phone); ?></div>
                                            <?php endif; ?>                                            
                                            <?php if (empty($contact2Name) && empty($contact2Phone)): ?>
                                                <span class="text-gray-400">No contact 2</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Select All Buttons -->
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" onclick="selectAllCheckboxes('selected_phones')" 
                                class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium">
                            Select All Phones
                        </button>
                        <button type="button" onclick="deselectAllCheckboxes('selected_phones')" 
                                class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium">
                            Deselect All Phones
                        </button>
                        <button type="button" onclick="selectAllCheckboxes('selected_emails')" 
                                class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium">
                            Select All Emails
                        </button>
                        <button type="button" onclick="deselectAllCheckboxes('selected_emails')" 
                                class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-sm font-medium">
                            Deselect All Emails
                        </button>
                    </div>
                </div>

                <!-- Message Composition -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- SMS Section -->
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-700 mb-3">SMS Message</h3>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Message Text</label>
                            <textarea name="sms_message" rows="6" 
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Enter SMS message (160 characters recommended)..."
                                      maxlength="480"> - DO NOT REPLY</textarea>
                            <p class="text-xs text-gray-500 mt-1">Character count: <span id="smsCharCount">16</span></p>
                        </div>
                    </div>

                    <!-- Email Section -->
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-700 mb-3">Email Message</h3>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                            <input type="text" name="email_subject" 
                                   class="w-full p-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Email subject...">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
                            <textarea name="email_message" rows="6" 
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Enter email message..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Send Button -->
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="window.close()" 
                            class="px-6 py-3 bg-gray-500 hover:bg-gray-600 text-white font-semibold rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" name="submit_send" 
                            class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                        Send Messages
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Character counter for SMS
        const smsTextarea = document.querySelector('textarea[name="sms_message"]');
        const smsCharCount = document.getElementById('smsCharCount');
        
        if (smsTextarea) {
            smsTextarea.addEventListener('input', function() {
                smsCharCount.textContent = this.value.length;
            });
        }

        // Select/Deselect all checkboxes
        function selectAllCheckboxes(name) {
            document.querySelectorAll(`input[name="${name}[]"]`).forEach(cb => cb.checked = true);
        }

        function deselectAllCheckboxes(name) {
            document.querySelectorAll(`input[name="${name}[]"]`).forEach(cb => cb.checked = false);
        }

        // Form validation
        document.getElementById('messageForm')?.addEventListener('submit', function(e) {
            const smsMessage = document.querySelector('textarea[name="sms_message"]').value.trim();
            const emailMessage = document.querySelector('textarea[name="email_message"]').value.trim();
            const emailSubject = document.querySelector('input[name="email_subject"]').value.trim();
            
            const selectedPhones = document.querySelectorAll('input[name="selected_phones[]"]:checked').length;
            const selectedEmails = document.querySelectorAll('input[name="selected_emails[]"]:checked').length;
            
            if (selectedPhones === 0 && selectedEmails === 0) {
                e.preventDefault();
                alert('Please select at least one phone number or email address to send messages.');
                return false;
            }
            
            if (selectedPhones > 0 && !smsMessage) {
                e.preventDefault();
                alert('Please enter an SMS message for the selected phone numbers.');
                return false;
            }
            
            if (selectedEmails > 0 && (!emailMessage || !emailSubject)) {
                e.preventDefault();
                alert('Please enter both email subject and message for the selected email addresses.');
                return false;
            }
            
            return confirm(`Send messages to ${selectedPhones} phone number(s) and ${selectedEmails} email address(es)?`);
        });
    </script>
</body>
</html>
