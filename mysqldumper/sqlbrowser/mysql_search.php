<?php
//alle Tabellen der aktuellen Datenbank ermitteln und Zugriffs-Array aufbauen
$sql='SHOW TABLES FROM `'.$db.'`';
$tables=ARRAY();
$link=MSD_mysql_connect();
$res=mysql_query($sql,$link);
if (!$res===false)
{
	WHILE ($row=mysql_fetch_array($res,MYSQL_NUM))
	{
		$tables[]=$row[0];
	}
}
else die("No Tables in Database!");

// Suchkriterien aus Session holen oder aus POST-Umgebung
// so bleiben die Suchkriterien auch erhalten wenn man zwischendurch woanders klickt
if (isset($_POST['suchbegriffe'])) $_SESSION['mysql_search']['suchbegriffe']=$_POST['suchbegriffe'];
if (!isset($_SESSION['mysql_search']['suchbegriffe'])) $_SESSION['mysql_search']['suchbegriffe']='';
$suchbegriffe=$_SESSION['mysql_search']['suchbegriffe'];

if (isset($_POST['suchart'])) $_SESSION['mysql_search']['suchart']=$_POST['suchart'];
if (!isset($_SESSION['mysql_search']['suchart'])) $_SESSION['mysql_search']['suchart']='AND';
$suchart=$_SESSION['mysql_search']['suchart'];

if (isset($_POST['table_selected'])) $_SESSION['mysql_search']['table_selected']=$_POST['table_selected'];
if (!isset($_SESSION['mysql_search']['table_selected'])) $_SESSION['mysql_search']['table_selected']=0;
$table_selected=$_SESSION['mysql_search']['table_selected'];
// Falls zwischendurch Tabellen geloescht wurden und der Index nicht mehr existiert, zuruecksetzen
if ($table_selected>count($tables)-1) $table_selected=0;

$offset=(isset($_POST['offset'])) ? intval($_POST['offset']):0;

function mysql_search($db,$tabelle,$suchbegriffe,$suchart,$offset=0,$anzahl_ergebnisse=20,$auszuschliessende_tabellen='')
{
	global $tables,$config;
	$ret=false;
	$link=MSD_mysql_connect();
	if (sizeof($tables)>0)
	{
		$suchbegriffe=trim(str_replace('*','',$suchbegriffe));
		$suchworte=explode(' ',$suchbegriffe);
		if (($suchbegriffe>'') && (is_array($suchworte)) )
		{
			// Leere Einträge (durch doppelte Leerzeichen) entfernen
			$anzahl_suchworte=sizeof($suchworte);
			for ($i=0;$i<$anzahl_suchworte;$i++)
			{
				if (trim($suchworte[$i])=='') unset($suchworte[$i]);
			}

			$bedingung='';
			$where='';
			$felder='';

			// Felder ermitteln
			$sql='SHOW COLUMNS FROM `'.$db.'`.`'.$tables[$tabelle].'`';
			$res=mysql_query($sql,$link);
			unset($felder);
			if (!$res===false)
			{
				// Felder der Tabelle ermitteln
				WHILE ($row=mysql_fetch_object($res))
				{
					$felder[]=$row->Field;
				}
			}

			$feldbedingung='';
			if ($suchart=='CONCAT')
			{
				if (is_array($felder))
				{
					//Concat-String bildem
					$concat=implode('`),LOWER(`',$felder);
					$concat='CONCAT_WS(\'\',LOWER(`'.$concat.'`))';
					$where='';
					foreach ($suchworte as $suchbegriff)
					{
						$where.=$concat.' LIKE \'%'.strtolower($suchbegriff).'%\' AND ';
					}
					$where=substr($where,0,-4); // letztes AND entfernen
					$sql='SELECT * FROM `'.$db.'`.`'.$tables[$tabelle].'` WHERE '.$where.' LIMIT '.$offset.','.$anzahl_ergebnisse;
				}
			}
			else
			{
				$pattern='`{FELD}` LIKE \'%{SUCHBEGRIFF}%\'';
				if (is_array($felder))
				{
					foreach ($felder as $feld)
					{
						unset($feldbedingung);
						foreach ($suchworte as $suchbegriff)
						{
							$suchen=ARRAY('{FELD}','{SUCHBEGRIFF}');
							$ersetzen=ARRAY($feld,$suchbegriff);
							$feldbedingung[]=str_replace($suchen,$ersetzen,$pattern);
						}
						$bedingung[]='('.implode(' '.$suchart.' ',$feldbedingung).') ';
					}
				}
				else die('<br>Fehler: ich konnte nicht ermitteln welche Felder die Tabelle "'.$tabelle.'" hat!');
				$where=implode(' OR ',$bedingung);
				$sql='SELECT * FROM `'.$db.'`.`'.$tables[$tabelle].'` WHERE ('.$where.') LIMIT '.$offset.','.$anzahl_ergebnisse;
			}
		}
		else $sql='SELECT * FROM `'.$db.'`.`'.$tables[$tabelle].'` LIMIT '.$offset.','.$anzahl_ergebnisse;

		$res=@mysql_query($sql,$link);
		if ($res)
		{
			WHILE ($row=mysql_fetch_array($res,MYSQL_ASSOC))
			{
				//Treffer markieren
				foreach ($row as $key=>$val)
				{
					foreach ($suchworte as $suchbegriff)
					{
						$row[$key]=markiere_suchtreffer($suchbegriff,$row[$key]);
					}
					$row[$key]=ersetze_suchtreffer($row[$key]);
				}
				$ret[]=$row;
			}
		}
	}
	return $ret;
}

