<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$pdo = friseur_db();
friseur_ensure_schema($pdo);
friseur_send_due_reminders($pdo);

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
        $phone = trim((string) ($in['phone'] ?? ''));
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            friseur_json(['error' => 'Bitte eine gültige E-Mail-Adresse angeben.'], 400);
        }
        if ($phone === '' || !preg_match('/^[0-9+\/\s()-]{5,30}$/', $phone)) {
            friseur_json(['error' => 'Bitte eine gültige Telefonnummer angeben.'], 400);
        }
        if (!friseur_valid_password($password)) {
            friseur_json(['error' => FRISEUR_PASSWORT_HINWEIS], 400);
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
            $upd = $pdo->prepare('UPDATE profiles SET password_hash = ?, display_name = ?, phone = ?, verification_token = ?, verification_expires = ? WHERE id = ?');
            $upd->execute([$passwordHash, $displayName, $phone, $token, $expires, $existing['id']]);
        } else {
            $ins = $pdo->prepare('INSERT INTO profiles (identifier, password_hash, role, display_name, phone, verified, verification_token, verification_expires) VALUES (?, ?, "customer", ?, ?, 0, ?, ?)');
            $ins->execute([$identifier, $passwordHash, $displayName, $phone, $token, $expires]);
        }

        friseur_send_verification_mail($identifier, $displayName, $token);
        friseur_json(['profile' => null, 'message' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse über den zugesendeten Link.']);

    case 'signin':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $identifier = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        friseur_check_login_lock($pdo, $identifier);
        $stmt = $pdo->prepare('SELECT id, password_hash, verified FROM profiles WHERE identifier = ?');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            friseur_register_login_failure($pdo, $identifier);
            usleep(300000);
            friseur_json(['error' => 'E-Mail/Name oder Passwort ist falsch.'], 401);
        }
        if ((int) $row['verified'] !== 1) {
            friseur_json(['error' => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse (Link in der Registrierungs-Mail).'], 403);
        }
        friseur_register_login_success($pdo, $identifier);
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

    case 'resend_verification':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $identifier = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));
        $genericOk = ['ok' => true, 'message' => 'Falls für diese Adresse eine unbestätigte Registrierung besteht, wurde eine neue Bestätigungsmail verschickt.'];
        if ($identifier === '') {
            friseur_json($genericOk);
        }
        $stmt = $pdo->prepare('SELECT id, display_name, verified FROM profiles WHERE identifier = ?');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if ($row && (int) $row['verified'] !== 1) {
            $token = bin2hex(random_bytes(32));
            $expires = (new DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE profiles SET verification_token = ?, verification_expires = ? WHERE id = ?')->execute([$token, $expires, $row['id']]);
            friseur_send_verification_mail($identifier, $row['display_name'], $token);
        }
        // Immer dieselbe Antwort, unabhängig davon ob/welcher Account existiert (kein Preisgeben von Konten).
        friseur_json($genericOk);

    case 'forgot_password':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $identifier = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));
        $genericOk = ['ok' => true, 'message' => 'Falls ein Konto mit dieser Adresse besteht, wurde eine E-Mail zum Zurücksetzen des Passworts verschickt.'];
        if ($identifier === '') {
            friseur_json($genericOk);
        }
        $stmt = $pdo->prepare('SELECT id, display_name FROM profiles WHERE identifier = ?');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if ($row) {
            $token = bin2hex(random_bytes(32));
            $expires = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE profiles SET reset_token = ?, reset_expires = ? WHERE id = ?')->execute([$token, $expires, $row['id']]);
            friseur_send_password_reset_mail($identifier, $row['display_name'], $token);
        }
        friseur_json($genericOk);

    case 'reset_password':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $token = trim((string) ($in['token'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            friseur_json(['error' => 'Ungültiger Link zum Zurücksetzen.'], 400);
        }
        if (!friseur_valid_password($password)) {
            friseur_json(['error' => FRISEUR_PASSWORT_HINWEIS], 400);
        }
        $stmt = $pdo->prepare('SELECT id, reset_expires FROM profiles WHERE reset_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            friseur_json(['error' => 'Der Link ist ungültig oder wurde bereits verwendet.'], 400);
        }
        if (!$row['reset_expires'] || new DateTimeImmutable((string) $row['reset_expires']) < new DateTimeImmutable()) {
            friseur_json(['error' => 'Der Link ist abgelaufen. Bitte fordern Sie einen neuen an.'], 400);
        }
        $pdo->prepare('UPDATE profiles SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        friseur_json(['ok' => true]);

    case 'signout':
        $_SESSION = [];
        session_destroy();
        friseur_json(['ok' => true]);

    case 'me':
        friseur_json(['profile' => friseur_current_profile($pdo)]);

    case 'update_profile':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($name === '') {
            friseur_json(['error' => 'Bitte einen Namen angeben.'], 400);
        }
        if ($phone === '' || !preg_match('/^[0-9+\/\s()-]{5,30}$/', $phone)) {
            friseur_json(['error' => 'Bitte eine gültige Telefonnummer angeben.'], 400);
        }
        $upd = $pdo->prepare('UPDATE profiles SET display_name = ?, phone = ? WHERE id = ?');
        $upd->execute([$name, $phone, $me['id']]);
        friseur_json(['profile' => friseur_current_profile($pdo)]);

    case 'change_password':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $current = (string) ($in['current_password'] ?? '');
        $new = (string) ($in['new_password'] ?? '');
        $row = $pdo->prepare('SELECT password_hash FROM profiles WHERE id = ?');
        $row->execute([$me['id']]);
        $hash = $row->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            friseur_json(['error' => 'Das aktuelle Passwort ist nicht korrekt.'], 400);
        }
        if (!friseur_valid_password($new)) {
            friseur_json(['error' => FRISEUR_PASSWORT_HINWEIS], 400);
        }
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE profiles SET password_hash = ? WHERE id = ?')->execute([$newHash, $me['id']]);
        friseur_json(['ok' => true]);

    // ---------- Profiles (nur Inhaber) ----------

    case 'profiles_list':
        $me = friseur_require_login($pdo);
        if ($me['role'] !== 'owner') friseur_json(['error' => 'Kein Zugriff.'], 403);
        $rows = $pdo->query("SELECT id, identifier, role, staff_id, display_name, phone, created_at FROM profiles WHERE role = 'customer' ORDER BY created_at DESC")->fetchAll();
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

        // Kund:innen dürfen selbst nur bis 24 Stunden vor dem Termin stornieren;
        // das Salon-Team (Inhaber:in/Mitarbeiter:in) kann jederzeit stornieren,
        // z. B. bei kurzfristigen Absagen durch die Kundschaft am Telefon.
        if ($me['role'] === 'customer') {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['start_time']);
            if ($start && $start->getTimestamp() - time() < 24 * 3600) {
                friseur_json(['error' => 'Eine Stornierung ist online nur bis 24 Stunden vor dem Termin möglich. Bitte kontaktieren Sie uns für kurzfristige Änderungen telefonisch.'], 403);
            }
        }

        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);

        // Wartelisten-Eintrag benachrichtigen, falls vorhanden (ältester zuerst, nur einmal).
        // "egal" = für diesen Tag wurde kein bestimmter Mitarbeiter gewünscht.
        $wl = $pdo->prepare("SELECT * FROM waitlist WHERE date = ? AND (staff_id = ? OR staff_id = 'egal') AND notified = 0 ORDER BY created_at ASC LIMIT 1");
        $wl->execute([$row['date'], $row['staff_id']]);
        $waiting = $wl->fetch();
        if ($waiting && filter_var($waiting['email'], FILTER_VALIDATE_EMAIL)) {
            friseur_send_waitlist_mail($waiting['email'], $waiting['customer_name'], [
                'date' => $row['date'],
                'staff_name' => $row['staff_name'],
            ]);
            $pdo->prepare('UPDATE waitlist SET notified = 1 WHERE id = ?')->execute([$waiting['id']]);
        }

        friseur_json(['ok' => true]);

    // ---------- Warteliste ----------

    case 'waitlist_join':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $date = (string) ($in['date'] ?? '');
        $staffId = (string) ($in['staff_id'] ?? '');
        $service = (string) ($in['service'] ?? '');
        if ($date === '' || $staffId === '' || $service === '') {
            friseur_json(['error' => 'Bitte Datum, Mitarbeiter:in und Anwendung angeben.'], 400);
        }
        $check = $pdo->prepare('SELECT id FROM waitlist WHERE customer_id = ? AND date = ? AND staff_id = ? AND notified = 0');
        $check->execute([$me['id'], $date, $staffId]);
        if ($check->fetch()) {
            friseur_json(['ok' => true, 'message' => 'Sie stehen für diesen Tag bereits auf der Warteliste.']);
        }
        $pdo->prepare('INSERT INTO waitlist (customer_id, customer_name, email, date, staff_id, service) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$me['id'], $me['display_name'], $me['identifier'], $date, $staffId, $service]);
        friseur_json(['ok' => true, 'message' => 'Sie wurden auf die Warteliste gesetzt. Wird ein Termin frei, erhalten Sie eine E-Mail.']);

    case 'waitlist_list':
        $me = friseur_require_login($pdo);
        if (!in_array($me['role'], ['staff', 'owner'], true)) friseur_json(['error' => 'Kein Zugriff.'], 403);
        $where = ['notified = 0'];
        $params = [];
        if ($me['role'] === 'staff') {
            $where[] = 'staff_id = ?';
            $params[] = $me['staff_id'];
        }
        $sql = 'SELECT * FROM waitlist WHERE ' . implode(' AND ', $where) . ' ORDER BY date, created_at';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        friseur_json(['waitlist' => $stmt->fetchAll()]);

    // ---------- Besucherstatistik (cookie-frei) ----------

    case 'track_view':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $in = friseur_body();
        $path = mb_substr(trim((string) ($in['path'] ?? '/')), 0, 255);
        $referrer = trim((string) ($in['referrer'] ?? ''));
        // Eigene Seite und leere Werte nicht als "Verweis" zählen.
        if ($referrer !== '' && stripos($referrer, $_SERVER['HTTP_HOST'] ?? '') !== false) {
            $referrer = '';
        }
        $referrer = $referrer !== '' ? mb_substr($referrer, 0, 255) : null;
        $pdo->prepare('INSERT INTO page_views (path, referrer, visitor_hash) VALUES (?, ?, ?)')
            ->execute([$path, $referrer, friseur_visitor_hash()]);
        friseur_json(['ok' => true]);

    case 'stats_summary':
        $me = friseur_require_login($pdo);
        if ($me['role'] !== 'owner') friseur_json(['error' => 'Kein Zugriff.'], 403);

        $totals = function (string $interval) use ($pdo): array {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques FROM page_views WHERE created_at >= NOW() - INTERVAL {$interval}");
            $stmt->execute();
            $row = $stmt->fetch();
            return ['views' => (int) $row['views'], 'uniques' => (int) $row['uniques']];
        };

        $daily = $pdo->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS uniques
            FROM page_views
            WHERE created_at >= NOW() - INTERVAL 14 DAY
            GROUP BY DATE(created_at)
            ORDER BY day DESC
        ")->fetchAll();

        $referrers = $pdo->query("
            SELECT referrer, COUNT(*) AS c
            FROM page_views
            WHERE referrer IS NOT NULL AND referrer != '' AND created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY referrer
            ORDER BY c DESC
            LIMIT 10
        ")->fetchAll();

        $topPages = $pdo->query("
            SELECT path, COUNT(*) AS c
            FROM page_views
            WHERE created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY path
            ORDER BY c DESC
            LIMIT 10
        ")->fetchAll();

        friseur_json([
            'today' => $totals('1 DAY'),
            'last7' => $totals('7 DAY'),
            'last30' => $totals('30 DAY'),
            'daily' => $daily,
            'referrers' => $referrers,
            'top_pages' => $topPages,
        ]);

    // ---------- Kundenmeinungen ----------

    case 'reviews_list':
        // Öffentlich lesbar (Startseite).
        $rows = $pdo->query('SELECT id, first_name, body, rating, created_at FROM reviews ORDER BY created_at DESC LIMIT 30')->fetchAll();
        friseur_json(['reviews' => $rows]);

    case 'review_create':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        $in = friseur_body();
        $body = trim((string) ($in['body'] ?? ''));
        $rating = (int) ($in['rating'] ?? 0);
        if ($body === '' || mb_strlen($body) < 5) {
            friseur_json(['error' => 'Bitte eine Meinung mit mindestens 5 Zeichen eingeben.'], 400);
        }
        if (mb_strlen($body) > 1000) {
            friseur_json(['error' => 'Bitte kürzer fassen (max. 1000 Zeichen).'], 400);
        }
        if ($rating < 1 || $rating > 5) {
            friseur_json(['error' => 'Bitte eine Sternebewertung von 1 bis 5 auswählen.'], 400);
        }
        // Vorname aus dem hinterlegten Namen ableiten, damit niemand einen fremden
        // Namen vortäuschen kann - echte Meinungen von echten angemeldeten Kund:innen.
        $firstName = trim(explode(' ', (string) $me['display_name'])[0]);
        $firstName = $firstName !== '' ? $firstName : $me['display_name'];
        $ins = $pdo->prepare('INSERT INTO reviews (customer_id, first_name, body, rating) VALUES (?, ?, ?, ?)');
        $ins->execute([$me['id'], $firstName, $body, $rating]);
        friseur_json(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);

    case 'review_delete':
        if ($method !== 'POST') friseur_json(['error' => 'Methode nicht erlaubt.'], 405);
        $me = friseur_require_login($pdo);
        if ($me['role'] !== 'owner') friseur_json(['error' => 'Kein Zugriff.'], 403);
        $in = friseur_body();
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) friseur_json(['error' => 'Ungültige Anfrage.'], 400);
        $pdo->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]);
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
