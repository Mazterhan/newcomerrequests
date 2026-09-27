<?php
declare(strict_types=1);

function cellText(?string $value, bool $expand = false): string
{
    $text = trim($value ?? '');
    if ($text === '') return '<span class="muted" aria-label="Not specified">—</span>';
    if ($expand && mb_strlen($text) > 180) {
        return '<details class="cell-details"><summary><span class="text-preview">'.e(mb_substr($text, 0, 160)).'…</span><span class="details-more">Read more</span><span class="details-less">Show less</span></summary><div class="cell-copy">'.e($text).'</div></details>';
    }
    return '<span class="cell-copy">'.e($text).'</span>';
}

function serviceNowTicketCell(?string $ticket, ?string $url): string
{
    $ticket = trim($ticket ?? '');
    $url = trim($url ?? '');
    if ($ticket === '') return '<span class="muted" aria-label="Not specified">—</span>';
    if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
        return '<a class="service-now-link" href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($ticket).'</a>';
    }
    return '<span class="cell-copy">'.e($ticket).'</span>';
}

function rowActions(string $table, array $row, string $page, bool $canRemove = true): string
{
    $id = (int)$row['id'];
    $label = $row['title'] ?? $row['name'] ?? $row['full_name'] ?? (($row['team'] ?? '').' / '.($row['app'] ?? ''));
    $out = '<div class="row-actions">';
    if ($table === 'access_requests' && ($row['approval_status'] ?? '') !== 'Revoked') {
        $out .= '<form method="post"><input type="hidden" name="action" value="revoke_access_request"><input type="hidden" name="page" value="'.e($page).'"><input type="hidden" name="id" value="'.$id.'"><button class="btn danger" aria-label="Revoke '.e($label).'">Revoke</button></form>';
    }
    if ($canRemove) {
        $out .= '<form method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="page" value="'.e($page).'"><input type="hidden" name="table" value="'.e($table).'"><input type="hidden" name="id" value="'.$id.'"><button class="btn danger" aria-label="Remove '.e($label).'">Remove</button></form>';
    }
    return $out.'</div>';
}

function requestDateTime(?string $date): string
{
    if (!$date) return '—';
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y H:i', $timestamp) : e($date);
}

/** Counts elapsed time while completely excluding Saturdays and Sundays. */
function businessElapsedSeconds(?string $createdAt): int
{
    if (!$createdAt) return 0;
    try {
        $cursor = new DateTimeImmutable($createdAt);
        $now = new DateTimeImmutable('now');
    } catch (Throwable) {
        return 0;
    }
    if ($cursor >= $now) return 0;
    $seconds = 0;
    while ($cursor < $now) {
        $dayEnd = $cursor->setTime(0, 0)->modify('+1 day');
        $segmentEnd = $dayEnd < $now ? $dayEnd : $now;
        if ((int)$cursor->format('N') < 6) $seconds += $segmentEnd->getTimestamp() - $cursor->getTimestamp();
        $cursor = $segmentEnd;
    }
    return $seconds;
}

function newcomerSlaState(?string $createdAt, ?string $passwords): string
{
    if (strcasecmp(trim($passwords ?? ''), 'Both') === 0) return 'ok';
    $seconds = businessElapsedSeconds($createdAt);
    if ($seconds >= 36 * 3600) return 'expired';
    if ($seconds >= 30 * 3600) return 'warning';
    return 'ok';
}

function accessRequestSlaState(?string $createdAt, mixed $accessGranted, ?string $status): string
{
    if ((int)$accessGranted === 1 || $status === 'Revoked') return 'ok';
    $seconds = businessElapsedSeconds($createdAt);
    if ($seconds >= 108 * 3600) return 'expired';
    if ($seconds >= 102 * 3600) return 'warning';
    return 'ok';
}

