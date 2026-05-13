<?php
/**
 * Audio Transcription Tool using OpenAI Whisper API
 */

require_once 'config.php';

$transcription = '';
$error = '';
$uploadedFile = '';
$usedProvider = '';

// Determine which API to use (prefer Google AI Studio if available)
$useGoogleAI = isset($googleAIStudioKey) && !empty($googleAIStudioKey);
$useOpenAI = isset($openAPIkey) && !empty($openAPIkey);

// Check if at least one API key is configured
if (!$useGoogleAI && !$useOpenAI) {
    $error = 'No transcription API key configured. Please add $googleAIStudioKey or $openAPIkey to config.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio_file']) && empty($error)) {
    $file = $_FILES['audio_file'];
    
    // Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload error: ' . $file['error'];
    } else {
        $allowedTypes = ['audio/wav', 'audio/wave', 'audio/x-wav', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/webm'];
        $fileType = mime_content_type($file['tmp_name']);
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file size (OpenAI limit is 25MB)
        if ($file['size'] > 25 * 1024 * 1024) {
            $error = 'File size exceeds 25MB limit.';
        } elseif (!in_array($fileType, $allowedTypes) && !in_array($fileExtension, ['wav', 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'webm'])) {
            $error = 'Invalid file type. Please upload a WAV, MP3, MP4, or WebM audio file.';
        } else {
            // Call transcription API (prefer Google AI Studio)
            if ($useGoogleAI) {
                $transcription = transcribeWithGoogleAI($file['tmp_name'], $file['name'], $googleAIStudioKey);
                $usedProvider = 'Google AI Studio';
            } else {
                $transcription = transcribeWithOpenAI($file['tmp_name'], $file['name'], $openAPIkey);
                $usedProvider = 'OpenAI Whisper';
            }
            
            if ($transcription === false || empty($transcription)) {
                $error = "Failed to transcribe audio using $usedProvider. Please check your API key and try again.";
            } elseif (strpos($transcription, 'failed') !== false || strpos($transcription, 'error') !== false) {
                // Error message returned from transcription function
                $error = $transcription;
            } else {
                $uploadedFile = $file['name'];
            }
        }
    }
}

/**
 * Transcribe audio file using Google AI Studio (Gemini) API
 */
function transcribeWithGoogleAI($filePath, $fileName, $apiKey) {
    // Read and encode file content
    $fileContent = file_get_contents($filePath);
    $base64Audio = base64_encode($fileContent);
    $mimeType = mime_content_type($filePath);
    
    // Use Gemini with inline audio data
    $transcribeUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . $apiKey;
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => 'Please transcribe this audio file. Provide only the transcription text without any additional commentary or formatting.'
                    ],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Audio
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $transcribeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        error_log("Google AI transcription cURL error: " . $curlError);
        return "Transcription failed: " . $curlError;
    }
    
    if ($httpCode !== 200) {
        error_log("Google AI transcription error (HTTP $httpCode): " . $response);
        return "Transcription failed (HTTP $httpCode): " . substr($response, 0, 300);
    }
    
    $result = json_decode($response, true);
    $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if (!$text) {
        error_log("Google AI: No text in response. Response: " . $response);
        return "Transcription failed: No text in response";
    }
    
    return $text;
}

/**
 * Transcribe audio file using OpenAI Whisper API
 */
