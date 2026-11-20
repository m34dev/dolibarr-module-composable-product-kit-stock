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
			'stock' => $outputlangs->transnoentitiesnoconv('ExportEffectiveStock'),
			'url'   => $outputlangs->transnoentitiesnoconv('PublicUrl'),
		);

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
		return array('ref', 'label', 'price', 'stock', 'url');
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

		$rows = array();
		foreach ($productIds as $productId) {
			$product = new Product($db);
			if ($product->fetch((int) $productId) <= 0) {
				continue;
			}
			if ($onSaleOnly && empty($product->status)) {
				continue;
			}
			$product->load_stock('nobatch');
			$product->fetch_optionals();

			$real_stock = isset($product->stock_reel) ? (float) $product->stock_reel : 0.0;
			$composable_result = self::getProductKitComposableStock((string) $product->id);

			$values = array(
				'ref'   => (string) $product->ref,
				'label' => (string) $product->label,
				'price' => isset($product->price) ? (float) $product->price : 0.0,
				'stock' => ($composable_result >= 0) ? $real_stock + $composable_result : $real_stock,
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
				if ($col == 'price' || $col == 'stock') {
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
		dol_syslog('ComposableProductKitStock::getProductKitComposableStock', LOG_DEBUG);
		global $db;
		$product = new Product($db);
		$result = $product->fetch($product_id);
		if($result < 1) {
			dol_syslog('Error loading product ID: ' . $product_id, LOG_ERR);
			return -1;
		}
		$product->get_sousproduits_arbo();
		$max_composable_subproduct_stock = array();
		$subproducts_physical_stock = array();
		$product_required_subproduct_quantities = array();
		if($product->type == Product::TYPE_SERVICE) {
			dol_syslog('Product is a service', LOG_DEBUG);
			return -2;
		} elseif(empty($product->sousprods)) {
			dol_syslog('Product has no subproducts', LOG_DEBUG);
			return -3;
		} else {
			dol_syslog('Product has ' . count($product->sousprods) . ' subproducts', LOG_DEBUG);

			if (!is_null($warehouse_id)) {
				$warehouse = new Entrepot($db);
				$result = $warehouse->fetch($warehouse_id);
				if ($result < 1) {
					dol_syslog('Error loading warehouse ID: ' . $warehouse_id, LOG_ERR);
					return -4;
				}
				dol_syslog('Warehouse ID: ' . $warehouse_id . ' ref: ' . $warehouse->ref, LOG_DEBUG);
			}

			foreach($product->sousprods as $product_ref => $subproducts_data) {
				dol_syslog('Product Ref: ' . $product_ref, LOG_DEBUG);
				foreach($subproducts_data as $subproduct_id => $subproduct_data) {
					dol_syslog('Subproduct ID: ' . $subproduct_id, LOG_DEBUG);
					// A quantity of 0 (possible through the API, an import or SQL) consumes nothing: it does not limit the kit
					if ((float) $subproduct_data[1] <= 0) {
						dol_syslog('Subproduct ID: ' . $subproduct_id . ' has no quantity in the kit, ignored', LOG_WARNING);
						continue;
					}
					$subproduct = new Product($db);
					$result = $subproduct->fetch($subproduct_id);
					if($result < 1) {
						dol_syslog('Error loading subproduct ID: ' . $subproduct_id, LOG_ERR);
						return -1;
					}
					if($subproduct->type == Product::TYPE_SERVICE) {
						dol_syslog('Subproduct is a service', LOG_DEBUG);
					} else {
						$result = $subproduct->load_stock('nobatch,novirtual');
						if($result < 1) {
							dol_syslog('Error loading stock, subproduct ID: ' . $subproduct_id, LOG_ERR);
						}
						if(is_null($warehouse_id)) {
							$subproducts_physical_stock[$subproduct_id] = $subproduct->stock_reel;
						} else {
							if(!isset($subproduct->stock_warehouse[$warehouse_id])) {
								$subproducts_physical_stock[$subproduct_id] = 0;
								dol_syslog('Subproduct ID: ' . $subproduct_id . ' does not have stock in warehouse ID: ' . $warehouse_id, LOG_DEBUG);
							} else {
								$subproducts_physical_stock[$subproduct_id] = $subproduct->stock_warehouse[$warehouse_id]->real;
								dol_syslog('Subproduct physical stock: ' . $subproducts_physical_stock[$subproduct_id], LOG_DEBUG);
							}
						}
						$product_required_subproduct_quantities[$subproduct_id] = (float) $subproduct_data[1];
						dol_syslog('Product required subproduct ' . $subproduct_id . ' quantity: ' . $product_required_subproduct_quantities[$subproduct_id], LOG_DEBUG);
					}
				}
			}
			foreach($subproducts_physical_stock as $subproduct_id => $subproduct_physical_stock) {
				// Rounded to the stock precision before floor(): with decimal quantities, 0.3 / 0.1 gives 2.9999999999999996
				// A negative stock composes no kit: clamped to 0, so the result never collides with the error codes
				$subproduct_composable_stock = max(0, (int) floor((float) price2num((float) $subproduct_physical_stock / $product_required_subproduct_quantities[$subproduct_id], 'MS', 1)));
				$max_composable_subproduct_stock[$subproduct_id] = $subproduct_composable_stock;
				dol_syslog('Subproduct ID: ' . $subproduct_id . ' composable stock: ' . $subproduct_composable_stock, LOG_DEBUG);
			}
			if (empty($max_composable_subproduct_stock)) {
				return -3;
			}
			$max_composable_stock = min($max_composable_subproduct_stock);
			dol_syslog('Max. composable stock: ' . $max_composable_stock, LOG_DEBUG);
			return $max_composable_stock;
		}
	}
	
	/**
	 * Get product kit composable stock per warehouse
	 *
	 * @param	string		$product_id				Product ID
	 * @return	int|array{ref:string,stock:int}		Composable stock for product kit per warehouse. If no product for ID -1. If the product is a service -2. If no subproducts -3. If no warehouse for ID -4.
	 */
	static function getWarehousesProductKitComposableStock(string $product_id)
	{
		dol_syslog('ComposableProductKitStock::getWarehousesProductKitComposableStock', LOG_DEBUG);
		global $db;
		$product = new Product($db);
		$result = $product->fetch($product_id);
		if($result < 1) {
			dol_syslog('Error loading product ID: ' . $product_id, LOG_ERR);
			return -1;
		}
		$warehouses_product_kit_composable_stock = array();
		$warehouse = new Entrepot($db);
		$warehouses = $warehouse->list_array();
		foreach($warehouses as $warehouse_id => $warehouse_ref) {
			dol_syslog('Warehouse ID: ' . $warehouse_id, LOG_DEBUG);
			$warehouse_product_kit_composable_stock = ComposableProductKitStock::getProductKitComposableStock($product_id, $warehouse_id);
			if($warehouse_product_kit_composable_stock == -1) {
				return -1;
			} elseif($warehouse_product_kit_composable_stock == -2) {
				return -2;
			} elseif($warehouse_product_kit_composable_stock == -3) {
				return -3;
			} elseif($warehouse_product_kit_composable_stock == -4) {
				return -4;
			} elseif($warehouse_product_kit_composable_stock == 0) {
				continue;
			} else {
				$warehouses_product_kit_composable_stock[$warehouse_ref] = $warehouse_product_kit_composable_stock;
			}
		}
		return $warehouses_product_kit_composable_stock;
	}
}
