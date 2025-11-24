<?php
/* Copyright (C) 2025       William Mead    <william@m34d.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/composableproductkitstock/core/modules/modComposableProductKitStock.class.php
 * \ingroup composableproductkitstock
 * \brief   Hooks
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once "composableproductkitstock.class.php";

/**
 * Class ActionsComposableProductKitStock
 */
class ActionsComposableProductKitStock extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;
	
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';
	
	/**
	 * @var string[] Errors
	 */
	public $errors = array();
	
	
	/**
	 * @var mixed[] Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();
	
	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;
	
	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;
	
	
	/**
	 * Constructor
	 *
	 *  @param	DoliDB	$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}
	
	/**
	 * Overloading the formObjectOptions function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function formObjectOptions($parameters, &$object, &$action, $hookmanager) {
		global $db, $langs;
		$langs->load("composableproductkitstock@composableproductkitstock");
		if($action == 'view' || $action == '') {
			$composable_produt_kit_stock_label = '';
			$composable_produt_kit_stock = ComposableProductKitStock::getProductKitComposableStock($object->id);
			if($composable_produt_kit_stock == -1 || $composable_produt_kit_stock == -2 || $composable_produt_kit_stock == -3) {
				$this->results = array('value' => $composable_produt_kit_stock);
				$this->resprints = $composable_produt_kit_stock_label;
			} else {
				$form = new Form($db);
				$composable_produt_kit_stock_label = (string)$composable_produt_kit_stock;
				$this->results = array('value' => $composable_produt_kit_stock);
				$this->resprints = '<tr><td>'.$form->textwithpicto($langs->trans("ComposableProductKitStockLevel"), $langs->trans("ComposableProductKitStockLevelTip")).'</td><td>'.$composable_produt_kit_stock_label.'</td></tr>';
			}
			if(getDolGlobalString('COMPOSABLEPRODUCTKITSTOCK_WAREHOUSEDETAIL')) {
				$warehouses_product_kit_composable_stock = ComposableProductKitStock::getWarehousesProductKitComposableStock($object->id);
				if(is_array($warehouses_product_kit_composable_stock) && !empty($warehouses_product_kit_composable_stock)) {
					$this->resprints .= '<tr><td>'.$langs->trans("WarehousesComposableProductKitStockLevel").'</td><td></td></tr>';
					foreach($warehouses_product_kit_composable_stock as $warehouse_ref => $warehouse_composable_stock) {
						$this->resprints .= '<tr><td style="padding-left:2em;">'.$warehouse_ref.'</td><td>'.$warehouse_composable_stock.'</td></tr>';
					}
				}
			}
		}
		return 0;
	}
	
	/**
	 * Overloading the printFieldListOption function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function printFieldListOption($parameters, &$object, &$action, $hookmanager) {
		if(in_array('productservicelist', $hookmanager->contextarray)) {
			$this->resprints = '<td class="liste_titre">&nbsp</td>';
			return 0;
		} else {
			return 0;
		}
	}

	/**
	 * Overloading the printFieldListTitle function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function printFieldListTitle($parameters, &$object, &$action, $hookmanager) {
		global $langs;
		$langs->load("composableproductkitstock@composableproductkitstock");
		if(in_array('productservicelist', $hookmanager->contextarray)) {
			$this->resprints = getTitleFieldOfList($langs->trans("ComposableProductKitStockLevelShort"), 0, $_SERVER["PHP_SELF"], "", "", $parameters['param'], '', $parameters['sortfield'], $parameters['sortorder'], 'center nowrap ');
			$parameters['totalarray']['nbfield']++;
			return 0;
		} elseif(in_array('productcompositioncard', $hookmanager->contextarray)) {
			$this->resprints = '<th class="center">' . $langs->trans("ComposableProductKitStockLevelSubProduct") . '</th>';
			return 0;
		} else {
			return 0;
		}
	}
	
	/**
	 * Overloading the printFieldListValue function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function printFieldListValue($parameters, &$object, &$action, $hookmanager) {
		global $langs;
		$langs->load("composableproductkitstock@composableproductkitstock");
		if(in_array('productservicelist', $hookmanager->contextarray) || in_array('productcompositioncard', $hookmanager->contextarray)) {
			if(in_array('productservicelist', $hookmanager->contextarray)) {
				if(empty($parameters['obj'])) {
					return -1;
				}
				$product_object = $parameters['obj'];
				$result = ComposableProductKitStock::getProductKitComposableStock($product_object->rowid);
			} elseif(in_array('productcompositioncard', $hookmanager->contextarray)) {
				$product_object = $object;
				$result = ComposableProductKitStock::getProductKitComposableStock($product_object->id);
			} else {
				return -1;
			}
			if($result == -1 || $result == -2) {
				$composable_produt_kit_stock = $langs->trans("NA");
			} elseif($result == -3) {
				if(in_array('productcompositioncard', $hookmanager->contextarray)) {
					$composable_produt_kit_stock = $langs->trans("NoSubSubProduct");
				} else {
					$composable_produt_kit_stock = $langs->trans("NoSubProduct");
				}
			} else {
				$composable_produt_kit_stock = empty((string)$result) ? 'error' : (string)$result;
			}
			$this->resprints = '<td class="center nowraponall">' . $composable_produt_kit_stock . '</td>';
			return 0;
		} else {
			return 0;
		}
	}

	/**
	 * Overloading the loadStaticObject function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...)
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function loadStaticObject($parameters, &$object, &$action, $hookmanager) {
		return 0;
	}
}
