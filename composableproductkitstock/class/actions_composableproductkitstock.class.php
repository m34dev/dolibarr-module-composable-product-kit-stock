<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2025       William Mead    <william@m34d.com>
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
		$form = new Form($db);
		$error = 0;
		$composableProdutKitStockLabel = '';
		$composableProdutKitStock = ComposableProductKitStock::getMaxProductKitComposableStock($object);
		if($composableProdutKitStock == -1) {
			$this->results = array('value' => $composableProdutKitStock);
			$this->resprints = '';
		} else {
			$composableProdutKitStockLabel = (string)$composableProdutKitStock;
			$this->results = array('value' => $composableProdutKitStock);
			$this->resprints = '<tr><td>'.$form->textwithpicto($langs->trans("ComposableProductKitStockLevel"), $langs->trans("ComposableProductKitStockLevelTip")).'</td><td>'.$composableProdutKitStockLabel.'</td></tr>';
		}
		return 0;
	}
}
