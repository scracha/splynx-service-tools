<?php
/**
 * Splynx Task API Endpoint
 * Serves the pre-generated task data from shared memory.
 */

require_once 'config.php';

header('Content-Type: application/json');

const TASK_STORE_PATH = '/dev/shm/splynx_open_tasks.json';

if (!file_exists(TASK_STORE_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'Task data not available. Run task_exporter_cli.php first.']);
    exit;
}

$jsonData = file_get_contents(TASK_STORE_PATH);
$tasks = json_decode($jsonData, true);

if ($tasks === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse task data file.']);
    exit;
}

echo json_encode($tasks);
