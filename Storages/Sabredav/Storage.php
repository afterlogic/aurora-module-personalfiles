<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 * 
 */

namespace Aurora\Modules\PersonalFiles\Storages\Sabredav;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @internal
 * 
 * @package Filestorage
 * @subpackage Storages
 */
class Storage extends \Aurora\Modules\PersonalFiles\Storages\Storage
{
	/**
	 * @param string $sUserPublicId
	 * @param string $sType
	 * @param bool $bUser
	 *
	 * @return string|null
	 */
	protected function getRootPath($sUserPublicId, $sType, $bUser = false)
	{
		$sRootPath = null;
		if ($sUserPublicId)
		{
			$oUser = \Aurora\Modules\Core\Module::getInstance()->GetUserByPublicId($sUserPublicId);
			if ($oUser)
			{
				$sUser = $bUser ? '/' . $oUser->UUID : '';
				$sRootPath = \Aurora\System\Api::DataPath() . \Afterlogic\DAV\Constants::FILESTORAGE_PATH_ROOT . 
					\Afterlogic\DAV\Constants::FILESTORAGE_PATH_PERSONAL . $sUser;
			}

			if ($sType === \Aurora\System\Enums\FileStorageType::Corporate)
			{
				$iTenantId = $oUser ? $oUser->IdTenant : 0;

				$sTenant = $bUser ? '/' . $iTenantId : '';
				$sRootPath = \Aurora\System\Api::DataPath() . \Afterlogic\DAV\Constants::FILESTORAGE_PATH_ROOT . 
					\Afterlogic\DAV\Constants::FILESTORAGE_PATH_CORPORATE . $sTenant;
			}
			else if ($sType === \Aurora\System\Enums\FileStorageType::Shared)
			{
				$sRootPath = \Aurora\System\Api::DataPath() . \Afterlogic\DAV\Constants::FILESTORAGE_PATH_ROOT . 
					\Afterlogic\DAV\Constants::FILESTORAGE_PATH_SHARED . $sUser;
			}
		}

		return $sRootPath;
	}

	/**
	 * @param int $sUserPublicId
	 * @param string $sType
	 * @param string $sPath
	 *
	 * @return Afterlogic\DAV\FS\Directory|null
	 */
	public function getDirectory($sUserPublicId, $sType, $sPath = '')
	{
		$oDirectory = null;
		
		if ($sUserPublicId)
		{
			$oServer = \Afterlogic\DAV\Server::getInstance();
			$oServer->setUser($sUserPublicId);
			$oDirectory = $oServer->tree->getNodeForPath('files/' . $sType . $sPath . '/');		
		}

		return $oDirectory;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 *
	 * @return bool
	 */
	public function isFileExists($iUserId, $sType, $sPath, $sName)
	{
		$bResult = false;
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);
		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory && $oDirectory->childExists($sName))
		{
			$oItem = $oDirectory->getChild($sName);
			if ($oItem instanceof \Sabre\DAV\FS\Node)
			{
				$bResult = true;
			}
		}
		
