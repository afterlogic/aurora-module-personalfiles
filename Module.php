<?php
/**
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing AfterLogic Software License
 * @copyright Copyright (c) 2018, Afterlogic Corp.
 
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected static $sStorageType = 'personal';

	/**
	 *
	 * @var \CApiFilesManager
	 */
	public $oApiFilesManager = null;

	/**
	 *
	 * @var \CApiModuleDecorator
	 */
	protected $oMinModuleDecorator = null;

	/**
	 * Initializes Files Module.
	 * 
	 * @ignore
	 */
	public function init() 
	{
		ini_set( 'default_charset', 'UTF-8' ); //support for cyrillic characters in file names
		$this->oApiFilesManager = new Manager($this);
		
		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
		$this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));

		$this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'), 10);
		$this->subscribeEvent('Files::GetFiles::after', array($this, 'onAfterGetFiles'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
		$this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
		$this->subscribeEvent('Files::GetQuota::after', array($this, 'onAfterGetQuota'));
		$this->subscribeEvent('Files::GetPublicFiles::after', array($this, 'onAfterGetPublicFiles'));
		$this->subscribeEvent('Files::CreateLink::after', array($this, 'onAfterCreateLink'));
		$this->subscribeEvent('Files::CreatePublicLink::after', array($this, 'onAfterCreatePublicLink'));
		$this->subscribeEvent('Files::GetFileContent::after', array($this, 'onAfterGetFileContent'));
		$this->subscribeEvent('Files::IsFileExists::after', array($this, 'onAfterIsFileExists'));
		$this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));
		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
		$this->subscribeEvent('Files::CheckQuota::after', array($this, 'onAfterCheckQuota'));
		$this->subscribeEvent('Files::DeletePublicLink::after', array($this, 'onAfterDeletePublicLink'));
	}
	
	/**
	 * Obtains list of module settings.
	 * 
	 * @return array
	 */
	public function GetSettings()
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		return array(
			'UserSpaceLimitMb' => $this->getConfig('UserSpaceLimitMb', 0),
		);
	}
	
	/**
	 * Updates module's settings - saves them to config.json file.
	 * 
	 * @param int $UserSpaceLimitMb User space limit setting in Mb.
	 * @return bool
	 */
	public function UpdateSettings($UserSpaceLimitMb)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
		
		$this->setConfig('UserSpaceLimitMb', $UserSpaceLimitMb);
		return (bool) $this->saveModuleConfig();
	}
	
	/**
	* Returns Min module decorator.
	* 
	* @return \CApiModuleDecorator
	*/
	private function getMinModuleDecorator()
	{
		return \Aurora\System\Api::GetModuleDecorator('Min');
	}
	
	/**
	 * Checks if storage type is personal.
	 * 
	 * @param string $Type Storage type.
	 * @return bool
	 */
	protected function checkStorageType($Type)
	{
		return $Type === static::$sStorageType;
	}	
	
	/**
	 * Returns HTML title for specified URL.
	 * @param string $sUrl
	 * @return string
	 */
	protected function getHtmlTitle($sUrl)
	{
		$oCurl = curl_init();
		\curl_setopt_array($oCurl, array(
			CURLOPT_URL => $sUrl,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_ENCODING => '',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_AUTOREFERER => true,
			CURLOPT_SSL_VERIFYPEER => false, //required for https urls
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_MAXREDIRS => 5
		));
		$sContent = curl_exec($oCurl);
		//$aInfo = curl_getinfo($oCurl);
		curl_close($oCurl);

		preg_match('/<title>(.*?)<\/title>/s', $sContent, $aTitle);
		return isset($aTitle['1']) ? trim($aTitle['1']) : '';
	}	
	
	/**
	 * Puts file content to $mResult.
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onGetFile($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			$iOffset = isset($aArgs['Offset']) ? $aArgs['Offset'] : 0;
			$iChunkSizet = isset($aArgs['ChunkSize']) ? $aArgs['ChunkSize'] : 0;
			$mResult = $this->oApiFilesManager->getFile($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id'], $iOffset, $iChunkSizet);
			
			return true;
		}
	}	
	
	/**
	 * Creates file.
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onCreateFile($aArgs, &$Result)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$Result = $this->oApiFilesManager->createFile(
				\Aurora\System\Api::getUserPublicIdById(isset($aArgs['UserId']) ? $aArgs['UserId'] : null),
				isset($aArgs['Type']) ? $aArgs['Type'] : null,
				isset($aArgs['Path']) ? $aArgs['Path'] : null,
				isset($aArgs['Name']) ? $aArgs['Name'] : null,
				isset($aArgs['Data']) ? $aArgs['Data'] : null,
				isset($aArgs['Overwrite']) ? $aArgs['Overwrite'] : null,
				isset($aArgs['RangeType']) ? $aArgs['RangeType'] : null,
				isset($aArgs['Offset']) ? $aArgs['Offset'] : null,
				isset($aArgs['ExtendedProps']) ? $aArgs['ExtendedProps'] : null
			);
			
			return true;
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onGetLinkType($aArgs, &$mResult)
	{
		$mResult = '';
	}	

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onCheckUrl($aArgs, &$mResult)
	{
		$iUserId = \Aurora\System\Api::getAuthenticatedUserId();

		if ($iUserId)
		{
			if (!empty($aArgs['Url']))
			{
				$sUrl = $aArgs['Url'];
				if ($sUrl)
				{
					$aRemoteFileInfo = \Aurora\System\Utils::GetRemoteFileInfo($sUrl);
					if ((int)$aRemoteFileInfo['code'] > 0)
					{
						$sFileName = basename($sUrl);
						$sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);

						if (empty($sFileExtension))
						{
							$sFileExtension = \Aurora\System\Utils::GetFileExtensionFromMimeContentType($aRemoteFileInfo['content-type']);
							$sFileName .= '.'.$sFileExtension;
						}

						if ($sFileExtension === 'htm' || $sFileExtension === 'html')
						{
							$sTitle = $this->getHtmlTitle($sUrl);
						}

						$mResult['Name'] = isset($sTitle) && strlen($sTitle)> 0 ? $sTitle : urldecode($sFileName);
						$mResult['Size'] = $aRemoteFileInfo['size'];
					}
				}
			}
		}		
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param \Aurora\Modules\Files\Classes\FileItem $oItem
	 * @return bool
	 */
	public function onAfterPopulateFileItem($aArgs, &$oItem)
	{
		if ($oItem->IsLink)
		{
			$sFileName = basename($oItem->LinkUrl);
			$sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);
			if ($sFileExtension === 'htm' || $sFileExtension === 'html')
			{
//				$oItem->Name = $this->getHtmlTitle($oItem->LinkUrl);
				return true;
			}
		}
	}	

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onBeforeDeleteUser($aArgs, &$mResult)
	{
		if (isset($aArgs['UserId']))
		{
			$oUser = \Aurora\System\Api::getUserById($aArgs['UserId']);
			if ($oUser)
			{
				$this->oApiFilesManager->ClearFiles($oUser->PublicId);
			}
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetStorages($aArgs, &$mResult)
	{
		array_unshift($mResult, [
			'Type' => static::$sStorageType, 
			'DisplayName' => $this->i18N('LABEL_STORAGE'), 
			'IsExternal' => false
		]);
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetFiles($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($this->checkStorageType($aArgs['Type']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			$mResult = $this->oApiFilesManager->getFiles($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Pattern']);
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetFileContent($aArgs, &$mResult) 
	{
		$sUUID = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
		$Type = $aArgs['Type'];
		$Path = $aArgs['Path'];
		$Name = $aArgs['Name'];
		
		$mFile = $this->oApiFilesManager->getFile($sUUID, $Type, $Path, $Name);
		if (is_resource($mFile))
		{
			$mResult = stream_get_contents($mFile);
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetFileInfo($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			$mResult = $this->oApiFilesManager->getFileInfo($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id']);
			
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetPublicFiles($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		$oMinDecorator =  $this->getMinModuleDecorator();
		if ($oMinDecorator)
		{
			$mMin = $oMinDecorator->GetMinByHash($aArgs['Hash']);
			if (!empty($mMin['__hash__']))
			{
				$sUserPublicId = $mMin['UserId'];
				if ($sUserPublicId)
				{
					$aItems = array();
					$sMinPath = implode('/', array($mMin['Path'], $mMin['Name']));
					$Path = $aArgs['Path'];
					$mPos = strpos($Path, $sMinPath);
					if ($mPos === 0 || $Path === '')
					{
						if ($mPos !== 0)
						{
							$Path =  $sMinPath . $Path;
						}
						$Path = str_replace('.', '', $Path);
						try
						{
							$aItems = $this->oApiFilesManager->getFiles($sUserPublicId, $mMin['Type'], $Path, '', $aArgs['Hash']);
						}
						catch (\Exception $oEx)
						{
							$aItems = array();
						}
					}
					$mResult['Items'] = $aItems;

//					$oResult['Quota'] = $this->GetQuota($iUserId);
				}
			}
		}
	}	

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCreateFolder(&$aArgs, &$mResult)
	{
		$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
		if ($this->checkStorageType($aArgs['Type']))
		{
			$mResult = $this->oApiFilesManager->createFolder($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['FolderName']);
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCreateLink($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		if ($this->checkStorageType($aArgs['Type']))
		{
			$Type = $aArgs['Type'];
			$UserId = $aArgs['UserId'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];
			$Link = $aArgs['Link'];

			if (substr($Link, 0, 11) === 'javascript:')
			{
				$Link = substr($Link, 11);
			}

			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($UserId);
			if ($this->checkStorageType($Type))
			{
				$Name = \trim(\MailSo\Base\Utils::ClearFileName($Name));
				$mResult = $this->oApiFilesManager->createLink($sUserPiblicId, $Type, $Path, $Link, $Name);
			}
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterDelete(&$aArgs, &$mResult)
	{
		$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
		if ($this->checkStorageType($aArgs['Type']))
		{
			$mResult = false;

			foreach ($aArgs['Items'] as $oItem)
			{
				if (!empty($oItem['Name']))
				{
					$mResult = $this->oApiFilesManager->delete($sUserPiblicId, $aArgs['Type'], $oItem['Path'], $oItem['Name']);
					if (!$mResult)
					{
						break;
					}
				}
			}
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterRename(&$aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			$sNewName = \trim(\MailSo\Base\Utils::ClearFileName($aArgs['NewName']));

			$sNewName = $this->oApiFilesManager->getNonExistentFileName($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $sNewName);
			$mResult = $this->oApiFilesManager->rename($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Name'], $sNewName, $aArgs['IsLink']);
			
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCopy(&$aArgs, &$mResult)
	{
		$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);

		if ($this->checkStorageType($aArgs['FromType']))
		{
			foreach ($aArgs['Files'] as $aItem)
			{
				$bFolderIntoItself = $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
				if (!$bFolderIntoItself)
				{
					$mResult = $this->oApiFilesManager->copy(
						$sUserPiblicId, 
						$aItem['FromType'], 
						$aArgs['ToType'], 
						$aItem['FromPath'], 
						$aArgs['ToPath'], 
						$aItem['Name'], 
						$this->oApiFilesManager->getNonExistentFileName(
							$sUserPiblicId, 
							$aArgs['ToType'], 
							$aArgs['ToPath'], 
							$aItem['Name']
						)
					);
				}
			}
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterMove(&$aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['FromType']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			foreach ($aArgs['Files'] as $aItem)
			{
				$bFolderIntoItself = $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
				if (!$bFolderIntoItself)
				{
					$mResult = $this->oApiFilesManager->move(
						$sUserPiblicId, 
						$aItem['FromType'], 
						$aArgs['ToType'], 
						$aItem['FromPath'], 
						$aArgs['ToPath'], 
						$aItem['Name'], 
						$this->oApiFilesManager->getNonExistentFileName(
							$sUserPiblicId, 
							$aArgs['ToType'], 
							$aArgs['ToPath'], 
							$aItem['Name']
						)
					);
				}
			}
			
			return true;
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetQuota($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($this->checkStorageType($aArgs['Type']))
		{
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
			$mResult = array(
				'Used' => $this->oApiFilesManager->getUserSpaceUsed($sUserPiblicId, [\Aurora\System\Enums\FileStorageType::Personal]),
				'Limit' => $this->getConfig('UserSpaceLimitMb', 0) * 1024 * 1024
			);
			
			return true;
		}
	}
	
	/**
	 * Creates public link for file or folder.
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCreatePublicLink($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			$Type = $aArgs['Type'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];
			$Size = $aArgs['Size'];
			$IsFolder = $aArgs['IsFolder'];

			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($UserId);
			$bFolder = (bool)$IsFolder;
			$mResult = $this->oApiFilesManager->createPublicLink($sUserPiblicId, $Type, $Path, $Name, $Size, $bFolder);
		}
	}	
	
	/**
	 * Deletes public link from file or folder.
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterDeletePublicLink($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			$Type = $aArgs['Type'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];

			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($UserId);

			$mResult = $this->oApiFilesManager->deletePublicLink($sUserPiblicId, $Type, $Path, $Name);
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterIsFileExists($aArgs, &$mResult)
	{
		if (isset($aArgs['Type']) && $this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			$Type = $aArgs['Type'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];

			$sUserPiblicId = \Aurora\System\Api::getUserPublicIdById($UserId);
			$mResult = $this->oApiFilesManager->isFileExists($sUserPiblicId, $Type, $Path, $Name);
		}
	}
	
	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCheckQuota($aArgs, &$mResult)
	{
		$Type = $aArgs['Type'];
		if ($this->checkStorageType($Type))
		{
			$sUserPublicId = $aArgs['PublicUserId'];
			$iSize = $aArgs['Size'];
			$aQuota = \Aurora\System\Api::GetModuleDecorator('Files')->GetQuota($sUserPublicId, $Type);
			$mResult = !($aQuota['Limit'] > 0 && $aQuota['Used'] + $iSize > $aQuota['Limit']);
			return true;
		}
	}
}
