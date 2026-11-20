<?php
/* Copyright (C) 2025       William Mead    <william@m34d.com>
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

use Luracast\Restler\RestException;

dol_include_once('/composableproductkitstock/class/composableproductkitstock.class.php');



/**
 * \file    htdocs/composableproductkitstock/class/api_composableproductkitstock.class.php
 * \ingroup composableproductkitstock
 * \brief   API for ComposableProductKitStock module
 */

/**
 * API class for composableproductkitstock
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class ComposableProductKitStockApi extends DolibarrApi
{
	
	/**
	 * Constructor
	 *
	 * @url     GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}
	
	
	/* BEGIN MODULEBUILDER API MYOBJECT */
	
	/**
	 * Get composable stock for product kit
	 *
	 * Return composable stock for product kit
	 *
	 * @param	string		$ref	Ref of product kit
	 * @return	Int					Composable stock for product kit
	 *
	 * @url	GET composableproductkitstock/{ref}
	 *
	 * @throws RestException 400 Product is a service
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Product not found, or not a kit
	 * @throws RestException 500 Internal server error
	 */
	public function get($ref)
	{
		if (!DolibarrApiAccess::$user->hasRight('produit', 'lire')) {
			throw new RestException(403);
		}
		if (!DolibarrApiAccess::$user->hasRight('stock', 'lire')) {
			throw new RestException(403);
		}
		if (empty($ref)) {
			throw new RestException(400, 'Ref is mandatory');
		}
		$product = new Product($this->db);
		$result = $product->fetch(0, $ref);
		if ($result <= 0) {
			throw new RestException(404, 'Product not found');
		}
		$result = ComposableProductKitStock::computeComposableStock($product);
		if (!is_array($result)) {
			if ($result == -2) {
				throw new RestException(400, 'Product is a service');
			}
			if ($result == -3) {
				throw new RestException(404, 'Product is not a kit or has no stockable subproducts');
			}
			throw new RestException(500, 'Error calculating the composable stock');
		}

		return $result['total'];
	}
}