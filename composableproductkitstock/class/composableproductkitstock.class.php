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
 * \file        htdocs/composableproductkitstock/class/composableproductkitstock.class.php
 * \ingroup     composableproductkitstock
 * \brief       Business logic for ComposableProductKitStock module
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

/**
 * Class for ComposableProductKitStock
 */
class ComposableProductKitStock
{
	/**
	 * Product::load_stock() options: physical stock only, of open warehouses (and internal ones when
	 * the warehouse extra status is enabled). Stock in closed warehouses cannot be used.
	 */
	const STOCK_OPTIONS = 'nobatch,novirtual,warehouseopen,warehouseinternal';

	/**
	 * @var ExtraFields|null	Product extrafields, loaded once
	 */
	private static $productExtraFields = null;

	/**
	 * Product extrafields definitions.
	 *
	 * @return	ExtraFields
	 */
	private static function getProductExtraFields()
	{
		global $db;

		if (self::$productExtraFields === null) {
			self::$productExtraFields = new ExtraFields($db);
			self::$productExtraFields->fetch_name_optionals_label('product');
		}
		return self::$productExtraFields;
	}

	/**
	 * Columns available in the stock export: standard attributes, then product extrafields (key 'extra_<code>').
	 *
	 * @param	Translate	$outputlangs	Language of the labels
	 * @return	array<string,string>		Column key => label (not HTML escaped)
	 */
	public static function getExportColumns($outputlangs)
	{
		$outputlangs->loadLangs(array('products', 'stocks', 'composableproductkitstock@composableproductkitstock'));

		$columns = array(
			'ref'   => $outputlangs->transnoentitiesnoconv('Ref'),
			'label' => $outputlangs->transnoentitiesnoconv('Label'),
			'price' => $outputlangs->transnoentitiesnoconv('Price'),
		);
		if (self::isExportStockCombined()) {
			// Single stock column: the physical stock is included in it
			$columns['stock'] = $outputlangs->transnoentitiesnoconv('ExportCombinedStock');
		} else {
			$columns['stock'] = $outputlangs->transnoentitiesnoconv('ExportEffectiveStock');
			$columns['physical_stock'] = $outputlangs->transnoentitiesnoconv('PhysicalStock');
		}
		$columns['url'] = $outputlangs->transnoentitiesnoconv('PublicUrl');

		$extrafields = self::getProductExtraFields();
		if (!empty($extrafields->attributes['product']['label'])) {
			foreach ($extrafields->attributes['product']['label'] as $key => $extralabel) {
				$columns['extra_'.$key] = $outputlangs->transnoentitiesnoconv($extralabel);
			}
		}

		return $columns;
	}

	/**
	 * Columns exported by default: the standard attributes.
	 *
	 * @return	string[]
	 */
	public static function getDefaultExportColumns()
	{
		if (self::isExportStockCombined()) {
			return array('ref', 'label', 'price', 'stock', 'url');
		}
		return array('ref', 'label', 'price', 'stock', 'physical_stock', 'url');
	}

	/**
	 * Is the export set to a single stock column combining physical and composable stock (module setup).
	 *
	 * @return	bool
	 */
	public static function isExportStockCombined()
	{
		return getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_EXPORT_COMBINED_STOCK') > 0;
	}

