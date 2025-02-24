<?php
// ==========================
// TITLE: WORDPRESS EASY MIGRATION TOOL
// AUTHOR: WEBHAMALI
// SITE URL: https://webhamali.com/
// LICENSE: GPL V3
// VERSION: 1.0
// ==========================
/**
 * Specs:
 *
 * This self-contained tool (PHP, HTML, CSS, JavaScript in one file)
 * automates the migration of a WordPress site.
 *
 * How it works:
 *   1. When loaded normally, it displays a web interface with a “Start Migration” button.
 *   2. When the button is clicked, JavaScript calls this same file with ?action=migrate.
 *   3. The PHP code:
 *       - Checks for wp-config.php to ensure it’s running in a WP directory.
 *       - Parses wp-config.php to extract DB_HOST, DB_NAME, DB_USER, DB_PASSWORD.
 *       - Extracts the table prefix (default “wp_” if not found).
 *       - Attempts to export the database using:
 *             a. mysqldump (if available),
 *             b. WP-CLI (checking for “wp”, “wp-cli”, or “wp-cli.phar”), or
 *             c. a pure-PHP fallback that connects to the DB and iterates through tables.
 *       - Connects to the database to query the blog name from the options table.
 *       - Sanitizes the blog name to create an archive filename.
 *       - Uses PHP’s ZipArchive to recursively package all files (except this tool’s own file) and the database dump into an archive.
 *       - Returns JSON logs and (if successful) a download link.
 *
 * How to deploy & run:
 *   - Place this file in the root folder of your WordPress installation.
 *   - Ensure that PHP’s shell_exec() function is enabled (for mysqldump/WP-CLI) or that the fallback works.
 *   - Ensure that the ZipArchive extension is installed.
 *   - Access the file in your browser. If the site is a valid WP install, the tool will load.
 *
 * Prerequisites:
 *   - PHP (with ZipArchive installed).
 *   - Either mysqldump, a WP-CLI binary (wp, wp-cli, or wp-cli.phar), or allow PHP’s fallback method.
 *
 * Testing:
 *   - Run in a valid WP directory.
 *   - Test by renaming/deleting wp-config.php to trigger the error.
 *   - Check for proper error messages on DB connection or archive creation issues.
 */

/**
 * Pure-PHP database dump function.
 * Connects to the DB via the given mysqli object, iterates over all tables,
 * and writes a SQL dump to $dumpFile.
 *
 * @param mysqli  $mysqli   Active mysqli connection.
 * @param string  $dumpFile The file path to save the dump.
 * @return bool             True if dump was successfully written.
 */
function phpDbDump($mysqli, $dumpFile) {
    $sqlDump = "";
    // Ensure proper character set.
    $mysqli->query("SET NAMES 'utf8'");
    $tablesResult = $mysqli->query("SHOW TABLES");
    if (!$tablesResult) {
        return false;
    }
    while ($row = $tablesResult->fetch_array()) {
         $table = $row[0];
         // Drop table statement.
         $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
         // Get CREATE TABLE statement.
         $createResult = $mysqli->query("SHOW CREATE TABLE `$table`");
         if ($createResult && $createRow = $createResult->fetch_assoc()) {
             $sqlDump .= $createRow['Create Table'] . ";\n\n";
         }
         // Get table data.
         $dataResult = $mysqli->query("SELECT * FROM `$table`");
         if ($dataResult && $dataResult->num_rows > 0) {
              while ($dataRow = $dataResult->fetch_assoc()) {
                   // Prepare column names and values.
                   $cols = array_map(function($col) { return "`" . $col . "`"; }, array_keys($dataRow));
                   $values = array_map(function($val) use ($mysqli) { 
                         if (is_null($val)) {
                             return "NULL";
                         }
                         return "'" . $mysqli->real_escape_string($val) . "'";
                   }, array_values($dataRow));
                   $sqlDump .= "INSERT INTO `$table` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $values) . ");\n";
              }
              $sqlDump .= "\n";
         }
    }
    // Write dump to file.
    file_put_contents($dumpFile, $sqlDump);
    return file_exists($dumpFile) && filesize($dumpFile) > 0;
}

// ---------------------------------------------------------------------
// DOWNLOAD HANDLER: Serve file if "download" parameter is provided.
// ---------------------------------------------------------------------
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    if (file_exists($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } else {
        echo "File not found.";
        exit;
    }
}

