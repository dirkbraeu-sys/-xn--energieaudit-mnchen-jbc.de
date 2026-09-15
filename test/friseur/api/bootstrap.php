<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('friseur_test');
    session_start();
}

$dbConfigFile = __DIR__ . '/db-config.php';
if (!file_exists($dbConfigFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Testbereich nicht konfiguriert (db-config.php fehlt).']);
    exit;
}
require_once $dbConfigFile;

function friseur_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function friseur_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profiles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('customer','staff','owner') NOT NULL DEFAULT 'customer',
            staff_id VARCHAR(30) NULL,
            display_name VARCHAR(190) NOT NULL,
            verified TINYINT(1) NOT NULL DEFAULT 1,
            verification_token VARCHAR(64) NULL,
            verification_expires DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_identifier (identifier)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    friseur_ensure_column($pdo, 'profiles', 'verified', "verified TINYINT(1) NOT NULL DEFAULT 1");
    friseur_ensure_column($pdo, 'profiles', 'verification_token', "verification_token VARCHAR(64) NULL");
    friseur_ensure_column($pdo, 'profiles', 'verification_expires', "verification_expires DATETIME NULL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NULL,
            customer_name VARCHAR(190) NOT NULL,
            service VARCHAR(190) NOT NULL,
            date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            staff_id VARCHAR(30) NOT NULL,
            staff_name VARCHAR(190) NOT NULL,
            manual TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_date (date),
            KEY idx_customer (customer_id),
            KEY idx_staff (staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS released_slots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id VARCHAR(30) NOT NULL,
            date DATE NOT NULL,
            time TIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_slot (staff_id, date, time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Demo-Zugänge einmalig anlegen (entspricht den Angaben aus der README).
    $seed = [
        ['stammkunde', 'demo2026', 'customer', null, 'Stammkunde (Demo)'],
        ['team', 'graf2026', 'owner', null, 'Andreas Graf (Inhaber)'],
        ['andreas', 'andreas2026', 'staff', 'andreas', 'Andreas'],
        ['yvonne', 'yvonne2026', 'staff', 'yvonne', 'Yvonne'],
        ['caro', 'caro2026', 'staff', 'caro', 'Caro'],
    ];
    $check = $pdo->prepare('SELECT id FROM profiles WHERE identifier = ?');
    $ins = $pdo->prepare('INSERT INTO profiles (identifier, password_hash, role, staff_id, display_name) VALUES (?, ?, ?, ?, ?)');
    foreach ($seed as [$identifier, $password, $role, $staffId, $displayName]) {
        $check->execute([$identifier]);
        if (!$check->fetch()) {
            $ins->execute([$identifier, password_hash($password, PASSWORD_DEFAULT), $role, $staffId, $displayName]);
        }
    }

    $done = true;
}

function friseur_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function friseur_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function friseur_current_profile(PDO $pdo): ?array
{
    if (empty($_SESSION['profile_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, identifier, role, staff_id, display_name FROM profiles WHERE id = ?');
    $stmt->execute([$_SESSION['profile_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function friseur_require_login(PDO $pdo): array
{
    $p = friseur_current_profile($pdo);
    if (!$p) {
        friseur_json(['error' => 'Nicht angemeldet.'], 401);
    }
    return $p;
}

function friseur_mail_from_header(): string
{
    return '=?UTF-8?B?' . base64_encode('Friseursalon München (Test)') . '?=' . ' <noreply@xn--energieaudit-mnchen-jbc.de>';
}

function friseur_send_booking_confirmation_mail(string $toEmail, string $customerName, array $booking): bool
{
    $dateFormatted = date('d.m.Y', strtotime((string) $booking['date']));
    $timeFormatted = substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5) . ' Uhr';
    $safeName = $customerName !== '' ? $customerName : 'Kunde/Kundin';
    $subject = 'Ihre Terminbestätigung – Friseursalon München (Test)';
    $body = "Hallo {$safeName},\n\n"
        . "vielen Dank für Ihre Terminbuchung. Hier die Details:\n\n"
        . "Anwendung: {$booking['service']}\n"
        . "Datum:     {$dateFormatted}\n"
        . "Uhrzeit:   {$timeFormatted}\n"
        . "Bei:       {$booking['staff_name']}\n\n"
        . "Sie können Ihren Termin jederzeit online unter \"Meine Termine\" einsehen, ändern oder stornieren.\n\n"
        . "Falls Sie diesen Termin nicht gebucht haben, kontaktieren Sie uns bitte.\n\n"
        . "Friseursalon München\n"
        . "Hinweis: Dies ist eine Testumgebung, nicht öffentlich online.\n";

    $headers = "From: " . friseur_mail_from_header() . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('friseur_send_booking_confirmation_mail: mail() lieferte false für ' . $toEmail);
    }
    return $ok;
}

function friseur_send_verification_mail(string $toEmail, string $name, string $token): bool
{
    $link = 'https://xn--energieaudit-mnchen-jbc.de/test/friseur/?verify=' . urlencode($token);
    $safeName = $name !== '' ? $name : 'Kunde/Kundin';
    $subject = 'Bitte bestätigen Sie Ihre E-Mail-Adresse – Friseursalon München (Test)';
    $body = "Hallo {$safeName},\n\n"
        . "vielen Dank für Ihre Registrierung im Testbereich der Friseur-Terminbuchung.\n"
        . "Bitte bestätigen Sie Ihre E-Mail-Adresse über folgenden Link (60 Minuten gültig):\n\n"
        . $link . "\n\n"
        . "Danach können Sie sich anmelden und Termine buchen.\n\n"
        . "Falls Sie diese Registrierung nicht veranlasst haben, ignorieren Sie diese E-Mail einfach.\n\n"
        . "Friseursalon München\n"
        . "Hinweis: Dies ist eine Testumgebung, nicht öffentlich online.\n";

    $headers = "From: " . friseur_mail_from_header() . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('friseur_send_verification_mail: mail() lieferte false für ' . $toEmail);
    }
    return $ok;
}
