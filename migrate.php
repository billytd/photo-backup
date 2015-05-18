<?php
/**
 * Photo Backup : traverse directory and upload new contents to S3 bucket
 * @author Billy Flaherty bflaherty4@gmail.com
 */

$path_config = './config.php';


// @todo create thumbnail
// @todo create SQL dump and upload to S3
// @todo parse EXIF data adnd save to DB


require $path_config;
require 'app/FileBackup.php';
require 'app/BackupUtility/S3BackupUtility.php';
require 'app/Writer/TextWriter.php';

// $config = fetchSettingsFromJSONFile($path_config);
$file_backup = new FileBackup($_CONFIG, new S3BackupUtility($_CONFIG), new textWriter());
$file_backup->performBackup();

exit;


/* CONFIGURATION */

$AWS_bucket = 'bflaherty-photos';
$AWS_object_prefix = 'iphone/';

$MAX_UPLOAD_ATTEMPTS = 0; // Maximum number of files to upload per script exec. (0 = infinite)

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'photos';

$path_base = '/Users/bflaherty/Pictures/iPhoto Library.photolibrary/Masters';

/* END CONFIGURATION */



// Load AWS SDK
require '/Users/bflaherty/util/aws/aws-autoloader.php';
use Aws\S3\S3Client;

// Instantiate the S3 client using your credential profile
$s3Client = S3Client::factory();

$matched_files = findContentForPath($path_base);

$upload_attempted = 0;
$upload_success   = 0;
$db_saved         = 0;
$new_found        = 0;

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

$sql_count = "SELECT count(id) as num FROM photos WHERE aws_key = ?";
$sql_insert = "INSERT INTO photos(file_name, full_path, size, ts_created, ts_modified, aws_bucket, aws_key, ts_aws_upload) VALUES (?,?,?,?,?,?,?,?)";

foreach($matched_files as &$file) {
    $aws_key = $file['aws_key'] = $AWS_object_prefix . $file['file_name'];

    // Check if file is in DB yet
    if (!isset($stmt_count)) {
        $stmt_count = $mysqli->prepare($sql_count);
        $stmt_count->bind_param('s', $aws_key);
    }
    $stmt_count->execute();
    $row = $stmt_count->get_result()->fetch_assoc();

    $file['uploaded'] = $row['num'] > 0;

    if (!$file['uploaded']) {
        ++$new_found;
    }
}

$upload_max = ($MAX_UPLOAD_ATTEMPTS > 0 && $MAX_UPLOAD_ATTEMPTS < $new_found) ? $MAX_UPLOAD_ATTEMPTS : $new_found;
reset($matched_files);
unset($file); // Prevents reusing the passed reference.
echo "\nFound {$new_found} new files. Attempting to upload {$upload_max}.\n";

foreach($matched_files as $file) {
    if ($MAX_UPLOAD_ATTEMPTS <= $upload_attempted && $MAX_UPLOAD_ATTEMPTS > 0) break;

    if (!$file['uploaded']) {
        $aws_key = $file['aws_key'];
        $local_md5 = md5_file($file['full_path']);

        ++$upload_attempted;
        echo "\n{$upload_attempted} of {$upload_max} (" . $file['file_name'] . ") : ";
        echo 'Uploading... ';
        $result = $s3Client->putObject(array(
            'Bucket'     => $AWS_bucket,
            'Key'        => $aws_key,
            'SourceFile' => $file['full_path']
        ));

        if (isset($result['ETag']) && trim($result['ETag'], '"') != $local_md5) {
            // Upload failed
            die("\nRemote Etag (".$result['ETag'].") does not match local ($local_md5) for file ".$file['full_path']);
        }

        ++$upload_success;

        echo 'OK. Saving... ';
        $file_name     = $file['file_name'];
        $full_path     = $file['full_path'];
        $size          = $file['size'];
        $ts_created    = $file['ts_created'];
        $ts_modified   = $file['ts_modified'];
        $ts_aws_upload = time();

        if (!isset($stmt_insert)) {
            $stmt_insert = $mysqli->prepare($sql_insert);
            $stmt_insert->bind_param('ssiiissi', $file_name, $full_path, $size, $ts_created, $ts_modified, $AWS_bucket, $aws_key, $ts_aws_upload);
        }

        if ($stmt_insert->execute()) {
            ++$db_saved;
            echo 'Ok.';
        } else {
            echo 'Error! ('.$stmt->error.')';
        }
    }
}


echo "\n\n--- COMPLETE ---\n
upload attempts: {$upload_attempted}\nupload success: {$upload_success}\ndb saved: {$db_saved}\n\n";

/**
 * Recursively finds files of known image and video types
 */
function findContentForPath($path, array $results = array()) {
    if (!is_dir($path)) {
        return [];
    }

    $path = preg_replace("/\/$/", '', $path);

    $ok_exts = ['jpg', 'jpeg', 'png', 'gif', 'mov', '3gp', 'avi', 'mpg'];

    $contents = scandir($path, SCANDIR_SORT_ASCENDING);

    foreach($contents as $el) {
        if (!in_array($el, ['.', '..'])) {
            $el_path = $path . PS . $el;

            if (!is_dir($el_path)) {
                $ext = strtolower(preg_replace("/.*\.([a-z0-9]+)$/i", "$1", $el));
                if (in_array($ext, $ok_exts)) {
                    $file_metadata = stat($el_path);
                    $results[] = [
                            'file_name'   => $el,
                            'full_path'   => $el_path,
                            'size'        => $file_metadata['size'],
                            'ts_created'  => $file_metadata['ctime'],
                            'ts_modified' => $file_metadata['mtime']
                        ];
                }
            } else {
                $results = findContentForPath($el_path, $results);
            }
        }
    }

    return $results;
}
