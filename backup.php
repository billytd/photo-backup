<?php
/**
 * Photo Backup : traverse directory and upload new contents to S3 bucket
 * @author Billy Flaherty bflaherty4@gmail.com
 */

$path_config = './config.php';

// @todo create setup()
// @todo create thumbnails
// @todo create SQL dump file at the end of backup and upload to the S3
// @todo parse EXIF data adnd save to DB

require $path_config;
require 'app/FileBackup.php';
require 'app/BackupUtility/S3BackupUtility.php';
require 'app/Writer/TextWriter.php';

$text_writer = new textWriter();

try {
    $file_backup = new FileBackup($_CONFIG, new S3BackupUtility($_CONFIG), $text_writer);
    if (isset($argv[1]) && trim(strtolower($argv[1])) == 'setup') {
        $file_backup->setup();
    } else {
        $file_backup->verifySetup();
        $file_backup->performBackup();
    }
} catch (Exception $e) {
    $text_writer->writeMessage('Error performing backup: ' . $e->getMessage());
}
