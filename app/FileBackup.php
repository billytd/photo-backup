<?php

class FileBackup {
    private $config          = [];
    private $config_required = ['backup_path', 'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];

    private $backup_utility;
    private $db;
    private $writer;

    public function __construct(array $config, BackupUtilityInterface $backup_utility, WriterInterface $writer)
    {
        $this->writer = $writer;

        if (count($missing = array_diff($this->config_required, array_keys($config))) > 0) {
            $this->writer->writeMessage('The following required settings were missing: ' . implode(', ', $missing));
            exit;
        }

        $this->config         = $config;
        $this->backup_utility = $backup_utility;
    }

    public function performBackup()
    {
        $files           = $this->fetchFilesForPath($this->config['backup_path']);
        $files_to_backup = $this->pruneAlreadyMatched($files);
        $count_new       = count($files_to_backup);
        $count_attempted = 0;
        $count_success   = 0;
        $count_db_saved  = 0;

        $this->writer->writeMessage("total files: " . count($files) . ", to backup: " . count($files_to_backup) . "\n");

        $sql_insert = "INSERT INTO photos(file_name, full_path, size, ts_created, ts_modified, aws_bucket, aws_key, ts_aws_upload, md5) VALUES (?,?,?,?,?,?,?,?,?)";

        $upload_max = ($this->config['MAX_UPLOAD_ATTEMPTS'] > 0 && $this->config['MAX_UPLOAD_ATTEMPTS'] < $count_new)
                ? $this->config['MAX_UPLOAD_ATTEMPTS']
                : $count_new;

        $this->writer->writeMessage('Found ' . $count_new . ' new files. Attempting to backup ' . $upload_max . '.');

        foreach($files_to_backup as $file) {
            if ($upload_max <= $count_attempted && $upload_max > 0) break; // stop, we reached the maximum

            ++$count_attempted;
            $this->writer->writeMessage($count_attempted . ' of ' . $upload_max . ' (' . $file['file_name'] . ') : Uploading...');
            $aws_key = $this->getNewAwsKey($file['file_name']);

            try {
                $this->backup_utility->uploadFile($file['full_path'], $aws_key);
            } catch (Exception $e) {
                $this->writer->writeMessage('Exception caught when attempting to upload file from "' . $file['full_path'] . '" to "' . $aws_key . '" with message "' . $e->getMessage() . "\"\nAborting.\n");
                return;
            }

            var_dump($file['full_path'], $aws_key);

            ++$count_success;

            $this->writer->writeMessage('Done. Saving meta data... ');
            $file_name     = $file['file_name'];
            $full_path     = $file['full_path'];
            $size          = $file['size'];
            $ts_created    = $file['ts_created'];
            $ts_modified   = $file['ts_modified'];
            $ts_aws_upload = time();
            $local_md5     = $file['md5'];

            if (!isset($stmt_insert)) {
                $stmt_insert = $this->getDb()->prepare($sql_insert);
                $stmt_insert->bind_param('ssiiissis', $file_name, $full_path, $size, $ts_created, $ts_modified, $this->config['aws_bucket'], $aws_key, $ts_aws_upload, $local_md5);
            }

            if ($stmt_insert->execute()) {
                ++$count_db_saved;
                $this->writer->writeMessage('Saved!');
            } else {
                $this->writer->writeMessage('Error saving to DB. (' . $stmt->error . ')');
            }
        }

        $this->writer->writeMessage("\n--- COMPLETE ---");

        $this->writer->writeMessage('upload attempts: ' . $count_attempted);
        $this->writer->writeMessage('upload success: ' .$count_success);
        $this->writer->writeMessage('db saved: ' . $count_db_saved . "\n");
    }

    private function pruneAlreadyMatched($matched_files)
    {
        $select_by_size_and_path  = 'SELECT id FROM photos WHERE size = ? AND full_path = ?';
        $select_by_md5            = 'SELECT * FROM photos WHERE md5 = ?';
        $select_by_md5_and_not_id = 'SELECT * FROM photos WHERE md5 = ? AND id != ?';

        foreach($matched_files as $key => &$file) {
            echo '.';
            $file_size      = $file['size'];
            $file_full_path = $file['full_path'];
            $file_md5       = md5_file($file['full_path']);
            $file['md5']    = $file_md5;

            // Check if file is in DB yet
            if (!isset($stmt_size_and_path)) {
                $stmt_size_and_path = $this->getDb()->prepare($select_by_size_and_path);
                $stmt_size_and_path->bind_param('is', $file_size, $file_full_path);
            }

            // check if filesize and path match
            $stmt_size_and_path->execute();
            $result = $stmt_size_and_path->get_result();

            if ($result->num_rows == 1) {
                $row = $result->fetch_assoc();
                $id  = $row['id'];

                if (!isset($stmt_md5_not_id)) {
                    $stmt_md5_not_id = $this->getDb()->prepare($select_by_md5_and_not_id);
                    $stmt_md5_not_id->bind_param('si', $file_md5, $id);
                }

                $stmt_md5_not_id->execute();
                $result = $stmt_md5_not_id->get_result();

                if ($result->num_rows != 0) {
                    $row = $result->fetch_assoc();
                    echo "\nUnexpected duplicate (known size & path, md5 matches another)...\n";
                    echo '    ' . $file['full_path'] . ', (' . $file_size . ")\n";
                    echo '    ' . $row['full_path'] . ', (' . $row['size'] . ")\n";
                }

                unset($matched_files[$key]);


            } else if ($result->num_rows > 1) {
                // unexpected
                echo '----------- more than one!';
                print_r($row);
            } else {

                if (!isset($stmt_md5)) {
                    $stmt_md5 = $this->getDb()->prepare($select_by_md5);
                    $stmt_md5->bind_param('s', $file_md5);
                }

                $stmt_md5->execute();
                $result = $stmt_md5->get_result();

                if ($result->num_rows != 0) {
                    $row = $result->fetch_assoc();
                    echo "\nUnexpected duplicate (unknown size & path, md5 match)...\n";
                    echo '    ' . $file['full_path'] . ', ' . $file_size . ', ' . $file_md5 . "\n";
                    echo '    ' . $row['full_path'] . ', ' . $row['size'] . ', ' . $row['md5'] . "\n";

                    unset($matched_files[$key]);
                }
            }
        }

        echo "\n";

        return $matched_files;
    }

    private function fetchFilesForPath($path, array $results = [])
    {
        if (!($path = realpath($path))) {
            return [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $contents = array_diff(scandir($path, SCANDIR_SORT_ASCENDING), ['.', '..']);

        foreach($contents as $file) {
            $full_path = $path . DIRECTORY_SEPARATOR . $file;

            if (is_dir($full_path)) {

                $results = $this->fetchFilesForPath($full_path, $results);

            } else if ($this->hasValidExtension($file)) {
                $file_metadata = stat($full_path);
                $results[] = [
                        'file_name'   => $file,
                        'full_path'   => $full_path,
                        'size'        => $file_metadata['size'],
                        'ts_created'  => $file_metadata['ctime'],
                        'ts_modified' => $file_metadata['mtime']
                    ];
            }
        }

        return $results;
    }

    private function hasValidExtension($filename)
    {
        if (!isset($this->config['ok_extensions']) || !is_array($this->config['ok_extensions']) || count($this->config['ok_extensions']) == 0) {
            // if no extensions specified, consider all extension valid.
            return true;
        }

        $ext = strtolower(preg_replace("/.*\.([a-z0-9]+)$/i", "$1", $filename));

        return in_array($ext, $this->config['ok_extensions']);
    }

    private function getNewAwsKey($file_name)
    {
        $aws_key = $this->config['upload_path_prefix'] . uniqid() . '-' . $file_name;
        return $aws_key;
    }

    private function getDb()
    {
        if (!$this->db) {
            $this->db = new mysqli($this->config['DB_HOST'], $this->config['DB_USER'], $this->config['DB_PASS'], $this->config['DB_NAME']);

            if ($this->db->connect_error) {
                die("\nMysqli Connect Error (" . $this->db->connect_errno . ") " . $this->db->connect_error . "\n");
            }
        }

        return $this->db;
    }

    public static function fetchSettingsFromJSONFile($path_settings)
    {
        if (!is_string($path_settings)) {
            return false;
        }

        $path_settings = realpath($path_settings);

        if (!file_exists($path_settings) || is_dir($path_settings)) {
            return false;
        }

        $json = file_get_contents($path_settings);

        $config = json_decode($json, true);

        return $config ? : false;
    }
}