	/**
	 * Ids of the products to export.
	 *
	 * @param	int		$categoryId		Product category id, 0 for all products
	 * @param	bool	$withSubcats	Also include the products of the sub-categories
	 * @return	int[]|int				Product ids, or -1 on error
	 */
	public static function fetchExportProductIds($categoryId = 0, $withSubcats = false)
	{
		global $db;

		if ($categoryId > 0) {
			$categoryIds = array((int) $categoryId);
			if ($withSubcats) {
				$categorie = new Categorie($db);
				$arbo = $categorie->get_full_arbo(Categorie::TYPE_PRODUCT, (int) $categoryId, 1);
				if (is_array($arbo)) {
					foreach ($arbo as $cat) {
						$categoryIds[] = (int) $cat['id'];
					}
				}
			}

			$ids = array();
			foreach (array_unique($categoryIds) as $id) {
				$categorie = new Categorie($db);
				if ($categorie->fetch($id) <= 0) {
					continue;
				}
				$objects = $categorie->getObjectsInCateg(Categorie::TYPE_PRODUCT, 1);
				if (!is_array($objects)) {
					return -1;
				}
				foreach ($objects as $productId) {
					$ids[(int) $productId] = (int) $productId;
				}
			}
			return array_values($ids);
		}

		// No category: all products. No framework method returns the ids of all products.
		$sql = 'SELECT p.rowid FROM '.$db->prefix().'product as p';
		$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
		$sql .= ' ORDER BY p.ref ASC';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		$ids = array();
		while ($obj = $db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$db->free($resql);

		return $ids;
	}

	/**
	 * Read the export data of products, sorted by ref. Values are raw: numbers as float, extrafields as stored.
	 *
	 * @param	int[]	$productIds		Product ids
	 * @param	bool	$onSaleOnly		Skip the products not on sale
	 * @return	array<int,array<string,mixed>>	One array column key => value per product
	 */
	public static function getExportRows($productIds, $onSaleOnly = false)
	{
		global $db;

		$extrafields = self::getProductExtraFields();
		$extrakeys = empty($extrafields->attributes['product']['label']) ? array() : array_keys($extrafields->attributes['product']['label']);
		$combined = self::isExportStockCombined();

		$rows = array();
		foreach ($productIds as $productId) {
			$product = new Product($db);
			if ($product->fetch((int) $productId) <= 0) {
				continue;
			}
			if ($onSaleOnly && empty($product->status)) {
				continue;
			}
			$product->fetch_optionals();

			// Stock column, kits: composable stock only by default. Shipping a kit moves the stock of the kit as well as of
			// its subproducts, so the kit's own stock goes negative while the composable stock already accounts for it.
			// With the combined stock option: kit's physical stock (0 if negative, for the same reason) + composable stock.
			// Other products: physical stock, of the same warehouses as the composable stock.
			// The physical stock column is the product's own physical stock, for kits as well (can be negative).
			$product->load_stock(self::STOCK_OPTIONS);
			$physical_stock = isset($product->stock_reel) ? (float) $product->stock_reel : 0.0;
			$composable_result = self::computeComposableStock($product);
			if (!is_array($composable_result)) {
				$stock = $physical_stock;
			} elseif ($combined) {
				$stock = max(0.0, $physical_stock) + $composable_result['total'];
			} else {
				$stock = (float) $composable_result['total'];
			}

			$values = array(
				'ref'   => (string) $product->ref,
				'label' => (string) $product->label,
				'price' => isset($product->price) ? (float) $product->price : 0.0,
				'stock' => $stock,
				'physical_stock' => $physical_stock,
				'url'   => (string) $product->url,
			);
			foreach ($extrakeys as $key) {
				$values['extra_'.$key] = isset($product->array_options['options_'.$key]) ? $product->array_options['options_'.$key] : '';
			}
			$rows[] = $values;
		}

		usort($rows, function ($a, $b) {
			return strcasecmp($a['ref'], $b['ref']);
		});

		return $rows;
	}

	/**
	 * CSV separator: Dolibarr's global setting of CSV exports (Setup > Modules > Data exports).
	 *
	 * @return	string
	 */
	public static function getCsvSeparator()
	{
		$separator = getDolGlobalString('EXPORT_CSV_SEPARATOR_TO_USE', ',');
		return ($separator === '' ? ',' : $separator);
	}

	/**
	 * Build the CSV file content (UTF-8 with BOM, so Excel opens it correctly).
	 *
	 * Numbers use a decimal comma when the separator is ';' (spreadsheets in comma decimal locales), a dot otherwise.
	 *
	 * @param	string[]						$columns		Column keys, in order
	 * @param	array<int,array<string,mixed>>	$rows			Rows from getExportRows()
	 * @param	Translate						$outputlangs	Language of the headers and dates
	 * @return	string
	 */
	public static function buildExportCsv($columns, $rows, $outputlangs)
	{
		$separator = self::getCsvSeparator();
		$labels = self::getExportColumns($outputlangs);
		$extrafields = self::getProductExtraFields();
		$types = empty($extrafields->attributes['product']['type']) ? array() : $extrafields->attributes['product']['type'];

		$out = "\xEF\xBB\xBF";

		$headers = array();
		foreach ($columns as $col) {
			$headers[] = self::csvQuote(isset($labels[$col]) ? $labels[$col] : $col);
		}
		$out .= implode($separator, $headers)."\n";

		foreach ($rows as $values) {
			$line = array();
			foreach ($columns as $col) {
				$raw = isset($values[$col]) ? $values[$col] : '';
				if ($col == 'price' || $col == 'stock' || $col == 'physical_stock') {
					$cell = self::formatNumber($raw, $separator);
				} elseif (strpos($col, 'extra_') === 0) {
					$type = isset($types[substr($col, 6)]) ? $types[substr($col, 6)] : '';
					if ($type === 'date') {
						$cell = $raw ? dol_print_date((int) $raw, 'day', 'auto', $outputlangs) : '';
					} elseif ($type === 'datetime') {
						$cell = $raw ? dol_print_date((int) $raw, 'dayhour', 'auto', $outputlangs) : '';
					} elseif ($type === 'boolean') {
						// Not 'checkbox': it is a multi-value list of keys (e.g. "1,3"), exported as stored like select and radio
						$cell = $raw ? '1' : '0';
					} elseif (($type === 'double' || $type === 'price') && $raw !== '' && $raw !== null) {
						$cell = self::formatNumber($raw, $separator);
					} else {
						$cell = (string) $raw;
					}
				} else {
					$cell = (string) $raw;
				}
				$line[] = self::csvQuote($cell);
			}
			$out .= implode($separator, $line)."\n";
		}

		return $out;
	}

	/**
	 * Format a number for the CSV: no thousands separator, no trailing zeros, decimal comma when the separator is ';'.
	 *
	 * @param	mixed	$value		Number
	 * @param	string	$separator	CSV separator
	 * @return	string
	 */
	private static function formatNumber($value, $separator)
	{
		$s = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');
		if ($s === '-0' || $s === '') {
			$s = '0';
		}
		if ($separator === ';') {
			$s = str_replace('.', ',', $s);
		}
		return $s;
	}

	/**
	 * Wrap a CSV cell value in double quotes, escaping any internal double quotes.
	 *
	 * @param	string	$value	Value
	 * @return	string
	 */
	public static function csvQuote($value)
	{
		$value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return '"'.str_replace('"', '""', $value).'"';
	}

	/**
	 * Warehouses the composable stock is detailed for: the same ones as STOCK_OPTIONS, open ones, and internal
	 * ones when the warehouse extra status is enabled.
	 *
	 * @return	array<int,string>	Warehouse id => ref
	 */
	public static function getWarehouses()
	{
		global $db;

		$warehouse = new Entrepot($db);
		$warehouses = $warehouse->list_array(Entrepot::STATUS_OPEN_ALL);
		if (getDolGlobalString('ENTREPOT_EXTRA_STATUS')) {
			$warehouses += $warehouse->list_array(Entrepot::STATUS_OPEN_INTERNAL);
		}
		return $warehouses;
	}

	/**
	 * Composable stock of a kit, in total and in some warehouses, from one stock reading per subproduct.
	 *
	 * Only the first level of subproducts is read: a subproduct that is itself a kit counts with its own physical
	 * stock. Service subproducts and subproducts with no quantity in the kit are ignored.
	 *
	 * @param	Product		$product		Product (only id and type are used, a full fetch is not needed)
	 * @param	int[]		$warehouseIds	Warehouses to compute the composable stock for
	 * @return	array{total:int,warehouses:array<int,int>}|int	Composable stock, total and by warehouse id. -1 on error, -2 if the product is a service, -3 if no subproducts.
	 */
	public static function computeComposableStock(Product $product, array $warehouseIds = array())
	{
		global $db;

		if ($product->type == Product::TYPE_SERVICE) {
			return -2;
		}
		if (empty($product->id)) {
			return -3;
		}

		// First level only: deeper levels are not used and would cost one query per nested product
		$children = $product->getChildsArbo($product->id, 1);
		if (!is_array($children)) {
			dol_syslog(__METHOD__.' error reading the subproducts of product ID: '.$product->id, LOG_ERR);
			return -1;
		}

		$total = null;
		$byWarehouse = array();
		foreach ($children as $subproductId => $child) {
			// $child: 0 = id, 1 = quantity in the kit, 2 = product type
			if ((int) $child[2] == Product::TYPE_SERVICE) {
				continue;
			}
			$qty = (float) $child[1];
			// A quantity of 0 (possible through the API, an import or SQL) consumes nothing: it does not limit the kit
			if ($qty <= 0) {
				dol_syslog(__METHOD__.' subproduct ID: '.$subproductId.' has no quantity in the kit, ignored', LOG_WARNING);
				continue;
			}

			// load_stock() only needs the id: no fetch of the subproduct
			$subproduct = new Product($db);
			$subproduct->id = (int) $subproductId;
			if ($subproduct->load_stock(self::STOCK_OPTIONS) < 0) {
				dol_syslog(__METHOD__.' error loading the stock of subproduct ID: '.$subproductId.' '.$subproduct->error, LOG_ERR);
			}

			$composable = self::getComposableFromStock((float) $subproduct->stock_reel, $qty);
			$total = ($total === null ? $composable : min($total, $composable));
			foreach ($warehouseIds as $warehouseId) {
				$stock = isset($subproduct->stock_warehouse[$warehouseId]) ? (float) $subproduct->stock_warehouse[$warehouseId]->real : 0.0;
				$composable = self::getComposableFromStock($stock, $qty);
				$byWarehouse[$warehouseId] = (isset($byWarehouse[$warehouseId]) ? min($byWarehouse[$warehouseId], $composable) : $composable);
			}
		}

		if ($total === null) {
			return -3;
		}
		return array('total' => $total, 'warehouses' => $byWarehouse);
	}

	/**
	 * Number of kits a subproduct stock allows.
	 *
	 * Rounded to the stock precision before floor(): with decimal quantities, 0.3 / 0.1 gives 2.9999999999999996.
	 * A negative stock composes no kit: 0, so the result never collides with the error codes.
	 *
	 * @param	float	$stock	Subproduct physical stock
	 * @param	float	$qty	Subproduct quantity in the kit, > 0
	 * @return	int
	 */
	private static function getComposableFromStock($stock, $qty)
	{
		return max(0, (int) floor((float) price2num($stock / $qty, 'MS', 1)));
	}

	/**
	 * Get composable stock for a product kit
	 *
	 * If warehouse ID is provided, composable stock is calculated for that warehouse only.
	 *
	 * @param	string			$product_id			Product ID
	 * @param	string|null		$warehouse_id		Warehouse ID
	 * @return	int									Maximum composable stock for product kit. If no product for ID -1. If the product is a service -2. If no subproducts -3. If no warehouse for ID -4.
	 */
	static function getProductKitComposableStock(string $product_id, ?string $warehouse_id = null): int
	{
		global $db;

		$product = new Product($db);
		if ($product->fetch((int) $product_id) < 1) {
			dol_syslog(__METHOD__.' error loading product ID: '.$product_id, LOG_ERR);
			return -1;
		}

		$warehouseIds = (is_null($warehouse_id) ? array() : array((int) $warehouse_id));
		$result = self::computeComposableStock($product, $warehouseIds);
		if (!is_array($result)) {
			return $result;
		}
		if (is_null($warehouse_id)) {
			return $result['total'];
		}

		$warehouse = new Entrepot($db);
		if ($warehouse->fetch((int) $warehouse_id) < 1) {
			dol_syslog(__METHOD__.' error loading warehouse ID: '.$warehouse_id, LOG_ERR);
			return -4;
		}
		return $result['warehouses'][(int) $warehouse_id];
	}

	/**
	 * Get product kit composable stock per warehouse
	 *
	 * @param	string		$product_id				Product ID
	 * @return	int|array<string,int>				Composable stock for product kit by warehouse ref, warehouses with a composable stock only. If no product for ID -1. If the product is a service -2. If no subproducts -3.
	 */
	static function getWarehousesProductKitComposableStock(string $product_id)
	{
		global $db;

		$product = new Product($db);
		if ($product->fetch((int) $product_id) < 1) {
			dol_syslog(__METHOD__.' error loading product ID: '.$product_id, LOG_ERR);
			return -1;
		}

		$warehouses = self::getWarehouses();
		$result = self::computeComposableStock($product, array_keys($warehouses));
		if (!is_array($result)) {
			return $result;
		}

		$byRef = array();
		foreach ($warehouses as $warehouseId => $warehouseRef) {
			if (!empty($result['warehouses'][$warehouseId])) {
				$byRef[$warehouseRef] = $result['warehouses'][$warehouseId];
			}
		}
		return $byRef;
	}
}
