<?php
 /******************************************************************************
 *
 * membernumber.php
 *
 * Dieses Plugin generiert für jedes aktive und ehemalige Mitglied eine
 * Mitgliedsnummer. 
 * 
 * Die erzeugten Mitgliedsnummern sind numerisch.
 * Begonnen wird bei der Zahl 1. 
 * Freie Nummern von geloeschten Mitgliedern werden wiederverwendet.
 *
 * Copyright    : (c) 2004 - 2015 The Admidio Team
 * Homepage     : http://www.admidio.org
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 * 
 *****************************************************************************/

require_once(substr(__FILE__, 0,strpos(__FILE__, 'adm_plugins')-1).'/adm_program/system/common.php');
require_once(dirname(__FILE__).'/common_function.php');

$members = array();
$message = '';

//prüfen, ob doppelte Mitgliedsnummern bestehen
$nummer = erzeuge_mitgliedsnummer();
 
// alle mitglieder abfragen
$sql = ' SELECT mem_usr_id
         FROM '.TBL_MEMBERS.' ';
$result = $gDb->query($sql);

while($row = $gDb->fetch_array($result))
{
	$members[$row['mem_usr_id']] = array();
}

// die IDs der Attribute aus der Datenbank herausssuchen
$attributes = array('SYS_LASTNAME' => 0, 'SYS_FIRSTNAME' => 0, 'PMB_MEMBERNUMBER' => 0);
foreach($attributes as $attribute => $dummy) 
{
    $sql = ' SELECT usf_id
             FROM '.TBL_USER_FIELDS.'
             WHERE usf_name = \''.$attribute.'\' ';
	$result = $gDb->query($sql);
	$row = $gDb->fetch_array($result);
	$attributes[$attribute] = $row['usf_id'];
}

// Die Daten jedes Mitglieds abfragen und in das Array schreiben
foreach ($members as $member => $key)
{ 	
	foreach ($attributes as $attribute => $usf_id) 
    {
        $sql = 'SELECT usd_value
                FROM '.TBL_USER_DATA.'
                WHERE usd_usr_id = \''.$member.'\'
                AND usd_usf_id = \''.$usf_id.'\' ';
		$result = $gDb->query($sql);
		$row = $gDb->fetch_array($result);
		$members[$member][$attribute] = $row['usd_value'];
	}    
}
   
//alle Mitglieder durchlaufen und prüfen, ob eine Mitgliedsnummer existiert       
 foreach ($members as $member => $key)
{ 
	if (($members[$member]['PMB_MEMBERNUMBER'] == '') || ($members[$member]['PMB_MEMBERNUMBER'] < 1))
	{
		$nummer = erzeuge_mitgliedsnummer();
		
		$user = new User($gDb, $gProfileFields, $member);
    	$user->setValue('MEMBERNUMBER', $nummer);
    	$user->save();
    	
    	$message .= $gL10n->get('PMB_MEMBERNUMBER_RES1',$members[$member]['SYS_FIRSTNAME'],$members[$member]['SYS_LASTNAME'],$nummer);
	}
}

// set headline of the script
$headline = $gL10n->get('PMB_PRODUCE_MEMBERNUMBER');

// create html page object
$page = new HtmlPage($headline);

$form = new HtmlForm('membernumber_form', null, $page); 

// Message ausgeben (wenn keinem Mitglied eine Mitgliedsnummer zugewiesen wurde, dann ist die Variable leer)
if ($message == '')
{
    $form->addDescription($gL10n->get('PMB_MEMBERNUMBER_RES2'));
}
else
{
    $form->addDescription($message);
}

$form->addButton('next_page', $gL10n->get('SYS_NEXT'), array('icon' => THEME_PATH.'/icons/forward.png', 'link' => 'menue.php?show_option=producemembernumber', 'class' => 'btn-primary'));

$page->addHtml($form->show(false));
$page->show();


?>