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
 * \file    composableproductkitstock/lib/composableproductkitstock.lib.php
 * \ingroup composableproductkitstock
 * \brief   Library files with common functions for ComposableProductKitStock
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function composableproductkitstockAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("composableproductkitstock@composableproductkitstock");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/composableproductkitstock/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Setup");
	$head[$h][2] = 'Setup';
	$h++;

	$head[$h][0] = dol_buildpath("/composableproductkitstock/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'composableproductkitstock@composableproductkitstock');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'composableproductkitstock@composableproductkitstock', 'remove');

	return $head;
}


/**
 * Value-from-POST callback of a FormSetup item holding an integer between two bounds.
 *
 * @param	FormSetupItem	$item	Setup item
 * @param	int				$min	Minimum value
 * @param	int				$max	Maximum value
 * @return	callable
 */
function composableproductkitstockIntegerValidator($item, $min, $max)
{
	return function () use ($item, $min, $max) {
		global $langs;
		$value = trim(GETPOST($item->confKey, 'alpha'));
		if (!preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
			setEventMessages($langs->trans('CPKSErrorIntegerBetween', $item->getNameText(), $min, $max), null, 'errors');
			return -1;
		}
		$item->fieldValue = (string) ((int) $value);
		return 1;
	};
}

/**
 * Problems in the configuration that would prevent the stock export emails from being sent, shown on the setup page.
 *
 * @param	DoliDB				$db		Database handler
 * @param	StockExportMailer	$mailer	Mailer
 * @return	string[]					Translated warnings (HTML)
 */
function composableproductkitstockGetSetupWarnings($db, $mailer)
{
	global $langs;

	$warnings = array();

	if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
		$warnings[] = $langs->trans('CPKSWarningMailsDisabled');
	}
	if (getDolGlobalString('MAIN_MAIL_FORCE_SENDTO')) {
		// CMailFile then sends everything to this address and drops cc
		$warnings[] = $langs->trans('CPKSWarningMailsForcedTo', getDolGlobalString('MAIN_MAIL_FORCE_SENDTO'));
	}

	// Scheduled job declared by the module descriptor, which the admin may have disabled
	$nbjobs = 0;
	if (isModEnabled('cron')) {
		require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
		$cronjob = new Cronjob($db);
		if ($cronjob->fetchAll('DESC', 't.rowid', 0, 0, 1, "(t.methodename:=:'runScheduledSending') AND (t.objectname:=:'StockExportMailer')") >= 0) {
			$nbjobs = count($cronjob->lines);
		}
	}
	if ($nbjobs == 0) {
		$warnings[] = $langs->trans('CPKSWarningCronDisabled').' <a href="'.DOL_URL_ROOT.'/cron/list.php">'.$langs->trans('CronList').'</a>';
	}

	if (!$mailer->getSender()) {
		$warnings[] = $langs->trans('CPKSWarningNoSender').' <a href="'.DOL_URL_ROOT.'/admin/mails.php">'.$langs->trans('EMails').'</a>';
	}

	$template = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_TEMPLATE');
	if ($template === '') {
		$warnings[] = $langs->trans('CPKSWarningNoTemplate');
	} elseif (!array_key_exists($template, $mailer->getTemplateOptions())) {
		$warnings[] = $langs->trans('CPKSWarningTemplateUnavailable', dol_escape_htmltag($template));
	}

	if (getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_CONTACT_CATEGORY') <= 0) {
		$warnings[] = $langs->trans('CPKSWarningNoContactCategory');
	}

	return $warnings;
}

/**
 * Substitution variables most useful in a stock export email template, with their description.
 * Every standard substitution variable can be used as well.
 *
 * @return	array<string,string>	Variable => translation key of its description
 */
function composableproductkitstockGetSubstitutionHelp()
{
	return array(
		'__CONTACTCIVNAME__' => 'CPKSSubstContactCivName',
		'__THIRDPARTY_NAME__' => 'CPKSSubstThirdpartyName',
		'__CPKS_NB_PRODUCTS__' => 'CPKSSubstNbProducts',
		'__CPKS_PRODUCT_CATEGORY__' => 'CPKSSubstProductCategory',
		'__CPKS_EXPORT_DATE__' => 'CPKSSubstExportDate',
		'__MYCOMPANY_NAME__' => 'CPKSSubstMyCompanyName',
		'__SENDEREMAIL_SIGNATURE__' => 'CPKSSubstSenderSignature',
	);
}
