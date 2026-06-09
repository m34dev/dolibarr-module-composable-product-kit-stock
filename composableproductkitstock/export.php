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
 * \file        htdocs/composableproductkitstock/export.php
 * \ingroup     composableproductkitstock
 * \brief       CSV export of product stock including composable kit stock
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

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
dol_include_once('/composableproductkitstock/class/composableproductkitstock.class.php');

$langs->loadLangs(array('products', 'stocks', 'composableproductkitstock@composableproductkitstock'));

if (!isModEnabled('composableproductkitstock')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('stock', 'lire')) {
	accessforbidden();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: '.dol_buildpath('/composableproductkitstock/export_options.php', 1));
	exit;
}

$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label('product');

$allowed_columns = array('ref', 'label', 'price', 'stock');
$column_labels = array(
	'ref'   => $langs->trans('Ref'),
	'label' => $langs->trans('Label'),
	'price' => $langs->trans('Price'),
	'stock' => $langs->trans('ExportEffectiveStock'),
);

if (!empty($extrafields->attributes['product']['label'])) {
	foreach (array_keys($extrafields->attributes['product']['label']) as $key) {
		$allowed_columns[] = 'extra_'.$key;
		$column_labels['extra_'.$key] = $extralabel;
	}
}

$selected = GETPOST('columns', 'array');
if (empty($selected)) {
	$selected = $allowed_columns;
} else {
	$selected = array_values(array_intersect($selected, $allowed_columns));
	if (empty($selected)) {
		$selected = $allowed_columns;
	}
}

top_httphead('text/csv');
header('Content-Disposition: attachment; filename="product_kit_stock_'.dol_print_date(dol_now(), 'dayhourlog').'.csv"');

// UTF-8 BOM so Excel opens the file correctly
echo "\xEF\xBB\xBF";

$headers = array();
foreach ($selected as $col) {
	$headers[] = $column_labels[$col];
}
echo implode(',', array_map('composableproductkitstock_csvquote', $headers))."\n";

$catId = (int) GETPOST('search_category_product_id', 'int');
$categoryIds = $catId > 0 ? array($catId) : array();

$sql = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.'product as p';
$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
if (!empty($categoryIds)) {
	$sql .= ' AND EXISTS (SELECT ck.fk_product FROM '.MAIN_DB_PREFIX.'categorie_product as ck';
	$sql .= ' WHERE ck.fk_product = p.rowid AND ck.fk_categorie IN ('.implode(',', $categoryIds).'))';
}
$sql .= ' ORDER BY p.ref ASC';
$resql = $db->query($sql);
if (!$resql) {
	print $db->lasterror();
	exit;
}

while ($obj = $db->fetch_object($resql)) {
	$product = new Product($db);
	$product->fetch((int) $obj->rowid);
	$product->load_stock('nobatch');

	$product->fetch_optionals();
	$price = isset($product->price) ? (float) $product->price : 0.0;
	$real_stock = isset($product->stock_reel) ? (float) $product->stock_reel : 0.0;

	$composable_result = ComposableProductKitStock::getProductKitComposableStock((string) $obj->rowid);
	$stock = ($composable_result >= 0) ? (string) ($real_stock + $composable_result) : (string) $real_stock;

	$all_values = array(
		'ref'   => $product->ref,
		'label' => $product->label,
		'price' => (string) $price,
		'stock' => $stock,
	);

	if (!empty($extrafields->attributes['product']['label'])) {
		foreach (array_keys($extrafields->attributes['product']['label']) as $key) {
			$raw = isset($product->array_options['options_'.$key]) ? $product->array_options['options_'.$key] : '';
			$type = $extrafields->attributes['product']['type'][$key] ?? '';
			if ($type === 'date') {
				$all_values['extra_'.$key] = $raw ? dol_print_date((int) $raw, 'day') : '';
			} elseif ($type === 'datetime') {
				$all_values['extra_'.$key] = $raw ? dol_print_date((int) $raw, 'dayhour') : '';
			} elseif ($type === 'boolean' || $type === 'checkbox') {
				$all_values['extra_'.$key] = $raw ? '1' : '0';
			} else {
				$all_values['extra_'.$key] = (string) $raw;
			}
		}
	}

	$row = array();
	foreach ($selected as $col) {
		$row[] = $all_values[$col];
	}
	echo implode(',', array_map('composableproductkitstock_csvquote', $row))."\n";
}

$db->free($resql);
exit;

/**
 * Wrap a CSV cell value in double quotes, escaping any internal double quotes.
 *
 * @param  string $value
 * @return string
 */
function composableproductkitstock_csvquote($value)
{
	$value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	return '"'.str_replace('"', '""', $value).'"';
}
