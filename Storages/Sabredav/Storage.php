<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 *
 */

namespace Aurora\Modules\PersonalFiles\Storages\Sabredav;

use Afterlogic\DAV\Constants;
use Afterlogic\DAV\FS\Shared\File as SharedFile;
use Afterlogic\DAV\FS\Shared\Directory as SharedDirectory;
use Afterlogic\DAV\Server;
use Aurora\Modules\Files\Enums\ErrorCodes as FilesErrorCodes;
use Aurora\Modules\SharedFiles\Enums\ErrorCodes;
use Aurora\System\Exceptions\ApiException;
use Exception;
use Sabre\DAV\FS\Node;

use function Sabre\Event\Loop\instance;

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

			$oDirectory = $oServer->tree->getNodeForPath('files/' . $sType . '/' . \trim($sPath, '/'));
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
	public function isFileExists($iUserId, $sType, $sPath, $sName, $bWithoutGroup = false)
	{
		$bResult = false;
		$oDirectory = $this->getDirectory($iUserId, $sType, $sPath);
		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory && $oDirectory->childExists($sName))
		{
			$oItem = $oDirectory->getChild($sName);
			if ($oItem instanceof \Sabre\DAV\FS\Node)
			{
				if ($bWithoutGroup && 
					($oItem instanceof SharedFile || $oItem instanceof SharedDirectory) && $oItem->getGroup() == 0) {
				} else {
					$bResult = true;
				}
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
		if ($oItem)
		{
			$bShared = ($oItem instanceof SharedFile || $oItem instanceof SharedDirectory);
			
			$sFilePath = !empty(trim($sPath, '/')) ? '/' . ltrim($sPath, '/') : ($bShared ? $oItem->getSharePath() : $oItem->getRelativePath());

			$oResult = new  \Aurora\Modules\Files\Classes\FileItem();
			$oResult->Type = $sType;
			$oResult->TypeStr = $sType;
			$oResult->RealPath = $oItem->getPath();
			$oResult->Path = $sFilePath;
			$oResult->Name = $oItem->getName();
			$oResult->Id = $oItem->getId();
			$oResult->FullPath = !empty(trim($oResult->Path, '/')) ? $oResult->Path . '/' . ltrim($oResult->Id, '/')  : '/' . $oResult->Id;
			$oResult->ETag = ($oItem instanceof \Afterlogic\DAV\FS\File) ? \trim($oItem->getETag(), '"') : '';
			$oResult->Shared = $bShared;
			$oResult->GroupId = $bShared ? $oItem->getGroupId() : null;
			$oResult->Initiator = $bShared ? basename($oItem->getInitiator()) : null;

			$sID = '';
			$aProps = [];
			if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
			{
				$sID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sType, $sFilePath, $oItem->getName()]);
				$oResult->IsFolder = true;

				$oResult->AddAction([
					'list' => []
				]);
			}

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
						$mFileData = stream_get_contents($mFileData);
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

			$oResult->Published = (isset($aProps['Published']) ? $aProps['Published'] : empty($mMin['__hash__'])) ? false : true;
			$oResult->Owner = isset($aProps['Owner']) ? $aProps['Owner'] : basename($oItem->getOwner());
			$oResult->ExtendedProps = isset($aProps['ExtendedProps']) ? $aProps['ExtendedProps'] : [];

			if ($bShared) {
				$aExtendedProps = $oResult->ExtendedProps;
				$aExtendedProps['SharedWithMeAccess'] = $oItem->getAccess();
				$oResult->ExtendedProps = $aExtendedProps;

				$oResult->ExtendedProps = array_merge(
					$oResult->ExtendedProps,
					$oItem->getDbProperties()
				);
			}
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
		$iUserId = ($oUser instanceof \Aurora\Modules\Core\Models\User) ? $oUser->Id : null;

		$oMin = \Aurora\Modules\Min\Module::getInstance();
		$mMin = $oMin->GetMinByID($sID);
		if (!empty($mMin['__hash__']))
		{
			$mResult = $mMin['__hash__'];
		}
		else
		{
			$oNode = Server::getNodeForPath('files/' . $sType . '/' . $sPath . '/' . $sName);
			if ($oNode instanceof \Afterlogic\DAV\FS\Shared\File || $oNode instanceof \Afterlogic\DAV\FS\Shared\Directory) {
				throw new ApiException(FilesErrorCodes::NotPermitted);
			}
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
	public function getFiles($sUserPublicId, $sType = \Aurora\System\Enums\FileStorageType::Personal, $sPath = '', $sPattern = '', $sPublicHash = null, $bIsShared = false)
	{
		$aResult = [];

		$oDirectory = $this->getDirectory($sUserPublicId, $sType, $sPath);

		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($sUserPublicId);

		if ($oDirectory !== null && $oDirectory instanceof \Afterlogic\DAV\FS\Directory) {
			$depth = 1;
			if (!empty($sPattern)) {
				$oServer->enablePropfindDepthInfinity = true;
				$depth = -1;
			}

			$sPath = 'files/' . $sType . '/'. trim($sPath, '/');
			$oIterator = $oServer->getPropertiesIteratorForPath($sPath, [
				'{DAV:}displayname',
				'{DAV:}getlastmodified',
				'{DAV:}getcontentlength',
				'{DAV:}resourcetype',
				'{DAV:}quota-used-bytes',
				'{DAV:}quota-available-bytes',
				'{DAV:}getetag',
				'{DAV:}getcontenttype',
				'{DAV:}share-path',
			], $depth);

			foreach ($oIterator as $iKey => $oItem) {
				// Skipping the parent path
				if ($iKey === 0) continue;

				$sHref = $oItem['href'];
				// Skipping the parent path
				if ($sHref === $sPath) continue;
				list(, $sName) = \Sabre\Uri\split($sHref);

				if (empty($sPattern) || fnmatch("*" . $sPattern . "*", $sName, FNM_CASEFOLD)) {
					$oNode = $oServer->tree->getNodeForPath($sHref);

					if ($oNode && !isset($aResult[$sHref])) {
						$aHref = \explode('/', $sHref, 3);
						list($sSubFullPath, ) = \Sabre\Uri\split($aHref[2]);

						$aResult[] = $this->getFileInfo($sUserPublicId, $sType, $oNode, $sPublicHash, '/'. trim($sSubFullPath, '/'));
					}
				}
			}

			if (!empty($sPattern)) {
				$aDirectoryInfo = $oDirectory->getChildrenProperties();
				foreach ($aDirectoryInfo as $oDirectoryInfo) {
					if (isset($oDirectoryInfo['Link']) && strpos($oDirectoryInfo['Name'], $sPattern) !== false) {
						$oNode = new \Afterlogic\DAV\FS\File($sType, $oDirectory->getPath() . '/' . $oDirectoryInfo['@Name']);
						if ($oNode) {
							$aResult[] = $this->getFileInfo($sUserPublicId, $sType, $oItem, $sPublicHash, $sPath);
						}
					}
				}
			}
			$oServer->enablePropfindDepthInfinity = false;

			usort($aResult,
				function ($a, $b) {
					return ($a->Name > $b->Name);
				}
			);
		} else {
			throw new ApiException(FilesErrorCodes::NotFound);
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
			\Afterlogic\DAV\Server::checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
		}

		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory)
		{
			if (!$oDirectory->childExists($sFolderName)) {
				$oDirectory->createDirectory($sFolderName);
				return true;
			} else {
				throw new ApiException(FilesErrorCodes::AlreadeExists);
			}
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
			$oItem->setProperty('Owner', $iUserId);

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
			\Afterlogic\DAV\Server::checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
			if ($oDirectory->childExists($sFileName)) {
				\Afterlogic\DAV\Server::checkPrivileges('files/' . $sType . $sPath . '/' . $sFileName, '{DAV:}write');
			}
		}

		if ($oDirectory instanceof \Afterlogic\DAV\FS\Directory || $oDirectory instanceof \Afterlogic\DAV\FS\Shared\Root)
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
		if ($oItem instanceof \Sabre\DAV\FS\Node) {
			if ($oItem instanceof \Sabre\DAVACL\IACL && !empty(trim($sPath, '/'))) {
				\Afterlogic\DAV\Server::checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
			}

			if ($oItem instanceof \Afterlogic\DAV\FS\Directory) {
				$this->updateMin($iUserId, $sType, $sPath, $sName, $sName, $oItem, true);
			}

			$oItem->delete();
			return true;
		} else {
			throw new ApiException(FilesErrorCodes::NotFound);
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
		if ($oNode)
		{
			if ($oNode->getName() !== $sNewName)
			{
				if ($oNode instanceof \Sabre\DAVACL\IACL)
				{
					\Afterlogic\DAV\Server::checkPrivileges('files/' . $sType . $sPath, '{DAV:}write');
				}

				if (strlen($sNewName) < 200)
				{
					$this->updateMin($iUserId, $sType, $sPath, $sName, $sNewName, $oNode);
					$oParentNode = $oServer->tree->getNodeForPath('files/' . $sType . $sPath);
					$bChildExists = true;
					if ($oParentNode) {
						try {
							$oChild = $oParentNode->getChild($sNewName);
							$bChildExists = $oChild instanceof Node;
						}
						catch (Exception $oEx) {
							$bChildExists = false;
						}
					}

					if ($bChildExists) {
						throw new ApiException(FilesErrorCodes::AlreadeExists);
					}
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
			$oItem->setProperty('Name', $sNewName);
			return true;
		}
		return false;
	}

	/**
	 * @param int $sUserPublicId
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
	public function copy($sUserPublicId, $sFromType, $sToType, $sFromPath, $sToPath, $sName, $sNewName, $bMove = false)
	{
		$oMin = \Aurora\Modules\Min\Module::getInstance();

		if (empty($sNewName) && !is_numeric($sNewName))
		{
			$sNewName = $sName;
		}

		$sFromRootPath = $this->getRootPath($sUserPublicId, $sFromType, true);
		$sToRootPath = $this->getRootPath($sUserPublicId, $sToType, true);

		$oFromDirectory = $this->getDirectory($sUserPublicId, $sFromType, $sFromPath);
		$oToDirectory = $this->getDirectory($sUserPublicId, $sToType, $sToPath);

		if ($oToDirectory && $oFromDirectory)
		{
			$oItem = null;
			try
			{
				$oItem = $oFromDirectory->getChild($sName);
			}
			catch (\Exception $oEx) {}

			if ($oItem !== null)
			{
				$bIsSharedFile = ($oItem instanceof SharedFile || $oItem instanceof SharedDirectory);
				$bIsSharedToDirectory = ($oToDirectory instanceof SharedDirectory);
				if ($bMove && $bIsSharedFile && $bIsSharedToDirectory) {
					throw new ApiException(ErrorCodes::NotPossibleToMoveSharedFileToSharedFolder);
				}
				$aExtendedProps = $oItem->getProperty('ExtendedProps');
				if (is_array($aExtendedProps) && isset($aExtendedProps['InitializationVector']) && $bIsSharedToDirectory) {
					throw new ApiException(ErrorCodes::NotPossibleToMoveSharedFileToSharedFolder);
				}
				if ($bIsSharedFile && !$oItem->isInherited() && $bMove)
				{
					$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
					$oPdo->updateSharedFileSharePath(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $oItem->getName(), $sFromPath, $sToPath, $oItem->getGroupId());
				}
				else
				{
					if ($oItem instanceof \Afterlogic\DAV\FS\File)
					{
						$oToDirectory->createFile($sNewName, $oItem->get());

						$oItemNew = $oToDirectory->getChild($sNewName);

						if ($oItemNew && $bMove)
						{
							$oSharedFiles = \Aurora\Api::GetModule('SharedFiles');
							if ($oSharedFiles)
							{
								$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
								$oPdo->updateShare(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $sFromType, $sFromPath . '/' . $sName, $sToType, $sToPath . '/' . $sNewName);
							}
						}
						$aProps = $oItem->getProperties(array());
						if (!$bMove)
						{
							$aProps['Owner'] = $sUserPublicId;
						}
						else
						{
							$sChildPath = substr(dirname($oItem->getPath()), strlen($sFromRootPath));
							$sID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sFromType, $sChildPath, $oItem->getName()]);

							$sNewChildPath = substr(dirname($oItemNew->getPath()), strlen($sToRootPath));

							$mMin = $oMin->GetMinByID($sID);
							if (!empty($mMin['__hash__']))
							{
								$sNewID = \Aurora\Modules\Min\Module::generateHashId([$sUserPublicId, $sToType, $sNewChildPath, $oItemNew->getName()]);

								$mMin['Path'] = $sNewChildPath;
								$mMin['Type'] = $sToType;
								$mMin['Name'] = $oItemNew->getName();

								$oMin->UpdateMinByID($sID, $mMin, $sNewID);
							}
						}
						$oItemNew->updateProperties($aProps);

						if (!isset($GLOBALS['__SKIP_HISTORY__']))
						{
							try
							{
								$oHistoryNode = $oFromDirectory->getChild($sName . '.hist');
								if ($oHistoryNode instanceof \Afterlogic\DAV\FS\Directory)
								{
									$this->copy($sUserPublicId, $sFromType, $sToType, $sFromPath, $sToPath, $sName . '.hist', $sNewName . '.hist', false);
								}
							}
							catch (\Exception $oEx) {}
						}
					}
					if ($oItem instanceof \Afterlogic\DAV\FS\Directory)
					{
						$oToDirectory->createDirectory($sNewName);

						$oSharedFiles = \Aurora\Api::GetModule('SharedFiles');
						if ($oSharedFiles)
						{
							$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
//							$oPdo->updateSharedFileSharePathWithLike(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $sFromPath, $sToPath);

							$aShares = $oPdo->getShares(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $sFromType, $sFromPath . '/' . $sName);
							foreach ($aShares as $aShare)
							{
								$sNonExistentFileName = $sNewName;
								if ($sName !== $sNewName) {
									$sNonExistentFileName = $oSharedFiles->getNonExistentFileName(Constants::PRINCIPALS_PREFIX . $sUserPublicId, $sNewName);
								}
								$oPdo->createSharedFile(
									Constants::PRINCIPALS_PREFIX . $sUserPublicId, 
									$sToType, 
									$sToPath . '/' . $sNewName, 
									$sNonExistentFileName, 
									$aShare['principaluri'], 
									$aShare['access'], 
									true, 
									$aShare['share_path'], 
									$aShare['group_id'],
									Constants::PRINCIPALS_PREFIX . $sUserPublicId
								);
							}
						}
						$oChildren = $oItem->getChildren();
						foreach ($oChildren as $oChild)
						{
							$sChildNewName = $this->getNonExistentFileName(
								$sUserPublicId,
									$sToType,
									$sToPath . '/' . $sNewName,
									$oChild->getName()
							);
							$this->copy(
								$sUserPublicId,
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
	 * @param array $aTypes Storage type list. Accepted values in array: **\Aurora\System\Enums\FileStorageType::Personal**, **\Aurora\System\Enums\FileStorageType::Corporate**, **\Aurora\System\Enums\FileStorageType::Shared**.
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
	 * @param \Aurora\Modules\StandardAuth\Models\Account $oAccount
	 * @param int $iType
	 * @param string $sPath
	 * @param string $sFileName
	 *
	 * @return string
	 */
	public function getNonExistentFileName($oAccount, $iType, $sPath, $sFileName, $bWithoutGroup = false)
	{
		$iIndex = 1;
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

		while ($this->isFileExists($oAccount, $iType, $sPath, $sFileName, $bWithoutGroup))
		{
			$sFileName = $sUploadNameWOExt.' ('.$iIndex.')'.$sUploadNameExt;
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

