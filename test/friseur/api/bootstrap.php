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
    friseur_ensure_column($pdo, 'profiles', 'reset_token', "reset_token VARCHAR(64) NULL");
    friseur_ensure_column($pdo, 'profiles', 'reset_expires', "reset_expires DATETIME NULL");
    friseur_ensure_column($pdo, 'profiles', 'phone', "phone VARCHAR(30) NULL");
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
            reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_date (date),
            KEY idx_customer (customer_id),
            KEY idx_staff (staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    friseur_ensure_column($pdo, 'bookings', 'reminder_sent', "reminder_sent TINYINT(1) NOT NULL DEFAULT 0");
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waitlist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            customer_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            date DATE NOT NULL,
            staff_id VARCHAR(30) NOT NULL,
            service VARCHAR(190) NOT NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_date_staff (date, staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            body TEXT NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    friseur_ensure_column($pdo, 'reviews', 'rating', "rating TINYINT UNSIGNED NOT NULL DEFAULT 5");

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
    $stmt = $pdo->prepare('SELECT id, identifier, role, staff_id, display_name, phone FROM profiles WHERE id = ?');
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

// Mindestanforderungen an Passwörter (gleiches Muster wie im Kundenlogin auf braeu-ing.de):
// mind. 8 Zeichen, je mindestens ein Groß-, ein Kleinbuchstabe, eine Ziffer und ein Sonderzeichen.
const FRISEUR_PASSWORT_HINWEIS = 'Das Passwort muss mindestens 8 Zeichen lang sein und einen Großbuchstaben, einen Kleinbuchstaben, eine Zahl und ein Sonderzeichen enthalten.';

function friseur_valid_password(string $password): bool
{
    if (mb_strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[A-ZÄÖÜ]/u', $password)) {
        return false;
    }
    if (!preg_match('/[a-zäöüß]/u', $password)) {
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    if (!preg_match('/[^A-Za-z0-9ÄÖÜäöüß]/u', $password)) {
        return false;
    }
    return true;
}

function friseur_mail_from_header(): string
{
    return '=?UTF-8?B?' . base64_encode('Friseursalon München (Test)') . '?=' . ' <noreply@xn--energieaudit-mnchen-jbc.de>';
}

function friseur_send_booking_confirmation_mail(string $toEmail, string $customerName, array $booking): bool
{
    $dateTimestamp = strtotime((string) $booking['date']);
    $dateFormatted = $dateTimestamp !== false ? date('d.m.Y', $dateTimestamp) : (string) $booking['date'];
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

function friseur_send_password_reset_mail(string $toEmail, string $name, string $token): bool
{
    $link = 'https://xn--energieaudit-mnchen-jbc.de/test/friseur/?reset=' . urlencode($token);
    $safeName = $name !== '' ? $name : 'Kunde/Kundin';
    $subject = 'Passwort zurücksetzen – Friseursalon München (Test)';
    $body = "Hallo {$safeName},\n\n"
        . "für Ihr Konto im Testbereich der Friseur-Terminbuchung wurde ein neues Passwort angefordert.\n"
        . "Über folgenden Link können Sie ein neues Passwort vergeben (30 Minuten gültig):\n\n"
        . $link . "\n\n"
        . "Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach – Ihr Passwort bleibt unverändert.\n\n"
        . "Friseursalon München\n"
        . "Hinweis: Dies ist eine Testumgebung, nicht öffentlich online.\n";

    $headers = "From: " . friseur_mail_from_header() . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('friseur_send_password_reset_mail: mail() lieferte false für ' . $toEmail);
    }
    return $ok;
}

function friseur_send_reminder_mail(string $toEmail, string $customerName, array $booking): bool
{
    $dateTimestamp = strtotime((string) $booking['date']);
    $dateFormatted = $dateTimestamp !== false ? date('d.m.Y', $dateTimestamp) : (string) $booking['date'];
    $timeFormatted = substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5) . ' Uhr';
    $safeName = $customerName !== '' ? $customerName : 'Kunde/Kundin';
    $subject = 'Erinnerung: Ihr Termin morgen – Friseursalon München (Test)';
    $body = "Hallo {$safeName},\n\n"
        . "kurze Erinnerung an Ihren Termin morgen:\n\n"
        . "Anwendung: {$booking['service']}\n"
        . "Datum:     {$dateFormatted}\n"
        . "Uhrzeit:   {$timeFormatted}\n"
        . "Bei:       {$booking['staff_name']}\n\n"
        . "Falls Sie den Termin nicht wahrnehmen können, stornieren Sie ihn bitte rechtzeitig unter \"Meine Termine\".\n\n"
        . "Friseursalon München\n"
        . "Hinweis: Dies ist eine Testumgebung, nicht öffentlich online.\n";

    $headers = "From: " . friseur_mail_from_header() . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('friseur_send_reminder_mail: mail() lieferte false für ' . $toEmail);
    }
    return $ok;
}

function friseur_send_waitlist_mail(string $toEmail, string $customerName, array $slot): bool
{
    $dateTimestamp = strtotime((string) $slot['date']);
    $dateFormatted = $dateTimestamp !== false ? date('d.m.Y', $dateTimestamp) : (string) $slot['date'];
    $safeName = $customerName !== '' ? $customerName : 'Kunde/Kundin';
    $subject = 'Ein Termin ist frei geworden – Friseursalon München (Test)';
    $body = "Hallo {$safeName},\n\n"
        . "gute Nachricht: Für den {$dateFormatted} bei {$slot['staff_name']} ist gerade ein Termin freigeworden,\n"
        . "für den Sie sich auf die Warteliste eingetragen hatten.\n\n"
        . "Bitte buchen Sie zeitnah online, da der Slot nicht reserviert ist und auch von anderen Kund:innen\n"
        . "gebucht werden kann (wer zuerst bucht, bekommt den Termin).\n\n"
        . "Friseursalon München\n"
        . "Hinweis: Dies ist eine Testumgebung, nicht öffentlich online.\n";

    $headers = "From: " . friseur_mail_from_header() . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) {
        error_log('friseur_send_waitlist_mail: mail() lieferte false für ' . $toEmail);
    }
    return $ok;
}

function friseur_send_due_reminders(PDO $pdo): void
{
    // Pragmatische Umsetzung fuer die Testumgebung: kein eigener Cronjob noetig -
    // bei jedem API-Aufruf werden faellige Erinnerungen (Termin ist "morgen") geprueft
    // und verschickt. Bei sehr wenig Traffic kann sich der Versand dadurch etwas
    // verzoegern, bis die naechste Anfrage eintrifft.
    $tomorrow = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT b.*, p.identifier AS customer_email FROM bookings b
         JOIN profiles p ON p.id = b.customer_id
         WHERE b.date = ? AND b.reminder_sent = 0 AND b.manual = 0
         LIMIT 20"
    );
    $stmt->execute([$tomorrow]);
    $due = $stmt->fetchAll();
    if (!$due) {
        return;
    }
    $markSent = $pdo->prepare('UPDATE bookings SET reminder_sent = 1 WHERE id = ?');
    foreach ($due as $booking) {
        if (filter_var($booking['customer_email'], FILTER_VALIDATE_EMAIL)) {
            friseur_send_reminder_mail($booking['customer_email'], $booking['customer_name'], $booking);
        }
        $markSent->execute([$booking['id']]);
    }
}
