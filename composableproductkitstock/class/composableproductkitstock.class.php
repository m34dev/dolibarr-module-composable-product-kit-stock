<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2025		William Mead			<william@m34d.com>
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

/**
 * Class for ComposableProductKitStock
 */
class ComposableProductKitStock
{
	/**
	 * @param	Product		$product		Product object
	 * @return	int							Maximum composable stock for product kit
	 */
	static function getMaxProductKitComposableStock($product)
	{
		$error = 0;
		$product->get_sousproduits_arbo();
		if(empty($product->sousprods)) {
			$error = -1;
			return $error;
		} else {
			foreach($product->sousprods as $sub_product_data) {
				$sub_product_id = $sub_product_data[0];
				$sub_product = new Product($sub_product_id);
			}
		}
		return 100;
	}
}