// Markiert den Suchbegriff mit einem Code (ASCII 01/02)
// - falls nicht gefunden : Rückgabe des Originalstrings
//
function markiere_suchtreffer($suchbegriff,$suchstring)
{
	$str=strtolower($suchstring);
	$suchbegriff=strtolower($suchbegriff);
	if ((strlen($str)>0) && (strlen($suchbegriff)>0) )
	{
		// Treffer Position bestimmen
		$offset=0;$trefferpos=0;
		while ( ($offset<=strlen($str)) )
		//Wenn nur der erste Treffer markiert werden soll, so muss die Zeile so lauten
		// 		while ( ($offset<=strlen($str)) || ($in_html==false) )
		{
			for ($offset=$trefferpos;$offset<=strlen($str);$offset++)
			{
				$start=strpos($str,$suchbegriff,$offset);
				if ($start===false) $offset=strlen($str)+1;
				else
				{
					if ($offset<=strlen($str))
					{
						//Treffer überprüfen
						$in_html=false;
						// Steht die Fundstelle zwischen < und > (also im HTML-Tag) ?
						for ($position=$start;$position>=0;$position--)
						{
							if (substr($str,$position,1)==">")
							{
								$in_html=false;
								$position=-1; // Schleife verlassen
							}
							if (substr($str,$position,1)=="<")
							{
								$in_html=true;
								$position=-1; // Schleife verlassen
							}
						}
						if ($in_html)
						{
							for ($position2=$start;$position2<strlen($str);$position2++)
							{
								if (substr($str,$position2,1)=="<")	{ $position2=strlen($str)+1; }
								if (substr($str,$position2,1)==">")
								{
									$in_html=true;$position2=strlen($str)+1;
									$offset=strlen($str)+1;
								}
							}
						}
						if (!$in_html)
						{
							$ersetzen=substr($suchstring,$start,strlen($suchbegriff));
							$str=substr($suchstring,0,$start);
							$str.=chr(1).$ersetzen.chr(2);
							$str.=substr($suchstring,($start+strlen($ersetzen)),(strlen($suchstring)-strlen($ersetzen)));
							$suchstring=$str;
						}
						if ($in_html) { $trefferpos=$start+1;$offset=$trefferpos; }
					}
					$offset=$start+1;
				}
			}
		}
	}
	return $suchstring;
}

// Ersetzt die Codes letztlich durch die Fontangabe
function ersetze_suchtreffer($text)
{
	$such=ARRAY(chr(1),chr(2));
	$ersetzen=ARRAY('<span class="treffer">','</span>');
	return str_replace($such,$ersetzen,htmlspecialchars($text));
}

$suchbegriffe=trim($suchbegriffe); // Leerzeichen vorne und hinten wegschneiden
if (isset($_POST['reset']))
{
	$suchbegriffe='';
	$_SESSION['mysql_search']['suchbegriffe']='';
	$suchart='';
	$_SESSION['mysql_search']['suchart']='';
	$table_selected=0;
	$_SESSION['mysql_search']['table_selected']=0;
}
$max_treffer=20;
$treffer=mysql_search($db,$table_selected,$suchbegriffe,$suchart,$offset,$max_treffer+1);
$showtables=0; // Anzeige der Tabellendaten im restlichen SQL-Browser ausschalten

