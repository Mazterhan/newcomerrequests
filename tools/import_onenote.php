<?php
/** One-time migration utility. It is not used by the web application at runtime. */
declare(strict_types=1);
$root = dirname(__DIR__);
$source = dirname($root) . '/Primark KT.one';
$db = new PDO('sqlite:' . $root . '/storage/portal.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$raw = file_get_contents($source);
if ($raw === false) die("OneNote source cannot be read.\n");
$titles = ['Teradata (IDW)','ESB Database','Browser Stack','Primark Log In instruction','Salesforce','Postman','Azure DEV','Bloomreach','OMS (Fluent Commerce)','SNYK','PCM Leika/Strelka','Sonar Cloud','Yext','Oracle','Dynamic Yield','Amplience','Figma','New Relic','Fluiid4','Commerce Tools','Atlassian (Jira/Confluence)'];
$signature = hex2bin('F31C001C301C001CFF1D0014821D00148B340014651C001862340088');
$pages = [];
foreach ($titles as $title) {
    $needle = iconv('UTF-8', 'UTF-16LE', $title);
    $at = 0;
    while (($pos = strpos($raw, $needle, $at)) !== false) {
        $before = substr($raw, max(0, $pos - 64), 64);
        if ($pos > 0x10000 && str_contains($before, $signature)) { $pages[] = ['offset'=>$pos, 'title'=>$title]; break; }
        $at = $pos + 2;
    }
}
usort($pages, fn($a,$b) => $a['offset'] <=> $b['offset']);
$skip = '/^(PageTitle|PageDateTime|Arial|Calibri|symbol|blockquote|cite|code|user|<.*|HYPERLINK)/i';
foreach ($pages as $i => $page) {
    $end = $pages[$i + 1]['offset'] ?? strlen($raw);
    $chunk = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, $page['offset'], $end - $page['offset']));
    preg_match_all('/[\x20-\x7E]{3,}/', $chunk, $matches);
    $lines = [];
    foreach ($matches[0] as $line) {
        $line = trim($line);
        if ($line === $page['title'] || preg_match($skip, $line) || isset($lines[$line])) continue;
        $lines[$line] = true;
    }
    $content = implode("\n\n", array_keys($lines));
    $stmt = $db->prepare('UPDATE instructions SET content=? WHERE title=?');
    $stmt->execute([$content ?: 'No readable textual steps were found in the OneNote source.', $page['title']]);
    printf("%s: %d text fragments migrated\n", $page['title'], count($lines));
}
