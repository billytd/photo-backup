<?php

use Aws\S3\S3Client;

require_once 'BackupUtilityInterface.php';

class S3BackupUtility implements BackupUtilityInterface {
    private $s3_client;
    private $config;
    private $config_required = ['aws_autoloader', 'aws_bucket'];

    public function __construct(array $config) {
        if (count($missing = array_diff($this->config_required, array_keys($config))) > 0) {
            die("\nThe following required settings were missing: " . implode(', ', $missing) . "\n");
        }

        require_once $config['aws_autoloader'];

        $this->config    = $config;
        $this->s3_client = S3Client::factory();
    }

    public function uploadFile($local_path, $remote_path)
    {
        $local_md5 = md5_file($local_path);
        try {
            $result = $this->s3_client->putObject([
                    'Bucket'     => $this->config['aws_bucket'],
                    'Key'        => $remote_path,
                    'SourceFile' => $local_path
                ]);
        } catch (Exception $e) {
            throw $e;
        }

        if (!isset($result['ETag']) || (trim($result['ETag'], '"') != $local_md5)) {
            // Upload failed
            throw new \Exception("MD5 hash mismatch. Local: $local_md5, Remote: " . $result['ETag'] . ", File: " . $file['full_path']);
        }
    }
}