		return $bResult;
	}

	/**
	 * @param string $sUserPublicId
	 * @param string $sType
	 * @param object $oItem
	 * @param string $sPublicHash
	 * @param string $sPath
	 *
	 * @return \Aurora\Modules\Files\Classes\FileItem|null
	 */
	public function getFileInfo($sUserPublicId, $sType, $oItem, $sPublicHash = null, $sPath = null)
	{
		$oResult = null;
		if ($oItem !== null)
		{
			$sFilePath = isset($sPath) ? $sPath : $oItem->getRelativePath();

			$oResult /*@var $oResult \Aurora\Modules\Files\Classes\FileItem */ = new  \Aurora\Modules\Files\Classes\FileItem();

			$oResult->Type = $sType;
			$oResult->TypeStr = $sType;
			$oResult->RealPath = $oItem->getPath();
			$oResult->Path = $sFilePath;
			$oResult->Name = $oItem->getName();
			$oResult->Id = $oItem->getId();
			$oResult->FullPath = $oResult->Name !== '' ? $oResult->Path . '/' . $oResult->Id  : $oResult->Path;
			$oResult->ETag = ($oItem instanceof \Afterlogic\DAV\FS\File) ? \trim($oItem->getETag(), '"') : '';
			$sID = '';
			if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
			{
				$sID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sType, $sFilePath, $oItem->getName()]);
				$oResult->IsFolder = true;

				$oResult->AddAction([
					'list' => []
				]);
			}

			$aProps = [];
			if ($oItem instanceof \Afterlogic\DAV\FS\File)
			{
				$aProps = $oItem->getProperties(
					array(
						'Owner', 
						'Published',
						'Name' ,
						'Link', 
						'ExtendedProps'
					)
				);

				$oResult->IsFolder = false;
				$oResult->Size = $oItem->getSize();
				$oResult->LastModified = $oItem->getLastModified();

				$oResult->AddAction([
					'view' => [
						'url' => '?download-file/' . $oResult->getHash($sPublicHash) .'/view'
					]
				]);
				$sID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sType, $sFilePath, $oItem->getName()]);

				$aPathInfo = pathinfo($oResult->Name);
				if (isset($aPathInfo['extension']) && strtolower($aPathInfo['extension']) === 'url')
				{
					$mFileData = $oItem->get();
					if (\is_resource($mFileData))
					{
						$mFileData = stream_get_contents($oItem->get());
					}
					$aUrlFileInfo = \Aurora\System\Utils::parseIniString($mFileData);
					if ($aUrlFileInfo && isset($aUrlFileInfo['URL']))
					{
						$oResult->IsLink = true;
						$oResult->LinkUrl = $aUrlFileInfo['URL'];

						$oResult->AddAction([
							'open' => [
								'url' => $aUrlFileInfo['URL']
							]
						]);
					}
					else
					{
						$oResult->AddAction([
							'download' => [
								'url' => '?download-file/' . $oResult->getHash($sPublicHash)
							]
						]);						
					}
					if (isset($aPathInfo['filename']))
					{
						$oResult->ContentType = \Aurora\System\Utils::MimeContentType($aPathInfo['filename']);
					}							
				}
				else						
				{
					$oResult->AddAction([
						'download' => [
							'url' => '?download-file/' . $oResult->getHash($sPublicHash)
						]
					]);
					$oResult->ContentType = $oItem->getContentType();
				}

				if (!$oResult->ContentType)
				{
					$oResult->ContentType = \Aurora\System\Utils::MimeContentType($oResult->Name);
				}

				$oSettings =& \Aurora\System\Api::GetSettings();
				if ($oSettings->GetValue('AllowThumbnail', true) && !$oResult->Thumb)
				{
					$iThumbnailLimit = ((int) $oSettings->GetValue('ThumbnailMaxFileSizeMb', 5)) * 1024 * 1024;
					$oResult->Thumb = $oResult->Size < $iThumbnailLimit && \Aurora\System\Utils::IsGDImageMimeTypeSuppoted($oResult->ContentType, $oResult->Name);
				}
			}

			$mMin = \Aurora\Modules\Min\Module::getInstance()->GetMinByID($sID);

			$oResult->Published = isset($aProps['Published']) ? $aProps['Published'] : empty($mMin['__hash__']) ? false : true;
			$oResult->Owner = isset($aProps['Owner']) ? $aProps['Owner'] : basename($oItem->getOwner());
			$oResult->ExtendedProps = isset($aProps['ExtendedProps']) ? $aProps['ExtendedProps'] : [];
		}

		return $oResult;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 *
	 * @return Afterlogic\DAV\FS\Directory|null
	 */
	public function getDirectoryInfo($iUserId, $sType, $sPath)
	{
		$sResult = null;
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);
		if ($oDirectory !== null && $oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			$sResult = $oDirectory->getChildrenProperties();
		}

		return $sResult;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 *
	 * @return Afterlogic\DAV\FS\File|null
	 */
	public function getFile($iUserId, $sType, $sPath, $sName)
	{
		$sResult = null;
		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($iUserId);
		$oNode = $oServer->tree->getNodeForPath('files/' . $sType . $sPath . '/' . $sName);		


		if ($oNode instanceof \Afterlogic\DAV\FS\File)
		{
			$sResult = $oNode->get(false);
		}

		return $sResult;
	}

	/**
	 * @param int $sUserPublicId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 *
	 * @return string|false
	 */
	public function createPublicLink($sUserPublicId, $sType, $sPath, $sName, $sSize, $bIsFolder)
	{
		$mResult = false;

		$sID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sType, $sPath, $sName]);

		$oUser = \Aurora\Modules\Core\Module::getInstance()->GetUserByPublicId($sUserPublicId);
		$iUserId = ($oUser instanceof \Aurora\Modules\Core\Classes\User) ? $oUser->EntityId : null;
		
		$oMin = \Aurora\Modules\Min\Module::getInstance();
		$mMin = $oMin->GetMinByID($sID);
		if (!empty($mMin['__hash__']))
		{
			$mResult = $mMin['__hash__'];
		}
		else
		{
			$mResult = $oMin->createMin(
				$sID, 
				array(
					'UserId' => $sUserPublicId,
					'Type' => $sType, 
					'Path' => $sPath, 
					'Name' => $sName,
					'Size' => $sSize,
					'IsFolder' => $bIsFolder
				),
				$iUserId
			);
		}
		
		return '?/files-pub/' . $mResult . '/list';
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 *
	 * @return bool
	 */
	public function deletePublicLink($iUserId, $sType, $sPath, $sName)
	{
		return \Aurora\Modules\Min\Module::getInstance()->DeleteMinByID(
			\Aurora\Modules\Min\Module::generateHashId([$iUserId, $sType, $sPath, $sName])
		);
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sPattern
	 * @param string $sPublicHash
	 *
	 * @return array
	 */
	public function getFiles($iUserId, $sType = \Aurora\System\Enums\FileStorageType::Personal, $sPath = '', $sPattern = '', $sPublicHash = null)
	{
		$aItems = array();
		$aResult = array();

		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);

		if ($oDirectory !== null && $oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			if (!empty($sPattern)/* || is_numeric($sPattern)*/)
			{
				$aItems = $oDirectory->Search($sPattern);
				$aDirectoryInfo = $oDirectory->getChildrenProperties();
				foreach ($aDirectoryInfo as $oDirectoryInfo)
				{
					if (isset($oDirectoryInfo['Link']) && strpos($oDirectoryInfo['Name'], $sPattern) !== false)
					{
						$aItems[] = new \Afterlogic\DAV\FS\File($oDirectory->getPath() . '/' . $oDirectoryInfo['@Name']);
					}
				}
			}
			else
			{
				try
				{
					$aItems = $oDirectory->getChildren();
				}
				catch (\Exception $oEx)
				{
					\Aurora\Api::LogException($oEx);
				}
			}

			foreach ($aItems as $oItem) 
			{
				$aResult[] = $this->getFileInfo($iUserId, $sType, $oItem, $sPublicHash);
			}
			
			usort($aResult, 
				function ($a, $b) 
					{ 
						return ($a->Name > $b->Name); 
					}
			);			
		}

		return $aResult;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sFolderName
	 *
	 * @return bool
	 */
	public function createFolder($iUserId, $sType, $sPath, $sFolderName)
	{
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);

		if ($oDirectory instanceof \Sabre\DAVACL\IACL)
		{
			$oServer = \Afterlogic\DAV\Server::getInstance();
			$oAclPlugin = $oServer->getPlugin('acl');
			$oAclPlugin->checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
		}

		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			$oDirectory->createDirectory($sFolderName);
			return true;
		}

		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sLink
	 * @param string $sName
	 *
	 * @return bool
	 */
	public function createLink($iUserId, $sType, $sPath, $sLink, $sName)
	{
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);

		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			$sFileName = $sName . '.url';

			$oDirectory->createFile(
				$sFileName, 
				"[InternetShortcut]\r\nURL=\"" . $sLink . "\"\r\n"
			);
			$oItem = $oDirectory->getChild($sFileName);
			$oItem->updateProperties(array(
				'Owner' => $iUserId
			));

			return true;
		}

		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sFileName
	 * @param string $sData
	 *
	 * @return bool
	 */
	public function createFile($iUserId, $sType, $sPath, $sFileName, $sData, $rangeType, $offset, $extendedProps = [])
	{
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);

		if ($oDirectory instanceof \Sabre\DAVACL\IACL)
		{
			$oServer = \Afterlogic\DAV\Server::getInstance();
			$oAclPlugin = $oServer->getPlugin('acl');
			$oAclPlugin->checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
		}

		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			$oDirectory->createFile($sFileName, $sData, $rangeType, $offset, $extendedProps);
			return true;
		}

		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 *
	 * @return bool
	 */
	public function delete($iUserId, $sType, $sPath, $sName)
	{
		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($iUserId);
		$sNodePath = 'files/' . $sType . $sPath . '/' . $sName;
		$oItem = $oServer->tree->getNodeForPath($sNodePath);		
		if ($oItem !== null)
		{
			if ($oItem instanceof \Sabre\DAVACL\IACL && !empty(trim($sPath, '/')))
			{
				$oServer = \Afterlogic\DAV\Server::getInstance();
				$oAclPlugin = $oServer->getPlugin('acl');
				$oAclPlugin->checkPrivileges($sNodePath, '{DAV:}write');
			}

			if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
			{
				$this->updateMin($iUserId, $sType, $sPath, $sName, $sName, $oItem, true);
			}

			$oItem->delete();
			return true;
		}

		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param string $sNewName
	 * @param Afterlogic\DAV\FS\File|Afterlogic\DAV\FS\Directory
	 * @param bool $bDelete Default value is **false**.
	 *
	 * @return bool
	 */
	public function updateMin($iUserId, $sType, $sPath, $sName, $sNewName, $oItem, $bDelete = false)
	{
		if ($iUserId)
		{
			$oMin = \Aurora\Modules\Min\Module::getInstance();

			$sRootPath = $this->getRootPath($iUserId, $sType, true);

			$sOldPath = $sPath . '/' . $sName;
			$sNewPath = $sPath . '/' . $sNewName;

			if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
			{
				foreach ($oItem->getChildren() as $oChild)
				{
					if ($oChild instanceof \Afterlogic\DAV\FS\File)
					{
						$sChildPath = substr(dirname($oChild->getPath()), strlen($sRootPath));
						$sID = \Aurora\Modules\Min\Module::generateHashId([$iUserId, $sType, $sChildPath, $oChild->getName()]);
						if ($bDelete)
						{
							$oMin->DeleteMinByID($sID);
						}
						else
						{
							$mMin = $oMin->GetMinByID($sID);
							if (!empty($mMin['__hash__']))
							{
								$sNewChildPath = $sNewPath . substr($sChildPath, strlen($sOldPath));
								$sNewID = \Aurora\Modules\Min\Module::generateHashId([$iUserId, $sType, $sNewChildPath, $oChild->getName()]);
								$mMin['Path'] = $sNewChildPath;
								$oMin->UpdateMinByID($sID, $mMin, $sNewID);
							}					
						}
					}
					if ($oChild instanceof \Afterlogic\DAV\FS\Directory)
					{
						$this->updateMin($iUserId, $sType, $sPath, $sName, $sNewName, $oChild, $bDelete);
					}
				}
			}
		}
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param string $sNewName
	 *
	 * @return bool
	 */
	public function rename($iUserId, $sType, $sPath, $sName, $sNewName)
	{
		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($iUserId);
		$oNode = $oServer->tree->getNodeForPath('files/' . $sType . $sPath . '/' . $sName );		
		if ($oNode !== null)
		{
			if ($oNode->getName() !== $sNewName)
			{
				if ($oNode instanceof \Sabre\DAVACL\IACL)
				{
					$oServer = \Afterlogic\DAV\Server::getInstance();
					$oAclPlugin = $oServer->getPlugin('acl');
					$oAclPlugin->checkPrivileges('files/' . $sType . $sPath . '/' . $sName, '{DAV:}write');
				}

				if (strlen($sNewName) < 200)
				{
					$this->updateMin($iUserId, $sType, $sPath, $sName, $sNewName, $oNode);
					$oNode->setName($sNewName);
					return true;
				}
			}
			else
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param string $sNewName
	 *
	 * @return bool
	 */
	public function renameLink($iUserId, $sType, $sPath, $sName, $sNewName)
	{
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);
		$oItem = $oDirectory->getChild($sName);

		if ($oItem)
		{
			$oItem->updateProperties(array(
				'Name' => $sNewName
			));
			return true;
		}
		return false;
	}

	/**
	 * @param int $iUserId
	 * @param string $sFromType
	 * @param string $sToType
	 * @param string $sFromPath
	 * @param string $sToPath
	 * @param string $sName
	 * @param string $sNewName
	 * @param bool $bMove Default value is **false**.
	 *
	 * @return bool
	 */
	public function copy($iUserId, $sFromType, $sToType, $sFromPath, $sToPath, $sName, $sNewName, $bMove = false)
	{
		$oMin = \Aurora\Modules\Min\Module::getInstance();

		if (empty($sNewName) && !is_numeric($sNewName))
		{
			$sNewName = $sName;
		}

		$sFromRootPath = $this->getRootPath($iUserId, $sFromType, true);
		$sToRootPath = $this->getRootPath($iUserId, $sToType, true);

		$oFromDirectory = $this->getDirectory($iUserId, $sFromType, $sFromPath);
		$oToDirectory = $this->getDirectory($iUserId, $sToType, $sToPath);

		if ($oToDirectory && $oFromDirectory)
		{
			$oItem = $oFromDirectory->getChild($sName);
			if ($oItem !== null)
			{
				if ($oItem instanceof \Afterlogic\DAV\FS\File)
				{
					$oToDirectory->createFile($sNewName, $oItem->get());

					$oItemNew = $oToDirectory->getChild($sNewName);
					$aProps = $oItem->getProperties(array());
					if (!$bMove)				
					{
						$aProps['Owner'] = $iUserId;
					}
					else
					{
						$sChildPath = substr(dirname($oItem->getPath()), strlen($sFromRootPath));
						$sID = \Aurora\Modules\Min\Module::generateHashId([$iUserId, $sFromType, $sChildPath, $oItem->getName()]);

						$sNewChildPath = substr(dirname($oItemNew->getPath()), strlen($sToRootPath));

						$mMin = $oMin->GetMinByID($sID);
						if (!empty($mMin['__hash__']))
						{
							$sNewID = \Aurora\Modules\Min\Module::generateHashId([$iUserId, $sToType, $sNewChildPath, $oItemNew->getName()]);

							$mMin['Path'] = $sNewChildPath;
							$mMin['Type'] = $sToType;
							$mMin['Name'] = $oItemNew->getName();

							$oMin->UpdateMinByID($sID, $mMin, $sNewID);
						}					
					}
					$oItemNew->updateProperties($aProps);
				}
				if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
				{
					$oToDirectory->createDirectory($sNewName);
					$oChildren = $oItem->getChildren();
					foreach ($oChildren as $oChild)
					{
						$sChildNewName = $this->getNonExistentFileName(
								$iUserId, 
								$sToType, 
								$sToPath . '/' . $sNewName, 
								$oChild->getName()
						);
						$this->copy(
							$iUserId, 
							$sFromType, 
							$sToType, 
							$sFromPath . '/' . $sName, 
							$sToPath . '/' . $sNewName, 
							$oChild->getName(), 
							$sChildNewName, 
							$bMove
						);
					}
				}
				if ($bMove)
				{
					$oItem->delete();
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns user used space in bytes for specified storages.
	 * 
	 * @param int $iUserId User identifier.
	 * @param string $aTypes Storage type list. Accepted values in array: **\Aurora\System\Enums\FileStorageType::Personal**, **\Aurora\System\Enums\FileStorageType::Corporate**, **\Aurora\System\Enums\FileStorageType::Shared**.
	 * 
	 * @return int;
	 */
	public function getUserSpaceUsed($iUserId, $aTypes)
	{
		$iUsageSize = 0;
		
		if ($iUserId)
		{
			foreach ($aTypes as $sType)
			{
				$sRootPath = $this->getRootPath($iUserId, $sType, true);
				$aSize = \Aurora\System\Utils::GetDirectorySize($sRootPath);
				$iUsageSize += (int) $aSize['size'];
			}
		}
		
		return $iUsageSize;
	}

	/**
	 * @param \Aurora\Modules\StandardAuth\Classes\Account $oAccount
	 * @param int $iType
	 * @param string $sPath
	 * @param string $sFileName
	 *
	 * @return string
	 */
	public function getNonExistentFileName($oAccount, $iType, $sPath, $sFileName)
	{
		$iIndex = 0;
		$sFileNamePathInfo = pathinfo($sFileName);
		$sUploadNameExt = '';
		$sUploadNameWOExt = $sFileName;
		if (isset($sFileNamePathInfo['extension']))
		{
			$sUploadNameExt = '.'.$sFileNamePathInfo['extension'];
		}

		if (isset($sFileNamePathInfo['filename']))
		{
			$sUploadNameWOExt = $sFileNamePathInfo['filename'];
		}

		while ($this->isFileExists($oAccount, $iType, $sPath, $sFileName))
		{
			$sFileName = $sUploadNameWOExt.'_'.$iIndex.$sUploadNameExt;
			$iIndex++;
		}

		return $sFileName;
	}

	/**
	 * @param int $iUserId
	 */
	public function clearPrivateFiles($iUserId)
	{
		if ($iUserId)
		{
			$sRootPath = $this->getRootPath($iUserId, \Aurora\System\Enums\FileStorageType::Personal, true);
			\Aurora\System\Utils::RecRmdir($sRootPath);
		}
	}

	/**
	 * @param int $iUserId
	 */
	public function clearCorporateFiles($iUserId)
	{
		// TODO
	}
}

