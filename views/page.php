<?php
require_once __DIR__.'/tables.php';
$description = $page === 'newcomers'
    ? 'Create and manage a newcomer record to support the provisioning of a Primark account and the controlled assignment of required access to internal and third-party services.'
    : ($page === 'dashboard' ? '' : 'List of instructions for user access provision.');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($nav[$page] ?? 'Overview')?> · Access Portal</title>
    <meta name="theme-color" content="#3858e9">
    <meta name="application-name" content="Access Portal">
    <link rel="manifest" href="manifest.webmanifest?v=1" type="application/manifest+json">
    <link rel="icon" href="assets/favicon.svg?v=<?=filemtime(__DIR__.'/../assets/favicon.svg')?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.png">
    <link rel="stylesheet" href="assets/app.css?v=<?=filemtime(__DIR__.'/../assets/app.css')?>">
    <script src="assets/rich.js?v=<?=filemtime(__DIR__.'/../assets/rich.js')?>" defer></script>
    <script src="assets/pwa-register.js?v=1" defer></script>
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="brand"><span class="logo">A</span>Access Portal</div>
        <small>Workspace</small>
        <?php foreach($nav as $key=>$label): ?>
            <a class="<?=$page===$key?'active':''?>" href="?page=<?=$key?>" <?=$page===$key?'aria-current="page"':''?>><?=e($label)?></a>
        <?php endforeach ?>
        <small>Prototype</small>
    </aside>
    <main class="main">
        <div class="top"><div><div class="eyebrow">Primark · newcomer operations</div><h1><?=e($nav[$page] ?? 'Overview')?></h1><?php if ($description !== ''): ?><p><?=e($description)?></p><?php endif ?></div></div>
        <?php if($flash): ?><div class="flash" role="status"><?=e($flash)?></div><?php endif ?>
        <?php if($page==='dashboard'): ?>
            <?php renderDashboardAnalytics(); ?>
            <div class="split">
                <section class="card"><?php renderTable('dashboard'); ?></section>
                <section class="card"><h2>How it works</h2><div class="note">1. Keep teams, applications and the access matrix current.</div><div class="note">2. Add a newcomer once their Primark email is available.</div><div class="note">3. Requests creates from the selected team’s matrix, then tracked through approval and SNOW.</div></section>
            </div>
        <?php elseif(isset($nav[$page])): ?>
            <?php
            $forms = [
                'teams'=>['Add team','add_team',[
                    ['name','Team name','text','',true],['lead','Team lead','text'],['primark_manager','Primark Manager','text'],['notes','Notes','text']]],
                'applications'=>['Add application','add_application',[
                    ['name','Name','text','',true],['approver','Business approver','text'],['backup','Backup approver','text'],['ad_group','AD group','text'],['levy','Copy Philip Levy / Emma Glennon','checkbox'],['comment','Comment','text']]],
                'rules'=>['Add matrix rule','add_rule',[
                    ['team_id','Team','teams','',true],['application_id','Application','applications','',true],['mirror','Mirror ID','textarea','mirror@primark.com'],['justification','Business justification','textarea','Why access is needed']]],
                'instructions'=>['Add instruction','add_instruction',[
                    ['application_id','Related application (optional)','applications'],['title','Title','text','',true],['content','Instruction','textarea','Describe the current access procedure, required approval, URLs and support contacts.']]],
            ];
            ?>
            <?php if(isset($forms[$page])): [$heading,$action,$fields]=$forms[$page]; ?>
                <template id="create-<?=$page?>-form">
                    <form method="post">
                        <input type="hidden" name="action" value="<?=$action?>"><input type="hidden" name="page" value="<?=$page?>">
                        <div class="form-grid <?=in_array($page,['newcomers','teams','applications'],true)?'three':''?>">
                        <?php foreach($fields as $field):
                            [$name,$label,$type]=$field;
                            $placeholder=$field[3]??''; $required=$field[4]??false;
                            $value='';
                            $inputId='create-'.$name;
                        ?>
                            <div class="field <?=$type==='textarea' && $page!=='rules'?'full':''?> <?=$type==='checkbox'?'checkbox-field':''?>">
                                <?php if($type==='checkbox'): ?>
                                    <label class="check-label"><input type="checkbox" name="<?=$name?>"> <?=e($label)?></label>
                                <?php else: ?>
                                    <label for="<?=$inputId?>"><?=e($label)?></label>
                                    <?php if(in_array($type,['teams','applications','newcomers'],true)): ?>
                                        <select name="<?=$name?>" id="<?=$inputId?>" <?=$required?'required':''?>>
                                            <?php if(!$required): ?><option value="">Not linked</option><?php endif ?>
                                            <?php $optionsSql = $type === 'newcomers' ? 'SELECT id,full_name AS name FROM newcomers ORDER BY full_name' : 'SELECT id,name FROM '.$type.' ORDER BY name'; ?>
                                            <?php foreach(q($optionsSql)->fetchAll(PDO::FETCH_ASSOC) as $option): ?><option value="<?=$option['id']?>" <?=(string)$value===(string)$option['id']?'selected':''?>><?=e($option['name'])?></option><?php endforeach ?>
                                        </select>
                                    <?php elseif($type==='textarea'): ?>
                                        <textarea name="<?=$name?>" id="<?=$inputId?>" placeholder="<?=e($placeholder)?>"><?=e($value)?></textarea>
                                    <?php else: ?>
                                        <input type="<?=$type?>" name="<?=$name?>" id="<?=$inputId?>" value="<?=e($value)?>" placeholder="<?=e($placeholder)?>" <?=$required?'required':''?>>
                                    <?php endif ?>
                                <?php endif ?>
                            </div>
                        <?php endforeach ?>
                        </div>
                        <div class="actions"><button class="btn"><?=e($heading)?></button><button type="button" class="btn secondary" data-cancel>Cancel</button></div>
                    </form>
                </template>
            <?php endif ?>
            <?php if($page==='requests'): ?>
                <template id="create-requests-form">
                    <form method="post" data-access-request-form>
                        <input type="hidden" name="action" value="add_access_requests_batch"><input type="hidden" name="page" value="requests"><input type="hidden" name="requests_json" value="">
                        <datalist id="request-user-options">
                            <?php foreach(q("SELECT n.id,n.full_name,n.manager,t.id AS team_id,t.name AS team FROM newcomers n JOIN teams t ON t.id=n.team_id WHERE trim(COALESCE(n.email,''))<>'' ORDER BY n.full_name")->fetchAll(PDO::FETCH_ASSOC) as $newcomer): ?><option value="<?=e($newcomer['full_name'])?>" data-user-id="<?=$newcomer['id']?>" data-team="<?=e($newcomer['team'])?>" data-team-id="<?=$newcomer['team_id']?>" data-manager="<?=e($newcomer['manager'])?>"></option><?php endforeach ?>
                        </datalist>
                        <datalist id="request-team-options">
                            <?php foreach(q('SELECT id,name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $team): ?><option value="<?=e($team['name'])?>" data-team-id="<?=$team['id']?>"></option><?php endforeach ?>
                        </datalist>
                        <section class="request-form-section">
                            <div class="form-grid request-user-grid">
                                <div class="field" data-request-known-user>
                                    <label for="request-newcomer">User name</label>
                                    <input type="text" name="user_name" id="request-newcomer" list="request-user-options" data-combobox="request-user-options" placeholder="Select a user" required>
                                </div>
                                <div class="field" data-request-known-team>
                                    <label for="request-known-team">Team</label>
                                    <input type="text" id="request-known-team" disabled>
                                </div>
                                <div class="field" data-request-manual-user hidden>
                                    <label for="request-manual-user">User name</label>
                                    <input type="text" name="manual_user_name" id="request-manual-user" placeholder="First &amp; Last user name">
                                </div>
                                <div class="field checkbox-field">
                                    <label class="check-label"><input type="checkbox" name="existing_user" data-existing-user> <span>The user is not in the list</span></label>
                                </div>
                                <div class="field" data-request-manual-email hidden>
                                    <label for="request-manual-email">User Primark account</label>
                                    <input type="email" name="manual_user_email" id="request-manual-email" placeholder="user@primark.com">
                                </div>
                                <div class="field" data-request-manual-team hidden>
                                    <label for="request-manual-team">Team</label>
                                    <input type="text" name="manual_team_name" id="request-manual-team" list="request-team-options" data-combobox="request-team-options" placeholder="Select a team">
                                </div>
                            </div>
                            <div class="field request-manager-field"><label for="request-manager">Primark manager</label><input type="text" name="manager" id="request-manager" placeholder="Primark manager name"></div>
                        </section>
                        <section class="request-form-section request-form-section--access">
                            <div class="request-applications" data-request-applications><p class="muted">Select a User or Team to load Applications.</p></div>
                            <div class="field full request-fallback-justification"><label for="request-justification">Business justification for added Applications</label><textarea name="justification" id="request-justification" placeholder="Why access is needed when the Application is not in the access matrix"></textarea></div>
                        </section>
                        <div class="actions"><button class="btn">Add applications access request</button><button type="button" class="btn secondary" data-cancel>Cancel</button></div>
                    </form>
                </template>
            <?php endif ?>
            <?php if($page==='newcomers'): ?>
                <template id="newcomer-create-form">
                    <form method="post">
                        <input type="hidden" name="action" value="add_newcomer">
                        <input type="hidden" name="page" value="newcomers">
                        <datalist id="business-unit-options">
                            <?php foreach(['Brand','Buying Office','Central Merchandising','DAJ','Design','Directors and PA','Ethical Trade','General Office','ICT','Merchandising','N/A','Packaging CoE','Payroll','People and Culture','Public Relations','Quality Assurance','SDFM','Sourcing','Store Development','Supply Chain','Training Accounts','Visual Merchandising','Web Team'] as $businessUnit): ?>
                                <option value="<?=e($businessUnit)?>"></option>
                            <?php endforeach ?>
                        </datalist>
                        <datalist id="team-options">
                            <?php foreach(q('SELECT name,primark_manager FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $team): ?>
                                <option value="<?=e($team['name'])?>" data-manager="<?=e($team['primark_manager'])?>"></option>
                            <?php endforeach ?>
                        </datalist>
                        <div class="form-grid three">
                            <div class="field">
                                <label for="new-full-name">Full name</label>
                                <input type="text" name="full_name" id="new-full-name" placeholder="First &amp; Last user name" required>
                            </div>
                            <div class="field">
                                <label for="new-team">Team</label>
                                <input type="text" name="team_name" id="new-team" list="team-options" data-combobox="team-options" placeholder="Select a team" required>
                            </div>
                            <div class="field">
                                <label for="new-business-unit">Business Unit</label>
                                <input type="text" name="business_unit" id="new-business-unit" list="business-unit-options" data-combobox="business-unit-options" placeholder="Select a business unit" required>
                            </div>
                            <div class="field">
                                    <label for="new-manager">Primark manager</label>
                                <input type="text" name="manager" id="new-manager" placeholder="Manager name">
                            </div>
                            <div class="field">
                                <label for="new-department">Department</label>
                                <input type="text" name="department" id="new-department" placeholder="Department">
                            </div>
                            <div class="field">
                                <label for="new-job-title">Job Title</label>
                                <input type="text" name="job_title" id="new-job-title" placeholder="Job title">
                            </div>
                            <div class="field full">
                                <label for="new-comment">Comment</label>
                                <textarea name="comment" id="new-comment" placeholder="Comment"></textarea>
                            </div>
                            <div class="field checkbox-field">
                                <label class="check-label"><input type="checkbox" name="account_requested"> Account requested</label>
                            </div>
                            <div class="field" data-account-request-details hidden>
                                <label for="new-snow-request-number">SNOW Request Number</label>
                                <input type="text" name="snow_request_number" id="new-snow-request-number" placeholder="Request number">
                            </div>
                            <div class="field" data-account-request-details hidden>
                                <label for="new-snow-request-url">SNOW Request URL</label>
                                <input type="url" name="snow_request_url" id="new-snow-request-url" placeholder="https://…">
                            </div>
                        </div>
                        <div class="actions"><button class="btn">Create newcomer</button><button type="button" class="btn secondary" data-cancel>Create later</button></div>
                    </form>
                </template>
            <?php endif ?>
            <?php if($page==='rules'): ?><section class="card coverage-card"><?php renderCoverageMatrix(); ?></section><?php endif ?>
            <section class="card table-card">
                <?php if($page==='requests'): ?><p class="table-help">Open a request to update approval, ServiceNow details, access status and comments.</p><?php endif ?>
                <?php renderTable($page); ?>
            </section>
        <?php endif ?>
    </main>
</div>
</body>
</html>
