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

$allowed_columns = array_keys(ComposableProductKitStock::getExportColumns($langs));

$selected = GETPOST('columns', 'array');
if (empty($selected)) {
	$selected = $allowed_columns;
} else {
	$selected = array_values(array_intersect($selected, $allowed_columns));
	if (empty($selected)) {
		$selected = $allowed_columns;
	}
}

$catId = (int) GETPOST('search_category_product_id', 'int');

$productIds = ComposableProductKitStock::fetchExportProductIds($catId > 0 ? $catId : 0, false);
if (!is_array($productIds)) {
	print $db->lasterror();
	exit;
}
$rows = ComposableProductKitStock::getExportRows($productIds, false);

top_httphead('text/csv');
header('Content-Disposition: attachment; filename="product_kit_stock_'.dol_print_date(dol_now(), 'dayhourlog').'.csv"');

print ComposableProductKitStock::buildExportCsv($selected, $rows, $langs);

exit;
