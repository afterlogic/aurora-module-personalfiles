<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\PersonalFiles;

use Aurora\System\SettingsProperty;

/**
 * @property bool $Disabled
 * @property int $UserSpaceLimitMb
 */

class Settings extends \Aurora\System\Module\Settings
{
    protected function initDefaults()
    {
        $this->aContainer = [
            "Disabled" => new SettingsProperty(
                false,
                "bool",
                null,
                "Setting to true disables the module",
            ),
            "UserSpaceLimitMb" => new SettingsProperty(
                100,
                "int",
                null,
                "Defines a default filesystem quota for user account, in Mbytes",
            ),
            "ShowSharedFilesInPersonalStorage" => new SettingsProperty(
                true,
                "bool",
                null,
                "Setting to true show shared with me files in personal storage",
            ),
        ];
    }
}