function transcribeWithOpenAI($filePath, $fileName, $apiKey) {
    $url = 'https://api.openai.com/v1/audio/transcriptions';
    
    // Create CURLFile for multipart upload
    $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
    
    $postData = [
        'file' => $cFile,
        'model' => 'whisper-1',
        'response_format' => 'json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minute timeout for large files
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        error_log("Transcription cURL Error: " . $curlError);
        return false;
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        error_log("Transcription API Error (HTTP $httpCode): " . $response);
        return false;
    }
    
    return $result['text'] ?? false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audio Transcription Tool</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .drop-zone {
            border: 2px dashed #cbd5e1;
            transition: all 0.3s ease;
        }
        .drop-zone.dragover {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen p-4 sm:p-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-6">
            <div class="flex items-center space-x-3 mb-2">
                <span class="material-icons text-blue-600 text-3xl">mic</span>
                <h1 class="text-3xl font-bold text-gray-800">Audio Transcription</h1>
            </div>
            <p class="text-gray-600 text-sm">Upload an audio file to transcribe it using 
                <?php 
                if (isset($googleAIStudioKey) && !empty($googleAIStudioKey)) {
                    echo 'Google AI Studio (Gemini 2.5 Flash)';
                } elseif (isset($openAPIkey) && !empty($openAPIkey)) {
                    echo 'OpenAI Whisper';
                } else {
                    echo 'AI transcription';
                }
                ?>
            </p>
        </div>

        <!-- Upload Form -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-6">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="drop-zone rounded-lg p-8 text-center cursor-pointer" id="dropZone">
                    <input type="file" name="audio_file" id="audioFile" accept=".wav,.mp3,.mp4,.mpeg,.mpga,.m4a,.webm,audio/*" class="hidden" required>
                    
                    <span class="material-icons text-gray-400 text-6xl mb-4">cloud_upload</span>
                    <p class="text-lg font-semibold text-gray-700 mb-2">Drop audio file here or click to browse</p>
                    <p class="text-sm text-gray-500 mb-4">Supported formats: WAV, MP3, MP4, M4A, WebM (Max 25MB)</p>
                    
                    <div id="fileInfo" class="hidden mt-4 p-3 bg-blue-50 rounded-lg">
                        <div class="flex items-center justify-center space-x-2">
                            <span class="material-icons text-blue-600">audio_file</span>
                            <span id="fileName" class="text-sm font-medium text-blue-900"></span>
                            <span id="fileSize" class="text-xs text-blue-600"></span>
                        </div>
                    </div>
                </div>

                <button type="submit" id="submitBtn" class="w-full mt-6 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition duration-150 ease-in-out shadow-md flex items-center justify-center space-x-2" disabled>
                    <span class="material-icons">transcribe</span>
                    <span>Transcribe Audio</span>
                </button>
            </form>
        </div>

        <!-- Error Message -->
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start">
                <span class="material-icons mr-3 mt-0.5">error</span>
                <div>
                    <p class="font-bold">Error</p>
                    <p class="text-sm"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Transcription Result -->
        <?php if (!empty($transcription)): ?>
            <div class="bg-white rounded-xl shadow-md p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-2">
                        <span class="material-icons text-green-600">check_circle</span>
                        <h2 class="text-xl font-bold text-gray-800">Transcription Result</h2>
                    </div>
                    <button onclick="copyToClipboard()" class="flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium transition">
                        <span class="material-icons text-sm">content_copy</span>
                        <span>Copy</span>
                    </button>
                </div>
                
                <?php if (!empty($uploadedFile)): ?>
                    <div class="mb-3 text-sm text-gray-600">
                        <span class="font-semibold">File:</span> <?php echo htmlspecialchars($uploadedFile); ?>
                        <?php if (!empty($usedProvider)): ?>
                            <span class="ml-3 text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded"><?php echo htmlspecialchars($usedProvider); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                    <p id="transcriptionText" class="text-gray-800 leading-relaxed whitespace-pre-wrap"><?php echo htmlspecialchars($transcription); ?></p>
                </div>
                
                <div class="mt-4 flex items-center justify-between text-sm text-gray-500">
                    <span>Word count: <span class="font-semibold"><?php echo str_word_count($transcription); ?></span></span>
                    <span>Character count: <span class="font-semibold"><?php echo mb_strlen($transcription); ?></span></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('audioFile');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const submitBtn = document.getElementById('submitBtn');
        const uploadForm = document.getElementById('uploadForm');

        // Click to browse
        dropZone.addEventListener('click', () => fileInput.click());

        // File input change
        fileInput.addEventListener('change', handleFileSelect);

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleFileSelect();
            }
        });

        function handleFileSelect() {
            const file = fileInput.files[0];
            if (file) {
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                fileInfo.classList.remove('hidden');
                submitBtn.disabled = false;
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function copyToClipboard() {
            const text = document.getElementById('transcriptionText').textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('Transcription copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }

        // Show loading state on submit
        uploadForm.addEventListener('submit', function() {
            submitBtn.innerHTML = '<span class="material-icons animate-spin">refresh</span><span>Transcribing...</span>';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>