// ---------------------------------------------------------------------
// MIGRATION PROCESS: Triggered when ?action=migrate is passed (AJAX request)
// ---------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'migrate') {
    header('Content-Type: application/json');
    $logs = [];
    $errors = [];

    // 1. Environment Detection: Check for wp-config.php
    if (!file_exists('wp-config.php')) {
        $errors[] = "Error: wp-config.php not found. Please run this tool in a WordPress installation directory.";
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }
    $logs[] = "Found wp-config.php.";

    // 2. Extracting WordPress Configuration:
    $configContent = file_get_contents('wp-config.php');
    // Capture DB constants (handles single or double quotes)
    $pattern = "/define\(\s*['\"](DB_HOST|DB_NAME|DB_USER|DB_PASSWORD)['\"],\s*['\"](.*?)['\"]\s*\)/";
    preg_match_all($pattern, $configContent, $matches, PREG_SET_ORDER);
    $dbConfig = [];
    foreach ($matches as $match) {
        $dbConfig[$match[1]] = $match[2];
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (empty($dbConfig[$key])) {
            $errors[] = "Error: $key is not defined in wp-config.php.";
        }
    }
    if (count($errors) > 0) {
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }
    $logs[] = "Extracted database configuration.";

    // Extract table prefix (default is 'wp_')
    $table_prefix = 'wp_';
    if (preg_match("/\\\$table_prefix\s*=\s*['\"](.*?)['\"]\s*;/", $configContent, $match)) {
        $table_prefix = $match[1];
    }
    $logs[] = "Using table prefix: $table_prefix";

    // 3. Database Operations:
    // 3A. Database Dump: Try mysqldump, then WP-CLI, then a pure-PHP fallback.
    $dumpFile = 'database.sql';
    $dumpSuccess = false;
    if (function_exists('shell_exec')) {
        // --- Try mysqldump first ---
        $mysqldumpCheck = trim(shell_exec("which mysqldump"));
        if ($mysqldumpCheck) {
            $cmd = "mysqldump --host=" . escapeshellarg($dbConfig['DB_HOST']) .
                   " --user=" . escapeshellarg($dbConfig['DB_USER']) .
                   " --password=" . escapeshellarg($dbConfig['DB_PASSWORD']) .
                   " " . escapeshellarg($dbConfig['DB_NAME']) .
                   " > " . escapeshellarg($dumpFile) . " 2>&1";
            $output = shell_exec($cmd);
            if (file_exists($dumpFile) && filesize($dumpFile) > 0) {
                $logs[] = "Database dump completed using mysqldump.";
                $dumpSuccess = true;
            } else {
                $logs[] = "mysqldump failed: " . $output;
            }
        }
        // --- Fallback: Try WP-CLI if mysqldump did not work ---
        if (!$dumpSuccess) {
            $wpcliCommands = ['wp', 'wp-cli', 'wp-cli.phar'];
            $wpcliFound = false;
            foreach ($wpcliCommands as $cli) {
                $check = trim(shell_exec("which " . escapeshellarg($cli)));
                if ($check) {
                    $wpcliFound = $cli;
                    break;
                }
            }
            if ($wpcliFound) {
                $cmd = $wpcliFound . " db export " . escapeshellarg($dumpFile) . " --quiet 2>&1";
                $output = shell_exec($cmd);
                if (file_exists($dumpFile) && filesize($dumpFile) > 0) {
                    $logs[] = "Database dump completed using WP-CLI ($wpcliFound).";
                    $dumpSuccess = true;
                } else {
                    $logs[] = "WP-CLI dump failed: " . $output;
                }
            } else {
                $logs[] = "No WP-CLI binary found.";
            }
        }
    } else {
        $logs[] = "shell_exec function is disabled.";
    }
    // --- Fallback: Pure-PHP DB dump if previous methods failed ---
    if (!$dumpSuccess) {
        $mysqliFallback = new mysqli($dbConfig['DB_HOST'], $dbConfig['DB_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME']);
        if ($mysqliFallback->connect_error) {
             $errors[] = "Error: PHP fallback - Database connection failed: " . $mysqliFallback->connect_error;
        } else {
             if (phpDbDump($mysqliFallback, $dumpFile)) {
                 $logs[] = "Database dump completed using pure PHP.";
                 $dumpSuccess = true;
             } else {
                 $errors[] = "Error: Database dump failed using pure PHP.";
             }
        }
    }
    if (!$dumpSuccess) {
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }

    // 3B. Retrieve the Site Name (Blog Name) from the database.
    $mysqli = new mysqli($dbConfig['DB_HOST'], $dbConfig['DB_USER'], $dbConfig['DB_PASSWORD'], $dbConfig['DB_NAME']);
    if ($mysqli->connect_error) {
        $errors[] = "Error: Database connection failed - " . $mysqli->connect_error;
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }
    $query = "SELECT option_value FROM " . $mysqli->real_escape_string($table_prefix) . "options WHERE option_name='blogname' LIMIT 1";
    $result = $mysqli->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $blogName = $row['option_value'];
        $logs[] = "Retrieved site name: $blogName";
    } else {
        $errors[] = "Error: Failed to retrieve blog name from database.";
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }
    $mysqli->close();

    // 4. Archiving Files:
    // Sanitize the blog name to create a safe archive filename.
    $archiveNameBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $blogName);
    $archiveFile = $archiveNameBase . ".zip";

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $errors[] = "Error: Could not create archive file.";
        } else {
            // Recursive function to add a folder to the ZIP archive.
            function addFolderToZip($folder, $zip, $relativePath) {
                $handle = opendir($folder);
                while (false !== ($file = readdir($handle))) {
                    if ($file == '.' || $file == '..') {
                        continue;
                    }
                    $fullPath = $folder . DIRECTORY_SEPARATOR . $file;
                    $localPath = $relativePath . $file;
                    // Exclude this migration tool file.
                    if (realpath($fullPath) == realpath(__FILE__)) {
                        continue;
                    }
                    if (is_dir($fullPath)) {
                        $zip->addEmptyDir($localPath);
                        addFolderToZip($fullPath, $zip, $localPath . DIRECTORY_SEPARATOR);
                    } else {
                        $zip->addFile($fullPath, $localPath);
                    }
                }
                closedir($handle);
            }
            // Add the entire current directory recursively.
            addFolderToZip(getcwd(), $zip, '');
            // Ensure the database dump is included.
            if (file_exists($dumpFile)) {
                $zip->addFile($dumpFile, $dumpFile);
            }
            $zip->close();
            $logs[] = "Archive created successfully: $archiveFile";
        }
    } else {
        $errors[] = "Error: ZipArchive class is not available.";
    }

    if (count($errors) > 0) {
        echo json_encode(["status" => "error", "logs" => $logs, "errors" => $errors]);
        exit;
    }

    // 5. Return Success: Build the download link.
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $downloadLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . "?download=" . urlencode($archiveFile);

    echo json_encode(["status" => "success", "logs" => $logs, "download" => $downloadLink]);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>WordPress Easy Migration Tool</title>
  <style>
    /* Basic responsive styling */
    body { font-family: Arial, sans-serif; background: #f2f2f2; margin: 0; padding: 20px; }
    .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    h1 { text-align: center; }
    p { text-align: center; }
    button { display: block; margin: 20px auto; padding: 10px 20px; font-size: 16px; cursor: pointer; }
    #logs { margin-top: 20px; background: #e9e9e9; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto; }
    .log-entry { margin-bottom: 5px; }
    .error { color: red; }
    .success { color: green; }
    #downloadLink { margin-top: 20px; display: block; text-align: center; font-weight: bold; }
  </style>
</head>
<body>
<div class="container">
  <h1>WordPress Easy Migration Tool</h1>
  <p>This tool will package your WordPress site files and database into a single archive.</p>
  <button id="startBtn">Start Migration</button>
  <div id="logs"></div>
  <a id="downloadLink" href="#" style="display:none;">Download Archive</a>
</div>
<script>
  // JavaScript to handle the migration process and update the UI.
  document.getElementById('startBtn').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    var logsDiv = document.getElementById('logs');
    logsDiv.innerHTML = '';
    var downloadLink = document.getElementById('downloadLink');
    downloadLink.style.display = 'none';

    function logMessage(message, type) {
      var div = document.createElement('div');
      div.className = 'log-entry' + (type ? ' ' + type : '');
      div.textContent = message;
      logsDiv.appendChild(div);
    }

    logMessage("Starting migration...");

    // AJAX call to start migration
    fetch("?action=migrate")
      .then(function(response) { return response.json(); })
      .then(function(data) {
          if (data.logs && data.logs.length > 0) {
              data.logs.forEach(function(msg) {
                  logMessage(msg);
              });
          }
          if (data.status === "success") {
              logMessage("Migration completed successfully.", "success");
              downloadLink.href = data.download;
              downloadLink.style.display = 'block';
              downloadLink.textContent = "Download Archive";
          } else {
              if (data.errors && data.errors.length > 0) {
                  data.errors.forEach(function(err) {
                      logMessage(err, "error");
                  });
              }
          }
          btn.disabled = false;
      })
      .catch(function(error) {
          logMessage("An error occurred: " + error, "error");
          btn.disabled = false;
      });
  });
</script>
</body>
</html>
