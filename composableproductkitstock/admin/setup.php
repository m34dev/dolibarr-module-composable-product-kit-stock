<?php
/* Copyright (C) 2025       William Mead    <william@m34d.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    composableproductkitstock/admin/setup.php
 * \ingroup composableproductkitstock
 * \brief   ComposableProductKitStock setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/composableproductkitstock.lib.php';
require_once '../class/stockexportmailer.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "mails", "cron", "categories", "products", "composableproductkitstock@composableproductkitstock"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('composableproductkitstocksetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$setupnotempty = 0;

// Access control
if (!$user->admin) {
	accessforbidden();
}


if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);
$form = new Form($db);
$mailer = new StockExportMailer($db);

$yesnooptions = array(
	'1' => $langs->trans('Yes'),
	'0' => $langs->trans('No'),
);

// Enter here all parameters in your setup page

$formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_WAREHOUSEDETAIL')->setAsYesNo();

// Applies to the CSV export and to the export sent by email. A plain select, not setAsYesNo(): saved with the form,
// before the exported fields below, so the page reloads with the matching list of fields.
$combineditem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EXPORT_COMBINED_STOCK');
$combineditem->setAsSelect($yesnooptions);
$combineditem->defaultFieldValue = '0';

// Export sent by email

$formSetup->newItem('CPKSSetupSectionExport')->setAsTitle();

$formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_PRODUCT_CATEGORY')->setAsCategory('product');

$columnsitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_COLUMNS');
$columnsitem->setAsMultiSelect(ComposableProductKitStock::getExportColumns($langs));
$columnsitem->defaultFieldValue = implode(',', ComposableProductKitStock::getDefaultExportColumns());
// Saved here rather than by FormSetup: an empty multiselect posts nothing, and at least one column is needed
$columnsitem->setValueFromPostCallBack(function () use ($columnsitem) {
	global $langs;
	$values = GETPOST($columnsitem->confKey, 'array');
	$values = array_values(array_intersect(is_array($values) ? $values : array(), array_keys(ComposableProductKitStock::getExportColumns($langs))));
	if (empty($values)) {
		setEventMessages($langs->trans('CPKSErrorNoColumn'), null, 'errors');
		return -1;
	}
	$columnsitem->fieldValue = implode(',', $values);
	return 1;
});

// CSV separator: Dolibarr's global setting, only shown here
$separatoritem = $formSetup->newItem('CPKSCsvSeparator');
$separatoritem->fieldInputOverride = '<code>'.dol_escape_htmltag(ComposableProductKitStock::getCsvSeparator()).'</code> <a href="'.DOL_URL_ROOT.'/admin/export.php">'.img_picto('', 'setup', 'class="pictofixedwidth"').$langs->trans('CPKSChangeCsvSeparator').'</a>';
$separatoritem->setValueFromPostCallBack(function () {
	return 0;
});

// Recipients

$formSetup->newItem('CPKSSetupSectionRecipients')->setAsTitle();

$formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_CONTACT_CATEGORY')->setAsCategory('contact');

// Schedule

$formSetup->newItem('CPKSSetupSectionSchedule')->setAsTitle();

// Not setAsYesNo(): that renders as an ajax on/off toggle that DELETES the constant row when switched
// off and bypasses the form save (so the activation date below could not be set on activation).
// A plain select always writes the literal '0'/'1'.
$enableditem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED');
$enableditem->setAsSelect($yesnooptions);
$enableditem->defaultFieldValue = '0';

$frequencyitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_FREQUENCY');
$frequencyitem->setAsSelect(array(
	'daily' => $langs->trans('CPKSFrequencyDaily'),
	'weekly' => $langs->trans('CPKSFrequencyWeekly'),
	'monthly' => $langs->trans('CPKSFrequencyMonthly'),
));
$frequencyitem->defaultFieldValue = 'weekly';

$weekdayitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_WEEKDAY');
$weekdayitem->setAsSelect(array(
	'1' => $langs->trans('Monday'),
	'2' => $langs->trans('Tuesday'),
	'3' => $langs->trans('Wednesday'),
	'4' => $langs->trans('Thursday'),
	'5' => $langs->trans('Friday'),
	'6' => $langs->trans('Saturday'),
	'7' => $langs->trans('Sunday'),
));
$weekdayitem->defaultFieldValue = '1';

$monthdays = array();
for ($d = 1; $d <= 28; $d++) {
	$monthdays[(string) $d] = (string) $d;
}
$monthdays['last'] = $langs->trans('CPKSLastDayOfMonth');
$monthdayitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_MONTHDAY');
$monthdayitem->setAsSelect($monthdays);
$monthdayitem->defaultFieldValue = '1';

$workdaysitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_WORKDAYS_ONLY');
$workdaysitem->setAsSelect($yesnooptions);
$workdaysitem->defaultFieldValue = '1';

$hours = array();
for ($h = 0; $h <= 24; $h++) {
	$hours[(string) $h] = sprintf('%02d:00', $h);
}
$hourstartitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_HOUR_START');
$hourstartitem->setAsSelect(array_slice($hours, 0, 24, true));
$hourstartitem->defaultFieldValue = '9';
$hourenditem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_HOUR_END');
$hourenditem->setAsSelect(array_slice($hours, 1, 24, true));
$hourenditem->defaultFieldValue = '18';

$batchitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_BATCH_SIZE');
$batchitem->defaultFieldValue = '10';
$batchitem->fieldAttr = array('type' => 'number', 'min' => 1, 'max' => 1000, 'class' => 'flat width75');
$batchitem->setValueFromPostCallBack(composableproductkitstockIntegerValidator($batchitem, 1, 1000));

// Email

$formSetup->newItem('CPKSSetupSectionEmail')->setAsTitle();

$senderitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_SENDER');
$senderitem->setAsSelect($mailer->getSenderOptions());
$senderitem->defaultFieldValue = 'robot';

$templateitem = $formSetup->newItem('COMPOSABLEPRODUCTKITSTOCK_EMAIL_TEMPLATE');
// Not setAsEmailTemplate(): it stores a template id (one language variant) and also lists the private
// templates of the current user, which the scheduled job (running as another user) could not use.
$templateitem->setAsSelect(array('' => $langs->trans('None')) + $mailer->getTemplateOptions());

$setupnotempty += count($formSetup->items);

/*
 * Actions
 */

