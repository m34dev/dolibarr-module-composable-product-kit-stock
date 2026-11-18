<?php
/* Copyright (C) 2026		William Mead			<william@m34d.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        composableproductkitstock/class/stockexportmailer.class.php
 * \ingroup     composableproductkitstock
 * \brief       Automatic sending of the composable kit stock export by email to the contacts of a category
 */

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/composableproductkitstock/class/composableproductkitstock.class.php');

/**
 * Sends the stock export (CSV) by email, one email per contact of the configured contact category.
 *
 * An hourly scheduled job calls runScheduledSending(). Sending is due once per period (day, week or month,
 * see the module setup); within the sending window, each run sends to at most "batch size" contacts not
 * yet sent during the current period. Sent and failed emails are recorded as agenda events on the contact,
 * which is also how the job knows who was already sent.
 */
class StockExportMailer
{
	/**
	 * Email template type (Setup > Emails > Email templates). At most 32 characters: llx_c_email_templates.type_template
	 * is a varchar(32), longer values are truncated on save and the templates no longer match.
	 */
	const TEMPLATE_TYPE = 'composableproductkitstock';

	/**
	 * Agenda event code of an export sent to a contact
	 */
	const EVENT_SENT = 'AC_CPKS_EXPORT_SENT';

	/**
	 * Agenda event code of a failed sending to a contact
	 */
	const EVENT_FAILED = 'AC_CPKS_EXPORT_FAILED';

	/**
	 * Maximum sending attempts per contact and period
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * @var DoliDB	Database handler
	 */
	public $db;

	/**
	 * @var string	Last error
	 */
	public $error = '';

	/**
	 * @var string[]	Errors
	 */
	public $errors = array();

	/**
	 * @var string	Output of the scheduled job
	 */
	public $output = '';

	/**
	 * @var array<int,array<string,mixed>>|null	Export rows, read once per instance (one job run)
	 */
	private $exportRows = null;

	/**
	 * @var array<string,string>	Generated CSV file per language code
	 */
	private $exportFiles = array();

	/**
	 * @var string	Temporary directory of this instance's CSV files
	 */
	private $exportDir = '';

	/**
	 * @var array<string,Translate>	Output languages already loaded
	 */
	private $outputLangsCache = array();


	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/*
	 * Settings
	 */

	/**
	 * Is automatic sending enabled in the module setup.
	 *
	 * @return	bool
	 */
	public function isAutoEnabled()
	{
		return getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED') > 0;
	}

