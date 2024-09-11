<?php
session_start();

// Include the configuration file
$config = include('config.php');

// Error handling and messages
$error = '';
$message = '';


// Password protection check
if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $config['page_password']) {
            $_SESSION['authenticated'] = true;
        } else {
            $error = 'Incorrect password!';
        }
    } else {
        showPasswordForm();
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Function to display password form
function showPasswordForm() {
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <h3 class="text-center">Enter Password</h3>
                <form method="post">
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    HTML;
}

// Function to backup the database
function backupDatabase($pdo, $backupPath) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $backupSQL = '';
        foreach ($tables as $table) {
            $createTableStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $backupSQL .= $createTableStmt['Create Table'] . ";\n\n";
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $values = array_map(function($value) use ($pdo) {
                    return $value === null ? 'NULL' : $pdo->quote($value);
                }, array_values($row));
                $backupSQL .= "INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n";
            }
            $backupSQL .= "\n";
        }
        file_put_contents($backupPath, $backupSQL);
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Function to import the SQL file into the database
function importSQLFile($sqlFile, $pdo) {
    try {
        $sql = file_get_contents($sqlFile);
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Function to drop all tables in the database
function cleanDatabase($pdo) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return true;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['sql_file'])) {

    if ($_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $uploadedFile = $_FILES['sql_file']['tmp_name'];
        $fileName = $_FILES['sql_file']['name'];
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($fileExtension != 'sql') {
            $error = 'Only SQL files are allowed.';
        } else {
            try {
                $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']}", $config['username'], $config['password']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $backupPath = 'backups/' . $config['dbname'] . '_backup_' . date('Y-m-d_H-i-s') . '.sql';
                if (!file_exists('backups')) {
                    mkdir('backups', 0777, true);
                }
                $backupResult = backupDatabase($pdo, $backupPath);
                if ($backupResult !== true) {
                    $error = "Error backing up the database: $backupResult";
                } else {
                    $cleanResult = cleanDatabase($pdo);
                    if ($cleanResult !== true) {
                        $error = "Error cleaning the database: $cleanResult";
                    } else {
                        $importResult = importSQLFile($uploadedFile, $pdo);
                        if ($importResult === true) {
                            $message = 'Database successfully backed up, cleaned, and imported.';
                        } else {
                            $error = "Error importing the SQL file: $importResult";
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = "Database connection failed: " . $e->getMessage();
            }
        }
    } else {
        $error = 'Please upload a valid SQL file.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload and Import SQL File</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">

    <h2 class="text-center">Upload and Import SQL File</h2>

    <!-- Show error or success message -->
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php elseif ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <!-- File upload form -->
    <form method="post" enctype="multipart/form-data" class="mt-4">
        <div class="mb-3">
            <label for="sql_file" class="form-label">Upload SQL File</label>
            <input type="file" name="sql_file" id="sql_file" class="form-control" accept=".sql" required>
        </div>
        <button type="submit" class="btn btn-primary">Upload and Import</button>
    </form>

    <!-- Logout button -->
    <a href="?logout=true" class="btn btn-danger mt-4">Logout</a>

</body>
</html>