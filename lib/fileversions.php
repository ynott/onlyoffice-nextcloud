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

use OCP\Files\File;
use OCP\Files\NotFoundException;

use OCA\Onlyoffice\DocumentService;

/**
 * File versions
 *
 * @package OCA\Onlyoffice
 */
class FileVersions {

    /**
     * Application name
     *
     * @var string
     */
    private static $appName = "onlyoffice";

    /**
     * Generate file history name
     *
     * @param integer $fileId - file identifier
     * @param integer $version - file version
     *
     * @return string
     */
    public static function getFileHistoryName($fileId, $fileVersion) {
        return "history_" . $fileId . "_" . $fileVersion . ".json";
    }

    /**
     * Generate file changes name
     *
     * @param integer $fileId - file identifier
     * @param integer $version - file version
     *
     * @return string
     */
    public static function getFileChangesName($fileId, $fileVersion) {
        return "changes_" . $fileId . "_" . $fileVersion . ".zip";
    }

    /**
     * Get changes from stored to history object
     *
     * @param string $ownerId - file owner id
     * @param string $fileId - file id
     * @param string $versionId - file version
     * @param string $prevVersion - previous version for check
     *
     * @return array
     */
    public static function getHistoryData($ownerId, $fileId, $versionId, $prevVersion) {
        $logger = \OC::$server->getLogger();

        if ($ownerId === null) {
            return null;
        }

        $appData = \OC::$server->getAppDataDir(self::$appName);

        try {
            $folderHistory = $appData->getFolder($ownerId);
        } catch (NotFoundException $e) {
            return null;
        }

        $historyName = self::getFileHistoryName($fileId, $versionId);

        if (!$folderHistory->fileExists($historyName)) {
            return null;
        }

        try {
            $historyFile = $folderHistory->getFile($historyName);
            $historyDataString = $historyFile->getContent();
            $historyData = json_decode($historyDataString, true);

            if ($historyData["prev"] !== $prevVersion) {
                $logger->debug("getHistoryData: previous $prevVersion is changed", ["app" => self::$appName]);

                $historyFile->delete();
                $logger->debug("getHistoryData: remove $historyName", ["app" => self::$appName]);

                $changesName = self::getFileChangesName($fileId, $versionId);
                if ($folderHistory->fileExists($changesName)) {
                    $changesFile = $folderHistory->getFile($changesName);
                    $changesFile->delete();
                    $logger->debug("getHistoryData: remove $changesName", ["app" => self::$appName]);
                }
                return null;
            }

            return $historyData;
        } catch (\Exception $e) {
            $logger->logException($e, ["message" => "getHistoryData: $fileId versionId", "app" => self::$appName]);
            return null;
        }
    }

    /**
     * Check if changes is stored
     *
     * @param string $ownerId - file owner id
     * @param string $fileId - file id
     * @param string $versionId - file version
     *
     * @return bool
     */
    public static function hasChanges($ownerId, $fileId, $versionId) {
        if ($ownerId === null) {
            return false;
        }

        $appData = \OC::$server->getAppDataDir(self::$appName);

        try {
            $folderHistory = $appData->getFolder($ownerId);
        } catch (NotFoundException $e) {
            return false;
        }

        $changesName = FileVersions::getFileChangesName($fileId, $versionId);
        return $folderHistory->fileExists($changesName);
    }

    /**
     * Get changes file
     *
     * @param IVersionManager $versionManager - file version manager
     * @param File $file - file
     * @param string $version - file version number
     *
     * @return OCP\Files\SimpleFS\ISimpleFile
     */
    public static function getChangesFile($versionManager, $file, $version) {
        $owner = $file->getFileInfo()->getOwner();
        if ($owner === null) {
            return null;
        }

        $appData = \OC::$server->getAppDataDir(self::$appName);

        $ownerId = $owner->getUID();
        $folderHistory = null;
        try {
            $folderHistory = $appData->getFolder($ownerId);
        } catch (NotFoundException $e) {
            return null;
        }

        $versions = array_reverse($versionManager->getVersionsForFile($owner, $file));

        $versionId = null;
        if ($version > count($versions)) {
            $versionId = $file->getFileInfo()->getMtime();
        } else {
            $fileVersion = array_values($versions)[$version - 1];

            $versionId = $fileVersion->getRevisionId();
        }

        $changesName = self::getFileChangesName($file->getId(), $versionId);
        if (!$folderHistory->fileExists($changesName)) {
            return null;
        }

        $changes = $folderHistory->getFile($changesName);
        return $changes;
    }

    /**
     * Save history to storage
     *
     * @param DocumentService $documentService - service connector to Document Service
     * @param OCP\Files\FileInfo $fileInfo - file info
     * @param array $history - file history
     * @param string $changesurl - link to file changes
     * @param string $prevVersion - previous version for check
     */
    public static function saveHistory($documentService, $fileInfo, $history, $changesurl, $prevVersion) {
        $logger = \OC::$server->getLogger();

        $owner = $fileInfo->getOwner();

        if ($owner === null) {
            return;
        }
        if (empty($history) || empty($changesurl)) {
            return;
        }

        $appData = \OC::$server->getAppDataDir(self::$appName);

        $ownerId = $owner->getUID();
        try {
            $folderHistory = $appData->getFolder($ownerId);
        } catch (NotFoundException $e) {
            $folderHistory = $appData->newFolder($ownerId);
        }

        try {
            $fileId = $fileInfo->getId();
            $version = $fileInfo->getMtime();

            $changes = $documentService->Request($changesurl);
            $changesName = self::getFileChangesName($fileId, $version);
            $changesFile = $folderHistory->newFile($changesName);
            $changesFile->putContent($changes);

            $history["prev"] = $prevVersion;
            $historyName = self::getFileHistoryName($fileId, $version);
            $historyFile = $folderHistory->newFile($historyName);
            $historyFile->putContent(json_encode($history));

            $logger->debug("Track: $fileId for $ownerId stored changes $changesName history $historyName", ["app" => self::$appName]);
        } catch (\Exception $e) {
            $logger->logException($e, ["message" => "Track: save $fileId history error", "app" => self::$appName]);
        }
    }

    /**
     * Delete all versions of file
     *
     * @param string $ownerId - file owner id
     * @param string $fileId - file id
     */
    public static function deleteAllVersions($ownerId, $fileId) {
        $logger = \OC::$server->getLogger();

        $logger->debug("deleteAllVersions $ownerId $fileId", ["app" => self::$appName]);

        if ($ownerId === null || $fileId === null) {
            return;
        }

        $appData = \OC::$server->getAppDataDir(self::$appName);
        $folderHistory = null;
        try {
            $folderHistory = $appData->getFolder($ownerId);
        } catch (NotFoundException $e) {
            return;
        }

        $storedFiles = $folderHistory->getDirectoryListing();
        foreach ($storedFiles as $storedFile) {
            $storedFileName = $storedFile->getName();
            if (strpos($storedFileName, "history_" . $fileId . "_") === 0
                || strpos($storedFileName, "changes_" . $fileId . "_") === 0) {
                $storedFile->delete();

                $logger->debug("deleteAllVersions $storedFileName", ["app" => self::$appName]);
            }
        }
    }
}
