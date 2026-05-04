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
dol_include_once('/composableproductkitstock/class/composableproductkitstock.class.php');

$langs->loadLangs(array('products', 'stocks', 'composableproductkitstock@composableproductkitstock'));

if (!isModEnabled('composableproductkitstock')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('stock', 'lire')) {
	accessforbidden();
}

top_httphead('text/csv');
header('Content-Disposition: attachment; filename="product_kit_stock_'.dol_print_date(dol_now(), 'dayhourlog').'.csv"');

// UTF-8 BOM so Excel opens the file correctly
echo "\xEF\xBB\xBF";

$headers = array(
	$langs->trans('Ref'),
	$langs->trans('Label'),
	$langs->trans('RealStock'),
	$langs->trans('VirtualStock'),
	$langs->trans('ComposableStock'),
);
echo implode(',', array_map('composableproductkitstock_csvquote', $headers))."\n";

$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product';
$sql .= ' WHERE entity IN ('.getEntity('product').')';
$sql .= ' ORDER BY ref ASC';
$resql = $db->query($sql);
if (!$resql) {
	print $db->lasterror();
	exit;
}

while ($obj = $db->fetch_object($resql)) {
	$product = new Product($db);
	$product->fetch((int) $obj->rowid);
	$product->load_stock('nobatch');

	$real_stock = isset($product->stock_reel) ? (float) $product->stock_reel : 0.0;
	$virtual_stock = isset($product->stock_theorique) ? (float) $product->stock_theorique : $real_stock;

	$composable_result = ComposableProductKitStock::getProductKitComposableStock((string) $obj->rowid);
	$composable_display = ($composable_result >= 0) ? (string) $composable_result : $langs->trans('NA');

	$row = array(
		$product->ref,
		$product->label,
		(string) $real_stock,
		(string) $virtual_stock,
		$composable_display,
	);
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
	return '"'.str_replace('"', '""', (string) $value).'"';
}
