<?php
declare(strict_types=1);

$db = new PDO('sqlite:' . __DIR__ . '/../storage/portal.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$applications = [
    'AMPLIANCE (CMS)' => ['Jayne Bibby — jbibby@primark.co.uk', 'Zak Crosby-Browne — zbrowne@primark.co.uk', 0, 'GG_Amplience_All_Users', "SNOW + Amplience hubs adding\nAs we have no free licenses, the only option is to switch between users"],
    'Atlassian (Jira/Confluence)' => ['No', '', 1, '', 'If some particular groups are necessary contact Mounika'],
    'Azure (Blob Storage, Service Bus, Function App, Cosmos DB, Storage Account)' => ['No', '', 1, 'AG_PMK_OSCAR_QA', 'SNOW'],
    'Azure DEV' => ['No', '', 1, 'AG_PMK_OSCAR_DEVELOPERS', 'SNOW'],
    'Bloomreach' => ['scott.carson@Primark.onmicrosoft.com', '', 1, 'AG_PMK_Bloomreach_Users_SSO', ''],
    'Browser Stack' => ['No', '', 1, 'AG_PMK_BrowserStack_Users_SSO', 'SNOW + user invite'],
    'Commerce Tools' => ['Humphrey Rose — hrose@primark.co.uk', 'Zak Crosby-Browne — zbrowne@primark.co.uk', 1, 'AG_PMK_CommercetoolsUnified_Users_SSO', ''],
    'Dynamic Yield' => ['Jamie Brown — jamie.brown@primark.com', 'Annette Rowson — arowson@primark.co.uk', 1, 'AG_PMK_Dynamic_Yield_PROD_USERS_SSO', 'SNOW + user invite'],
    'Figma' => ['pbhaakhariya@primark.com', 'psharma@primark.co.uk', 0, '', 'Access is granted by Puja through email request'],
    'Fluiid4' => ['Zak Crosby-Browne — zbrowne@primark.co.uk', '', 0, 'AG_PMK_Fluiid4_UAT_SSO', 'SNOW + user invite'],
    'Level Access' => ['No', '', 0, '', ''],
    'New Relic' => ['No', '', 1, 'AG_PMK_NewRelic_BasicUser_SSO', 'SNOW'],
    'OMS (Order Management System) Fluent Commerce' => ['No', '', 1, 'AG_PMK_FluentCommerce_UAT2_SSO', ''],
    'Oracle Financials ORTCS1' => ['', '', 0, '', ''],
    'Oracle Retail Stack (Includes: RMS, RESA, IDW, CPM, FFE, REIM, ESB, RPM, PSM)' => ['TCS', '', 0, '', 'TCS Team Request'],
    'PCM (Strelka/Leika)' => ['Zak Crosby-Browne — zbrowne@primark.co.uk', '', 1, 'AG_PMK_PCM_Copywriters_SSO', 'SNOW'],
    "Payment Layer (Barcley's Portal)" => ['', '', 0, '', ''],
    'Postman' => ['', '', 0, '', ''],
    'Salesforce Marketing Cloud' => ['Elizabeth Richards — erichards@primark.co.uk', '', 0, '', ''],
    'Salesforce Service Cloud' => ['Anna Bolger — abolger@primark.ie', 'Lisa Fox — lfox@primark.ie', 0, '', ''],
    'Snyk' => ['', '', 1, 'AG_PMK_SNYK_USERS_SSO', 'SNOW + email to owners'],
    'SonarCloud' => ['', '', 0, '', ''],
    'Tableau' => ['', '', 0, '', ''],
    'Teradata (IDW)' => ['', '', 0, '', ''],
    'Yext' => ['', '', 0, '', ''],
    'AVD' => ['', '', 0, '', ''],
    'ProductsUp' => ['', '', 0, '', ''],
];

$renames = [
    'Amplience' => 'AMPLIANCE (CMS)',
    'Azure DevOps' => 'Azure DEV',
    'OMS (Fluent Commerce)' => 'OMS (Order Management System) Fluent Commerce',
    'Oracle Retail Stack (Includes: RMS, RESA, IDW, CPM, FFE, REIM, ESB, RPM, PSM)' => 'Oracle Retail Stack (Includes: RMS, RESA, IDW, CPM, FFE, REIM, ESB, RPM, PSM)',
    'PCM Leika/Strelka' => 'PCM (Strelka/Leika)',
    'Salesforce' => 'Salesforce Marketing Cloud',
    'SNYK' => 'Snyk',
];

$db->beginTransaction();
try {
    $findId = $db->prepare('SELECT id FROM applications WHERE name=?');
    $rename = $db->prepare('UPDATE applications SET name=? WHERE name=?');
    foreach ($renames as $from => $to) {
        $findId->execute([$from]);
        if ($findId->fetchColumn()) $rename->execute([$to, $from]);
    }

    // ESB is part of the Oracle Retail Stack in the source data. Preserve its one matrix rule.
    $findId->execute(['Oracle Retail Stack (Includes: RMS, RESA, IDW, CPM, FFE, REIM, ESB, RPM, PSM)']);
    $oracleRetailId = (int)$findId->fetchColumn();
    $findId->execute(['ESB Database']);
    $esbId = (int)$findId->fetchColumn();
    if ($esbId && $oracleRetailId) {
        $rules = $db->prepare('SELECT id,team_id FROM access_rules WHERE application_id=?');
        $rules->execute([$esbId]);
        $exists = $db->prepare('SELECT id FROM access_rules WHERE team_id=? AND application_id=?');
        $move = $db->prepare('UPDATE access_rules SET application_id=? WHERE id=?');
        foreach ($rules->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $exists->execute([(int)$rule['team_id'], $oracleRetailId]);
            if (!$exists->fetchColumn()) $move->execute([$oracleRetailId, (int)$rule['id']]);
        }
    }
    $delete = $db->prepare('DELETE FROM applications WHERE name=?');
    foreach (['ESB Database', 'Oracle', 'Oracle Financials (OF) OTE'] as $name) $delete->execute([$name]);

    $upsert = $db->prepare('INSERT INTO applications(name,approver,backup_approver,levy_copy,ad_group,comment,enabled) VALUES(?,?,?,?,?,?,1) ON CONFLICT(name) DO UPDATE SET approver=excluded.approver,backup_approver=excluded.backup_approver,levy_copy=excluded.levy_copy,ad_group=excluded.ad_group,comment=excluded.comment,enabled=1');
    foreach ($applications as $name => [$approver,$backup,$levy,$adGroup,$comment]) {
        $upsert->execute([$name,$approver,$backup,$levy,$adGroup,$comment]);
    }
    $db->commit();
} catch (Throwable $exception) {
    $db->rollBack();
    throw $exception;
}

echo 'Applications synchronised: ' . count($applications) . PHP_EOL;
