# photo-backup
Checks a given local folder for new photos, backs them up to an AWS S3 bucket and stores metadata to a mysql DB.

Setting it up:
  1.  Copy/rename config.example.php to config.php and update the values for your local system.
  2.  From the command line, navigate to the project root.
  3.  Run "php backup.php setup" to create the DB schema.
  4.  Run "php backup.php" to run a backup.
