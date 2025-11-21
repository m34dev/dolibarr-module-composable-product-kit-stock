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

/**
 * Class for ComposableProductKitStock
 */
class ComposableProductKitStock
{
	/**
	 * Get composable stock for a product kit
	 *
	 * If warehouse ID is provided, composable stock is calculated for that warehouse only.
	 *
	 * @param	string			$product_id			Product ID
	 * @param	string|null		$warehouse_id		Warehouse ID
	 * @return	int									Maximum composable stock for product kit. If no product for ID -1. If the product is a service -2. If no subproducts -3. If no warehouse for ID -4.
	 */
	static function getProductKitComposableStock(string $product_id, string|null $warehouse_id = null): int
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
			dol_syslog('ComposableProductKitStock::getProductKitComposableStock', LOG_DEBUG);
			dol_syslog('Product has ' . count($product->sousprods) . ' subproducts', LOG_DEBUG);
			foreach($product->sousprods as $product_ref => $subproducts_data) {
				dol_syslog('Product Ref: ' . $product_ref, LOG_DEBUG);
				foreach($subproducts_data as $subproduct_id => $subproduct_data) {
					dol_syslog('Subproduct ID: ' . $subproduct_id, LOG_DEBUG);
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
							$warehouse = new Entrepot($db);
							$result = $warehouse->fetch($warehouse_id);
							if($result < 1) {
								dol_syslog('Error loading warehouse ID: ' . $warehouse_id, LOG_ERR);
								return -4;
							} else {
								dol_syslog('Warehouse ID: ' . $warehouse_id . ' ref: ' . $warehouse->ref, LOG_DEBUG);
								$subproducts_physical_stock[$subproduct_id] = $subproduct->stock_warehouse[$warehouse_id]->real;
							}
						}
						dol_syslog('Subproduct physical stock: ' . $subproducts_physical_stock[$subproduct_id], LOG_DEBUG);
						$product_required_subproduct_quantities[$subproduct_id] = $subproduct_data[1];
						dol_syslog('Product required subproduct ' . $subproduct_id . ' quantity: ' . $product_required_subproduct_quantities[$subproduct_id], LOG_DEBUG);
					}
				}
			}
			foreach($subproducts_physical_stock as $subproduct_id => $subproduct_physical_stock) {
				$subproduct_composable_stock = floor($subproduct_physical_stock / $product_required_subproduct_quantities[$subproduct_id]);
				$max_composable_subproduct_stock[$subproduct_id] = $subproduct_composable_stock;
				dol_syslog('Subproduct ' . $subproduct_id . ' composable stock: ' . $subproduct_composable_stock, LOG_DEBUG);
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
	static function getWarehousesProductKitComposableStock(string $product_id): int|array
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
