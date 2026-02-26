<?php

/**
 * @file
 * Creates Drupal media entities from test files.
 *
 * Run via: ddev drush php:script web/modules/custom/mcp_file_content/tests/create_media_entities.php
 */

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

$dir = '/tmp/test-files';
$publicDir = 'public://mcp-test-files';

// Ensure directory exists.
\Drupal::service('file_system')->prepareDirectory($publicDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

$files = glob("$dir/*");
if (empty($files)) {
  echo "No test files found in $dir\n";
  return;
}

$created = [];
foreach ($files as $filePath) {
  $filename = basename($filePath);
  $ext = pathinfo($filename, PATHINFO_EXTENSION);

  // Determine media bundle and MIME type.
  $mimeMap = [
    'docx' => ['bundle' => 'document', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'html' => ['bundle' => 'document', 'mime' => 'text/html'],
    'txt' => ['bundle' => 'document', 'mime' => 'text/plain'],
    'csv' => ['bundle' => 'document', 'mime' => 'text/csv'],
    'pdf' => ['bundle' => 'document', 'mime' => 'application/pdf'],
    'pptx' => ['bundle' => 'document', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'jpg' => ['bundle' => 'image', 'mime' => 'image/jpeg'],
    'png' => ['bundle' => 'image', 'mime' => 'image/png'],
  ];

  if (!isset($mimeMap[$ext])) {
    echo "Skipping unknown extension: $filename\n";
    continue;
  }

  $info = $mimeMap[$ext];

  // Copy file to public filesystem.
  $destination = "$publicDir/$filename";
  $contents = file_get_contents($filePath);
  $uri = \Drupal::service('file_system')->saveData($contents, $destination, \Drupal\Core\File\FileExists::Replace);

  // Create file entity.
  $file = File::create([
    'uri' => $uri,
    'filename' => $filename,
    'filemime' => $info['mime'],
    'status' => 1,
  ]);
  $file->save();

  // Create media entity.
  $mediaData = [
    'bundle' => $info['bundle'],
    'name' => pathinfo($filename, PATHINFO_FILENAME),
    'status' => 1,
    'uid' => 1,
  ];

  // Set the source field based on bundle type.
  if ($info['bundle'] === 'image') {
    $mediaData['field_media_image'] = [
      'target_id' => $file->id(),
      'alt' => pathinfo($filename, PATHINFO_FILENAME),
    ];
  }
  else {
    $mediaData['field_media_document'] = [
      'target_id' => $file->id(),
    ];
  }

  $media = Media::create($mediaData);
  $media->save();
  $created[] = [
    'id' => $media->id(),
    'name' => $media->getName(),
    'bundle' => $info['bundle'],
    'file' => $filename,
  ];
  echo "Created media #{$media->id()}: {$media->getName()} ({$info['bundle']}) - $filename\n";
}

echo "\n=== Summary ===\n";
echo "Created " . count($created) . " media entities\n";
echo "\nMedia IDs for testing:\n";
foreach ($created as $item) {
  echo "  {$item['id']}: {$item['name']} ({$item['file']})\n";
}
