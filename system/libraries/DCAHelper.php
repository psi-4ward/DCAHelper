<?php if(!defined('TL_ROOT')) {die('You cannot access this file directly!');
}

/**
 * @copyright 4ward.media 2012 <http://www.4wardmedia.de>
 * @author Christoph Wiechert <wio@psitrax.de>
 */


class DCAHelper extends Controller
{

	public function __construct()
	{
		parent::__construct();
		$this->import('Database');
		$this->import('BackendUser','User');
	}


	/**
	 * Publish/unpublish
	 *
	 * @param int $intId
	 * @param bool $blnVisible
	 * @throws Exception
	 * @return void
	 */
	public function toggleVisibility($intId, $blnVisible)
	{
		// get the table name
		if($this->Input->get('table'))
		{
			$table = $this->Input->get('table');
		}
		else
		{
			foreach ($GLOBALS['BE_MOD'] as $arrGroup)
			{
				if (is_array($arrGroup[$this->Input->get('do')]['tables']))
				{
					$table = $arrGroup[$this->Input->get('do')]['tables'][0];
					break;
				}
			}
		}

		if(empty($table))	throw new Exception('Could not find the table name!');


		// Check permissions to publish
		if (!$this->User->isAdmin && !$this->User->hasAccess($table.'::published', 'alexf'))
		{
			$this->log('Not enough permissions to publish/unpublish '.$table.' ID "'.$intId.'"', $table.' toggleVisibility', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		$this->createInitialVersion($table, $intId);

		// Trigger the save_callback
		if (is_array($GLOBALS['TL_DCA'][$table]['fields']['published']['save_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$table]['fields']['published']['save_callback'] as $callback)
			{
				$this->import($callback[0]);
				$blnVisible = $this->$callback[0]->$callback[1]($blnVisible, $this);
			}
		}

		// Update the database
		$this->Database->prepare("UPDATE {$table} SET tstamp=". time() .", published='" . ($blnVisible ? 1 : '') . "' WHERE id=?")
					   ->execute($intId);

		$this->createNewVersion($table, $intId);
	}


	/**
	 * Return the "toggle visibility" button
	 * @param array $row
	 * @param string $href
	 * @param string $label
	 * @param string $title
	 * @param string $icon
	 * @param string $attributes
	 * @param string $table
	 * @return string
	 */
	public function toggleIcon($row, $href, $label, $title, $icon, $attributes, $strTable)
	{

		if (strlen($this->Input->get('tid')))
		{
			$this->toggleVisibility($this->Input->get('tid'), ($this->Input->get('state') == 1));
			$this->redirect($this->getReferer());
		}

		// Check permissions AFTER checking the tid, so hacking attempts are logged
		if (!$this->User->isAdmin && !$this->User->hasAccess($strTable.'::published', 'alexf'))
		{
			return '';
		}

		$href .= '&amp;tid='.$row['id'].'&amp;state='.($row['published'] ? '' : 1);

		if (!$row['published'])
		{
			$icon = 'invisible.gif';
		}

		return '<a href="'.$this->addToUrl($href).'" title="'.specialchars($title).'"'.$attributes.'>'.$this->generateImage($icon, $label).'</a> ';
	}


	/**
	 * Auto-generate the alias if it has not been set yet
	 * @param mixed $varValue
	 * @param DataContainer $dc
	 * @throws Exception
	 * @return mixed
	 */
	public function generateAlias($varValue, DataContainer $dc)
	{
		$autoAlias = false;

		$sourceField = (empty($GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['sourceField'])) ? 'name' : $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['sourceField'];

		// Generate alias if there is none
		if (!strlen($varValue))
		{
			$autoAlias = true;
			$varValue = standardize($this->restoreBasicEntities($dc->activeRecord->$sourceField));
		}

		$objAlias = $this->Database->prepare("SELECT id FROM {$dc->table} WHERE alias=?")->execute($varValue);

		// Check whether the news alias exists
		if ($objAlias->numRows > 1 && !$autoAlias)
		{
			throw new Exception(sprintf($GLOBALS['TL_LANG']['ERR']['aliasExists'], $varValue));
		}

		// Add ID to alias
		if ($objAlias->numRows && $autoAlias)
		{
			$varValue .= '-' . $dc->id;
		}

		return $varValue;
	}


	/**
	 * Save the values to an foreign table (ie M:N)
	 *
	 * You have to define a foreignTable array-key in your DCA
	 * 'foreignTable' => 'tl_other_table.otherID'
	 * and set the save_callback to this function
	 *
	 * @param $varValue
	 * @param \DataContainer $dc
	 * @return string empty string (perhaps you want to use evel-param doNotSaveEmpty=>true
	 * @throws Exception
	 */
	public function saveToForeignTable($varValue, DataContainer $dc)
	{
		$varValue = deserialize($varValue,true);

		// read foreignTable
		$foreignTable = $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['foreignTable'];
		if(empty($foreignTable)) throw new Exception('foreignTable not defined in table:'.$dc->table.', field:'.$dc->field);
		list($foreignTable,$foreignField) = explode('.',$foreignTable);

		// fetch existing relations
		$objCurrent = $this->Database->execute("SELECT id,`$foreignField` FROM `$foreignTable` WHERE pid=".$dc->activeRecord->id);
		$arrCurrent = $objCurrent->fetchEach($foreignField);

		// diff to find obsolete relations and delete it from the table
		$arrObsolete = array_diff($arrCurrent,$varValue);
		if(count($arrObsolete))
		{
			foreach($arrObsolete as $obsoleteID)
			{
				$this->Database->prepare("DELETE FROM `$foreignTable` WHERE pid=? AND `$foreignField`=?")
								->execute($dc->activeRecord->id,$obsoleteID);
			}
		}

		// diff to find new relations and insert it to the table
		$arrNew = array_diff($varValue,$arrCurrent);
		if(count($arrNew))
		{
			$tstamp = ($this->Database->fieldExists('tstamp',$foreignTable)) ? ', tstamp='.time() : '';
			foreach($arrNew as $newID)
			{
				$this->Database->prepare("INSERT INTO `$foreignTable` SET pid=?, `$foreignField`=?".$tstamp)
								->execute($dc->activeRecord->id,$newID);
			}
		}

		return '';
	}


	/**
	 * Load relations from an foreign table
	 *
	 * You have to define a foreignTable array-key in your DCA
	 * 'foreignTable' => 'tl_mn_table.otherID'
	 * and set the load_callback to this function
	 *
	 * @param $varValue not
	 * @param \DataContainer $dc
	 * @return array Array with matching entities
	 * @throws Exception
	 */
	public function loadFromForeignTable($varValue, DataContainer $dc)
	{
		// read foreignTable
		$foreignTable = $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['foreignTable'];
		if(empty($foreignTable)) throw new Exception('foreignTable not defined in table:'.$dc->table.', field:'.$dc->field);
		list($foreignTable,$foreignField) = explode('.',$foreignTable);

		// fetch relations
		$objErg = $this->Database->execute("SELECT `$foreignField` FROM `$foreignTable` WHERE pid=".$dc->activeRecord->id);
		return $objErg->fetchEach($foreignField);
	}



	/**
	 * Load options form the taxonomy-modul
	 *
	 * You have to define a taxonomyPID array-key in your DCA
	 * 'taxonomyPID' => 5
	 * and set the options_callback to this function
	 * return the array with (id=>name)
	 *
	 * To load only the names without the entitiy-IDs set
	 * 'taxonomyAssoc' => true
	 * return the array with (name=>name)
	 *
	 *
	 * @param \DataContainer $dc
	 * @return array
	 * @throws Exception
	 */
	public function loadFromTaxonomy($dc)
	{
		$taxonomyPID = $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['taxonomyPID'];
		if(empty($taxonomyPID)) throw new Exception('taxonomyPID not defined in table:'.$dc->table.', field:'.$dc->field);

		// fetch relations
		$objErg = $this->Database->execute("SELECT id,name FROM tl_taxonomy WHERE pid=".$taxonomyPID.' ORDER BY sorting');

		if($GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field]['taxonomyAssoc'])
		{
			return $objErg->fetchEach('name');
		}
		else
		{
			$arrErg = array();
			while($objErg->next()) $arrErg[$objErg->id] = $objErg->name;
			return $arrErg;
		}
	}


	/**
	 * Get array of modules by module type
	 *
	 * using:
	 * 	'inputType'               => 'select',
	 *  'options_callback'        => array('tl_module_custom', 'getModules'),
	 *
	 * or if you want to get only modules of special type then use next code:
	 *
	 * 	'options_callback'        => array('tl_module_custom', 'getArchiveModules'),
	 *
	 * class tl_module_custom extends DCAHelper
	 * {
	 *    public function getArchiveModules(DataContainer $dc)
	 *    {
	 *       return $this->getModules($dc, 'newsreader');
	 *    }
	 * }
	 *
	 * @param DataContainer
	 * @param string $strModuleType Module type
	 * @return array
	 */
	public function getModules(DataContainer $dc, $strModuleType = '')
	{
		$arrModules = array();

		$objModules = $this->Database->prepare('
			SELECT m.id, m.name, t.name AS theme
			FROM tl_module m
			LEFT JOIN tl_theme t ON m.pid=t.id
			' . ($strModuleType ? 'WHERE m.type=?' : '') . '
			ORDER BY t.name, m.name')
				->execute($strModuleType);

		while ($objModules->next())
		{
			$arrModules[$objModules->theme][$objModules->id] = $objModules->name . ' (ID ' . $objModules->id . ')';
		}

		return $arrModules;
	}


	/**
	 * Get all forms and return them as array
	 * @param DataContainer
	 * @return array
	 */
	public function getForms(DataContainer $dc)
	{
		if (!$this->User->isAdmin && !is_array($this->User->forms))
		{
			return array();
		}

		$arrForms = array();
		$objForms = $this->Database->execute("SELECT id, title FROM tl_form ORDER BY title");

		while ($objForms->next())
		{
			if ($this->User->isAdmin || $this->User->hasAccess($objForms->id, 'forms'))
			{
				$arrForms[$objForms->id] = $objForms->title . ' (ID ' . $objForms->id . ')';
			}
		}

		return $arrForms;
	}


	/**
	 * Return the edit module wizard
	 * @param DataContainer
	 * @return string
	 */
	public function editModule(DataContainer $dc)
	{
		return ($dc->value < 1) ? '' : ' <a href="contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $dc->value . '" title="'.sprintf(specialchars($GLOBALS['TL_LANG']['tl_content']['editalias'][1]), $dc->value).'" style="padding-left:3px">' . $this->generateImage('alias.gif', $GLOBALS['TL_LANG']['tl_content']['editalias'][0], 'style="vertical-align:top"') . '</a>';
	}


	/**
	 * Return the edit form wizard
	 * @param DataContainer
	 * @return string
	 */
	public function editForm(DataContainer $dc)
	{
		return ($dc->value < 1) ? '' : ' <a href="contao/main.php?do=form&amp;table=tl_form_field&amp;id=' . $dc->value . '" title="'.sprintf(specialchars($GLOBALS['TL_LANG']['tl_content']['editalias'][1]), $dc->value).'" style="padding-left:3px">' . $this->generateImage('alias.gif', $GLOBALS['TL_LANG']['tl_content']['editalias'][0], 'style="vertical-align:top"') . '</a>';
	}
}