$showpreview = false;

// Same as core/actions_setmoduleoptions.inc.php does for a FormSetup, but keeping the result
if ($action == 'update') {
	$wasenabled = getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED');
	$result = $formSetup->saveConfFromPost();

	// Sending starts with the first period from the activation day, so switching on does not send at once
	if ($result > 0 && !$wasenabled && getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED')) {
		dolibarr_set_const($db, 'COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED_SINCE', dol_now(), 'chaine', 0, '', $conf->entity);
	}
	if ($result > 0) {
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
} elseif ($action == 'sendtest') {
	if ($mailer->sendTest($user) > 0) {
		setEventMessages($langs->trans('CPKSTestSent', $user->email), null);
	} else {
		setEventMessages($mailer->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
} elseif ($action == 'preview') {
	$showpreview = true;
}

$action = 'edit';

/*
 * View
 */

$help_url = '';
$title = "ComposableProductKitStockSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-composableproductkitstock page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($title), $linkback, 'object_composableproductkitstock@composableproductkitstock');

// Configuration header
$head = composableproductkitstockAdminPrepareHead();
print dol_get_fiche_head($head, 'Setup', $langs->trans($title), -1, "setup");

// Setup page goes here

echo '<span class="opacitymedium">'.$langs->trans("ComposableProductKitStockSetupPage").'</span><br><br>';

// Prerequisites of the automatic sending
if (getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED')) {
	foreach (composableproductkitstockGetSetupWarnings($db, $mailer) as $warning) {
		print info_admin($warning, 0, 0, 'warning');
	}
}

// Sending status
$status = array();
if ($mailer->isAutoEnabled()) {
	$duestart = $mailer->getDuePeriodStart();
	if ($duestart) {
		$stats = $mailer->getSendingStats($duestart);
		$nbsent = 0;
		if (is_array($stats)) {
			foreach ($stats as $stat) {
				$nbsent += ($stat['sent'] > 0 ? 1 : 0);
			}
		}
		$status[] = $langs->trans('CPKSStatusCurrentPeriod', dol_print_date($duestart, 'day', 'tzserver'), $nbsent);
	}
	$status[] = $langs->trans('CPKSStatusNextPeriod', dol_print_date($duestart ? $mailer->getNextPeriodStart() : $mailer->getNextSendingDate(), 'day', 'tzserver'));
} else {
	$status[] = $langs->trans('CPKSStatusDisabled');
}
if (getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_LASTRUN')) {
	$status[] = $langs->trans('CPKSStatusLastRun', dol_escape_htmltag(getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_LASTRUN')));
}
print info_admin(implode('<br>', $status), 0, 0, 'info');

if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
	print '<br>';
}

if (empty($setupnotempty)) {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Test email and recipients preview
print '<div class="center">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="sendtest">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CPKSSendTest')).'" title="'.dol_escape_htmltag($langs->trans('CPKSSendTestHelp', $user->email)).'">';
print '</form> ';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#recipients" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="preview">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CPKSPreviewRecipients')).'">';
print '</form>';
print '</div><br>';

if ($showpreview) {
	$recipients = $mailer->getRecipients();
	$duestart = $mailer->getDuePeriodStart();
	$stats = ($duestart ? $mailer->getSendingStats($duestart) : array());
	if (!is_array($stats)) {
		$stats = array();
	}

	print '<a id="recipients"></a>';
	print load_fiche_titre($langs->trans('CPKSRecipients').' ('.(is_array($recipients) ? count($recipients) : 0).')', '', 'contact');
	if (!is_array($recipients)) {
		print info_admin(dol_escape_htmltag($mailer->error), 0, 0, 'error');
	} else {
		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Contact').'</td>';
		print '<td>'.$langs->trans('ThirdParty').'</td>';
		print '<td>'.$langs->trans('Email').'</td>';
		print '<td>'.$langs->trans('Language').'</td>';
		print '<td class="center">'.$langs->trans('CPKSSentThisPeriod').'</td>';
		print '</tr>';
		if (empty($recipients)) {
			print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('CPKSNoRecipient').'</span></td></tr>';
		}
		foreach ($recipients as $contact) {
			$stat = (isset($stats[$contact->id]) ? $stats[$contact->id] : array('sent' => 0, 'failed' => 0));
			print '<tr class="oddeven">';
			print '<td class="tdoverflowmax200">'.$contact->getNomUrl(1).'</td>';
			print '<td class="tdoverflowmax200">'.(is_object($contact->thirdparty) ? $contact->thirdparty->getNomUrl(1) : '').'</td>';
			print '<td class="tdoverflowmax250">'.dol_print_email($contact->email, 0, 0, 1).'</td>';
			print '<td>'.dol_escape_htmltag($mailer->getContactLanguage($contact)).'</td>';
			print '<td class="center">';
			if ($stat['sent'] > 0) {
				print img_picto($langs->trans('Yes'), 'tick');
			} elseif ($stat['failed'] > 0) {
				print img_warning($langs->trans('CPKSFailedAttempts', $stat['failed']));
			}
			print '</td>';
			print '</tr>';
		}
		print '</table>';
		print '</div><br>';
	}
}

// Substitution variables usable in the email template
print load_fiche_titre($langs->trans('CPKSSubstitutionsTitle'), '', '');
print '<span class="opacitymedium">'.$langs->trans('CPKSSubstitutionsHelp').'</span> ';
print '<a href="'.DOL_URL_ROOT.'/admin/mails_templates.php?search_type_template='.urlencode(StockExportMailer::TEMPLATE_TYPE).'">'.img_picto('', 'email', 'class="pictofixedwidth"').$langs->trans('CPKSEditEmailTemplates').'</a><br><br>';
print '<table class="noborder centpercent">';
foreach (composableproductkitstockGetSubstitutionHelp() as $key => $labelkey) {
	print '<tr class="oddeven"><td class="tdoverflowmax300"><code>'.$key.'</code></td><td>'.$langs->trans($labelkey).'</td></tr>';
}
print '</table>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
