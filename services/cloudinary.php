<?php
// services/cloudinary.php

require_once __DIR__ . '/../vendor/autoload.php';

use Cloudinary\Cloudinary;

function getCloudinaryInstance() {
    
    return new Cloudinary(
        [
            'cloud' => [
                'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
                'api_key'    => $_ENV['CLOUDINARY_API_KEY'],
                'api_secret' => $_ENV['CLOUDINARY_API_SECRET']
            ],
            'url' => [
                'secure' => true // Always https
            ]
        ]
    );
}

/**
 * Upload file to Cloudinary
 * @param string $filePath - local temp file path
 * @param string $folder - Cloudinary folder name
 * @return string - uploaded file URL
 */
function uploadToCloudinary($filePath, $folder = 'blog-app') {
    $cloudinary = getCloudinaryInstance();
    $result = $cloudinary->uploadApi()->upload($filePath, [
        'folder' => $folder
    ]);
    return $result['secure_url'] ?? null;
}
