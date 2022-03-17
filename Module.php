<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles;

use Aurora\Api;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Files\Module as FilesModule;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.

 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected static $sStorageType = 'personal';
	protected static $iStorageOrder = 0;

	/**
	 * Indicates if it's allowed to move files/folders to this storage.
	 * @var type bool
	 */
	protected static $bIsDroppable = true;

	protected $oBeforeDeleteUser = null;

	/**
	 *
	 * @var \CApiFilesManager
	 */
	public $oManager = null;

	/**
	 *
	 * @var \CApiModuleDecorator
	 */
	protected $oMinModuleDecorator = null;

	public function getManager()
	{
		if ($this->oManager === null)
		{
			$this->oManager = new Manager($this);
		}

		return $this->oManager;
	}

	/**
	 * Initializes Files Module.
	 *
	 * @ignore
	 */
	public function init()
	{
		ini_set( 'default_charset', 'UTF-8' ); //support for cyrillic characters in file names

		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
		$this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));

		$this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'), 10);
		$this->subscribeEvent('Files::GetItems', array($this, 'onGetItems'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
		$this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
		$this->subscribeEvent('Files::GetQuota::after', array($this, 'onAfterGetQuota'));
		$this->subscribeEvent('Files::CreateLink::after', array($this, 'onAfterCreateLink'));
		$this->subscribeEvent('Files::CreatePublicLink::after', array($this, 'onAfterCreatePublicLink'));
		$this->subscribeEvent('Files::GetFileContent::after', array($this, 'onAfterGetFileContent'));
		$this->subscribeEvent('Files::IsFileExists::after', array($this, 'onAfterIsFileExists'));
		$this->subscribeEvent('Files::PopulateFileItem::after', array($this, 'onAfterPopulateFileItem'));
		$this->subscribeEvent('Files::CheckQuota::after', array($this, 'onAfterCheckQuota'));
		$this->subscribeEvent('Files::DeletePublicLink::after', array($this, 'onAfterDeletePublicLink'));
		$this->subscribeEvent('Files::GetSubModules::after', array($this, 'onAfterGetSubModules'));
		$this->subscribeEvent('Files::UpdateExtendedProps::after', array($this, 'onAfterUpdateExtendedProps'));
		$this->subscribeEvent('Files::GetExtendedProps::after', array($this, 'onAfterGetExtendedProps'));

		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
		$this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));

		$this->subscribeEvent('Files::GetNonExistentFileName::after', array($this, 'onAfterGetNonExistentFileName'));

		$this->subscribeEvent('Files::GetAccessInfoForPath::after', array($this, 'onAfterGetAccessInfoForPath'));

		\Aurora\Modules\Core\Classes\User::extend(
			self::GetName(),
			[
				'UsedSpace' => array('bigint', 0),
			]
		);
	}

	/**
	 * Obtains list of module settings.
	 *
	 * @return array
	 */
	public function GetSettings()
	{
		Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

		return array(
			'UserSpaceLimitMb' => $this->getUserSpaceLimitMb(),
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
		Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);

		FilesModule::getInstance()->setConfig('UserSpaceLimitMb', $UserSpaceLimitMb);
		return (bool) FilesModule::getInstance()->saveModuleConfig();
	}

	public function UpdateUsedSpace()
	{
		$iResult = 0;
		Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		$oUser = Api::getAuthenticatedUser();

		if ($oUser)
		{
			$iResult = $this->getManager()->getUserSpaceUsed($oUser->PublicId, [\Aurora\System\Enums\FileStorageType::Personal]);
			$oUser->setExtendedProp(self::GetName() . '::UsedSpace', $iResult);
			$oUser->save();
		}

		return $iResult;
	}

	/**
	* Returns Min module decorator.
	*
	* @return \CApiModuleDecorator
	*/
	private function getMinModuleDecorator()
	{
		Api::GetModuleDecorator('Min');
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
			$UserId = $aArgs['UserId'];

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$iOffset = isset($aArgs['Offset']) ? $aArgs['Offset'] : 0;
			$iChunkSizet = isset($aArgs['ChunkSize']) ? $aArgs['ChunkSize'] : 0;

			try
			{
				$mResult = $this->getManager()->getFile($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id'], $iOffset, $iChunkSizet);
			}
			catch (\Sabre\DAV\Exception\NotFound $oEx)
			{
				$mResult = false;
//				echo(\Aurora\System\Managers\Response::GetJsonFromObject('Json', \Aurora\System\Managers\Response::FalseResponse(__METHOD__, 404, 'Not Found')));
				$this->oHttp->StatusHeader(404);
				exit;
			}

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
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$Result = $this->getManager()->createFile(
				Api::getUserPublicIdById($UserId),
				isset($aArgs['Type']) ? $aArgs['Type'] : null,
				isset($aArgs['Path']) ? $aArgs['Path'] : null,
				isset($aArgs['Name']) ? $aArgs['Name'] : null,
				isset($aArgs['Data']) ? $aArgs['Data'] : null,
				isset($aArgs['Overwrite']) ? $aArgs['Overwrite'] : null,
				isset($aArgs['RangeType']) ? $aArgs['RangeType'] : null,
				isset($aArgs['Offset']) ? $aArgs['Offset'] : null,
				isset($aArgs['ExtendedProps']) ? $aArgs['ExtendedProps'] : null
			);

			self::Decorator()->UpdateUsedSpace();
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
		$iUserId = Api::getAuthenticatedUserId();

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
			$this->oBeforeDeleteUser = Api::getUserById($aArgs['UserId']);
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterDeleteUser($aArgs, $mResult)
	{
		if ($mResult && $this->oBeforeDeleteUser instanceof \Aurora\Modules\Core\Models\User)
		{
			$this->getManager()->ClearFiles($this->oBeforeDeleteUser->PublicId);
			$this->oBeforeDeleteUser = null;
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
			'IsExternal' => false,
			'Order' => static::$iStorageOrder,
			'IsDroppable' => static::$bIsDroppable
		]);
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetSubModules($aArgs, &$mResult)
	{
		array_unshift($mResult, 'local.' . static::$sStorageType);
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onGetItems($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$sHash = isset($aArgs['PublicHash']) ? $aArgs['PublicHash'] : null;
			$bIsShared = isset($aArgs['Shared']) ? !!$aArgs['Shared'] : false;
			$mResult = array_merge(
				$mResult,
				$this->getManager()->getFiles(
					$sUserPiblicId, 
					$aArgs['Type'], 
					$aArgs['Path'], 
					$aArgs['Pattern'], 
					$sHash,
					$bIsShared
				)
			);
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetFileContent($aArgs, &$mResult)
	{
		$UserId = $aArgs['UserId'];
		Api::CheckAccess($UserId);

		$sUUID = Api::getUserPublicIdById($UserId);
		$Type = $aArgs['Type'];
		$Path = $aArgs['Path'];
		$Name = $aArgs['Name'];

		$mFile = $this->getManager()->getFile($sUUID, $Type, $Path, $Name);
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
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$mResult = $this->getManager()->getFileInfo($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id']);

//			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCreateFolder(&$aArgs, &$mResult)
	{
		$UserId = $aArgs['UserId'];
		Api::CheckAccess($UserId);
		$sUserPiblicId = Api::getUserPublicIdById($UserId);
		if ($this->checkStorageType($aArgs['Type']))
		{
			$mResult = $this->getManager()->createFolder($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['FolderName']);
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
		Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

		if ($this->checkStorageType($aArgs['Type']))
		{
			$Type = $aArgs['Type'];
			$UserId = $aArgs['UserId'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];
			$Link = $aArgs['Link'];

			Api::CheckAccess($UserId);

			if (substr($Link, 0, 11) === 'javascript:')
			{
				$Link = substr($Link, 11);
			}

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			if ($this->checkStorageType($Type))
			{
				$Name = \trim(\MailSo\Base\Utils::ClearFileName($Name));
				$mResult = $this->getManager()->createLink($sUserPiblicId, $Type, $Path, $Link, $Name);
				self::Decorator()->UpdateUsedSpace();
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
		$UserId = $aArgs['UserId'];
		Api::CheckAccess($UserId);
		$sUserPiblicId = Api::getUserPublicIdById($UserId);
		if ($this->checkStorageType($aArgs['Type']))
		{
			$mResult = false;

			foreach ($aArgs['Items'] as $oItem)
			{
				if (isset($oItem['Name']) && strlen(trim($oItem['Name'])) > 0)
				{
					$mResult = $this->getManager()->delete($sUserPiblicId, $aArgs['Type'], $oItem['Path'], $oItem['Name']);
					if (!$mResult)
					{
						break;
					}
				}
			}

			self::Decorator()->UpdateUsedSpace();
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
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$sNewName = \trim(\MailSo\Base\Utils::ClearFileName($aArgs['NewName']));

//			$sNewName = $this->getManager()->getNonExistentFileName($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $sNewName);
			$bIsLink = isset($aArgs['IsLink']) ? $aArgs['IsLink'] : false;
			$mResult = $this->getManager()->rename($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Name'], $sNewName, $bIsLink);
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterCopy(&$aArgs, &$mResult)
	{
		$UserId = $aArgs['UserId'];
		Api::CheckAccess($UserId);

		$sUserPiblicId = Api::getUserPublicIdById($UserId);

		if ($this->checkStorageType($aArgs['FromType']))
		{
			foreach ($aArgs['Files'] as $aItem)
			{
				$bFolderIntoItself = isset($aItem['IsFolder']) && $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
				if (!$bFolderIntoItself)
				{
					$sNewName = isset($aItem['NewName']) ? $aItem['NewName'] : $aItem['Name'];
					$mResult = $this->getManager()->copy(
						$sUserPiblicId,
						$aItem['FromType'],
						$aArgs['ToType'],
						$aItem['FromPath'],
						$aArgs['ToPath'],
						$aItem['Name'],
						$this->getManager()->getNonExistentFileName(
							$sUserPiblicId,
							$aArgs['ToType'],
							$aArgs['ToPath'],
							$sNewName
						)
					);
				}
			}
			self::Decorator()->UpdateUsedSpace();
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
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);

			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			foreach ($aArgs['Files'] as $aItem)
			{
				$bFolderIntoItself = $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
				if (!$bFolderIntoItself)
				{
					$sNewName = isset($aItem['NewName']) ? $aItem['NewName'] : $aItem['Name'];
					$mResult = $this->getManager()->copy(
						$sUserPiblicId,
						$aItem['FromType'],
						$aArgs['ToType'],
						$aItem['FromPath'],
						$aArgs['ToPath'],
						$aItem['Name'],
						$this->getManager()->getNonExistentFileName(
							$sUserPiblicId,
							$aArgs['ToType'],
							$aArgs['ToPath'],
							$sNewName
						),
						true
					);
				}
			}

			self::Decorator()->UpdateUsedSpace();
		}
	}

	protected function getUserSpaceLimitMb()
	{
		$iSpaceLimitMb = FilesModule::getInstance()->getConfig('UserSpaceLimitMb', 0);

		$iUserId = Api::getAuthenticatedUserId();
		$oUser = CoreModule::Decorator()->GetUserUnchecked($iUserId);

		if ($oUser)
		{
			$iSpaceLimitMb = $oUser->{'Files::UserSpaceLimitMb'};
		}

		$aArgs = [
			'UserId' => $iUserId
		];
		$this->broadcastEvent(
			'GetUserSpaceLimitMb',
			$aArgs,
			$iSpaceLimitMb
		);
		return $iSpaceLimitMb;
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetQuota($aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$iSize = 0;

			$oUser = CoreModule::Decorator()->GetUserUnchecked($aArgs['UserId']);

			if ($oUser)
			{
				$iSize = isset($oUser->{self::GetName() . '::UsedSpace'}) ? $oUser->{self::GetName() . '::UsedSpace'} : 0;
			}

			$mResult = array(
				'Used' => (int) $iSize,
				'Limit' => $this->getUserSpaceLimitMb() * 1024 * 1024
			);
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
			$UserId = (int) $aArgs['UserId'];
			$Type = $aArgs['Type'];
			$Path = $aArgs['Path'];
			$Name = $aArgs['Name'];
			$Size = $aArgs['Size'];
			$IsFolder = $aArgs['IsFolder'];

			Api::CheckAccess($UserId);

			Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$bFolder = (bool) $IsFolder;
			$mResult = $this->getManager()->createPublicLink($sUserPiblicId, $Type, $Path, $Name, $Size, $bFolder);
			self::Decorator()->UpdateUsedSpace();
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

			Api::CheckAccess($UserId);

			Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

			$sUserPiblicId = Api::getUserPublicIdById($UserId);

			$mResult = $this->getManager()->deletePublicLink($sUserPiblicId, $Type, $Path, $Name);
			self::Decorator()->UpdateUsedSpace();
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
			Api::CheckAccess($UserId);

			$mResult = $this->getManager()->isFileExists(
				Api::getUserPublicIdById($UserId),
				$aArgs['Type'],
				$aArgs['Path'],
				$aArgs['Name']
			);
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
			$sUserId = $aArgs['UserId'];
			$iSize = (int) $aArgs['Size'];
			$aQuota = FilesModule::Decorator()->GetQuota($sUserId, $Type);
			$Limit = (int) $aQuota['Limit'];
			$Used = (int) $aQuota['Used'];
			$mResult = !($Limit > 0 && $Used + $iSize > $Limit);
			return true;
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterUpdateExtendedProps(&$aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);
			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$mResult = $this->getManager()->updateExtendedProps(
				$sUserPiblicId,
				$aArgs['Type'],
				$aArgs['Path'],
				$aArgs['Name'],
				$aArgs['ExtendedProps']
			);
		}
	}

	/**
	 * @ignore
	 * @param array $aArgs Arguments of event.
	 * @param mixed $mResult Is passed by reference.
	 */
	public function onAfterGetExtendedProps(&$aArgs, &$mResult)
	{
		$bCorrectArgs = isset($aArgs['Type']) && isset($aArgs['Path']) && isset($aArgs['Name']);
		if ($bCorrectArgs && $this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);
			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$mResult = $this->getManager()->getExtendedProps(
				$sUserPiblicId,
				$aArgs['Type'],
				$aArgs['Path'],
				$aArgs['Name']
			);
		}
	}

	public function onAfterGetNonExistentFileName(&$aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);
			$sUserPiblicId = Api::getUserPublicIdById($UserId);
			$mResult = $this->getManager()->getNonExistentFileName(
				$sUserPiblicId,
				$aArgs['Type'],
				$aArgs['Path'],
				$aArgs['Name'],
				$aArgs['WithoutGroup']
			);
		}
	}

	public function onAfterGetAccessInfoForPath(&$aArgs, &$mResult)
	{
		if ($this->checkStorageType($aArgs['Type']))
		{
			$UserId = $aArgs['UserId'];
			Api::CheckAccess($UserId);
			$sUserPiblicId = Api::getUserPublicIdById($UserId);

			$oServer = \Afterlogic\DAV\Server::getInstance();
			$oServer->setUser($sUserPiblicId);
			
			$aItems = [];
			$sPath = $aArgs['Path'];
			$aPaths = explode('/', $sPath);
			do  {
				$oNode = $oServer->tree->getNodeForPath('files/' . $aArgs['Type'] . '/' . $sPath);
				if ($oNode) {
					$aItems[$sPath] = $oNode->getAccess();
				}
				array_pop($aPaths);
				$sPath = implode('/', $aPaths);

			} while (count($aPaths) > 0);
			$mResult = $aItems;
		}
	}
}
