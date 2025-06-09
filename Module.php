<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles;

use Afterlogic\DAV\Constants;
use Afterlogic\DAV\FS\Directory;
use Afterlogic\DAV\FS\File;
use Afterlogic\DAV\FS\Shared\File as SharedFile;
use Afterlogic\DAV\FS\Shared\Directory as SharedDirectory;
use Afterlogic\DAV\Server;
use Aurora\Api;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Files\Classes\FileItem;
use Aurora\Modules\Files\Enums\ErrorCodes;
use Aurora\Modules\Files\Module as FilesModule;
use Aurora\System\Exceptions\ApiException;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.

 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $sStorageType = 'personal';
    protected static $iStorageOrder = 0;

    /**
     * Indicates if it's allowed to move files/folders to this storage.
     * @var boolean
     */
    protected static $bIsDroppable = true;

    protected $oBeforeDeleteUserRootPath = '';
    protected $oBeforeDeleteUser = null;
    public static $sTrashFolder = '.trash';

    /**
     *
     * @var \Aurora\Modules\PersonalFiles\Manager
     */
    public $oManager = null;

    /**
     *
     * @var \Aurora\Modules\Min\Module
     */
    protected $oMinModuleDecorator = null;

    public function getManager()
    {
        if ($this->oManager === null) {
            $this->oManager = new Manager($this);
        }

        return $this->oManager;
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    /**
     * Initializes Files Module.
     *
     * @ignore
     */
    public function init()
    {
        ini_set('default_charset', 'UTF-8'); //support for cyrillic characters in file names

        $this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
        $this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
        $this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
        $this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));

        $this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
        $this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'), 10);
        $this->subscribeEvent('Files::GetItems::before', [$this, 'onBeforeGetItems']);
        $this->subscribeEvent('Files::GetItems', array($this, 'onGetItems'));
        $this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
        $this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
        $this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
        $this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
        $this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
        $this->subscribeEvent('Files::Restore::after', array($this, 'onAfterRestore'));
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

        if ($oUser) {
            $iResult = $this->getManager()->getUserSpaceUsed($oUser->PublicId, [\Aurora\System\Enums\FileStorageType::Personal]);
            $oUser->setExtendedProp('PersonalFiles::UsedSpace', $iResult);
            $oUser->save();
        }

        return $iResult;
    }

    /**
    * Returns Min module decorator.
    *
    * @return \Aurora\System\Module\Decorator
    */
    private function getMinModuleDecorator()
    {
        return Api::GetModuleDecorator('Min');
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
     * Checks if file name is allowed.
     *
     * @param string $FileName
     *
     * @return bool
     */
    protected function isFileAllowed($FileName)
    {
        $forbiddenFileList = [
            '.sabredav'
        ];

        $FileName = \trim(\MailSo\Base\Utils::ClearFileName($FileName));

        $result = !in_array($FileName, $forbiddenFileList);

        return  $result;
    }

    protected function getTrashPath($sPath)
    {
        return '/' . self::$sTrashFolder . \ltrim($sPath);
    }

        /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onBeforeGetItems(&$aArgs, &$mResult)
    {
        if ($this->getConfig('AllowTrash', true) && $aArgs['Type'] === 'trash') {
            $aArgs['Type'] = \Aurora\System\Enums\FileStorageType::Personal;
            $aArgs['Path'] = $this->getTrashPath($aArgs['Path']);

            if (!FilesModule::Decorator()->IsFileExists($aArgs['UserId'], \Aurora\System\Enums\FileStorageType::Personal, '', self::$sTrashFolder)) {
                FilesModule::Decorator()->CreateFolder($aArgs['UserId'], \Aurora\System\Enums\FileStorageType::Personal, '', self::$sTrashFolder);
            }
        }
    }

    /**
     * Puts file content to $mResult.
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onGetFile($aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type'])) {
            $UserId = $aArgs['UserId'];

            $sUserPiblicId = Api::getUserPublicIdById($UserId);
            $iOffset = isset($aArgs['Offset']) ? $aArgs['Offset'] : 0;
            $iChunkSizet = isset($aArgs['ChunkSize']) ? $aArgs['ChunkSize'] : 0;

            try {
                $mResult = $this->getManager()->getFile($sUserPiblicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id'], $iOffset, $iChunkSizet);
            } catch (\Sabre\DAV\Exception\NotFound $oEx) {
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
    public function onCreateFile($aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type'])) {
            if (!self::isFileAllowed($aArgs['Name'])) {
                throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::CanNotUploadFileErrorData);
            }

            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);

            $currentUser = Server::getUser();
            Server::setUser(Api::getUserPublicIdById($UserId));
            $mResult = $this->getManager()->createFile(
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
            Server::setUser($currentUser);

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

        if ($iUserId) {
            if (!empty($aArgs['Url'])) {
                $sUrl = $aArgs['Url'];
                if ($sUrl) {
                    $aRemoteFileInfo = \Aurora\System\Utils::GetRemoteFileInfo($sUrl);
                    if ((int)$aRemoteFileInfo['code'] > 0) {
                        $sFileName = basename($sUrl);
                        $sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);

                        if (empty($sFileExtension)) {
                            $sFileExtension = \Aurora\System\Utils::GetFileExtensionFromMimeContentType($aRemoteFileInfo['content-type']);
                            $sFileName .= '.' . $sFileExtension;
                        }

                        if ($sFileExtension === 'htm' || $sFileExtension === 'html') {
                            $sTitle = $this->getHtmlTitle($sUrl);
                        }

                        $mResult['Name'] = isset($sTitle) && strlen($sTitle) > 0 ? $sTitle : urldecode($sFileName);
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
        if ($oItem->IsLink) {
            $sFileName = basename($oItem->LinkUrl);
            $sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);
            if ($sFileExtension === 'htm' || $sFileExtension === 'html') {
                //				$oItem->Name = $this->getHtmlTitle($oItem->LinkUrl);
                return true;
            }
        }

        return false;
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onBeforeDeleteUser($aArgs, &$mResult)
    {
        if (isset($aArgs['UserId'])) {
            $this->oBeforeDeleteUser = Api::getUserById($aArgs['UserId']);
            if ($this->oBeforeDeleteUser) {
                $this->oBeforeDeleteUserRootPath = $this->getManager()->oStorage->getRootPath($this->oBeforeDeleteUser->PublicId, \Aurora\System\Enums\FileStorageType::Personal, true);
            }
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterDeleteUser($aArgs, $mResult)
    {
        if ($mResult && !empty($this->oBeforeDeleteUserRootPath)) {
            \Aurora\System\Utils::RecRmdir($this->oBeforeDeleteUserRootPath);
            $this->oBeforeDeleteUserRootPath = '';
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

        if (FilesModule::getInstance()->getConfig('AllowTrash', true) && $this->checkStorageType(\Aurora\System\Enums\FileStorageType::Personal)) {
            array_unshift($mResult, [
                'Type' => 'trash',
                'DisplayName' => $this->i18N('LABEL_TRASH_STORAGE'),
                'IsExternal' => false,
                'Order' => 999,
                'IsDroppable' => false
            ]);
        }
        if (FilesModule::getInstance()->getConfig('AllowFavorites', true) && $this->checkStorageType(\Aurora\System\Enums\FileStorageType::Personal)) {
            array_unshift($mResult, [
                'Type' => 'favorites',
                'DisplayName' => $this->i18N('LABEL_FAVORITES_STORAGE'),
                'IsExternal' => false,
                'Order' => 998,
                'IsDroppable' => false
            ]);
        }
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
        if ($this->checkStorageType($aArgs['Type'])) {
            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);

            $sUserPiblicId = Api::getUserPublicIdById($UserId);
            $sHash = isset($aArgs['PublicHash']) ? $aArgs['PublicHash'] : null;
            $bIsShared = isset($aArgs['Shared']) ? !!$aArgs['Shared'] : false;

            $files = $this->getManager()->getFiles(
                $sUserPiblicId,
                $aArgs['Type'],
                $aArgs['Path'],
                $aArgs['Pattern'],
                $sHash,
                $bIsShared
            );

            if ($aArgs['Path'] === '' && is_array($files)) {
                foreach ($files as $iKey => $oFileItem) {
                    if ($oFileItem instanceof FileItem && $oFileItem->IsFolder && $oFileItem->Name === self::$sTrashFolder) {
                        unset($files[$iKey]);
                    }
                }
            }

            $mResult = array_merge(
                $mResult,
                $files
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
        if (is_resource($mFile)) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
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

        if ($this->checkStorageType($aArgs['Type'])) {
            $Type = $aArgs['Type'];
            $UserId = $aArgs['UserId'];
            $Path = $aArgs['Path'];
            $Name = $aArgs['Name'];
            $Link = $aArgs['Link'];

            Api::CheckAccess($UserId);

            if (substr($Link, 0, 11) === 'javascript:') {
                $Link = substr($Link, 11);
            }

            $sUserPiblicId = Api::getUserPublicIdById($UserId);
            if ($this->checkStorageType($Type)) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
            $mResult = false;

            $sUserPiblicId = Api::getUserPublicIdById($UserId);
            foreach ($aArgs['Items'] as $key => $aItem) {
                try {
                    $oNode = Server::getNodeForPath(Constants::FILESTORAGE_PATH_ROOT . '/' . $aArgs['Type'] . '/' . $aItem['Path'] . '/' . $aItem['Name']);
                } catch (\Exception $oEx) {
                    Api::LogException($oEx);
                    throw new ApiException(ErrorCodes::NotFound, $oEx, "Node not found");
                }

                if (!$oNode) {
                    throw new ApiException(ErrorCodes::NotFound, null, "Node not found");
                }

                if ($oNode instanceof SharedFile || $oNode instanceof SharedDirectory) {
                    if (!$oNode->isInherited()) {
                        throw new ApiException(ErrorCodes::CantDeleteSharedItem);
                    }
                }

                $aPathItems = preg_split('/' . preg_quote('/', '/') . '/', \trim($aItem['Path'], '/'));
                $sFirstPath = isset($aPathItems[0]) ? $aPathItems[0] : '';

                if ($this->getConfig('AllowTrash', true) && $sFirstPath !== self::$sTrashFolder) {
                    $oNode->deleteShares();

                    if (!FilesModule::Decorator()->IsFileExists($aArgs['UserId'], \Aurora\System\Enums\FileStorageType::Personal, '', self::$sTrashFolder)) {
                        FilesModule::Decorator()->CreateFolder($aArgs['UserId'], \Aurora\System\Enums\FileStorageType::Personal, '', self::$sTrashFolder);
                    }
                    $sNewName = $this->getManager()->getNonExistentFileName(
                        $sUserPiblicId,
                        \Aurora\System\Enums\FileStorageType::Personal,
                        '/' . self::$sTrashFolder,
                        $aItem['Name']
                    );
                    $aArgs['Files'][$key]['NewName'] = $sNewName;
                    $mResult = $this->getManager()->copy(
                        $sUserPiblicId,
                        $aArgs['Type'],
                        \Aurora\System\Enums\FileStorageType::Personal,
                        $aItem['Path'],
                        '/' . self::$sTrashFolder,
                        $aItem['Name'],
                        $sNewName,
                        true
                    );
                    if ($mResult) {
                        $oNode = Server::getNodeForPath(Constants::FILESTORAGE_PATH_ROOT . '/' . \Aurora\System\Enums\FileStorageType::Personal . '/' . self::$sTrashFolder . '/' . $sNewName);
                        if ($oNode) {
                            $mExtendedProps = $oNode->getProperty('ExtendedProps');
                            $aExtendedProps = is_array($mExtendedProps) ? $mExtendedProps : [];
                            $aExtendedProps['TrashOriginalPath'] = $aItem['Path'] . '/' . $aItem['Name'];
                
                            $oNode->setProperty('ExtendedProps', $aExtendedProps);
                        }
                    }
                } else {
                    Server::deleteNode('files/' . $aArgs['Type'] . '/' . $aItem['Path'] . '/' . $aItem['Name']);
                }

                $oItem = new FileItem();
                $oItem->Id = $aItem['Name'];
                $oItem->Name = $aItem['Name'];
                $oItem->TypeStr = $aArgs['Type'];
                $oItem->Path = $aItem['Path'];

                FilesModule::Decorator()->DeletePublicLink($UserId, $aArgs['Type'], $aItem['Path'], $aItem['Name']);
                \Aurora\System\Managers\Thumb::RemoveFromCache($UserId, $oItem->getHash(), $aItem['Name']);

                $mResult = true;
            }

            self::Decorator()->UpdateUsedSpace();
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterRestore(&$aArgs, &$mResult)
    {
        $UserId = $aArgs['UserId'];
        $Items = $aArgs['Items'];

        $sUserPiblicId = Api::getUserPublicIdById($UserId);
        $Files = [];
        foreach ($Items as $item) {
            $oNode = Server::getNodeForPath(Constants::FILESTORAGE_PATH_ROOT . '/personal/' . self::$sTrashFolder . '/' . $item);
            if ($oNode) {
                $mExtendedProps = $oNode->getProperty('ExtendedProps');
                $aExtendedProps = is_array($mExtendedProps) ? $mExtendedProps : [];
                $originalPath = isset($aExtendedProps['TrashOriginalPath']) ? $aExtendedProps['TrashOriginalPath'] : false;
                if ($originalPath === false) {
                    Api::Log('ERROR: The node \'' . $item .  '\' has no original path');
                } else {

                    list($toPath, $toName) = \Sabre\Uri\split($originalPath);
                    $fileItem = [
                        'FromPath' => '/' . self::$sTrashFolder,
                        'FromName' => $item,
                        'ToPath' => $toPath,
                        'ToName' => $toName,
                    ];
                    $fileItem['Name'] = $this->getManager()->getNonExistentFileName(
                        $sUserPiblicId,
                        'personal',
                        $fileItem['ToPath'],
                        $fileItem['ToName']
                    );
                    $Files[] = $fileItem;
                }
            }
        }

        foreach ($Files as $aFileItem) {
            $mResult = $this->getManager()->copy(
                $sUserPiblicId,
                'personal',
                'personal',
                $aFileItem['FromPath'],
                $aFileItem['ToPath'],
                $aFileItem['FromName'],
                $aFileItem['ToName'],
                true
            );
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterRename(&$aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type'])) {
            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);

            if (!self::isFileAllowed($aArgs['Name'])) {
                throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::CanNotUploadFileErrorData);
            }

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

        if ($this->checkStorageType($aArgs['FromType'])) {
            foreach ($aArgs['Files'] as $aItem) {
                $bFolderIntoItself = isset($aItem['IsFolder']) && $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'] . '/' . $aItem['Name'];
                if (!$bFolderIntoItself) {
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
        if ($this->checkStorageType($aArgs['FromType'])) {
            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);

            $sUserPiblicId = Api::getUserPublicIdById($UserId);
            foreach ($aArgs['Files'] as $key => $aItem) {
                $bIntoItself = $aArgs['ToType'] . '/' . $aArgs['ToPath'] === $aItem['FromType'] . '/' . $aItem['FromPath'];

                if (!$bIntoItself) {
                    $sNewName = isset($aItem['NewName']) ? $aItem['NewName'] : $aItem['Name'];

                    if (!self::IsFileAllowed(basename($sNewName))) {
                        throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::CanNotUploadFileErrorData);
                    }

                    $sNewName = $this->getManager()->getNonExistentFileName(
                        $sUserPiblicId,
                        $aArgs['ToType'],
                        $aArgs['ToPath'],
                        $sNewName
                    );
                    $aArgs['Files'][$key]['NewName'] = $sNewName;
                    $mResult = $this->getManager()->copy(
                        $sUserPiblicId,
                        $aItem['FromType'],
                        $aArgs['ToType'],
                        $aItem['FromPath'],
                        $aArgs['ToPath'],
                        $aItem['Name'],
                        $sNewName,
                        true
                    );
                } else {
                    throw new ApiException(ErrorCodes::CannotCopyOrMoveItemToItself);
                }
            }

            self::Decorator()->UpdateUsedSpace();
        }
    }

    protected function getUserSpaceLimitMb()
    {
        $iSpaceLimitMb = FilesModule::getInstance()->oModuleSettings->UserSpaceLimitMb;

        $iUserId = Api::getAuthenticatedUserId();
        $oUser = \Aurora\Api::getUserById($iUserId);

        if ($oUser) {
            $iSpaceLimitMb = $oUser->getExtendedProp('Files::UserSpaceLimitMb');
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
        if ($this->checkStorageType($aArgs['Type'])) {
            $iSize = 0;

            $oUser = \Aurora\Api::getUserById($aArgs['UserId']);

            if ($oUser) {
                $iSize = null !== $oUser->getExtendedProp('PersonalFiles::UsedSpace') ? $oUser->getExtendedProp('PersonalFiles::UsedSpace') : 0;
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
        if ($this->checkStorageType($aArgs['Type'])) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
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
        if (isset($aArgs['Type']) && $this->checkStorageType($aArgs['Type'])) {
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
        if ($this->checkStorageType($Type)) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
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
        if ($bCorrectArgs && $this->checkStorageType($aArgs['Type'])) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
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
        if ($this->checkStorageType($aArgs['Type'])) {
            $UserId = $aArgs['UserId'];
            Api::CheckAccess($UserId);
            $sUserPiblicId = Api::getUserPublicIdById($UserId);

            $aItems = [];
            $sPath = $aArgs['Path'];
            $aPaths = explode('/', $sPath);
            do {
                $oNode = Server::getNodeForPath('files/' . $aArgs['Type'] . '/' . $sPath, $sUserPiblicId);
                if (!empty($sPath) && ($oNode instanceof SharedFile || $oNode instanceof SharedDirectory)) {
                    $aItems[$sPath] = $oNode->getAccess();
                }
                array_pop($aPaths);
                $sPath = implode('/', $aPaths);
            } while (count($aPaths) > 0);
            $mResult = $aItems;
        }
    }
}
