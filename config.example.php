<?php
/**
 * Copy/rename this file to config.php then update the values to match your local environment.
 */

$_CONFIG = [
        'backup_path'         => '/path/to/files',
        'upload_path_prefix'  => 'billy-iphoto/',
        'DB_HOST'             => '127.0.0.1',
        'DB_USER'             => 'root',
        'DB_PASS'             => '',
        'DB_NAME'             => 'photos-db',
        'MAX_UPLOAD_ATTEMPTS' => 0, // The max number of files to upload during script execution. ( 0 = unlimited )
        'aws_autoloader'      => '/path/to/aws/aws-autoloader.php', // AWS php
        'aws_bucket'          => 'your-aws-bucket-name',
        'ok_extensions'       => ['jpg', 'jpeg', 'png', 'gif', 'mov', '3gp', 'avi', 'mpg']
    ];