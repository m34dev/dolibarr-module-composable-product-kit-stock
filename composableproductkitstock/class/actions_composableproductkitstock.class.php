<?php
/* Copyright (C) 2025		William Mead		<william@m34d.com>
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

require_once __DIR__.'/composableproductkitstock.class.php';

/**
 * Class ActionsComposableProductKitStock
 */
class ActionsComposableProductKitStock
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
		if(($action == 'view' || $action == '') && $object instanceof Product) {
			// Total and per-warehouse composable stock from a single reading of the subproducts stock
			$warehouses = getDolGlobalInt('COMPOSABLEPRODUCTKITSTOCK_WAREHOUSEDETAIL') ? ComposableProductKitStock::getWarehouses() : array();
			$result = ComposableProductKitStock::computeComposableStock($object, array_keys($warehouses));
			if(!is_array($result)) {
				$this->results = array('value' => $result);
				$this->resprints = '';
			} else {
				$form = new Form($db);
				$this->results = array('value' => $result['total']);
				$this->resprints = '<tr><td>'.$form->textwithpicto($langs->trans("ComposableProductKitStockLevel"), $langs->trans("ComposableProductKitStockLevelTip")).'</td><td>'.$result['total'].'</td></tr>';

				$detail = '';
				foreach($warehouses as $warehouse_id => $warehouse_ref) {
					if(!empty($result['warehouses'][$warehouse_id])) {
						$detail .= '<tr><td style="padding-left:2em;">'.dol_escape_htmltag($warehouse_ref).'</td><td>'.$result['warehouses'][$warehouse_id].'</td></tr>';
					}
				}
				if($detail !== '') {
					$this->resprints .= '<tr><td>'.$langs->trans("WarehousesComposableProductKitStockLevel").'</td><td></td></tr>'.$detail;
				}
			}
		}
		return 0;
	}

	/**
	 * Overloading the doActions function: replacing the parent's function with the one below
	 *
	 * @param	array			$parameters		Hook metadatas (context, etc...) — contains 'arrayfields' by reference
	 * @param	Product			&$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			&$action		Current action (if set). Generally create or edit or null
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								< 0 on error, 0 on success, 1 to replace standard code
	 */
	function doActions($parameters, &$object, &$action, $hookmanager) {
		global $langs;
		if (in_array('productservicelist', $hookmanager->contextarray)) {
			$langs->load("composableproductkitstock@composableproductkitstock");
			$parameters['arrayfields']['composablestock'] = array(
				'label'    => $langs->trans("ComposableProductKitStockLevelShort"),
				'checked'  => 1,
				'enabled'  => 1,
				'position' => 1000,
			);
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
			if(empty($parameters['arrayfields']['composablestock']['checked'])) {
				return 0;
			}
			$this->resprints = '<td class="liste_titre">&nbsp;</td>';
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
			if(empty($parameters['arrayfields']['composablestock']['checked'])) {
				return 0;
			}
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
			if(in_array('productservicelist', $hookmanager->contextarray) && empty($parameters['arrayfields']['composablestock']['checked'])) {
				return 0;
			}
			if(in_array('productservicelist', $hookmanager->contextarray)) {
				if(empty($parameters['obj'])) {
					return -1;
				}
				// The list row has the id and type, all the calculation needs: no fetch of the product
				$product_object = new Product($this->db);
				$product_object->id = (int) $parameters['obj']->rowid;
				$product_object->type = (int) $parameters['obj']->fk_product_type;
			} elseif(in_array('productcompositioncard', $hookmanager->contextarray) && $object instanceof Product) {
				$product_object = $object;
			} else {
				return -1;
			}
			$result = ComposableProductKitStock::computeComposableStock($product_object);
			if(is_array($result)) {
				$composable_produt_kit_stock = (string) $result['total'];
			} elseif($result == -3) {
				if(in_array('productcompositioncard', $hookmanager->contextarray)) {
					$composable_produt_kit_stock = $langs->trans("NoSubSubProduct");
				} else {
					$composable_produt_kit_stock = $langs->trans("NoSubProduct");
				}
			} else {
				$composable_produt_kit_stock = $langs->trans("NA");
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

	/**
	 * Add the stock export email template type to Setup > Emails > Email templates
	 *
	 * @param	array			$parameters		Hook metadatas, with 'elementList'
	 * @param	CommonObject	&$object		Not used
	 * @param	string			&$action		Current action
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								0 to keep standard types
	 */
	public function emailElementlist($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (in_array('emailtemplates', explode(':', $parameters['context']))) {
			$langs->load('composableproductkitstock@composableproductkitstock');
			dol_include_once('/composableproductkitstock/class/stockexportmailer.class.php');
			$this->results = array(
				StockExportMailer::TEMPLATE_TYPE => img_picto('', 'composableproductkitstock@composableproductkitstock', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('CPKSTemplateType')),
			);
		}
		return 0;
	}
}
