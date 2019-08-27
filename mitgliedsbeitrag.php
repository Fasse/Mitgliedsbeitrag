<?php
/**
 ***********************************************************************************************
 * Mitgliedsbeitrag
 *
 * Version 4.3.3
 *
 * Dieses Plugin berechnet Mitgliedsbeitraege anhand von Rollenzugehoerigkeiten.
 *
 * Author: rmb
 *
 * Compatible with Admidio version 3.3
 *
 * @copyright 2004-2019 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

//Fehlermeldungen anzeigen
//error_reporting(E_ALL);

require_once(__DIR__ . '/../../adm_program/system/common.php');
require_once(__DIR__ . '/../../adm_program/system/login_valid.php');
require_once(__DIR__ . '/common_function.php');
require_once(__DIR__ . '/classes/configtable.php');

//script_name ist der Name wie er im Menue eingetragen werden muss, also ohne evtl. vorgelagerte Ordner wie z.B. /playground/adm_plugins/mitgliedsbeitrag...
$_SESSION['pMembershipFee']['script_name'] = substr($_SERVER['SCRIPT_NAME'], strpos($_SERVER['SCRIPT_NAME'], FOLDER_PLUGINS));

// only authorized user are allowed to start this module
if (!isUserAuthorized($_SESSION['pMembershipFee']['script_name']))
{
	$gMessage->show($gL10n->get('SYS_NO_RIGHTS'));
}

// Initialize and check the parameters
$showOption = admFuncVariableIsValid($_GET, 'show_option', 'string');

$pPreferences = new ConfigTablePMB();
$checked = $pPreferences->checkforupdate();

if ($checked == 1)        //Update (Konfigurationdaten sind vorhanden, der Stand ist aber unterschiedlich zur Version.php)
{
	$pPreferences->init();
}
elseif ($checked == 2)        //Installationsroutine durchlaufen
{
    $pPreferences->init();      //Konfigurationstabelle anlegen (vor dem weiteren Installationsprozedere)
	admRedirect(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/'.'installation.php');
}

$pPreferences->read();            // (checked == 0) : nur Einlesen der Konfigurationsdaten

$duedates = array();
$directdebittype = false;
$duedatecount = 0;
$paidcount = 0;

//alle Mitglieder einlesen
$members = list_members(array('DUEDATE'.ORG_ID, 'SEQUENCETYPE'.ORG_ID, 'CONTRIBUTORY_TEXT'.ORG_ID, 'PAID'.ORG_ID, 'FEE'.ORG_ID, 'MANDATEID'.ORG_ID, 'MANDATEDATE'.ORG_ID, 'IBAN', 'BIC'), 0);

//jetzt wird gezaehlt
foreach ($members as $member => $memberdata)
{
    //alle Faelligkeitsdaten einlesen
    if (!empty($memberdata['DUEDATE'.ORG_ID])
    	&& !empty($memberdata['FEE'.ORG_ID])
        && empty($memberdata['PAID'.ORG_ID])
        && !empty($memberdata['CONTRIBUTORY_TEXT'.ORG_ID])
        && !empty($memberdata['IBAN']))
    {
        $directdebittype = true;

        if(!isset($duedates[$memberdata['DUEDATE'.ORG_ID]]))
        {
            $duedates[$memberdata['DUEDATE'.ORG_ID]] = array();
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['FNAL'] = 0;
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['RCUR'] = 0;
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['OOFF'] = 0;
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['FRST'] = 0;
        }

        if($memberdata['SEQUENCETYPE'.ORG_ID] == 'FNAL')
        {
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['FNAL']++;
        }
        elseif($memberdata['SEQUENCETYPE'.ORG_ID] == 'RCUR')
        {
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['RCUR']++;
        }
        elseif($memberdata['SEQUENCETYPE'.ORG_ID] == 'OOFF')
        {
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['OOFF']++;
        }
        else
        {
            $duedates[$memberdata['DUEDATE'.ORG_ID]]['FRST']++;
        }
    }
    if (!empty($memberdata['DUEDATE'.ORG_ID]))
    {
    	$duedatecount++;
    }
    if (!empty($memberdata['PAID'.ORG_ID]))
    {
        $paidcount++;
    }
}
unset($members);

$beitrag = analyse_mem();
$sum = 0;

$rols = beitragsrollen_einlesen();
$sortArray = array();
$selectBoxEntriesBeitragsrollen = array();

foreach ($rols as $key => $data)
{
    $selectBoxEntriesBeitragsrollen[$key] = array($key, $data['rolle'], expand_rollentyp($data['rollentyp']));
    $sortArray[$key] = expand_rollentyp($data['rollentyp']);
}

array_multisort($sortArray, SORT_ASC, $selectBoxEntriesBeitragsrollen);
$selectBoxEntriesAlleRollen = 'SELECT rol_id, rol_name, cat_name
          						 FROM '.TBL_ROLES.'
    					   INNER JOIN '.TBL_CATEGORIES.'
                                   ON cat_id = rol_cat_id
                                WHERE rol_valid   = 1
                                  AND (  cat_org_id  = '. ORG_ID. '
                                   OR cat_org_id IS NULL )
                             ORDER BY cat_sequence, rol_name';

$headline = $gL10n->get('PLG_MITGLIEDSBEITRAG_MEMBERSHIP_FEE');

$gNavigation->addStartUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag.php', $headline);

// create html page object
$page = new HtmlPage($headline);

if($showOption != '')
{
    if(in_array($showOption, array('mandategenerate', 'mandates', 'createmandateid')) == true)
    {
        $navOption = 'mandatemanagement';
    }
    elseif(in_array($showOption, array('sepa', 'statementexport')) == true)
    {
        $navOption = 'export';
    }
    elseif(in_array($showOption, array('producemembernumber', 'copy', 'familyrolesupdate')) == true)
    {
        $navOption = 'options';
    }
    else
    {
        $navOption = 'fees';
    }

    $page->addJavascript('$("#tabs_nav_'.$navOption.'").attr("class", "active");
        $("#tabs-'.$navOption.'").attr("class", "tab-pane active");
        $("#collapse_'.$showOption.'").attr("class", "panel-collapse collapse in");
        location.hash = "#" + "panel_'.$showOption.'";', true);
}
else
{
    $page->addJavascript('$("#tabs_nav_fees").attr("class", "active");
    $("#tabs-fees").attr("class", "tab-pane active");
    ', true);
}

$page->addJavascript('
    $(".form-preferences").submit(function(event) {
        var id = $(this).attr("id");
        var action = $(this).attr("action");
        $("#"+id+" .form-alert").hide();

        // disable default form submit
        event.preventDefault();

        $.ajax({
            type:    "POST",
            url:     action,
            data:    $(this).serialize(),
            success: function(data) {
                if(data == "delete") {
                    var data = "success";
                    var replace = true;
                }

                if(data == "success") {
                    $("#"+id+" .form-alert").attr("class", "alert alert-success form-alert");
                    $("#"+id+" .form-alert").html("<span class=\"glyphicon glyphicon-ok\"></span><strong>'.$gL10n->get('SYS_SAVE_DATA').'</strong>");
                    $("#"+id+" .form-alert").fadeIn("slow");
                    $("#"+id+" .form-alert").animate({opacity: 1.0}, 2500);
                    $("#"+id+" .form-alert").fadeOut("slow");
                }
                else {
                    $("#"+id+" .form-alert").attr("class", "alert alert-danger form-alert");
                    $("#"+id+" .form-alert").fadeIn();
                    $("#"+id+" .form-alert").html("<span class=\"glyphicon glyphicon-remove\"></span>"+data);
                }
                if(replace == true) {
                   window.location.replace("'. ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag.php?show_option=delete");
                }
            }
        });
    });

    ', true);

// create module menu with back link
$headerMenu = new HtmlNavbar('menu_header', $headline, $page);

$form = new HtmlForm('navbar_static_display', '', $page, array('type' => 'navbar', 'setFocus' => false));

$form->addCustomContent('', '<table class="table table-condensed">
    <tr>
        <td style="text-align: right;">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_TOTAL').':</td>
        <td style="text-align: right;">'.($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech']).' '.$gPreferences['system_currency'].'</td>
        <td>&#160;&#160;&#160;&#160;</td>
        <td align = "right">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ALREADY_PAID').':</td>
        <td style="text-align: right;">'.($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech']).' '.$gPreferences['system_currency'].'</td>
        <td>&#160;&#160;&#160;&#160;</td>
        <td style="text-align: right;">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PENDING').':</td>
        <td style="text-align: right;">'.(($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech'])-($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech'])).' '.$gPreferences['system_currency'].'</td>
    </tr>
    <tr>
        <td style="text-align: right;">#</td>
        <td style="text-align: right;">'.($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl']).'</td>
        <td>&#160;&#160;&#160;&#160;</td>
        <td style="text-align: right;">#</td>
        <td style="text-align: right;">'.($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']).'</td>
        <td>&#160;&#160;&#160;&#160;</td>
        <td style="text-align: right;">#</td>
        <td style="text-align: right;">'.(($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl'])-($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl'])).'</td>
    </tr>
</table>');

$headerMenu->addForm($form->show(false));

if (isUserAuthorizedForPreferences())
{
    // show link to pluginpreferences
    $headerMenu->addItem('admMenuItemPreferencesLists', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/preferences.php',
                        $gL10n->get('SYS_SETTINGS'), 'options.png', 'right');
}

$page->addHtml($headerMenu->show(false));

if(count($rols) > 0)
{
    $page->addHtml('
    <ul class="nav nav-tabs" id="preferences_tabs">
        <li id="tabs_nav_fees"><a href="#tabs-fees" data-toggle="tab">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FEES').'</a></li>
        <li id="tabs_nav_mandatemanagement"><a href="#tabs-mandatemanagement" data-toggle="tab">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_MANAGEMENT').'</a></li>
        <li id="tabs_nav_export"><a href="#tabs-export" data-toggle="tab">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_EXPORT').'</a></li>
        <li id="tabs_nav_options"><a href="#tabs-options" data-toggle="tab">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_OPTIONS').'</a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane" id="tabs-fees">
            <div class="panel-group" id="accordion_fees">');

    			if (count(beitragsrollen_einlesen('alt')) > 0)
    			{
    				$page->addHtml('<div class="panel panel-default" id="panel_remapping">
                    	<div class="panel-heading">
                        	<h4 class="panel-title">
                            	<a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_remapping">
                                	<img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_REMAPPING_AGE_STAGGERED_ROLES').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_REMAPPING_AGE_STAGGERED_ROLES').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_REMAPPING_AGE_STAGGERED_ROLES').'
                            	</a>
                        	</h4>
                    	</div>
                    	<div id="collapse_remapping" class="panel-collapse collapse">
                        	<div class="panel-body">');
                            	// show form
                            	$form = new HtmlForm('configurations_form', null, $page);
                            	$form->addButton('btn_remapping_AGE_STAGGERed_roles', $gL10n->get('PLG_MITGLIEDSBEITRAG_REMAPPING'), array('icon' => THEME_URL .'/icons/edit.png', 'link' => 'remapping.php', 'class' => 'btn-primary col-sm-offset-3'));
                            	$form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_REMAPPING_AGE_STAGGERED_ROLES_DESC'));
                            	$page->addHtml($form->show(false));
                        	$page->addHtml('</div>
                    	</div>
                	</div>');
     			}
  
    			$page->addHtml('<div class="panel panel-default" id="panel_delete">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_delete">
                                <img src="'. THEME_URL .'/icons/delete.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RESET').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RESET').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RESET').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_delete" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('delete_all_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=delete', $page, array('class' => 'form-preferences'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_DELETE_DESC').'</strong>');
                            $form->addInput('delete_all', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE_ALL'), ($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl']), array('property' => FIELD_READONLY, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_DELETE_ALL_DESC'));                             //FIELD_DISABLED
                            $form->addSubmitButton('btn_delete_all', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE'), array('icon' => THEME_URL .'/icons/delete.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $page->addHtml($form->show(false));

                            $form = new HtmlForm('with_paid_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=delete', $page, array('class' => 'form-preferences'));
                            $form->addLine();
                            $form->addInput('with_paid', $gL10n->get('PLG_MITGLIEDSBEITRAG_WITH_PAID'), ($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']), array('property' => FIELD_READONLY, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_WITH_PAID_DESC'));                             //FIELD_DISABLED
                            $form->addSubmitButton('btn_with_paid', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE'), array('icon' => THEME_URL .'/icons/delete.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $page->addHtml($form->show(false));

                            $form = new HtmlForm('without_paid_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=delete', $page, array('class' => 'form-preferences'));
                            $form->addLine();
                            $form->addInput('without_paid', $gL10n->get('PLG_MITGLIEDSBEITRAG_WITHOUT_PAID'), (($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl'])-($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl'])), array('property' => FIELD_READONLY, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_WITHOUT_PAID_DESC'));                             //FIELD_DISABLED
                            $form->addSubmitButton('btn_without_paid', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE'), array('icon' => THEME_URL .'/icons/delete.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $page->addHtml($form->show(false));

                            $form = new HtmlForm('paid_only_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=delete', $page, array('class' => 'form-preferences'));
                            $form->addLine();
                            $form->addInput('paid_only', $gL10n->get('PLG_MITGLIEDSBEITRAG_PAID_ONLY'), $paidcount, array('property' => FIELD_READONLY, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_PAID_ONLY_DESC'));                             //FIELD_DISABLED
                            $form->addSubmitButton('btn_paid_only', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE'), array('icon' => THEME_URL .'/icons/delete.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $page->addHtml($form->show(false));

                            $form = new HtmlForm('duedate_only_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=delete', $page, array('class' => 'form-preferences'));
                            $form->addLine();
                            $form->addInput('duedate_only', $gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE_ONLY'), $duedatecount, array('property' => FIELD_READONLY, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_DUEDATE_ONLY_DESC'));
                            $form->addSubmitButton('btn_duedate_only', $gL10n->get('PLG_MITGLIEDSBEITRAG_DELETE'), array('icon' => THEME_URL .'/icons/delete.png',  'class' => 'btn-primary col-sm-offset-3'));
                           $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>

                <div class="panel panel-default" id="panel_recalculation">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_recalculation">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RECALCULATION').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RECALCULATION').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_RECALCULATION').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_recalculation" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                        	unset($_SESSION['pMembershipFee']['recalculation_user']);
                            $form = new HtmlForm('recalculation_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/recalculation.php', $page);
                            $form->addSelectBox('recalculation_roleselection', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_SELECTION'), $selectBoxEntriesBeitragsrollen, array('defaultValue' => (isset($_SESSION['pMembershipFee']['recalculation_rol_sel']) ? $_SESSION['pMembershipFee']['recalculation_rol_sel'] : ''), 'showContextDependentFirstEntry' => false, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_RECALCULATION_ROLLQUERY_DESC', 'multiselect' => true));
                            $radioButtonEntries = array('standard'  => $gL10n->get('PLG_MITGLIEDSBEITRAG_DEFAULT'),
                                                        'overwrite' => $gL10n->get('PLG_MITGLIEDSBEITRAG_OVERWRITE'),
                                                        'summation' => $gL10n->get('PLG_MITGLIEDSBEITRAG_SUMMATION'));
                            $form->addRadioButton('recalculation_modus', '', $radioButtonEntries, array('defaultValue' => 'standard', 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_RECALCULATION_MODUS_DESC'));
                            $form->addSubmitButton('btn_recalculation', $gL10n->get('PLG_MITGLIEDSBEITRAG_RECALCULATION'), array('icon' => THEME_URL .'/icons/edit.png', 'class' => ' col-sm-offset-3'));
                            $form->addCustomContent('', '<br/><strong>'.$gL10n->get('SYS_NOTE').':</strong> '.$gL10n->get('PLG_MITGLIEDSBEITRAG_RECALCULATION_MODUS_NOTE'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
                        		
                <div class="panel panel-default" id="panel_payments">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_payments">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_PAYMENTS').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_PAYMENTS').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_PAYMENTS').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_payments" class="panel-collapse collapse">
                    <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('payments_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/payments.php', $page);
                            $form->addSelectBox('payments_roleselection', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_SELECTION'), $selectBoxEntriesBeitragsrollen, array('defaultValue' => (isset($_SESSION['pMembershipFee']['payments_rol_sel']) ? $_SESSION['pMembershipFee']['payments_rol_sel'] : ''), 'showContextDependentFirstEntry' => false, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_PAYMENTS_ROLLQUERY_DESC', 'multiselect' => true));
                            $form->addSubmitButton('btn_payments', $gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_PAYMENTS_EDIT'), array('icon' => THEME_URL .'/icons/edit.png', 'class' => ' col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_PAYMENTS_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
                <div class="panel panel-default" id="panel_analysis">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_analysis">
                                <img src="'. THEME_URL .'/icons/info.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_ANALYSIS').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_ANALYSIS').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_ANALYSIS').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_analysis" class="panel-collapse collapse">
                        <div class="panel-body">');
                           // show form
                            $page->addHtml('<div id="members_contribution" class="panel panel-default">
                                <div class="panel-heading">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MEMBERS_CONTRIBUTION').'</div>
                                <div class="panel-body">');

                                    $datatable = false;
                                    $hoverRows = true;
                                    $classTable  = 'table table-condensed';
                                    $table = new HtmlTable('table_members_contribution', $page, $hoverRows, $datatable, $classTable);

                                    $columnAttributes['style'] = 'text-align: left';
                                    $table->addColumn('', $columnAttributes, 'th');

                                    $columnAttributes['colspan'] = 2;
                                    $columnAttributes['style'] = 'text-align: right';
                                    $table->addColumn($gL10n->get('PLG_MITGLIEDSBEITRAG_WITH_ACCOUNT_DATA'), $columnAttributes, 'th');
                                    $table->addColumn($gL10n->get('PLG_MITGLIEDSBEITRAG_WITHOUT_ACCOUNT_DATA'), $columnAttributes, 'th');
                                    $table->addColumn($gL10n->get('PLG_MITGLIEDSBEITRAG_SUM'), $columnAttributes, 'th');

                                    $columnAlign  = array('left', 'right', 'right', 'right', 'right', 'right', 'right');
                                    $table->setColumnAlignByArray($columnAlign);

                                    $columnValues = array();
                                    $columnValues[] = '';
                                    $columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_NUMBER');
                                    $columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_NUMBER');
                                    $columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_NUMBER');
                                    $table->addRowByArray($columnValues);

                                    $columnValues = array();
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_DUES');
                                    $columnValues[] = $beitrag['BEITRAG_kto'].' '.$gPreferences['system_currency'];
                                    $columnValues[] = $beitrag['BEITRAG_kto_anzahl'];
                                    $columnValues[] = $beitrag['BEITRAG_rech'].' '.$gPreferences['system_currency'];
                                    $columnValues[] = $beitrag['BEITRAG_rech_anzahl'];
                                    $columnValues[] = ($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech']).' '.$gPreferences['system_currency'];
                                    $columnValues[] = ($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl']);
                                    $table->addRowByArray($columnValues);

                                    $columnValues = array();
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_ALREADY_PAID');
                                    $columnValues[] = $beitrag['BEZAHLT_kto'].' '.$gPreferences['system_currency'];
                                    $columnValues[] = $beitrag['BEZAHLT_kto_anzahl'];
                                    $columnValues[] = $beitrag['BEZAHLT_rech'].' '.$gPreferences['system_currency'];
                                    $columnValues[] = $beitrag['BEZAHLT_rech_anzahl'];
                                    $columnValues[] = ($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech']).' '.$gPreferences['system_currency'];
                                    $columnValues[] = ($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']);
                                    $table->addRowByArray($columnValues);

                                    $columnValues = array();
                                    $columnValues[] = $gL10n->get('PLG_MITGLIEDSBEITRAG_PENDING');
                                    $columnValues[] = ($beitrag['BEITRAG_kto']-$beitrag['BEZAHLT_kto']).' '.$gPreferences['system_currency'];
                                    $columnValues[] = ($beitrag['BEITRAG_kto_anzahl']-$beitrag['BEZAHLT_kto_anzahl']);
                                    $columnValues[] = ($beitrag['BEITRAG_rech']-$beitrag['BEZAHLT_rech']).' '.$gPreferences['system_currency'];
                                    $columnValues[] = ($beitrag['BEITRAG_rech_anzahl']-$beitrag['BEZAHLT_rech_anzahl']);
                                    $columnValues[] = (($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech'])-($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech'])).' '.$gPreferences['system_currency'];
                                    $columnValues[] = (($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl'])-($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']));
                                    $table->addRowByArray($columnValues);

                                    $table->setDatatablesRowsPerPage(10);
                                    $page->addHtml($table->show(false));
                                    $page->addHtml('<strong>'.$gL10n->get('SYS_NOTE').':</strong> '.$gL10n->get('PLG_MITGLIEDSBEITRAG_MEMBERS_CONTRIBUTION_DESC'));
                                $page->addHtml('</div>
                            </div>

                            <div id="roles_contribution" class="panel panel-default">
                                <div class="panel-heading">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLES_CONTRIBUTION').'</div>
                                <div class="panel-body">');

                                    $datatable = true;
                                    $hoverRows = true;
                                    $classTable  = 'table table-condensed';
                                    $table = new HtmlTable('table_roles_contribution', $page, $hoverRows, $datatable, $classTable);

                                    $columnAlign  = array('left', 'right', 'right', 'right', 'right');
                                    $table->setColumnAlignByArray($columnAlign);

                                    $columnValues = array($gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE'), 'dummy', $gL10n->get('SYS_CONTRIBUTION'), $gL10n->get('PLG_MITGLIEDSBEITRAG_NUMBER'), $gL10n->get('PLG_MITGLIEDSBEITRAG_SUM'));
                                    $table->addRowHeadingByArray($columnValues);

                                    $rollen = analyse_rol();
                                    foreach ($rollen as $rol => $roldata)
                                    {
                                        $columnValues = array();
                                        $columnValues[] = $roldata['rolle'];
                                        $columnValues[] = expand_rollentyp($roldata['rollentyp']);
                                        $columnValues[] = $roldata['rol_cost'].' '.$gPreferences['system_currency'];
                                        $columnValues[] = count($roldata['members']);
                                        $columnValues[] = ($roldata['rol_cost']*count($roldata['members'])).' '.$gPreferences['system_currency'];

                                        $sum += ($roldata['rol_cost']*count($roldata['members']));
                                        $table->addRowByArray($columnValues);
                                    }

                                    $columnValues = array($gL10n->get('PLG_MITGLIEDSBEITRAG_TOTAL'), '', '', '', $sum.' '.$gPreferences['system_currency']);
                                    $table->addRowByArray($columnValues);
                                    $table->setDatatablesGroupColumn(2);
                                    $table->setDatatablesRowsPerPage(10);

                                    $page->addHtml($table->show(false));
                                    $page->addHtml('<strong>'.$gL10n->get('SYS_NOTE').':</strong> '.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLES_CONTRIBUTION_DESC'));
                                $page->addHtml('</div>
                            </div>
                        </div>
                    </div>
                </div>        		
                <div class="panel panel-default" id="panel_history">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_fees" href="#collapse_history">
                                <img src="'. THEME_URL .'/icons/list.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_HISTORY').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_HISTORY').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_HISTORY').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_history" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('history_form', null, $page);
                            $form->addButton('btn_history', $gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_HISTORY_SHOW'), array('icon' => THEME_URL .'/icons/list.png', 'link' => 'history.php', 'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CONTRIBUTION_HISTORY_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>	      		
            </div>
        </div>

        <div class="tab-pane" id="tabs-mandatemanagement">
            <div class="panel-group" id="accordion_mandatemanagement">	
                 <div class="panel panel-default" id="panel_createmandateid">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_createmandateid">
                                <img src="'. THEME_URL .'/icons/disk.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CREATE_MANDATE_ID').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CREATE_MANDATE_ID').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_CREATE_MANDATE_ID').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_createmandateid" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            unset($_SESSION['pMembershipFee']['createmandateid_user']);
                            $form = new HtmlForm('createmandateid_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/create_mandate_id.php', $page);                            
                            $form->addSelectBoxFromSql('createmandateid_roleselection', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_SELECTION'), $gDb, $selectBoxEntriesAlleRollen, array('defaultValue' => (isset($_SESSION['pMembershipFee']['createmandateid_rol_sel']) ? $_SESSION['pMembershipFee']['createmandateid_rol_sel'] : ''), 'showContextDependentFirstEntry' => false, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_CREATE_MANDATE_ID_DESC', 'multiselect' => true));
                            $form->addSubmitButton('btn_createmandateid', $gL10n->get('PLG_MITGLIEDSBEITRAG_CREATE_MANDATE_ID'), array('icon' => THEME_URL .'/icons/disk.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
                        		
                <div class="panel panel-default" id="panel_mandates">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_mandatemanagement" href="#collapse_mandates">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_EDIT').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_EDIT').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_EDIT').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_mandates" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('configurations_form', null, $page);
                            $form->addButton('btn_mandates', $gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_EDIT'), array('icon' => THEME_URL .'/icons/edit.png', 'link' => 'mandates.php', 'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_EDIT_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
            </div>
        </div>
        ');

        $page->addHtml('
        <div class="tab-pane" id="tabs-export">
            <div class="panel-group" id="accordion_export">
                <div class="panel panel-default" id="panel_sepa">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_export" href="#collapse_sepa">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_SEPA').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_SEPA').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_SEPA').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_sepa" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('duedates_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/duedates.php', $page);
	                        $form->addSelectBox('duedates_roleselection', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_SELECTION'), $selectBoxEntriesBeitragsrollen, array('defaultValue' => (isset($_SESSION['pMembershipFee']['duedates_rol_sel']) ? $_SESSION['pMembershipFee']['duedates_rol_sel'] : ''), 'showContextDependentFirstEntry' => false, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_DUEDATE_ROLLQUERY_DESC', 'multiselect' => true));
                            $form->addSubmitButton('btn_duedates', $gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE'), array('icon' => THEME_URL .'/icons/edit.png', 'class' => ' col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE_EDIT_DESC'));
                            $form->addLine();
                            $page->addHtml($form->show(false));

                            $form = new HtmlForm('sepa_export_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/export_sepa.php', $page);
                            if (!$directdebittype)
                            {
                                $html = '<div class="alert alert-warning alert-small" role="alert"><span class="glyphicon glyphicon-warning-sign"></span>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_NO_DUEDATES_EXIST').'</div>';
                                $form->addCustomContent('', $html);
                            }
                            else
                            {
                                $htmlTable = '
                                <table class="table table-condensed">
                                    <thead>
                                        <tr>
                                            <th style="text-align: center;font-weight:bold;">'.$gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE').'</th>
                                            <th style="text-align: center;font-weight:bold;">FRST</th>
                                            <th style="text-align: center;font-weight:bold;">RCUR</th>
                                            <th style="text-align: center;font-weight:bold;">FNAL</th>
                                            <th style="text-align: center;font-weight:bold;">OOFF</th>
                                        </tr>
                                    </thead>';

                                    $htmlTable .= '
                                    <tbody id="test">';

                                        foreach($duedates as $duedate => $duedatedata)
                                        {
                                        	$datumtemp = \DateTime::createFromFormat('Y-m-d', $duedate);

                                            $htmlTable .= '
                                            <tr>
                                                <td style="text-align: center;">'.$datumtemp->format($gPreferences['system_date']).'</td>
                                                <td style="text-align: center;"><input type="checkbox" name="duedatesepatype[]" ';
                                                    if ($duedatedata['FRST'] == 0)
                                                    {
                                                        $htmlTable .= ' disabled="disabled" ';
                                                    }
                                                    $htmlTable .= 'value="'.$duedate.'FRST" /><small> ('.$duedatedata['FRST'].')</small>
                                                </td>
                                                <td style="text-align: center;"><input type="checkbox" name="duedatesepatype[]" ';
                                                    if ($duedatedata['RCUR'] == 0)
                                                    {
                                                        $htmlTable .= ' disabled="disabled" ';
                                                    }
                                                    $htmlTable .= 'value="'.$duedate.'RCUR" /><small> ('.$duedatedata['RCUR'].')</small>
                                                </td>
                                                <td style="text-align: center;"><input type="checkbox" name="duedatesepatype[]"  ';
                                                    if ($duedatedata['FNAL'] == 0)
                                                    {
                                                        $htmlTable .= ' disabled="disabled" ';
                                                    }
                                                    $htmlTable .= 'value="'.$duedate.'FNAL" /><small> ('.$duedatedata['FNAL'].')</small>
                                                </td>
                                                <td style="text-align: center;"><input type="checkbox" name="duedatesepatype[]"  ';
                                                    if ($duedatedata['OOFF'] == 0)
                                                    {
                                                        $htmlTable .= ' disabled="disabled" ';
                                                    }
                                                    $htmlTable .= 'value="'.$duedate.'OOFF" /><small> ('.$duedatedata['OOFF'].')</small>
                                                </td>
                                            </tr>';
                                            }
                                        $htmlTable .= '
                                        </tbody>
                                </table>';

                                $form->addCustomContent($gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE_SELECTION'), $htmlTable);
                                $form->addCustomContent('', $gL10n->get('PLG_MITGLIEDSBEITRAG_DUEDATE_SELECTION_DESC'));

                                $form->addSubmitButton('btn_xml_file', $gL10n->get('PLG_MITGLIEDSBEITRAG_XML_FILE'), array('icon' => THEME_URL .'/icons/download.png', 'class' => 'btn-primary col-sm-offset-3'));
                                $form->addCustomContent('', $gL10n->get('PLG_MITGLIEDSBEITRAG_XML_FILE_DESC'));

                                $form->addSubmitButton('btn_xml_kontroll_datei', $gL10n->get('PLG_MITGLIEDSBEITRAG_CONTROL_FILE'), array('icon' => THEME_URL .'/icons/download.png', 'class' => 'btn-primary col-sm-offset-3'));
                                $form->addCustomContent('', $gL10n->get('PLG_MITGLIEDSBEITRAG_CONTROL_FILE_DESC'));

                                $html = '<div class="alert alert-warning alert-small" role="alert"><span class="glyphicon glyphicon-warning-sign"></span>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_SEPA_EXPORT_INFO').'</div>';
                                $form->addStaticControl('', '', $html);

                                $form->addLine();
                                $form->addButton('btn_pre_notification', $gL10n->get('PLG_MITGLIEDSBEITRAG_PRE_NOTIFICATION'), array('icon' => THEME_URL .'/icons/download.png', 'link' => 'pre_notification.php', 'class' => 'btn-primary col-sm-offset-3'));
                                $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PRE_NOTIFICATION_DESC'));
                            }
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
                <div class="panel panel-default" id="panel_statementexport">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_export" href="#collapse_statementexport">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_STATEMENT_EXPORT').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_STATEMENT_EXPORT').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_STATEMENT_EXPORT').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_statementexport" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('rechnung_export_form', null, $page, array('class' => 'form-preferences'));
                            $form->addButton('btn_rechnung_export', $gL10n->get('PLG_MITGLIEDSBEITRAG_STATEMENT_FILE'), array('icon' => THEME_URL .'/icons/download.png', 'link' => 'export_bill.php', 'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_STATEMENT_FILE_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
            </div>
        </div>
        ');

        $page->addHtml('
        <div class="tab-pane" id="tabs-options">
            <div class="panel-group" id="accordion_options">
                <div class="panel panel-default" id="panel_producemembernumber">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_producemembernumber">
                                <img src="'. THEME_URL .'/icons/disk.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_producemembernumber" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            unset($_SESSION['pMembershipFee']['membernumber_user']);
                            $form = new HtmlForm('producemembernumber_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/membernumber.php', $page);                            
                            $form->addSelectBoxFromSql('producemembernumber_roleselection', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_SELECTION'), $gDb, $selectBoxEntriesAlleRollen, array('defaultValue' => (isset($_SESSION['pMembershipFee']['membernumber_rol_sel']) ? $_SESSION['pMembershipFee']['membernumber_rol_sel'] : ''), 'showContextDependentFirstEntry' => false, 'helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER_DESC2', 'multiselect' => true));
                            $form->addInput('producemembernumber_format', $gL10n->get('PLG_MITGLIEDSBEITRAG_FORMAT'), (isset($_SESSION['pMembershipFee']['membernumber_format']) ? $_SESSION['pMembershipFee']['membernumber_format'] : (isset($pPreferences->config['membernumber']['format']) ? $pPreferences->config['membernumber']['format'] : '')), array('helpTextIdInline' => 'PLG_MITGLIEDSBEITRAG_FORMAT_DESC'));
                            $form->addSubmitButton('btn_producemembernumber', $gL10n->get('PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER'), array('icon' => THEME_URL .'/icons/edit.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_PRODUCE_MEMBERNUMBER_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>

                <div class="panel panel-default" id="panel_familyrolesupdate">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_familyrolesupdate">
                                <img src="'. THEME_URL .'/icons/group.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_UPDATE').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_UPDATE').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_UPDATE').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_familyrolesupdate" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            unset($_SESSION['pMembershipFee']['familyroles_update']);
                            $form = new HtmlForm('familyrolesupdate_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/familyroles_update.php', $page);                            
                            $form->addSubmitButton('btn_familyrolesupdate', $gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_UPDATE'), array('icon' => THEME_URL .'/icons/edit.png',  'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_UPDATE_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>

                <div class="panel panel-default" id="panel_copy">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_copy">
                                <img src="'. THEME_URL .'/icons/edit.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_COPY').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_COPY').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_COPY').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_copy" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('copy_form', null, $page);
                            $form->addButton('btn_copy', $gL10n->get('PLG_MITGLIEDSBEITRAG_COPY'), array('icon' => THEME_URL .'/icons/edit.png', 'link' => 'copy.php', 'class' => 'btn-primary col-sm-offset-3'));
                            $form->addCustomContent('', '<br/>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_COPY_DESC'));
                            $page->addHtml($form->show(false));
                        $page->addHtml('</div>
                    </div>
                </div>
                <div class="panel panel-default" id="panel_tests">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_tests">
                                <img src="'. THEME_URL .'/icons/info.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_TEST').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_TEST').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_TEST').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_tests" class="panel-collapse collapse">
                        <div class="panel-body">');
                            // show form
                            $form = new HtmlForm('configurations_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/mitgliedsbeitrag_function.php?form=tests', $page, array('class' => 'form-preferences'));
                            $form->openGroupBox('AGE_STAGGERed_roles', $gL10n->get('PLG_MITGLIEDSBEITRAG_AGE_STAGGERED_ROLES'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_AGE_STAGGERED_ROLES_DESC').'</strong>');
                            foreach (check_rols() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            // Pruefung der Rollenmitgliedschaften in den altersgestaffelten Rollen nur, wenn es mehrere Staffelungen gibt
                            if (count($pPreferences->config['Altersrollen']['altersrollen_token']) > 1)
                            {
                                $form->openGroupBox('role_membership_AGE_STAGGERed_roles', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_AGE_STAGGERED_ROLES'));
                                $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_AGE_STAGGERED_ROLES_DESC').'</strong>');
                                foreach (check_rollenmitgliedschaft_altersrolle() as $data)
                                {
                                    $form->addDescription($data);
                                }
                                $form->closeGroupBox();
                            }
                            $form->openGroupBox('role_membership_duty', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_DUTY'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_DUTY_DESC').'</strong>');
                            foreach (check_rollenmitgliedschaft_pflicht() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            $form->openGroupBox('role_membership_exclusion', $gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_EXCLUSION'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_MEMBERSHIP_EXCLUSION_DESC').'</strong>');
                            foreach (check_rollenmitgliedschaft_ausschluss() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            $form->openGroupBox('family_roles', $gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_FAMILY_ROLES_ROLE_TEST_DESC').'</strong>');
                            foreach (check_family_roles() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            $form->openGroupBox('mandate_management', $gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_MANAGEMENT'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_MANDATE_MANAGEMENT_DESC2').'</strong>');
                            foreach (check_mandate_management() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            $form->openGroupBox('iban_check', $gL10n->get('PLG_MITGLIEDSBEITRAG_IBANCHECK'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_IBANCHECK_DESC').'</strong>');
                            foreach (check_iban() as $data)
                            {
                                $form->addDescription($data);
                            }
                            $form->closeGroupBox();
                            
                            $form->openGroupBox('bic_check', $gL10n->get('PLG_MITGLIEDSBEITRAG_BICCHECK'));
                            $form->addDescription('<strong>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_BICCHECK_DESC').'</strong>');
                            foreach (check_bic() as $data)
                            {
                            	$form->addDescription($data);
                            }
                            $form->closeGroupBox();

                            //seltsamerweise wird in diesem Abschnitt nichts angezeigt wenn diese Anweisung fehlt
                            $form->addStaticControl('', '', '');

                            $page->addHtml($form->show(false));
                       $page->addHtml('</div>
                    </div>
                </div>

                <div class="panel panel-default" id="panel_roleoverview">
                    <div class="panel-heading">
                        <h4 class="panel-title">
                            <a class="icon-text-link" data-toggle="collapse" data-parent="#accordion_options" href="#collapse_roleoverview">
                                <img src="'. THEME_URL .'/icons/info.png" alt="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_OVERVIEW').'" title="'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_OVERVIEW').'" />'.$gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_OVERVIEW').'
                            </a>
                        </h4>
                    </div>
                    <div id="collapse_roleoverview" class="panel-collapse collapse">
                        <div class="panel-body">');

                            $datatable = true;
                            $hoverRows = true;
                            $classTable  = 'table table-condensed';
                            $table = new HtmlTable('table_role_overview', $page, $hoverRows, $datatable, $classTable);

                            $columnAlign  = array('left', 'right', 'right');
                            $table->setColumnAlignByArray($columnAlign);

                            $columnValues = array($gL10n->get('PLG_MITGLIEDSBEITRAG_ROLE_NAME'), 'dummy', $gL10n->get('PLG_MITGLIEDSBEITRAG_MEMBER_ACCOUNT'));
                            $table->addRowHeadingByArray($columnValues);

                            $rollen = beitragsrollen_einlesen('', array('LAST_NAME'));
                            foreach ($rollen as $rol_id => $data)
                            {
                                $columnValues = array();
                                $columnValues[] = '<a href="'. ADMIDIO_URL . FOLDER_MODULES . '/roles/roles_new.php?rol_id='. $rol_id. '">'.$data['rolle']. '</a>';
                                $columnValues[] = expand_rollentyp($data['rollentyp']);
                                $columnValues[] = count($data['members']);
                                $table->addRowByArray($columnValues);
                            }
                            $table->setDatatablesGroupColumn(2);
                            $table->setDatatablesRowsPerPage(10);

                            $page->addHtml($table->show(false));

                        $page->addHtml('</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    ');
}
else
{
    $form = new HtmlForm('no_roles_defined_form', null, $page);
    $html = '<div class="alert alert-warning alert-small" role="alert"><span class="glyphicon glyphicon-warning-sign"></span>'.$gL10n->get('PLG_MITGLIEDSBEITRAG_NO_CONTRIBUTION_ROLES_DEFINED').'</div>';
    $form->addDescription($html);
    //seltsamerweise wird in diesem Abschnitt nichts angezeigt wenn diese Anweisung fehlt
    $form->addStaticControl('', '', '');
    $page->addHtml($form->show(false));
}
$page->show();
