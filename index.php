<?php
/**
 * Media Subdomain Router
 * Serves media files with proper headers and security
 */

// Set headers for media serving
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

// Get the request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = trim($requestUri, '/');

// Determine if localhost
$is_localhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

// Remove any prefix for localhost (subdomain prefix in URI)
if ($is_localhost) {
    // Remove leading slash if present
    $requestUri = ltrim($requestUri, '/');
    // Remove subdomain prefix if present
    if (strpos($requestUri, 'sd3sk.hubtweak/') === 0) {
        $requestUri = substr($requestUri, strlen('sd3sk.hubtweak/'));
    }
    // Also handle if it's just the subdomain without trailing slash
    if ($requestUri === 'sd3sk.hubtweak') {
        $requestUri = '';
    }
}

// Base directory for media files
$mediaBaseDir = __DIR__;

// If request is empty, show directory listing or 404
if (empty($requestUri) || $requestUri === 'index.php') {
    http_response_code(404);
    echo 'Media file not specified';
    exit();
}

// Construct full file path
$filePath = $mediaBaseDir . '/' . $requestUri;

// Security: Prevent directory traversal - normalize the path first
$normalizedPath = str_replace('\\', '/', $filePath);
$normalizedBase = str_replace('\\', '/', $mediaBaseDir);

// Check for directory traversal attempts
if (strpos($normalizedPath, '..') !== false) {
    http_response_code(403);
    echo 'Access denied: Invalid path';
    exit();
}

// Resolve real paths
$realPath = realpath($filePath);
$realBase = realpath($mediaBaseDir);

// If realpath fails, the file doesn't exist
if ($realPath === false) {
    http_response_code(404);
    echo 'File not found: ' . htmlspecialchars($requestUri);
    exit();
}

// Ensure the resolved path is within the base directory
if ($realBase === false || strpos($realPath, $realBase) !== 0) {
    http_response_code(403);
    echo 'Access denied: Path outside base directory';
    exit();
}

// Check if it's actually a file (not a directory)
if (!is_file($realPath)) {
    http_response_code(404);
    echo 'Not a file: ' . htmlspecialchars($requestUri);
    exit();
}

// Determine content type based on file extension
$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'm3u8' => 'application/vnd.apple.mpegurl',
    'ts' => 'video/mp2t',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
    'json' => 'application/json'
];

$contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
header('Content-Type: ' . $contentType);

// Set caching headers for media files
$maxAge = 31536000; // 1 year
header('Cache-Control: public, max-age=' . $maxAge);
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');

// Set content length
$fileSize = filesize($realPath);
header('Content-Length: ' . $fileSize);

// Handle range requests for video/audio (for seeking)
if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = intval($matches[1]);
        $end = $matches[2] === '' ? $fileSize - 1 : intval($matches[2]);
        
        if ($start < 0 || $start > $fileSize - 1 || $end < $start) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit();
        }
        
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Content-Length: ' . ($end - $start + 1));
        
        $handle = fopen($realPath, 'rb');
        fseek($handle, $start);
        $buffer = '';
        $remaining = $end - $start + 1;
        
        while ($remaining > 0 && !feof($handle)) {
            $chunkSize = min(8192, $remaining);
            $buffer .= fread($handle, $chunkSize);
            $remaining -= $chunkSize;
        }
        fclose($handle);
        
        echo $buffer;
        exit();
    }
}

// Output file
readfile($realPath);
exit();