function renderDashboardAnalytics(): void
{
    $newcomerRows = q('SELECT id,full_name,email,passwords_received,created_at FROM newcomers ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
    $requestRows = q("SELECT ar.id,ar.created_at,ar.approval_status,ar.access_granted,ar.user_confirmation,COALESCE(n.full_name,ar.manual_user_name) AS full_name,a.name AS application FROM access_requests ar LEFT JOIN newcomers n ON n.id=ar.newcomer_id JOIN applications a ON a.id=ar.application_id ORDER BY ar.created_at ASC,ar.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $newcomerNeedsAction = [];
    $newcomerBreaches = [];
    foreach ($newcomerRows as $row) {
        $needsAction = trim($row['email'] ?? '') === '' || strcasecmp(trim($row['passwords_received'] ?? ''), 'Both') !== 0;
        if (!$needsAction) continue;
        $newcomerNeedsAction[] = $row;
        $seconds = businessElapsedSeconds($row['created_at'] ?? null);
        if ($seconds >= 36 * 3600) { $row['elapsed_seconds'] = $seconds; $newcomerBreaches[] = $row; }
    }
    $requestNeedsAction = [];
    $requestBreaches = [];
    foreach ($requestRows as $row) {
        $needsAction = ($row['approval_status'] ?? '') === 'Not requested' && (int)($row['access_granted'] ?? 0) === 0 && (int)($row['user_confirmation'] ?? 0) === 0;
        if (!$needsAction) continue;
        $requestNeedsAction[] = $row;
        $seconds = businessElapsedSeconds($row['created_at'] ?? null);
        if ($seconds >= 108 * 3600) { $row['elapsed_seconds'] = $seconds; $requestBreaches[] = $row; }
    }
    $hours = static fn(int $seconds): string => ceil($seconds / 3600).' business h';
    ?>
    <section class="card analytics-section" aria-labelledby="sla-analytics-title">
        <div class="analytics-heading"><div><h2 id="sla-analytics-title">SLA and action queue</h2><p>Elapsed time excludes Saturdays and Sundays.</p></div></div>
        <div class="analytics-metrics">
            <div><span>New joiners needing setup</span><strong><?=count($newcomerNeedsAction)?></strong></div>
            <div class="analytics-metric--alert"><span>New joiner SLA breaches</span><strong><?=count($newcomerBreaches)?></strong></div>
            <div><span>Requests awaiting approval</span><strong><?=count($requestNeedsAction)?></strong></div>
            <div class="analytics-metric--alert"><span>Access request SLA breaches</span><strong><?=count($requestBreaches)?></strong></div>
        </div>
        <div class="analytics-grid">
            <section class="analytics-list-card">
                <div class="analytics-list-heading"><h3>New joiners over 36 hours</h3><a href="?page=newcomers">Open New joiners</a></div>
                <?php if (!$newcomerBreaches): ?><p class="analytics-empty">No overdue new joiners.</p><?php else: ?><ul class="analytics-list"><?php foreach ($newcomerBreaches as $row): ?>
                    <li><div><strong><?=e($row['full_name'])?></strong><span><?=trim($row['email'] ?? '') === '' ? 'Email pending' : 'Passwords incomplete'?> · <?=requestDateTime($row['created_at'] ?? null)?></span></div><b><?=e($hours((int)$row['elapsed_seconds']))?></b></li>
                <?php endforeach ?></ul><?php endif ?>
            </section>
            <section class="analytics-list-card">
                <div class="analytics-list-heading"><h3>Access requests over 108 hours</h3><a href="?page=requests">Open Access requests</a></div>
                <?php if (!$requestBreaches): ?><p class="analytics-empty">No overdue access requests.</p><?php else: ?><ul class="analytics-list"><?php foreach ($requestBreaches as $row): ?>
                    <li><div><strong><?=e($row['full_name'])?></strong><span><?=e($row['application'])?> · <?=requestDateTime($row['created_at'] ?? null)?></span></div><b><?=e($hours((int)$row['elapsed_seconds']))?></b></li>
                <?php endforeach ?></ul><?php endif ?>
            </section>
        </div>
    </section>
    <?php
}

function renderCoverageMatrix(): void
{
    $teams = q('SELECT id,name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $applications = q('SELECT id,name FROM applications ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $rules = q('SELECT team_id,application_id FROM access_rules')->fetchAll(PDO::FETCH_ASSOC);
    $coverage = [];
    foreach ($rules as $rule) $coverage[(int)$rule['team_id']][(int)$rule['application_id']] = true;
    ?>
    <div class="matrix-heading">
        <div><h2>Access coverage</h2><p>Green cells indicate an access rule. Select a team or application to bring its assigned intersections to the front; select it again to restore the original order.</p></div>
    </div>
    <div class="table-wrap coverage-wrap" tabindex="0" role="region" aria-label="Access coverage matrix">
        <table class="coverage-matrix" data-coverage-matrix>
            <thead><tr><th scope="col" class="matrix-corner">Team / Application</th><?php foreach ($applications as $application): ?>
                <th scope="col" data-matrix-app-header data-matrix-app-id="<?=$application['id']?>"><button type="button" class="matrix-label" data-matrix-application="<?=$application['id']?>"><?=e($application['name'])?></button></th>
            <?php endforeach ?></tr></thead>
            <tbody><?php foreach ($teams as $team): ?><tr data-matrix-team-id="<?=$team['id']?>">
                <th scope="row"><button type="button" class="matrix-label" data-matrix-team="<?=$team['id']?>"><?=e($team['name'])?></button></th>
                <?php foreach ($applications as $application): $hasRule = !empty($coverage[(int)$team['id']][(int)$application['id']]); ?>
                    <td data-matrix-app-id="<?=$application['id']?>" class="<?=$hasRule ? 'matrix-hit' : ''?>" aria-label="<?=e($team['name'].' / '.$application['name'].': '.($hasRule ? 'Rule exists' : 'No rule'))?>"></td>
                <?php endforeach ?>
            </tr><?php endforeach ?></tbody>
        </table>
    </div>
    <?php
}

/** Every header, column width and row is emitted together; JS never adds columns. */
function renderTable(string $kind): void
{
    $specs = [
        'dashboard' => ['Latest access requests', 'SELECT ar.*,COALESCE(n.full_name,ar.manual_user_name) full_name,a.name app FROM access_requests ar LEFT JOIN newcomers n ON n.id=ar.newcomer_id JOIN applications a ON a.id=ar.application_id ORDER BY ar.id DESC LIMIT 6', ['User','Application','Approval','SNOW'], [28,28,24,20], null],
        'newcomers' => ['New joiners', 'SELECT n.*,t.name team FROM newcomers n JOIN teams t ON t.id=n.team_id ORDER BY n.created_at DESC,n.id DESC', ['Name','DateTime','Team / manager','Account','NPTR','Passwords received','User confirmed access','Status','Actions'], [18,12,15,9,10,12,12,12], 'newcomers'],
        'requests' => ['Access requests', "SELECT ar.*,COALESCE(n.full_name,ar.manual_user_name) full_name,COALESCE(n.email,ar.manual_user_email) email,a.name app,MAX(ar.created_at) OVER (PARTITION BY LOWER(COALESCE(NULLIF(n.email,''),NULLIF(ar.manual_user_email,''),ar.manual_user_name,''))) AS latest_user_request,MAX(ar.id) OVER (PARTITION BY LOWER(COALESCE(NULLIF(n.email,''),NULLIF(ar.manual_user_email,''),ar.manual_user_name,''))) AS latest_user_request_id FROM access_requests ar LEFT JOIN newcomers n ON n.id=ar.newcomer_id JOIN applications a ON a.id=ar.application_id ORDER BY latest_user_request DESC,latest_user_request_id DESC,ar.created_at DESC,ar.id DESC", ['User','Requested dateTime','Application','Approval','ServiceNow','Access','Comments','Actions'], [16,13,15,11,11,10,12], 'access_requests'],
        'teams' => ['Teams', 'SELECT * FROM teams ORDER BY name', ['Team','Team lead','Primark Manager','Notes','Actions'], [24,20,24,32], 'teams'],
        'applications' => ['Applications', 'SELECT * FROM applications ORDER BY name', ['Application','Approver / backup','AD group','Notes','Actions'], [26,26,22,26], 'applications'],
        'rules' => ['Access matrix', 'SELECT r.*,t.name team,a.name app FROM access_rules r JOIN teams t ON t.id=r.team_id JOIN applications a ON a.id=r.application_id ORDER BY t.name,a.name', ['Team','Application','Mirror ID','Business justification','Actions'], [18,25,25,32], 'access_rules'],
        'instructions' => ['Instructions', 'SELECT i.*,a.name app FROM instructions i LEFT JOIN applications a ON a.id=i.application_id ORDER BY i.title', ['Instruction','Application','Preview','Actions'], [27,23,50], 'instructions'],
    ];
    [$title,$sql,$headers,$weights,$table] = $specs[$kind];
    $rows = q($sql)->fetchAll(PDO::FETCH_ASSOC);
    $actionWidth = $table ? ($kind === 'requests' ? 82 : 88) : 0;
    echo '<div class="table-heading"><h2 id="table-title-'.e($kind).'">'.e($title).'</h2>';
    if ($kind === 'instructions') echo '<label class="table-search"><span class="sr-only">Search instructions</span><input type="search" data-instruction-search placeholder="Search instructions, applications or content" autocomplete="off"></label>';
    if ($kind === 'teams') echo '<label class="table-search"><span class="sr-only">Search teams</span><input type="search" data-team-search placeholder="Search teams" autocomplete="off"></label>';
    if ($kind === 'applications') echo '<label class="table-search"><span class="sr-only">Search applications</span><input type="search" data-application-search placeholder="Search applications" autocomplete="off"></label>';
    if ($kind === 'rules') echo '<label class="table-search"><span class="sr-only">Search access matrix</span><input type="search" data-rule-search placeholder="Search access matrix" autocomplete="off"></label>';
    if ($kind === 'requests') echo '<label class="table-search"><span class="sr-only">Search access requests</span><input type="search" data-request-search placeholder="Search access requests" autocomplete="off"></label>';
    if (!in_array($kind, ['instructions','teams','applications','rules','requests'], true)) echo '<span class="record-count" data-record-count>'.count($rows).(count($rows) === 1 ? ' record' : ' records').'</span>';
    $createButtons = [
        'newcomers' => ['Create newcomer', 'newcomer-create-form'],
        'teams' => ['Add team', 'create-teams-form'],
        'applications' => ['Add application', 'create-applications-form'],
        'rules' => ['Add matrix rule', 'create-rules-form'],
        'instructions' => ['Add instruction', 'create-instructions-form'],
        'requests' => ['Add applications access request', 'create-requests-form'],
    ];
    if (isset($createButtons[$kind])) {
        [$label,$template] = $createButtons[$kind];
        echo '<button type="button" class="btn" data-create-template="'.e($template).'">'.e($label).'</button>';
    }
    if ($kind === 'dashboard') echo '<a class="btn secondary" href="?page=requests">Open tracker</a>';
    echo '</div><div class="table-wrap table-wrap--'.e($kind).'" tabindex="0" role="region" aria-labelledby="table-title-'.e($kind).'">';
    echo '<table class="table table--'.e($kind).'" style="--actions-width:'.$actionWidth.'px"><colgroup>';
    foreach ($weights as $weight) echo '<col style="width:'.$weight.'%">';
    if ($table) echo '<col class="actions-col">';
    echo '</colgroup><thead><tr>';
    foreach ($headers as $index => $label) {
        if ($kind === 'instructions' && $index < 2) {
            $keys = ['instruction','application'];
            echo '<th scope="col" aria-sort="'.($index === 0 ? 'ascending' : 'none').'"><button type="button" class="sort-button" data-instruction-sort="'.$keys[$index].'">'.e($label).'</button></th>';
        } elseif ($kind === 'teams' && $index < 2) {
            $keys = ['team','lead'];
            echo '<th scope="col" aria-sort="'.($index === 0 ? 'ascending' : 'none').'"><button type="button" class="sort-button" data-team-sort="'.$keys[$index].'">'.e($label).'</button></th>';
        } elseif ($kind === 'applications' && $index < 3) {
            $keys = ['application','approver','adGroup'];
            echo '<th scope="col" aria-sort="'.($index === 0 ? 'ascending' : 'none').'"><button type="button" class="sort-button" data-application-sort="'.$keys[$index].'">'.e($label).'</button></th>';
        } elseif ($kind === 'rules' && $index < 2) {
            $keys = ['team','application'];
            echo '<th scope="col" aria-sort="'.($index === 0 ? 'ascending' : 'none').'"><button type="button" class="sort-button" data-rule-sort="'.$keys[$index].'">'.e($label).'</button></th>';
        } else {
            echo '<th scope="col">'.e($label).'</th>';
        }
    }
    echo '</tr></thead><tbody>';
    $previousRequestUser = null;
    $lastRenderedRequestUser = null;
    $requestGroupIndex = -1;
    foreach ($rows as $r) {
        $cells = [];
        switch ($kind) {
            case 'dashboard':
                $cells = [cellText($r['full_name']),cellText($r['app']),statusBadge($r['approval_status']),cellText($r['snow_ticket'])];
                break;
            case 'newcomers':
                $passwords = $r['passwords_received'] ?? 'None';
                $newcomerSla = newcomerSlaState($r['created_at'] ?? null, $passwords);
                $passwordColor = match ($passwords) { 'Both' => 'green', 'TAP','Network' => 'orange', default => 'red' };
                $confirmed = (bool)($r['user_confirmed_access'] ?? false);
                $email = trim($r['email'] ?? '');
                $cells = [
                    '<strong>'.e($r['full_name']).'</strong><span class="cell-secondary '.($email !== '' ? 'email-address' : 'email-pending').'">'.e($email !== '' ? $email : 'Email pending').'</span>',
                    requestDateTime($r['created_at'] ?? null),
                    cellText($r['team']).($r['manager'] ? '<span class="cell-secondary">'.e($r['manager']).'</span>' : ''),
                    $r['account_requested'] ? 'Requested' : 'Pending',
                    cellText($r['nptr_number']),
                    '<span class="badge '.$passwordColor.'">'.e($passwords).'</span>',
                    '<span class="badge '.($confirmed ? 'green' : 'red').'">'.($confirmed ? 'Yes' : 'No').'</span>',
                    statusBadge($r['status']),
                ];
                break;
            case 'requests':
                $requestSla = accessRequestSlaState($r['created_at'] ?? null, $r['access_granted'] ?? 0, $r['approval_status'] ?? null);
                $userKey = ($r['full_name'] ?? '').'|'.($r['email'] ?? '');
                $showUser = $userKey !== $previousRequestUser;
                $previousRequestUser = $userKey;
                $cells = [
                    $showUser
                        ? '<strong>'.e($r['full_name']).'</strong><span class="cell-secondary">'.e($r['email']).'</span>'
                        : '<span class="muted request-user-arrow">↳</span><span class="request-user-on-search"><strong>'.e($r['full_name']).'</strong><span class="cell-secondary">'.e($r['email']).'</span></span>',
                    requestDateTime($r['created_at'] ?? null),
                    '<strong>'.e($r['app']).'</strong>',
                    statusBadge($r['approval_status']),
                    serviceNowTicketCell($r['snow_ticket'] ?? null, $r['snow_ticket_url'] ?? null),
                    '<div class="access-state">'.($r['access_granted'] ? '<span class="badge green">Granted</span>' : '<span class="muted">Not granted</span>').($r['user_confirmation'] ? '<span class="badge green">Confirmed</span>' : '<span class="muted">Not confirmed</span>').'</div>',
                    cellText($r['comments'],true),
                ];
                break;
            case 'teams':
                $cells = ['<strong>'.e($r['name']).'</strong>',cellText($r['lead']),cellText($r['primark_manager']),cellText($r['notes'],true)];
                break;
            case 'applications':
                $cells = ['<strong>'.e($r['name']).'</strong>'.(!$r['enabled'] ? '<span class="cell-secondary">Disabled</span>' : ''),cellText($r['approver'] ?: 'No approval').($r['backup_approver'] ? '<span class="cell-secondary">Backup: '.e($r['backup_approver']).'</span>' : '').($r['levy_copy'] ? '<span class="cell-secondary">CC: Philip Levy / Emma Glennon</span>' : ''),cellText($r['ad_group']),cellText($r['comment'],true)];
                break;
            case 'rules':
                $cells = [cellText($r['team']),'<strong>'.e($r['app']).'</strong>',cellText($r['mirror_id']),cellText($r['justification'],true)];
                break;
            case 'instructions':
                $plain = html_entity_decode(strip_tags(preg_replace('/<\/(?:p|div|li|h[1-6])>/i', ' ', $r['content'] ?? '')), ENT_QUOTES, 'UTF-8');
                $plain = preg_replace('/\s+/u',' ',trim($plain));
                $preview = mb_strlen($plain) > 190 ? mb_substr($plain,0,190).'…' : $plain;
                $cells = ['<strong>'.e($r['title']).'</strong>',cellText($r['app']),cellText($preview)];
                break;
        }
        $instructionData = '';
        if ($kind === 'instructions') {
            $instructionData = ' data-instruction="'.e($r['title']).'" data-application="'.e($r['app'] ?? '').'" data-preview="'.e($plain).'" data-search="'.e($r['title'].' '.($r['app'] ?? '').' '.$plain).'"';
        }
        $teamData = '';
        if ($kind === 'teams') {
            $teamData = ' data-team="'.e($r['name']).'" data-lead="'.e($r['lead'] ?? '').'" data-search="'.e($r['name'].' '.($r['lead'] ?? '').' '.($r['primark_manager'] ?? '').' '.($r['notes'] ?? '')).'"';
        }
        $applicationData = '';
        if ($kind === 'applications') {
            $applicationData = ' data-application="'.e($r['name']).'" data-approver="'.e(($r['approver'] ?? '').' '.($r['backup_approver'] ?? '')).'" data-ad-group="'.e($r['ad_group'] ?? '').'" data-search="'.e($r['name'].' '.($r['approver'] ?? '').' '.($r['backup_approver'] ?? '').' '.($r['ad_group'] ?? '').' '.($r['comment'] ?? '')).'"';
        }
        $ruleData = '';
        if ($kind === 'rules') {
            $ruleData = ' data-team="'.e($r['team']).'" data-application="'.e($r['app']).'" data-search="'.e($r['team'].' '.$r['app'].' '.($r['mirror_id'] ?? '').' '.($r['justification'] ?? '')).'"';
        }
        $requestData = '';
        if ($kind === 'requests') {
            $requestData = ' data-request data-search="'.e(($r['full_name'] ?? '').' '.($r['email'] ?? '').' '.($r['app'] ?? '').' '.($r['mirror_id'] ?? '').' '.($r['approval_status'] ?? '').' '.($r['snow_ticket'] ?? '').' '.($r['comments'] ?? '')).'"';
        }
        $requestClasses = '';
        if ($kind === 'requests') {
            $isNewRequestGroup = $userKey !== $lastRenderedRequestUser;
            if ($isNewRequestGroup) $requestGroupIndex++;
            $requestClasses = ($isNewRequestGroup ? ' request-group-start' : '').($requestGroupIndex % 2 ? ' request-user-group-alt' : '').(($r['approval_status'] ?? '') === 'Revoked' ? ' request-revoked' : '');
            $lastRenderedRequestUser = $userKey;
        }
        $requestSlaClasses = $kind === 'requests' && ($requestSla ?? '') === 'expired' ? ' request-sla-breached' : '';
        $newcomerClasses = $kind === 'newcomers' && ($newcomerSla ?? '') === 'expired' ? ' sla-breached' : '';
        $recordLabel = $r['title'] ?? $r['name'] ?? $r['full_name'] ?? (($r['team'] ?? '').' / '.($r['app'] ?? ''));
        $openRecordData = $table ? ' data-open-table="'.e($table).'" data-record-label="'.e($recordLabel).'" tabindex="0" role="button"' : '';
        echo '<tr class="'.trim($requestClasses.$requestSlaClasses.$newcomerClasses).'" data-record-id="'.(int)$r['id'].'"'.$openRecordData.$instructionData.$teamData.$applicationData.$ruleData.$requestData.'>';
        foreach ($cells as $cell) echo '<td>'.$cell.'</td>';
        if ($table) echo '<td class="actions-cell">'.rowActions($table,$r,$kind,$kind !== 'requests').'</td>';
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="'.count($headers).'" class="empty">No records yet.</td></tr>';
    echo '</tbody></table></div>';
}
