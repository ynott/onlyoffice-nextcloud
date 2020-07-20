<?php
/**
 *
 * (c) Copyright Ascensio System SIA 2020
 *
 * This program is a free software product.
 * You can redistribute it and/or modify it under the terms of the GNU Affero General Public License
 * (AGPL) version 3 as published by the Free Software Foundation.
 * In accordance with Section 7(a) of the GNU AGPL its Section 15 shall be amended to the effect
 * that Ascensio System SIA expressly excludes the warranty of non-infringement of any third-party rights.
 *
 * This program is distributed WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * For details, see the GNU AGPL at: http://www.gnu.org/licenses/agpl-3.0.html
 *
 * You can contact Ascensio System SIA at 20A-12 Ernesta Birznieka-Upisha street, Riga, Latvia, EU, LV-1050.
 *
 * The interactive user interfaces in modified source and object code versions of the Program
 * must display Appropriate Legal Notices, as required under Section 5 of the GNU AGPL version 3.
 *
 * Pursuant to Section 7(b) of the License you must retain the original Product logo when distributing the program.
 * Pursuant to Section 7(e) we decline to grant you any rights under trademark law for use of our trademarks.
 *
 * All the Product's GUI elements, including illustrations and icon sets, as well as technical
 * writing content are licensed under the terms of the Creative Commons Attribution-ShareAlike 4.0 International.
 * See the License terms at http://creativecommons.org/licenses/by-sa/4.0/legalcode
 *
 */

namespace OCA\Onlyoffice;

use OC\Files\Filesystem;

use OCP\Util;

use OCA\Onlyoffice\FileVersions;

/**
 * The class to handle the filesystem hooks
 *
 * @package OCA\Onlyoffice
 */
class Hooks {

    /**
     * Application name
     *
     * @var string
     */
    private static $appName = "onlyoffice";

    public static function connectHooks() {
        // Listen user deletion
        Util::connectHook("OC_User", "pre_deleteUser", Hooks::class, "userDelete");

        // Listen file deletion
        Util::connectHook("OC_Filesystem", "delete", Hooks::class, "fileDelete");

        // Listen file version deletion
        Util::connectHook("\OCP\Versions", "preDelete", Hooks::class, "fileVersionDelete");

        // Listen file version restore
        Util::connectHook("\OCP\Versions", "rollback", Hooks::class, "fileVersionRestore");
    }

    /**
     * Erase user file versions
     *
     * @param array $params - hook params
     */
    public static function userDelete($params) {
        $userId = $params["uid"];

        FileVersions::deleteAllVersions($userId);
    }

    /**
     * Erase versions of deleted file
     *
     * @param array $params - hook params
     */
    public static function fileDelete($params) {
        $filePath = $params[Filesystem::signal_param_path];
        if ($filePath === "") {
            return;
        }

        try {
            $ownerId = Filesystem::getOwner($filePath);

            $fileInfo = Filesystem::getFileInfo($filePath);
            $fileId = $fileInfo->getId();

            FileVersions::deleteAllVersions($ownerId, $fileId);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->logException($e, ["message" => "Hook: fileDelete " . json_encode($params), "app" => self::$appName]);
        }
    }

    /**
     * Erase versions of deleted version of file
     *
     * @param array $params - hook param
     */
    public static function fileVersionDelete($params) {
        $pathVersion = $params["path"];
        if ($pathVersion === "") {
            return;
        }

        try {
            list ($filePath, $versionId) = FileVersions::splitPathVersion($pathVersion);

            $ownerId = Filesystem::getOwner($filePath);

            $fileInfo = Filesystem::getFileInfo($filePath);
            $fileId = $fileInfo->getId();

            FileVersions::deleteVersion($ownerId, $fileId, $versionId);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->logException($e, ["message" => "Hook: fileVersionDelete " . json_encode($params), "app" => self::$appName]);
        }
    }

    /**
     * Erase versions of restored version of file
     *
     * @param array $params - hook param
     */
    public static function fileVersionRestore($params) {
        $filePath = $params["path"];
        if ($filePath === "") {
            return;
        }

        $versionId = $params["revision"];

        try {
            $ownerId = Filesystem::getOwner($filePath);

            $fileInfo = Filesystem::getFileInfo($filePath);
            $fileId = $fileInfo->getId();

            FileVersions::deleteVersion($ownerId, $fileId, $versionId);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->logException($e, ["message" => "Hook: fileVersionRestore " . json_encode($params), "app" => self::$appName]);
        }
    }
}
