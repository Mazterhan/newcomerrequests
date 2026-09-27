<?php
/** One-time import of the team directory from Primark.xlsx / Access. */
declare(strict_types=1);
$db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/portal.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$teams = [
    'Readiness' => 'Kyrylo Starchenko',
    'Mobile Manual' => 'Yenlik Koneyeva',
    'Automation Web' => 'Artem Ostrolutskyi',
    'Automation Mobile' => 'Ostap-Tadei Demchyshyn',
    'Performance' => 'Hanna Zhuk1',
    'Checkout' => 'Aliaksandr Belavus',
    'CSC' => 'Vishal Gopalrao Korade',
    'GTP' => 'Nataliia Etkalo',
    'MKA' => 'Yuliia Smirnova',
    'OMS' => 'Oleksandr Shepyk',
    'Product Model' => 'Serhii Blynov',
    'PCM' => 'Mykhailo Spilnyk',
];
$add = $db->prepare('INSERT INTO teams(name,lead) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET lead=excluded.lead');
$count = 0;
foreach ($teams as $name => $lead) { $add->execute([$name, $lead]); $count += $add->rowCount(); }
echo "Added $count team(s).\n";
