<?php

if (!defined('TYPO3_MODE') && !defined('TYPO3')) {
    die('Access denied.');
}

if (version_compare(\JWeiland\Avalex\Utility\Typo3Utility::getTypo3Version(), '13.0', '<')) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_avalex_configuration');
}
