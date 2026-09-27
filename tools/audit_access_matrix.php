<?php
declare(strict_types=1);
$root=dirname(__DIR__); $book=dirname($root).'/Primark.xlsx';
$zip=new ZipArchive(); $zip->open($book);
$xml=fn(string $name)=>simplexml_load_string($zip->getFromName($name));
$shared=[]; foreach($xml('xl/sharedStrings.xml')->si as $si) { $parts=[]; foreach($si->r as $run)$parts[]=(string)$run->t; $shared[]=(string)$si->t ?: implode('',$parts); }
$sheet=$xml('xl/worksheets/sheet1.xml'); $rows=[];
foreach($sheet->sheetData->row as $row){$cells=[];foreach($row->c as $c){preg_match('/^[A-Z]+/',(string)$c['r'],$m);$v=(string)$c->v;$cells[$m[0]]=(string)$c['t']==='s'?($shared[(int)$v]??''):$v;} $rows[(int)$row['r']]=$cells;}
$teams=[];foreach($rows[1] as $col=>$value)if($col>='C'&&$value!=='')$teams[$col]=trim($value);
$meta=['Contacts','Mirror ID','Business Justification'];$apps=[];$current=null;
foreach($rows as $number=>$cells){$label=trim($cells['A']??'');if($number<3)continue;if($label!==''&&!in_array($label,$meta,true)){$current=$label;$apps[$current]=[];continue;}if($current===null||!in_array($label,['Mirror ID','Business Justification'],true))continue;foreach($teams as $col=>$team){$value=trim($cells[$col]??'');if($value!=='')$apps[$current][$team][$label]=$value;}}
$db=new PDO('sqlite:'.$root.'/storage/portal.sqlite');$actual=$db->query('SELECT t.name team,a.name app FROM access_rules r JOIN teams t ON t.id=r.team_id JOIN applications a ON a.id=r.application_id')->fetchAll(PDO::FETCH_ASSOC);$keys=[];foreach($actual as $r)$keys[strtolower($r['team']).'|'.strtolower($r['app'])]=true;
$aliases=['ampliance (cms)'=>'amplience','oms (order management system) fluent commerce'=>'oms (fluent commerce)','pcm (strelka/leika)'=>'pcm leika/strelka','snyk'=>'snyk','sonarcloud'=>'sonar cloud'];
$expected=[];foreach($apps as $app=>$forTeams){$normalized=$aliases[strtolower($app)]??$app;foreach($forTeams as $team=>$data)$expected[]=['team'=>$team,'app'=>$app,'normalized'=>$normalized,'mirror'=>$data['Mirror ID']??'','justification'=>$data['Business Justification']??''];}
$missing=[];foreach($expected as $r)if(!isset($keys[strtolower($r['team']).'|'.strtolower($r['normalized'])]))$missing[]=$r;
echo 'Excel access rules: '.count($expected).PHP_EOL;echo 'Portal access rules: '.count($actual).PHP_EOL;echo 'Missing rules: '.count($missing).PHP_EOL.PHP_EOL;foreach($missing as $r)echo $r['team'].' | '.$r['app'].PHP_EOL;
