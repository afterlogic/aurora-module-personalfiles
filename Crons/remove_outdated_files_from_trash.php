<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles;

use Aurora\Modules\Core\Module as CoreModule;
use Afterlogic\DAV\Server;
use Afterlogic\DAV\Constants;
use \Aurora\System\Enums\FileStorageType;

if (PHP_SAPI !== 'cli') {
    exit("Use the console for running this script");
}

require_once \dirname(__file__) . "../../../system/autoload.php";
\Aurora\System\Api::Init(true);

$offset = 0;
$limit = 50;
$period = Module::getInstance()->getConfig('TrashFilesLifetimeDays', 1);

$usersCount = CoreModule::Decorator()->GetTotalUsersCount();
if ($usersCount > 0) {
    while ($offset < $usersCount) {
        $users = CoreModule::Decorator()->GetUsers(0, $offset, $limit);
        if (count($users['Items']) > 0) {
            foreach ($users['Items'] as $user) {
                $oTrash = Server::getNodeForPath(Constants::FILESTORAGE_PATH_ROOT . '/' . FileStorageType::Personal . '/' . Module::$sTrashFolder, $user['PublicId']);
                if ($oTrash instanceof \Afterlogic\Dav\FS\Directory) {
                    $children = $oTrash->getChildren();
                    foreach ($children as $child) {
                        $lastModified = $child->getLastModified();
                        if ($lastModified && (time() - $lastModified) / 86400 >= $period) {
                            $child->delete();
                        }
                    }
                }   
            }
        }
        $offset += $limit;
    }
}
