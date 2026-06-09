<?php
/* Copyright (C) 2025		William Mead			<william@m34d.com>
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
 * \file        htdocs/composableproductkitstock/export_options.php
 * \ingroup     composableproductkitstock
 * \brief       Column selector for CSV export of product kit stock
 */

$res = 0;
if (!$res && file_exists(__DIR__."/../main.inc.php")) {
	$res = @include __DIR__."/../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../main.inc.php")) {
	$res = @include __DIR__."/../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

$langs->loadLangs(array('products', 'stocks', 'composableproductkitstock@composableproductkitstock'));

if (!isModEnabled('composableproductkitstock')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('stock', 'lire')) {
	accessforbidden();
}

$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label('product');

$available_columns = array(
	'ref'   => $langs->trans('Ref'),
	'label' => $langs->trans('Label'),
	'price' => $langs->trans('Price'),
	'stock' => $langs->trans('ExportEffectiveStock'),
);

$extra_columns = array();
if (!empty($extrafields->attributes['product']['label'])) {
	foreach ($extrafields->attributes['product']['label'] as $key => $extralabel) {
		$extra_columns['extra_'.$key] = $extralabel;
	}
}

/*
 * View
 */

$formother = new FormOther($db);

llxHeader('', $langs->trans('ExportDataset_composableproductkitstock_0'), '', '', 0, 0, '', '', '', 'mod-composableproductkitstock page-export_options');

print load_fiche_titre($langs->trans('ExportDataset_composableproductkitstock_0'), '', 'object_composableproductkitstock@composableproductkitstock');

print '<form id="export_options_form" method="POST" action="'.dol_buildpath('/composableproductkitstock/export.php', 1).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans('ExportFilter').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('Categories').'</td>';
print '<td>'.$formother->select_categories('product', 0, 'search_category_product_id').'</td>';
print '</tr>';
print '</table>';
print '</div>';

print '<br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('ExportSelectColumn').'</td>';
print '<td class="center">'.$langs->trans('ExportInclude').'</td>';
print '</tr>';

foreach ($available_columns as $key => $label) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($label).'</td>';
	print '<td class="center"><input type="checkbox" name="columns[]" value="'.dol_escape_htmltag($key).'" checked="checked"></td>';
	print '</tr>';
}

if (!empty($extra_columns)) {
	print '<tr class="liste_titre">';
	print '<td colspan="2">'.$langs->trans('ExtraFields').'</td>';
	print '</tr>';
	foreach ($extra_columns as $key => $label) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($label).'</td>';
		print '<td class="center"><input type="checkbox" name="columns[]" value="'.dol_escape_htmltag($key).'"></td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print '<div class="center" style="padding:20px">';
print dolGetButtonAction($langs->trans('ExportDownloadCSV'), '', 'default', '', '', 1, array(
	'attr' => array('onclick' => "document.getElementById('export_options_form').submit();"),
));
print '</div>';

print '</form>';

llxFooter();
$db->close();
