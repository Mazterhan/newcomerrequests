<?php
declare(strict_types=1);

function migrateNewcomerFields(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(newcomers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $required = ['passwords_received', 'user_confirmed_access', 'business_unit', 'department', 'job_title', 'snow_request_number', 'snow_request_url', 'comment'];
    if (!array_diff($required, $columns)) return;
    $db->beginTransaction();
    try {
        if (!in_array('passwords_received', $columns, true)) {
            $db->exec("ALTER TABLE newcomers ADD COLUMN passwords_received TEXT NOT NULL DEFAULT 'None' CHECK(passwords_received IN ('None','TAP','Network','Both'))");
        }
        if (!in_array('user_confirmed_access', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN user_confirmed_access INTEGER NOT NULL DEFAULT 0 CHECK(user_confirmed_access IN (0,1))');
        }
        if (!in_array('business_unit', $columns, true)) {
            $db->exec("ALTER TABLE newcomers ADD COLUMN business_unit TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('department', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN department TEXT');
        }
        if (!in_array('job_title', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN job_title TEXT');
        }
        if (!in_array('snow_request_number', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN snow_request_number TEXT');
        }
        if (!in_array('snow_request_url', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN snow_request_url TEXT');
        }
        if (!in_array('comment', $columns, true)) {
            $db->exec('ALTER TABLE newcomers ADD COLUMN comment TEXT');
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function migrateAccessRequestFields(PDO $db): void
{
    $columns = $db->query('PRAGMA table_info(access_requests)')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($columns, 'name');
    $newcomer = array_values(array_filter($columns, static fn(array $column): bool => $column['name'] === 'newcomer_id'))[0] ?? null;
    $needsNullableNewcomer = $newcomer && (int)$newcomer['notnull'] === 1;
    if (!$needsNullableNewcomer && in_array('manual_user_name', $names, true) && in_array('manual_user_email', $names, true) && in_array('manual_team_id', $names, true) && in_array('manager', $names, true) && in_array('snow_ticket_url', $names, true)) return;

    if ($needsNullableNewcomer) {
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->beginTransaction();
        try {
            $db->exec('CREATE TABLE access_requests_rebuild(id INTEGER PRIMARY KEY, newcomer_id INTEGER, manual_user_name TEXT, manual_user_email TEXT, manual_team_id INTEGER, manager TEXT, application_id INTEGER NOT NULL, mirror_id TEXT, justification TEXT, approval_status TEXT DEFAULT \'Not requested\', snow_ticket TEXT, snow_ticket_url TEXT, access_granted INTEGER DEFAULT 0, user_confirmation INTEGER DEFAULT 0, comments TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(newcomer_id) REFERENCES newcomers(id) ON DELETE CASCADE, FOREIGN KEY(manual_team_id) REFERENCES teams(id), FOREIGN KEY(application_id) REFERENCES applications(id))');
            $db->exec('INSERT INTO access_requests_rebuild(id,newcomer_id,application_id,mirror_id,justification,approval_status,snow_ticket,access_granted,user_confirmation,comments,created_at) SELECT id,newcomer_id,application_id,mirror_id,justification,approval_status,snow_ticket,access_granted,user_confirmation,comments,created_at FROM access_requests');
            $db->exec('DROP TABLE access_requests');
            $db->exec('ALTER TABLE access_requests_rebuild RENAME TO access_requests');
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        } finally {
            $db->exec('PRAGMA foreign_keys = ON');
        }
        return;
    }
    if (!in_array('manual_user_name', $names, true)) $db->exec('ALTER TABLE access_requests ADD COLUMN manual_user_name TEXT');
    if (!in_array('manual_user_email', $names, true)) $db->exec('ALTER TABLE access_requests ADD COLUMN manual_user_email TEXT');
    if (!in_array('manual_team_id', $names, true)) $db->exec('ALTER TABLE access_requests ADD COLUMN manual_team_id INTEGER');
    if (!in_array('manager', $names, true)) $db->exec('ALTER TABLE access_requests ADD COLUMN manager TEXT');
    if (!in_array('snow_ticket_url', $names, true)) $db->exec('ALTER TABLE access_requests ADD COLUMN snow_ticket_url TEXT');
}

function migrateTeamFields(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(teams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('primark_manager', $columns, true)) $db->exec('ALTER TABLE teams ADD COLUMN primark_manager TEXT');
}

function validNewcomerEmail(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function newcomerDuplicate(string $fullName, string $email, int $ignoreId = 0): bool
{
    $fullName = trim($fullName);
    $email = trim($email);
    if ($fullName === '') return false;
    return (bool)q(
        "SELECT 1 FROM newcomers WHERE id<>? AND (LOWER(TRIM(full_name))=LOWER(?) OR (?<>'' AND LOWER(TRIM(COALESCE(email,'')))=LOWER(?))) LIMIT 1",
        [$ignoreId, $fullName, $email, $email]
    )->fetchColumn();
}

function newcomerStatus(string $status, string $email, string $nptr): string
{
    if ($status === 'Complete') return $status;
    return $status;
}

function migrateNewcomerStatuses(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS portal_migrations (name TEXT PRIMARY KEY)');
    $key = 'newcomer_auto_status_v2';
    $check = $db->prepare('SELECT name FROM portal_migrations WHERE name=?');
    $check->execute([$key]);
    if ($check->fetchColumn()) return;
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT id,email,nptr_number,status FROM newcomers')->fetchAll(PDO::FETCH_ASSOC);
        $update = $db->prepare('UPDATE newcomers SET status=? WHERE id=?');
        foreach ($rows as $row) {
            $savedStatus = $row['status'] === 'NPTR pending' ? 'Account pending' : $row['status'];
            $status = newcomerStatus($savedStatus, $row['email'] ?? '', $row['nptr_number'] ?? '');
            if ($status !== $row['status']) $update->execute([$status, $row['id']]);
        }
        $db->prepare('INSERT INTO portal_migrations(name) VALUES(?)')->execute([$key]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function updateNewcomer(array $input): void
{
    $id = (int)($input['id'] ?? 0);
    $existing = q('SELECT * FROM newcomers WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { $_SESSION['flash'] = 'Newcomer not found.'; redirect('newcomers'); }
    $email = trim($input['email'] ?? '');
    $fullName = trim($input['full_name'] ?? '');
    if (newcomerDuplicate($fullName, $email, $id)) {
        $_SESSION['flash'] = 'A newcomer with this First & Last user name or Primark email already exists.';
        redirect('newcomers');
    }
    $canEditAccess = validNewcomerEmail($email);
    if ($email !== '' && !$canEditAccess) {
        $_SESSION['flash'] = 'Please enter a valid Primark email address or leave the email field empty.';
        redirect('newcomers');
    }
    // Disabled controls are not submitted. Preserve saved values while locked,
    // including when a direct POST attempts to change them without an email.
    $passwords = $canEditAccess ? ($input['passwords_received'] ?? $existing['passwords_received']) : $existing['passwords_received'];
    $confirmed = $canEditAccess ? ($input['user_confirmed_access'] ?? (string)$existing['user_confirmed_access']) : (string)$existing['user_confirmed_access'];
    if (!in_array($passwords, ['None','TAP','Network','Both'], true) || !in_array($confirmed, ['0','1'], true)) {
        $_SESSION['flash'] = 'Please select valid values for passwords received and user confirmed access.';
        redirect('newcomers');
    }
    if ($passwords !== 'Both') $confirmed = '0';
    $nptr = $canEditAccess ? trim($input['nptr_number'] ?? $input['nptr'] ?? $existing['nptr_number'] ?? '') : ($existing['nptr_number'] ?? '');
    $status = $canEditAccess ? ($input['status'] ?? $existing['status']) : $existing['status'];
    if (!in_array($status, ['Account pending','Passwords requested','App access requested','In progress','Complete'], true)) {
        $_SESSION['flash'] = 'Please select a valid newcomer status.';
        redirect('newcomers');
    }
    if ($passwords === 'Both' && $confirmed === '1') $status = 'Complete';
    elseif ($status === 'Complete') $status = 'App access requested';
    if ($canEditAccess) $status = newcomerStatus($status, $email, $nptr);
    $requestedDateTime = trim($input['requested_datetime'] ?? '');
    $createdAt = $requestedDateTime === '' ? null : str_replace('T', ' ', $requestedDateTime).':00';
    // Hiding the checkbox must not reset the previously recorded request state.
    $requested = $email !== '' ? (int)$existing['account_requested'] : (isset($input['account_requested']) ? 1 : 0);
    q('UPDATE newcomers SET full_name=?,team_id=?,account_requested=?,email=?,nptr_number=?,manager=?,status=?,passwords_received=?,user_confirmed_access=?,comment=?,created_at=COALESCE(?,created_at) WHERE id=?', [
        $fullName, (int)$input['team_id'], $requested, $email,
        $nptr, trim($input['manager']), $status,
        $passwords, (int)$confirmed, trim($input['comment'] ?? ''), $createdAt, $id,
    ]);
}
