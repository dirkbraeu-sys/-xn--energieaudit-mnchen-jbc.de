<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$pdo = friseur_db();
friseur_ensure_schema($pdo);

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {

    // ---------- Auth ----------

    case 'signup':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $identifier = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $displayName = trim((string) ($in['display_name'] ?? $identifier));
        $displayName = $displayName !== '' ? $displayName : $identifier;
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6) {
            friseur_json(['error' => 'Bitte eine gültige E-Mail-Adresse und ein Passwort mit mind. 6 Zeichen angeben.'], 400);
        }
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $check = $pdo->prepare('SELECT id, verified FROM profiles WHERE identifier = ?');
        $check->execute([$identifier]);
        $existing = $check->fetch();
        if ($existing) {
            if ((int) $existing['verified'] === 1) {
                friseur_json(['error' => 'Für diese Adresse besteht bereits ein Konto. Bitte melden Sie sich an.'], 400);
            }
            // Registrierung noch nicht bestätigt: neuen Token vergeben und erneut zusenden.
            $upd = $pdo->prepare('UPDATE profiles SET password_hash = ?, display_name = ?, verification_token = ?, verification_expires = ? WHERE id = ?');
            $upd->execute([$passwordHash, $displayName, $token, $expires, $existing['id']]);
        } else {
            $ins = $pdo->prepare('INSERT INTO profiles (identifier, password_hash, role, display_name, verified, verification_token, verification_expires) VALUES (?, ?, "customer", ?, 0, ?, ?)');
            $ins->execute([$identifier, $passwordHash, $displayName, $token, $expires]);
        }

        friseur_send_verification_mail($identifier, $displayName, $token);
        friseur_json(['profile' => null, 'message' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse über den zugesendeten Link.']);

    case 'signin':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $identifier = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, password_hash, verified FROM profiles WHERE identifier = ?');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            usleep(300000);
            friseur_json(['error' => 'E-Mail/Name oder Passwort ist falsch.'], 401);
        }
        if ((int) $row['verified'] !== 1) {
            friseur_json(['error' => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse (Link in der Registrierungs-Mail).'], 403);
        }
        session_regenerate_id(true);
        $_SESSION['profile_id'] = (int) $row['id'];
        friseur_json(['profile' => friseur_current_profile($pdo)]);

    case 'verify_email':
        $in = $method === 'POST' ? friseur_body() : $_GET;
        $token = trim((string) ($in['token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            friseur_json(['error' => 'Ungültiger Bestätigungslink.'], 400);
        }
        $stmt = $pdo->prepare('SELECT id, display_name, verification_expires FROM profiles WHERE verification_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            friseur_json(['error' => 'Der Bestätigungslink ist ungültig oder wurde bereits verwendet.'], 400);
        }
        if (new DateTimeImmutable((string) $row['verification_expires']) < new DateTimeImmutable()) {
            friseur_json(['error' => 'Der Bestätigungslink ist abgelaufen. Bitte registrieren Sie sich erneut.'], 400);
        }
        $pdo->prepare('UPDATE profiles SET verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?')->execute([$row['id']]);
        friseur_json(['ok' => true, 'name' => $row['display_name']]);

    case 'signout':
        $_SESSION = [];
        session_destroy();
        friseur_json(['ok' => true]);

    case 'me':
        friseur_json(['profile' => friseur_current_profile($pdo)]);

    // ---------- Profiles (nur Inhaber) ----------

    case 'profiles_list':
        $me = friseur_require_login($pdo);
        if ($me['role'] !== 'owner') friseur_json(['error' => 'Kein Zugriff.'], 403);
        $rows = $pdo->query("SELECT id, identifier, role, staff_id, display_name, created_at FROM profiles WHERE role = 'customer' ORDER BY created_at DESC")->fetchAll();
        friseur_json(['profiles' => $rows]);

    // ---------- Bookings ----------

    case 'busy_slots':
        // Öffentlich lesbar (keine Kundendaten) – zur Verfügbarkeitsprüfung.
        $date = (string) ($_GET['date'] ?? '');
        if (!empty($_GET['staff_id'])) {
            $stmt = $pdo->prepare('SELECT start_time, end_time, staff_id FROM bookings WHERE date = ? AND staff_id = ?');
            $stmt->execute([$date, (string) $_GET['staff_id']]);
        } else {
            $stmt = $pdo->prepare('SELECT start_time, end_time, staff_id FROM bookings WHERE date = ?');
            $stmt->execute([$date]);
        }
        friseur_json(['rows' => $stmt->fetchAll()]);

    case 'bookings_list':
        $me = friseur_require_login($pdo);
        $where = [];
        $params = [];
        if ($me['role'] === 'customer') {
            $where[] = 'customer_id = ?';
            $params[] = $me['id'];
        } elseif ($me['role'] === 'staff') {
            $where[] = 'staff_id = ?';
            $params[] = $me['staff_id'];
        } // owner sieht alles
        if (!empty($_GET['date'])) {
            $where[] = 'date = ?';
            $params[] = (string) $_GET['date'];
        }
        if (!empty($_GET['from'])) {
            $where[] = 'date >= ?';
            $params[] = (string) $_GET['from'];
        }
        if (!empty($_GET['staff_id']) && $me['role'] === 'owner') {
            $where[] = 'staff_id = ?';
            $params[] = (string) $_GET['staff_id'];
        }
        if (!empty($_GET['customer_id']) && $me['role'] === 'owner') {
            $where[] = 'customer_id = ?';
            $params[] = (int) $_GET['customer_id'];
        }
        $sql = 'SELECT * FROM bookings' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY date, start_time';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        friseur_json(['bookings' => $stmt->fetchAll()]);

    case 'booking_get':
        $me = friseur_require_login($pdo);
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && $me['role'] === 'customer' && (int) $row['customer_id'] !== (int) $me['id']) {
            friseur_json(['error' => 'Kein Zugriff.'], 403);
        }
        if ($row && $me['role'] === 'staff' && $row['staff_id'] !== $me['staff_id']) {
            friseur_json(['error' => 'Kein Zugriff.'], 403);
        }
        friseur_json(['booking' => $row]);

    case 'booking_create':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $manual = !empty($in['manual']);
        if ($manual && !in_array($me['role'], ['staff', 'owner'], true)) {
            friseur_json(['error' => 'Kein Zugriff.'], 403);
        }
        $customerId = $manual ? null : (int) $me['id'];
        $customerName = $manual ? trim((string) ($in['customer_name'] ?? '')) : $me['display_name'];
        $service = (string) ($in['service'] ?? '');
        $date = (string) ($in['date'] ?? '');
        $startTime = (string) ($in['start_time'] ?? '');
        $endTime = (string) ($in['end_time'] ?? '');
        $staffId = (string) ($in['staff_id'] ?? '');
        $staffName = (string) ($in['staff_name'] ?? '');
        if ($service === '' || $date === '' || $startTime === '' || $endTime === '' || $staffId === '') {
            friseur_json(['error' => 'Bitte Anwendung, Datum, Uhrzeit und Mitarbeiter:in angeben.'], 400);
        }
        // Kollisionsprüfung: zwischen Auswahl und finaler Bestätigung (z. B. während der
        // E-Mail-Bestätigung bei der Registrierung) kann der Slot inzwischen belegt worden sein.
        $conflictStmt = $pdo->prepare(
            'SELECT id FROM bookings WHERE staff_id = ? AND date = ? AND start_time < ? AND end_time > ? LIMIT 1'
        );
        $conflictStmt->execute([$staffId, $date, $endTime, $startTime]);
        if ($conflictStmt->fetch()) {
            friseur_json(['error' => 'Dieser Termin wurde inzwischen leider von jemand anderem gebucht. Bitte wählen Sie eine andere Zeit.'], 409);
        }
        $stmt = $pdo->prepare('INSERT INTO bookings (customer_id, customer_name, service, date, start_time, end_time, staff_id, staff_name, manual) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $customerId,
            $customerName,
            $service,
            $date,
            $startTime,
            $endTime,
            $staffId,
            $staffName,
            $manual ? 1 : 0,
        ]);
        $newId = (int) $pdo->lastInsertId();

        if (!$manual && filter_var($me['identifier'], FILTER_VALIDATE_EMAIL)) {
            friseur_send_booking_confirmation_mail($me['identifier'], $customerName, [
                'service' => $service,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'staff_name' => $staffName,
            ]);
        }

        friseur_json(['id' => $newId]);

    case 'booking_update':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $id = (int) ($in['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) friseur_json(['error' => 'Nicht gefunden.'], 404);
        $allowed = $me['role'] === 'owner'
            || ($me['role'] === 'staff' && $row['staff_id'] === $me['staff_id'])
            || ((int) $row['customer_id'] === (int) $me['id']);
        if (!$allowed) friseur_json(['error' => 'Kein Zugriff.'], 403);

        $fields = [];
        $params = [];
        foreach (['service', 'date', 'start_time', 'end_time', 'staff_id', 'staff_name', 'customer_name'] as $f) {
            if (array_key_exists($f, $in)) {
                $fields[] = "$f = ?";
                $params[] = $in[$f];
            }
        }
        if ($fields) {
            $params[] = $id;
            $pdo->prepare('UPDATE bookings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        friseur_json(['ok' => true]);

    case 'booking_delete':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $id = (int) ($in['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) friseur_json(['ok' => true]);
        $allowed = $me['role'] === 'owner'
            || ($me['role'] === 'staff' && $row['staff_id'] === $me['staff_id'])
            || ((int) $row['customer_id'] === (int) $me['id']);
        if (!$allowed) friseur_json(['error' => 'Kein Zugriff.'], 403);
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
        friseur_json(['ok' => true]);

    // ---------- Released slots ----------

    case 'released_slots_list':
        // Öffentlich lesbar.
        $staffId = (string) ($_GET['staff_id'] ?? '');
        $date = (string) ($_GET['date'] ?? '');
        $stmt = $pdo->prepare('SELECT time FROM released_slots WHERE staff_id = ? AND date = ?');
        $stmt->execute([$staffId, $date]);
        friseur_json(['times' => array_column($stmt->fetchAll(), 'time')]);

    case 'released_slot_toggle':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $staffId = (string) ($in['staff_id'] ?? '');
        if (!in_array($me['role'], ['staff', 'owner'], true) || ($me['role'] === 'staff' && $me['staff_id'] !== $staffId)) {
            friseur_json(['error' => 'Kein Zugriff.'], 403);
        }
        $date = (string) ($in['date'] ?? '');
        $time = (string) ($in['time'] ?? '');
        $check = $pdo->prepare('SELECT id FROM released_slots WHERE staff_id = ? AND date = ? AND time = ?');
        $check->execute([$staffId, $date, $time]);
        $row = $check->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM released_slots WHERE id = ?')->execute([$row['id']]);
            friseur_json(['released' => false]);
        }
        $pdo->prepare('INSERT INTO released_slots (staff_id, date, time) VALUES (?, ?, ?)')->execute([$staffId, $date, $time]);
        friseur_json(['released' => true]);

    case 'released_slots_clear_day':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $staffId = (string) ($in['staff_id'] ?? '');
        if (!in_array($me['role'], ['staff', 'owner'], true) || ($me['role'] === 'staff' && $me['staff_id'] !== $staffId)) {
            friseur_json(['error' => 'Kein Zugriff.'], 403);
        }
        $pdo->prepare('DELETE FROM released_slots WHERE staff_id = ? AND date = ?')->execute([$staffId, (string) ($in['date'] ?? '')]);
        friseur_json(['ok' => true]);

    case 'released_slots_bulk':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
        $stmt = $pdo->prepare('INSERT IGNORE INTO released_slots (staff_id, date, time) VALUES (?, ?, ?)');
        foreach ($rows as $r) {
            $staffId = (string) ($r['staff_id'] ?? '');
            if (!in_array($me['role'], ['staff', 'owner'], true) || ($me['role'] === 'staff' && $me['staff_id'] !== $staffId)) {
                continue;
            }
            $stmt->execute([$staffId, (string) ($r['date'] ?? ''), (string) ($r['time'] ?? '')]);
        }
        friseur_json(['ok' => true]);

    default:
        friseur_json(['error' => 'Unbekannte Aktion.'], 404);
}
