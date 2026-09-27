<?php
/** One-time import from Primark.xlsx / Access into the portal's independent database. */
declare(strict_types=1);
$root=dirname(__DIR__); $book=dirname($root).'/Primark.xlsx';
$zip=new ZipArchive(); if($zip->open($book)!==true) die("Cannot open workbook.\n");
$xml=fn(string $name)=>simplexml_load_string($zip->getFromName($name));
$shared=[]; foreach($xml('xl/sharedStrings.xml')->si as $si){$parts=[];foreach($si->r as $run)$parts[]=(string)$run->t;$shared[]=(string)$si->t?:implode('',$parts);}
$sheet=$xml('xl/worksheets/sheet1.xml');$rows=[];
foreach($sheet->sheetData->row as $row){$cells=[];foreach($row->c as $c){preg_match('/^[A-Z]+/',(string)$c['r'],$m);$v=(string)$c->v;$cells[$m[0]]=(string)$c['t']==='s'?($shared[(int)$v]??''):$v;}$rows[(int)$row['r']]=$cells;}
$teams=[];foreach($rows[1] as $column=>$value)if($column>='C'&&trim($value)!=='')$teams[$column]=trim($value);
$aliases=['ampliance (cms)'=>'Amplience','oms (order management system) fluent commerce'=>'OMS (Fluent Commerce)','pcm (strelka/leika)'=>'PCM Leika/Strelka'];
$meta=['Contacts','Mirror ID','Business Justification'];$apps=[];$current=null;
foreach($rows as $number=>$cells){$label=trim($cells['A']??'');if($number<3)continue;if($label!==''&&!in_array($label,$meta,true)){$current=$label;$apps[$current]=['comment'=>trim($cells['B']??''),'teams'=>[]];continue;}if($current===null||!in_array($label,['Mirror ID','Business Justification'],true))continue;foreach($teams as $column=>$team){$value=trim($cells[$column]??'');if($value!=='')$apps[$current]['teams'][$team][$label]=$value;}}
$db=new PDO('sqlite:'.$root.'/storage/portal.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->beginTransaction();
$findTeam=$db->prepare('SELECT id FROM teams WHERE name=?');$addTeam=$db->prepare('INSERT INTO teams(name) VALUES(?)');
$findApp=$db->prepare('SELECT id FROM applications WHERE name=?');$addApp=$db->prepare('INSERT INTO applications(name,comment) VALUES(?,?)');
$upsert=$db->prepare('INSERT INTO access_rules(team_id,application_id,mirror_id,justification) VALUES(?,?,?,?) ON CONFLICT(team_id,application_id) DO UPDATE SET mirror_id=excluded.mirror_id,justification=excluded.justification');
$teamIds=[];$appIds=[];$applicationsCreated=0;$rules=0;
foreach($apps as $sourceName=>$data){if(!$data['teams'])continue;$name=$aliases[strtolower($sourceName)]??$sourceName;$findApp->execute([$name]);$appId=$findApp->fetchColumn();if(!$appId){$addApp->execute([$name,$data['comment']]);$appId=(int)$db->lastInsertId();$applicationsCreated++;}$appIds[$name]=$appId;
 foreach($data['teams'] as $teamName=>$fields){if(!isset($teamIds[$teamName])){$findTeam->execute([$teamName]);$teamId=$findTeam->fetchColumn();if(!$teamId){$addTeam->execute([$teamName]);$teamId=(int)$db->lastInsertId();}$teamIds[$teamName]=$teamId;}$upsert->execute([$teamIds[$teamName],$appId,$fields['Mirror ID']??'',$fields['Business Justification']??'']);$rules++;}}
$db->commit();$zip->close();echo "Imported $rules Excel rule(s); created $applicationsCreated application(s).\n";