	/**
	 * Maximum number of emails sent by one run of the scheduled job.
	 *
	 * @return	int
	 */
	public function getBatchSize()
	{
		return max(1, getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_BATCH_SIZE', 10));
	}

	/**
	 * Columns of the emailed export, as configured (only those still available).
	 *
	 * @return	string[]
	 */
	public function getExportColumns()
	{
		global $langs;

		$available = array_keys(ComposableProductKitStock::getExportColumns($langs));
		$configured = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_COLUMNS');
		$columns = ($configured === '' ? ComposableProductKitStock::getDefaultExportColumns() : explode(',', $configured));
		$columns = array_values(array_intersect($columns, $available));

		return empty($columns) ? ComposableProductKitStock::getDefaultExportColumns() : $columns;
	}

	/**
	 * Is the scheduled job allowed to send now: within the configured hours (server time zone) and,
	 * if configured, on a working day (Monday to Friday).
	 *
	 * @param	int|null	$now	Timestamp to test (default: now)
	 * @return	bool
	 */
	public function isInSendingWindow($now = null)
	{
		$now = ($now === null ? dol_now() : $now);
		$tmp = dol_getdate($now, true);
		$hour = (int) $tmp['hours'];
		$weekday = (int) $tmp['wday']; // 0 = Sunday, 6 = Saturday

		if (getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_WORKDAYS_ONLY', 1) && ($weekday == 0 || $weekday == 6)) {
			return false;
		}

		$start = min(23, max(0, getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_HOUR_START', 9)));
		$end = min(24, max(0, getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_HOUR_END', 18)));
		if ($start == $end) {
			return true;
		}
		if ($start < $end) {
			return $hour >= $start && $hour < $end;
		}
		// Window spanning midnight, e.g. 20 -> 6
		return $hour >= $start || $hour < $end;
	}


	/*
	 * Periods
	 */

	/**
	 * Sending date (00:00, server time zone) of a monthly period in a given month: the configured day,
	 * or the last day of the month.
	 *
	 * @param	int		$year	Year
	 * @param	int		$month	Month (1-12)
	 * @return	int				Timestamp
	 */
	private function getMonthlyDate($year, $month)
	{
		$daysinmonth = (int) date('t', dol_mktime(12, 0, 0, $month, 1, $year, 'tzserver'));
		$setting = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_MONTHDAY', '1');
		$day = ($setting == 'last' ? $daysinmonth : min($daysinmonth, max(1, (int) $setting)));

		return (int) dol_mktime(0, 0, 0, $month, $day, $year, 'tzserver');
	}

	/**
	 * Start (00:00, server time zone) of the sending period containing a date: the latest configured
	 * recurrence date before or equal to it.
	 *
	 * @param	int|null	$now	Timestamp (default: now)
	 * @return	int					Timestamp
	 */
	public function getPeriodStart($now = null)
	{
		$now = ($now === null ? dol_now() : $now);
		$tmp = dol_getdate($now, true);
		$today = (int) dol_mktime(0, 0, 0, (int) $tmp['mon'], (int) $tmp['mday'], (int) $tmp['year'], 'tzserver');

		$frequency = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_FREQUENCY', 'weekly');
		if ($frequency == 'daily') {
			return $today;
		}
		if ($frequency == 'monthly') {
			$date = $this->getMonthlyDate((int) $tmp['year'], (int) $tmp['mon']);
			if ($date > $today) {
				$year = ((int) $tmp['mon'] == 1 ? (int) $tmp['year'] - 1 : (int) $tmp['year']);
				$month = ((int) $tmp['mon'] == 1 ? 12 : (int) $tmp['mon'] - 1);
				$date = $this->getMonthlyDate($year, $month);
			}
			return $date;
		}

		// Weekly: configured weekday, 1 = Monday ... 7 = Sunday
		$target = min(7, max(1, getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_WEEKDAY', 1)));
		$isoweekday = ((int) $tmp['wday'] == 0 ? 7 : (int) $tmp['wday']);
		$diff = ($isoweekday - $target + 7) % 7;

		return (int) dol_time_plus_duree($today, -$diff, 'd');
	}

	/**
	 * Start of the sending period following the one containing a date.
	 *
	 * @param	int|null	$now	Timestamp (default: now)
	 * @return	int					Timestamp
	 */
	public function getNextPeriodStart($now = null)
	{
		$start = $this->getPeriodStart($now);

		$frequency = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_FREQUENCY', 'weekly');
		if ($frequency == 'daily') {
			return (int) dol_time_plus_duree($start, 1, 'd');
		}
		if ($frequency == 'monthly') {
			$tmp = dol_getdate($start, true);
			$year = ((int) $tmp['mon'] == 12 ? (int) $tmp['year'] + 1 : (int) $tmp['year']);
			$month = ((int) $tmp['mon'] == 12 ? 1 : (int) $tmp['mon'] + 1);
			return $this->getMonthlyDate($year, $month);
		}
		return (int) dol_time_plus_duree($start, 7, 'd');
	}

	/**
	 * Start of the current period if sending is due for it, else 0.
	 *
	 * A period that started before the day automatic sending was enabled is not due, so that enabling it
	 * does not send at once: the first sending is on the first recurrence date from the activation day.
	 *
	 * @param	int|null	$now	Timestamp (default: now)
	 * @return	int					Timestamp, or 0 if not due
	 */
	public function getDuePeriodStart($now = null)
	{
		if (!$this->isAutoEnabled()) {
			return 0;
		}
		$start = $this->getPeriodStart($now);
		$since = getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED_SINCE');
		if ($since > 0 && $start < dol_get_first_hour($since)) {
			return 0;
		}
		return $start;
	}

	/**
	 * Date of the next sending: start of the current period if it is due, else of the next one that will be.
	 *
	 * @return	int		Timestamp, or 0 if automatic sending is disabled
	 */
	public function getNextSendingDate()
	{
		if (!$this->isAutoEnabled()) {
			return 0;
		}
		$due = $this->getDuePeriodStart();
		if ($due) {
			return $due;
		}
		$next = $this->getNextPeriodStart();
		// Enabled during the current period: the next period may still start before the activation day
		while ($next < dol_get_first_hour(getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_ENABLED_SINCE'))) {
			$next = $this->getNextPeriodStart($next);
		}
		return $next;
	}


	/*
	 * Recipients
	 */

	/**
	 * Contacts receiving the export: active contacts of the configured contact category and its
	 * sub-categories, with a valid email that is not unsubscribed, whose third party (if any) is active.
	 * Contacts sharing an email receive a single email (the first one by id).
	 *
	 * @return	array<int,Contact>|int		Contacts by id, or -1 on error
	 */
	public function getRecipients()
	{
		$categoryId = getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_CONTACT_CATEGORY');
		if ($categoryId <= 0) {
			return array();
		}

		$categoryIds = array($categoryId);
		$categorie = new Categorie($this->db);
		$arbo = $categorie->get_full_arbo(Categorie::TYPE_CONTACT, $categoryId, 1);
		if (is_array($arbo)) {
			foreach ($arbo as $cat) {
				$categoryIds[] = (int) $cat['id'];
			}
		}

		$contactIds = array();
		foreach (array_unique($categoryIds) as $id) {
			$categorie = new Categorie($this->db);
			if ($categorie->fetch($id) <= 0) {
				continue;
			}
			$objects = $categorie->getObjectsInCateg(Categorie::TYPE_CONTACT, 1);
			if (!is_array($objects)) {
				$this->error = $categorie->error;
				return -1;
			}
			foreach ($objects as $contactId) {
				$contactIds[(int) $contactId] = (int) $contactId;
			}
		}
		ksort($contactIds);

		$recipients = array();
		$emails = array();
		foreach ($contactIds as $contactId) {
			$contact = new Contact($this->db);
			if ($contact->fetch($contactId) <= 0) {
				continue;
			}
			if ((int) $contact->status != 1 || !isValidEmail($contact->email)) {
				continue;
			}
			if ($contact->getNoEmail() < 0 || !empty($contact->no_email)) {
				continue; // Unsubscribed (llx_mailing_unsubscribe)
			}
			if ($contact->socid > 0) {
				$contact->fetch_thirdparty();
				if (!is_object($contact->thirdparty) || (int) $contact->thirdparty->status != 1) {
					continue;
				}
			}
			$key = strtolower(trim($contact->email));
			if (isset($emails[$key])) {
				continue;
			}
			$emails[$key] = 1;
			$recipients[$contactId] = $contact;
		}

		return $recipients;
	}

	/**
	 * Sent and failed emails per contact since a date, read from the agenda events of the module.
	 * There is no framework method to count events by code and date, hence the SQL query.
	 *
	 * @param	int		$since	Timestamp
	 * @return	array<int,array{sent:int,failed:int}>|int	By contact id, or -1 on error
	 */
	public function getSendingStats($since)
	{
		global $conf;

		$sql = "SELECT a.fk_element, a.code, COUNT(a.id) as nb FROM ".$this->db->prefix()."actioncomm as a";
		$sql .= " WHERE a.entity = ".((int) $conf->entity);
		$sql .= " AND a.elementtype = 'contact'";
		$sql .= " AND a.code IN ('".$this->db->escape(self::EVENT_SENT)."', '".$this->db->escape(self::EVENT_FAILED)."')";
		$sql .= " AND a.datep >= '".$this->db->idate($since)."'";
		$sql .= " GROUP BY a.fk_element, a.code";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$stats = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$id = (int) $obj->fk_element;
			if (!isset($stats[$id])) {
				$stats[$id] = array('sent' => 0, 'failed' => 0);
			}
			$stats[$id][$obj->code == self::EVENT_SENT ? 'sent' : 'failed'] += (int) $obj->nb;
		}
		$this->db->free($resql);

		return $stats;
	}

	/**
	 * Recipients still to send during a period: not sent yet, and not failed MAX_ATTEMPTS times.
	 *
	 * @param	int		$periodstart	Period start
	 * @return	array<int,Contact>|int	Contacts by id, or -1 on error
	 */
	public function fetchDueRecipients($periodstart)
	{
		$recipients = $this->getRecipients();
		if (!is_array($recipients)) {
			return -1;
		}
		$stats = $this->getSendingStats($periodstart);
		if (!is_array($stats)) {
			return -1;
		}

		foreach (array_keys($recipients) as $contactId) {
			if (isset($stats[$contactId]) && ($stats[$contactId]['sent'] > 0 || $stats[$contactId]['failed'] >= self::MAX_ATTEMPTS)) {
				unset($recipients[$contactId]);
			}
		}
		return $recipients;
	}

	/**
	 * Language of the emails of a contact: contact language, contact country, third party language,
	 * third party country, then the instance default language. Languages of contacts and third parties
	 * are only used when the multi-language option is on, as in the core.
	 *
	 * @param	Contact|null	$contact	Contact (null: current user language)
	 * @return	string						Language code, e.g. fr_FR
	 */
	public function getContactLanguage($contact)
	{
		global $langs;

		$newlang = '';
		if (is_object($contact) && getDolGlobalInt('MAIN_MULTILANGS')) {
			$thirdparty = (is_object($contact->thirdparty) ? $contact->thirdparty : null);
			if (!empty($contact->default_lang)) {
				$newlang = $contact->default_lang;
			} elseif (!empty($contact->country_code)) {
				$newlang = (string) getLanguageCodeFromCountryCode($contact->country_code);
			}
			if (empty($newlang) && $thirdparty && !empty($thirdparty->default_lang)) {
				$newlang = $thirdparty->default_lang;
			}
			if (empty($newlang) && $thirdparty && !empty($thirdparty->country_code)) {
				$newlang = (string) getLanguageCodeFromCountryCode($thirdparty->country_code);
			}
		}
		if (empty($newlang)) {
			$newlang = is_object($contact) ? getDolGlobalString('MAIN_LANG_DEFAULT', $langs->defaultlang) : $langs->defaultlang;
		}
		if (empty($newlang) || $newlang == 'auto') {
			$newlang = $langs->defaultlang;
		}
		return $newlang;
	}

	/**
	 * Translation object for a language, with the files used by the emails and the export loaded.
	 *
	 * @param	string		$langcode	Language code
	 * @return	Translate
	 */
	private function getOutputLangs($langcode)
	{
		global $conf;

		if (!isset($this->outputLangsCache[$langcode])) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($langcode);
			$outputlangs->loadLangs(array('main', 'dict', 'companies', 'mails', 'products', 'stocks', 'composableproductkitstock@composableproductkitstock'));
			$this->outputLangsCache[$langcode] = $outputlangs;
		}
		return $this->outputLangsCache[$langcode];
	}


	/*
	 * Sender and template
	 */

	/**
	 * Sender choices offered in the module setup, with the email each one currently resolves to.
	 *
	 * @return	array<string,string>	Setting value => label
	 */
	public function getSenderOptions()
	{
		global $langs;

		$options = array();
		$companyemail = getDolGlobalString('MAIN_INFO_SOCIETE_MAIL');
		$options['company'] = $langs->trans('CPKSSenderCompany').' ('.($companyemail ? $companyemail : $langs->trans('NotDefined')).')';
		$robotemail = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		$options['robot'] = $langs->trans('CPKSSenderRobot').' ('.($robotemail ? $robotemail : $langs->trans('NotDefined')).')';

		// Public sender profiles (Setup > Emails > Sender profiles): no framework method lists them
		$sql = "SELECT rowid, label, email FROM ".$this->db->prefix()."c_email_senderprofile";
		$sql .= " WHERE active = 1 AND (private = 0 OR private IS NULL) AND entity IN (".getEntity('c_email_senderprofile').")";
		$sql .= " ORDER BY position ASC, label ASC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$options['senderprofile_'.$obj->rowid] = $langs->trans('CPKSSenderProfile').' '.$obj->label.' ('.$obj->email.')';
			}
			$this->db->free($resql);
		}

		return $options;
	}

	/**
	 * Active public sender profile (Setup > Emails > Sender profiles), as offered by getSenderOptions().
	 *
	 * @param	int		$id		Sender profile id
	 * @return	array{email:string,name:string,signature:string}|null	Null if not found, disabled or private
	 */
	public function getSenderProfile($id)
	{
		$sql = "SELECT label, email, signature FROM ".$this->db->prefix()."c_email_senderprofile";
		$sql .= " WHERE rowid = ".((int) $id)." AND active = 1 AND (private = 0 OR private IS NULL)";
		$sql .= " AND entity IN (".getEntity('c_email_senderprofile').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array('email' => (string) $obj->email, 'name' => (string) $obj->label, 'signature' => (string) $obj->signature);
	}

	/**
	 * Configured sender of the emails.
	 *
	 * @return	array{email:string,name:string,signature:string}|null	Null if no valid email found
	 */
	public function getSender()
	{
		$mode = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_SENDER', 'robot');
		$sender = null;

		if ($mode == 'company') {
			$sender = array('email' => getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'), 'name' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM'), 'signature' => '');
		} elseif ($mode == 'robot') {
			$sender = array('email' => getDolGlobalString('MAIN_MAIL_EMAIL_FROM'), 'name' => getDolGlobalString('MAIN_MAIL_EMAIL_FROM_NAME', getDolGlobalString('MAIN_INFO_SOCIETE_NOM')), 'signature' => '');
		} elseif (preg_match('/^senderprofile_(\d+)$/', $mode, $reg)) {
			$sender = $this->getSenderProfile((int) $reg[1]);
		}

		if (empty($sender) || !isValidEmail($sender['email'])) {
			return null;
		}
		return $sender;
	}

	/**
	 * Email template codes offered in the module setup: active public templates of the module type (or of
	 * type "all"), one entry per code with its languages. Private templates are excluded because the
	 * scheduled job runs as another user than the one who selected the template, and would not see them.
	 *
	 * @return	array<string,string>	Template code => label
	 */
	public function getTemplateOptions()
	{
		global $langs, $user;

		$formmail = new FormMail($this->db);
		if ($formmail->fetchAllEMailTemplate(self::TEMPLATE_TYPE, $user, $langs) < 0) {
			dol_syslog(__METHOD__.' '.$formmail->error, LOG_ERR);
			return array();
		}

		$langsbycode = array();
		foreach ($formmail->lines_model as $line) {
			$code = (string) $line->label;
			if (!empty($line->private) || $code === '') {
				continue;
			}
			$langsbycode[$code][] = (empty($line->lang) ? $langs->trans('CPKSTemplateAnyLanguage') : $line->lang);
		}
		ksort($langsbycode);

		$options = array();
		foreach ($langsbycode as $code => $codelangs) {
			$codelangs = array_unique($codelangs);
			sort($codelangs);
			$options[$code] = $code.' ('.implode(', ', $codelangs).')';
		}
		return $options;
	}

	/**
	 * Configured email template in a language: Dolibarr picks the variant of the template code in the
	 * language, else in its main language (es_MX -> es_ES), else the variant without language.
	 *
	 * @param	Translate	$outputlangs	Language
	 * @return	CEmailTemplate|ModelMail|null	Null if no usable variant
	 */
	public function getEmailTemplate($outputlangs)
	{
		global $user;

		$code = getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_TEMPLATE');
		if ($code === '') {
			return null;
		}

		$formmail = new FormMail($this->db);
		$template = $formmail->getEMailTemplate($this->db, self::TEMPLATE_TYPE, $user, $outputlangs, -2, 1, $code);
		if (!is_object($template) || empty($template->id)) {
			return null;
		}
		return $template;
	}


	/*
	 * Export file
	 */

	/**
	 * Export rows of the configured product category (and sub-categories), products on sale only.
	 * Read once per instance, so all the emails of one job run carry the same data.
	 *
	 * @return	array<int,array<string,mixed>>|int	Rows, or -1 on error
	 */
	public function getExportRows()
	{
		if ($this->exportRows === null) {
			$productIds = ComposableProductKitStock::fetchExportProductIds(getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_PRODUCT_CATEGORY'), true);
			if (!is_array($productIds)) {
				return -1;
			}
			$this->exportRows = ComposableProductKitStock::getExportRows($productIds, true);
		}
		return $this->exportRows;
	}

	/**
	 * CSV file of the export with headers in a language, generated once per language and instance.
	 *
	 * @param	Translate	$outputlangs	Language
	 * @return	string						File path, or '' on error
	 */
	private function getExportFile($outputlangs)
	{
		global $conf;

		$langcode = (string) $outputlangs->defaultlang;
		if (isset($this->exportFiles[$langcode])) {
			return $this->exportFiles[$langcode];
		}

		$rows = $this->getExportRows();
		if (!is_array($rows)) {
			return '';
		}

		if ($this->exportDir === '') {
			$tempdir = (!empty($conf->composableproductkitstock->dir_temp) ? $conf->composableproductkitstock->dir_temp : DOL_DATA_ROOT.'/composableproductkitstock/temp');
			$this->exportDir = $tempdir.'/email_'.dol_print_date(dol_now(), 'dayhourlog').'_'.mt_rand(100000, 999999);
		}
		$dir = $this->exportDir.'/'.dol_sanitizeFileName($langcode);
		if (dol_mkdir($dir) < 0) {
			$this->error = 'Failed to create directory '.$dir;
			return '';
		}

		$file = $dir.'/stock_kits_'.dol_print_date(dol_now(), '%Y%m%d-%H%M', 'tzserver').'.csv';
		$content = ComposableProductKitStock::buildExportCsv($this->getExportColumns(), $rows, $outputlangs);
		if (file_put_contents($file, $content) === false) {
			$this->error = 'Failed to write file '.$file;
			return '';
		}
		dolChmod($file);

		$this->exportFiles[$langcode] = $file;
		return $file;
	}

	/**
	 * Delete the CSV files generated by this instance.
	 *
	 * @return	void
	 */
	public function cleanExportFiles()
	{
		if ($this->exportDir !== '' && dol_is_dir($this->exportDir)) {
			dol_delete_dir_recursive($this->exportDir);
		}
		$this->exportFiles = array();
		$this->exportDir = '';
	}


	/*
	 * Sending
	 */

	/**
	 * Send the export to a contact and record the result as an agenda event on the contact.
	 *
	 * @param	Contact		$contact	Recipient
	 * @param	User		$user		User sending (event author)
	 * @return	int						>0 if sent, <0 if failed ($this->error contains the reason)
	 */
	public function sendToContact(Contact $contact, User $user)
	{
		global $langs;

		$langs->loadLangs(array('main', 'mails', 'composableproductkitstock@composableproductkitstock'));

		$to = dol_string_nospecial($contact->getFullName($langs), ' ', array(",")).' <'.$contact->email.'>';
		$mail = $this->sendEmail($to, $contact);

		$contactname = $contact->getFullName($langs);
		if ($mail === null) {
			$this->logEvent($contact, $user, self::EVENT_FAILED, $langs->transnoentitiesnoconv('CPKSEventFailedLabel', $contactname), dol_escape_htmltag($this->error));
			return -1;
		}

		$note = $langs->transnoentitiesnoconv('MailFrom').': '.dol_escape_htmltag($mail['from']).'<br>';
		$note .= $langs->transnoentitiesnoconv('MailTo').': '.dol_escape_htmltag($mail['to']).'<br>';
		if ($mail['cc']) {
			$note .= $langs->transnoentitiesnoconv('MailCC').': '.dol_escape_htmltag($mail['cc']).'<br>';
		}
		$note .= $langs->transnoentitiesnoconv('MailTopic').': '.dol_escape_htmltag($mail['subject']).'<br>';
		$note .= $langs->transnoentitiesnoconv('AttachedFiles').': '.dol_escape_htmltag(basename($mail['file'])).'<br><br>';
		$note .= ($mail['ishtml'] ? $mail['message'] : dol_nl2br(dol_escape_htmltag($mail['message'])));

		$this->logEvent($contact, $user, self::EVENT_SENT, $langs->transnoentitiesnoconv('CPKSEventSentLabel', $contactname), $note, $mail);

		return 1;
	}

	/**
	 * Send a test email to the current user, in the user's language. No agenda event is recorded.
	 *
	 * @param	User	$user	Current user
	 * @return	int				>0 if sent, <0 if failed ($this->error contains the reason)
	 */
	public function sendTest(User $user)
	{
		global $langs;

		if (!isValidEmail($user->email)) {
			$this->error = $langs->transnoentitiesnoconv('CPKSErrorUserHasNoEmail');
			return -1;
		}
		$to = dol_string_nospecial($user->getFullName($langs), ' ', array(",")).' <'.$user->email.'>';
		$mail = $this->sendEmail($to, null);
		$this->cleanExportFiles();

		return ($mail === null ? -1 : 1);
	}

	/**
	 * Build and send the email with the export attached.
	 *
	 * @param	string			$to			Recipient ("Name <email>")
	 * @param	Contact|null	$contact	Contact (language and substitutions), null for a test email
	 * @return	array{from:string,to:string,cc:string,subject:string,message:string,ishtml:int,file:string,msgid:string,contactids:int[]}|null	Null if failed ($this->error contains the reason)
	 */
	private function sendEmail($to, $contact)
	{
		global $langs;

		$this->error = '';

		$sender = $this->getSender();
		if (empty($sender)) {
			$this->error = $langs->transnoentitiesnoconv('CPKSErrorNoSender');
			return null;
		}

		// Template in the recipient language, else in the default language of the instance
		$outputlangs = $this->getOutputLangs($this->getContactLanguage($contact));
		$template = $this->getEmailTemplate($outputlangs);
		$defaultlang = getDolGlobalString('MAIN_LANG_DEFAULT', $langs->defaultlang);
		if (empty($template) && $defaultlang && $defaultlang != 'auto' && $defaultlang != $outputlangs->defaultlang) {
			$outputlangs = $this->getOutputLangs($defaultlang);
			$template = $this->getEmailTemplate($outputlangs);
		}
		if (empty($template)) {
			$this->error = $langs->transnoentitiesnoconv('CPKSErrorNoTemplate', getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_TEMPLATE'), $outputlangs->defaultlang);
			return null;
		}

		$rows = $this->getExportRows();
		$file = $this->getExportFile($outputlangs);
		if (!is_array($rows) || $file === '') {
			$this->error = $langs->transnoentitiesnoconv('CPKSErrorExportFailed', $this->error);
			return null;
		}

		$categorylabel = $outputlangs->transnoentitiesnoconv('All');
		$categoryId = getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EMAIL_PRODUCT_CATEGORY');
		if ($categoryId > 0) {
			$categorie = new Categorie($this->db);
			if ($categorie->fetch($categoryId) > 0) {
				$categorylabel = $categorie->label;
			}
		}

		$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $contact);
		$substitutionarray['__CONTACTCIVNAME__'] = (is_object($contact) ? $contact->getFullName($outputlangs, 1) : '');
		$substitutionarray['__SENDEREMAIL_SIGNATURE__'] = $sender['signature'];
		$substitutionarray['__CPKS_NB_PRODUCTS__'] = count($rows);
		$substitutionarray['__CPKS_PRODUCT_CATEGORY__'] = $categorylabel;
		$substitutionarray['__CPKS_EXPORT_DATE__'] = dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs);
		complete_substitutions_array($substitutionarray, $outputlangs, $contact);

		$subject = make_substitutions((string) $template->topic, $substitutionarray, $outputlangs);
		$message = make_substitutions((string) $template->content, $substitutionarray, $outputlangs);
		$ishtml = dol_textishtml($message) ? 1 : 0;
		$cc = (empty($template->email_tocc) ? '' : trim(make_substitutions((string) $template->email_tocc, $substitutionarray, $outputlangs)));

		$from = dol_string_nospecial($sender['name'], ' ', array(",")).' <'.$sender['email'].'>';
		$trackid = 'cpks'.(is_object($contact) ? $contact->id : 0);

		$mailfile = new CMailFile(
			$subject,
			$to,
			$from,
			$message,
			array($file),
			array(dol_mimetype($file)),
			array(basename($file)),
			$cc,
			'',
			0,
			$ishtml,
			'',
			'',
			$trackid,
			'',
			'standard'
		);
		if (!empty($mailfile->error) || !$mailfile->sendfile()) {
			$this->error = $langs->transnoentitiesnoconv('CPKSErrorSendFailed', $mailfile->error);
			return null;
		}

		return array(
			'from' => $from,
			'to' => $to,
			'cc' => $cc,
			'subject' => $subject,
			'message' => $message,
			'ishtml' => $ishtml,
			'file' => $file,
			'msgid' => (string) $mailfile->msgid,
			'contactids' => (is_object($contact) ? array((int) $contact->id) : array()),
		);
	}

	/**
	 * Record an agenda event linked to a contact (and its third party).
	 *
	 * @param	Contact					$contact	Contact
	 * @param	User					$user		Author
	 * @param	string					$code		Event code (EVENT_SENT or EVENT_FAILED)
	 * @param	string					$label		Label
	 * @param	string					$note		Description (HTML)
	 * @param	array<string,mixed>		$email		Email details from sendEmail(), empty for a failure
	 * @return	int								Event id if OK, <0 if KO
	 */
	private function logEvent(Contact $contact, User $user, $code, $label, $note, $email = array())
	{
		$now = dol_now();

		$actioncomm = new ActionComm($this->db);
		$actioncomm->type_code = 'AC_OTH_AUTO';
		$actioncomm->code = $code;
		$actioncomm->label = dol_trunc($label, 255, 'right', 'UTF-8', 1);
		$actioncomm->note_private = $note;
		$actioncomm->datep = $now;
		$actioncomm->datef = $now;
		$actioncomm->durationp = 0;
		$actioncomm->percentage = -1;
		$actioncomm->socid = ($contact->socid > 0 ? (int) $contact->socid : 0);
		$actioncomm->contact_id = (int) $contact->id;
		$actioncomm->socpeopleassigned[$contact->id] = array('id' => $contact->id);
		$actioncomm->authorid = $user->id;
		$actioncomm->userownerid = $user->id;
		$actioncomm->fk_element = (int) $contact->id;
		$actioncomm->elementid = (int) $contact->id;
		$actioncomm->elementtype = $contact->element;

		if (!empty($email)) {
			$actioncomm->email_from = $email['from'];
			$actioncomm->email_to = $email['to'];
			$actioncomm->email_tocc = $email['cc'];
			$actioncomm->email_subject = $email['subject'];
			$actioncomm->email_msgid = $email['msgid'];
		}

		$result = $actioncomm->create($user);
		if ($result < 0) {
			dol_syslog(__METHOD__.' failed to create agenda event for contact '.$contact->id.': '.$actioncomm->error.' '.implode(',', $actioncomm->errors), LOG_WARNING);
		}
		return $result;
	}


	/*
	 * Scheduled job
	 */

	/**
	 * Scheduled job (hourly): when sending is due for the current period and within the sending window,
	 * send the export to at most "batch size" contacts not sent yet during the period. Contacts left over
	 * are handled by the next runs.
	 *
	 * @return	int		0 if OK, <0 if error (the cron runner treats any non-zero value as an error)
	 */
	public function runScheduledSending()
	{
		global $conf, $langs, $user;

		$langs->loadLangs(array('main', 'mails', 'composableproductkitstock@composableproductkitstock'));
		$this->output = '';
		$this->error = '';
		$this->errors = array();

		if (!$this->isAutoEnabled()) {
			$this->output = $langs->trans('CPKSCronAutoDisabled');
			return 0;
		}
		$periodstart = $this->getDuePeriodStart();
		if (!$periodstart) {
			$this->output = $langs->trans('CPKSCronNotDue', dol_print_date($this->getNextSendingDate(), 'day', 'tzserver'));
			return 0;
		}
		if (!$this->isInSendingWindow()) {
			$this->output = $langs->trans('CPKSCronOutsideWindow');
			return 0;
		}

		$recipients = $this->fetchDueRecipients($periodstart);
		if (!is_array($recipients)) {
			return -1;
		}
		if (empty($recipients)) {
			$this->output = $langs->trans('CPKSCronNothingToSend', dol_print_date($periodstart, 'day', 'tzserver'));
			return 0;
		}

		// Configuration problems are reported once, instead of one failure event per contact
		if (!$this->getSender()) {
			$this->error = $langs->trans('CPKSErrorNoSender');
			return -1;
		}
		if (getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_EMAIL_TEMPLATE') === '') {
			$this->error = $langs->trans('CPKSWarningNoTemplate');
			return -1;
		}
		$rows = $this->getExportRows();
		if (!is_array($rows)) {
			$this->error = $langs->trans('CPKSErrorExportFailed', '');
			return -1;
		}
		if (empty($rows)) {
			$this->output = $langs->trans('CPKSCronEmptyExport');
			return 0;
		}

		$batchsize = $this->getBatchSize();
		$nbsent = 0;
		$nbfailed = 0;
		$details = array();
		foreach ($recipients as $contact) {
			if ($nbsent + $nbfailed >= $batchsize) {
				break;
			}
			if ($this->sendToContact($contact, $user) > 0) {
				$nbsent++;
				$details[] = $contact->email.': '.$langs->trans('CPKSSent');
			} else {
				$nbfailed++;
				$details[] = $contact->email.': '.$this->error;
			}
		}
		$this->cleanExportFiles();

		$remaining = count($recipients) - $nbsent - $nbfailed;
		$this->error = '';
		$this->output = $langs->trans('CPKSCronSummary', $nbsent, $nbfailed, $remaining);
		dolibarr_set_const($this->db, 'COMPOSABLEPRODUCTKITSTOCK_EMAIL_LASTRUN', dol_print_date(dol_now(), 'dayhour', 'tzserver').' - '.$this->output, 'chaine', 0, '', $conf->entity);
		if (!empty($details)) {
			$this->output .= "\n".implode("\n", $details);
		}

		return 0;
	}
}
