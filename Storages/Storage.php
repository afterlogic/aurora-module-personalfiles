<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles\Storages;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 */
class Storage extends \Aurora\System\Managers\AbstractStorage
{
	/**
	 * 
	 * @param \Aurora\System\Managers\AbstractManager $oManager
	 */
	public function __construct(\Aurora\System\Managers\AbstractManager &$oManager)
	{
		parent::__construct($oManager);
	}
	
	/**
	 * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
	 */
	public function init($oAccount)
	{
	
	}
	
	public function isFileExists($oAccount, $iType, $sPath, $sName)
	{
		return false;
	}
	
	public function getFileInfo($iUserId, $sType, $oItem)
	{
	
	}
	
	public function getDirectoryInfo($oAccount, $iType, $sPath)
	{
		
	}
	
	public function getFile($oAccount, $iType, $sPath, $sName)
	{

	}

	public function getFiles($oAccount, $iType, $sPath, $sPattern)
	{
		
	}
	
	public function createFolder($oAccount, $iType, $sPath, $sFolderName)
	{

	}
	
	public function createFile($iUserId, $sType, $sPath, $sFileName, $sData, $rangeType, $offset)
	{

	}
	
	public function createLink($oAccount, $iType, $sPath, $sLink, $sName)
	{
	
		
	}	
	
	public function delete($oAccount, $iType, $sPath, $sName)
	{

	}

	public function rename($oAccount, $iType, $sPath, $sName, $sNewName)
	{

	}
	
	public function copy($oAccount, $iFromType, $iToType, $sFromPath, $sToPath, $sName, $sNewName)
	{
		
	}

	public function getNonExistentFileName($oAccount, $iType, $sPath, $sFileName)
	{
	
	}	
	
	public function clearPrivateFiles($oAccount)
	{
		
	}

	public function clearCorporateFiles($oAccount)
	{
		
	}
}