// Fix bis zur kompletten Umstellung auf Templates
echo $aus; $aus='';

$anzahl_tabellen=sizeof($tables);
$table_options='';
if ($anzahl_tabellen>0)
{
	for ($i=0;$i<$anzahl_tabellen;$i++)
	{
		if (isset($tables[$i]))
		{
			$table_options.='<option value="'.$i.'"';
			if ($i==$table_selected) $table_options.=' selected';
			$table_options.='>'.$tables[$i].'</option>'."\n";
		}
	}
}

$tpl=new MSDTemplate;
$tpl->set_filenames(array('show' => './sqlbrowser/templates/mysql_search.tpl'));

$tpl->assign_vars(array(
	'LANG_SQLSEARCH' => $lang['sql_search'],
	'LANG_SQL_SEARCHWORDS' => $lang['sql_searchwords'],
	'SUCHBEGRIFFE' => $suchbegriffe,
	'LANG_START_SQLSEARCH' => $lang['start_sql_search'],
	'LANG_RESET_SEARCHWORDS' => $lang['reset_searchwords'],
	'LANG_SEARCH_OPTIONS' => $lang['search_options'],
	'AND_SEARCH' => $suchart=='AND' ? ' checked':'',
	'OR_SEARCH' => $suchart=='OR' ? ' checked':'',
	'CONCAT_SEARCH' => $suchart=='CONCAT' ? ' checked':'',
	'TABLE_OPTIONS' => $table_options,
	'LANG_SEARCH_OPTIONS_AND' => $lang['search_options_and'],
	'LANG_SEARCH_OPTIONS_OR' => $lang['search_options_or'],
	'LANG_SEARCH_OPTIONS_CONCAT' => $lang['search_options_concat'],
	'LANG_SEARCH_IN_TABLE' => $lang['search_in_table']
));


if (is_array($treffer) && isset($treffer[0]))
{
	$search_message=sprintf($lang['search_results'],$suchbegriffe,$tables[$table_selected]);
	$anzahl_treffer=count($treffer);
	// Blaettern-Buttons
	$tpl->assign_block_vars('HITS',array(
		'LANG_SEARCH_RESULTS' => $search_message,
		'LAST_OFFSET' => $offset-$max_treffer,
		'BACK_BUTTON_DISABLED' => $offset>0 ? '':' disabled',
		'NEXT_OFFSET' => $offset+$max_treffer,
		'NEXT_BUTTON_DISABLED' => ($anzahl_treffer!=$max_treffer+1) ? ' disabled':'',
		'LANG_ACCESS_KEYS' => $lang['search_access_keys']
	));

	// Ausgabe der Treffertabelle
	$anzahl_felder=sizeof($treffer[0]);

	// Ausgabe der Tabellenueberschrift/ Feldnamen
	foreach ($treffer[0] as $key=>$val)
	{
		$tpl->assign_block_vars('HITS.TABLEHEAD',array('KEY'=>$key));
	}

	// Ausgabe der Daten
	$zeige_treffer=sizeof($treffer);
	if ($zeige_treffer==$max_treffer+1) $zeige_treffer=$max_treffer;
	for ($a=0;$a<$zeige_treffer;$a++)
	{
		$tpl->assign_block_vars('HITS.TABLEROW',array(
			'CLASS'=>($a % 2) ? 'dbrow':'dbrow1',
			'NR' => $a+$offset+1));

		foreach ($treffer[$a] as $key=>$val)
		{
			if ($val=='') $val="&nbsp;";
			$tpl->assign_block_vars('HITS.TABLEROW.TABLEDATA',array('VAL' => $val));
		}
	}
}
else
{
	if (!isset($tables[$table_selected])) $tables[$table_selected]='';
	if ($suchbegriffe=='') $tpl->assign_block_vars('NO_ENTRIES',array('LANG_NO_ENTRIES' => sprintf($lang['no_entries'],$tables[$table_selected])));
	else $tpl->assign_block_vars('NO_RESULTS',array('LANG_SEARCH_NO_RESULTS' => sprintf($lang['search_no_results'],$suchbegriffe,$tables[$table_selected])));
}

$tpl->pparse('show');
?>
