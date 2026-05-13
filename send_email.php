<?php
require_once 'config.php';
require_once 'SplynxApiClient.php';

$customerIds = isset($_POST['customer_ids']) ? json_decode($_POST['customer_ids'], true) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_send'])) {
    $api = new SplynxApiClient($splynxApiUrl, $apiKey, $apiSecret);
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    $copyTo = $_POST['copy_to'];
    
    $successCount = 0;
    foreach ($_POST['ids'] as $id) {
        $payload = [
            'type' => 'mail',
            'subject' => $subject,
            'message' => $message,
            'copy_to' => $copyTo
        ];
        // Based on Splynx API: /admin/customers/customer/{customer_id}/send-document
        $result = $api->post("customers/customer/$id/send-document", $payload);
        if ($result) $successCount++;
    }
    $statusMsg = "Successfully queued $successCount emails.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Customers</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded-xl shadow-md">
        <h1 class="text-2xl font-bold mb-4">Send Document to <?php echo count($customerIds); ?> Customers</h1>
        
        <?php if (isset($statusMsg)): ?>
            <div class="bg-green-100 text-green-800 p-3 rounded mb-4"><?php echo $statusMsg; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php foreach ($customerIds as $id): ?>
                <input type="hidden" name="ids[]" value="<?php echo $id; ?>">
            <?php endforeach; ?>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Subject</label>
                <input type="text" name="subject" required class="w-full border p-2 rounded" placeholder="Network Maintenance Notice">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">CC (Optional copy_to)</label>
                <input type="email" name="copy_to" class="w-full border p-2 rounded" placeholder="admin@yourisp.com">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Message (String)</label>
                <textarea name="message" rows="6" required class="w-full border p-2 rounded" placeholder="Dear Customer, please be advised..."></textarea>
            </div>

            <button type="submit" name="submit_send" class="bg-blue-600 text-white px-6 py-2 rounded font-bold">Send to All</button>
        </form>
    </div>
</body>
</html>