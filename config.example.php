<?php

$_CONFIG = [
        'backup_path'         => '/path/to/files',
        'upload_path_prefix'  => 'billy-iphoto/',
        'DB_HOST'             => '127.0.0.1',
        'DB_USER'             => 'root',
        'DB_PASS'             => '',
        'DB_NAME'             => 'photos-db',
        'MAX_UPLOAD_ATTEMPTS' => 0, // 0 = unlimited
        'aws_autoloader'      => '/path/to/aws/aws-autoloader.php', // AWS php
        'aws_bucket'          => 'your-aws-bucket-name',
        'ok_extensions'       => ['jpg', 'jpeg', 'png', 'gif', 'mov', '3gp', 'avi', 'mpg']
    ];