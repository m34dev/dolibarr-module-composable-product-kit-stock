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
	 * @param	string		$product_id		Product ID
	 * @return	int							Maximum composable stock for product kit. If no product for ID -1. If the product is a service -2. If no subproducts -3.
	 */
	static function getMaxProductKitComposableStock($product_id)
	{
		global $db;
		$product = new Product($db);
		$result = $product->fetch($product_id);
		if($result < 1) {
			return -1;
		}
		$product->get_sousproduits_arbo();
		$max_composable_subproduct_stock = array();
		$subproducts_physical_stock = array();
		$product_required_subproduct_quantities = array();
		if($product->type == Product::TYPE_SERVICE) {
			return -2;
		} elseif(empty($product->sousprods)) {
			return -3;
		} else {
			dol_syslog('ComposableProductKitStock::getMaxProductKitComposableStock', LOG_DEBUG);
			dol_syslog('Product has ' . count($product->sousprods) . ' subproducts', LOG_DEBUG);
			foreach($product->sousprods as $product_ref => $subproducts_data) {
				dol_syslog('Product Ref: ' . $product_ref, LOG_DEBUG);
				foreach($subproducts_data as $subproduct_id => $subproduct_data) {
					dol_syslog('Subproduct ID: ' . $subproduct_id, LOG_DEBUG);
					$subproduct = new Product($db);
					$subproduct->fetch($subproduct_id);
					if($subproduct->type == Product::TYPE_SERVICE) {
						dol_syslog('Subproduct is a service', LOG_DEBUG);
					} else {
						$subproduct->load_stock('nobatch,novirtual');
						$subproducts_physical_stock[$subproduct_id] = $subproduct->stock_reel;
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
}
