<?php
/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    admin/remoteinstance.php
 * \ingroup digiboard
 * \brief   DigiBoard remote instances setup page
 */

// Load DigiBoard environment
if (file_exists('../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../digiboard.main.inc.php';
} elseif (file_exists('../../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../../digiboard.main.inc.php';
} else {
    die('Include of digiboard main fails');
}

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// Load DigiBoard libraries
require_once __DIR__ . '/../lib/digiboard.lib.php';
require_once __DIR__ . '/../class/digiboardremoteinstance.class.php';

// Global variables definitions
global $conf, $db, $langs, $user;

// Load translation files required by the page
saturne_load_langs(['admin']);

// Get parameters
$action = GETPOST('action', 'alpha');
$id     = GETPOST('id', 'alpha');

// Initialize technical objects
$remoteInstance = new DigiBoardRemoteInstance($db);

// Security check - Protection if external user
$permissionToRead = $user->hasRight('digiboard', 'adminpage', 'read');
saturne_check_access($permissionToRead);

/*
 * Actions
 */

if ($action == 'save') {
    $result = $remoteInstance->saveInstance([
        'id'      => $id,
        'label'   => GETPOST('label', 'alphanohtml'),
        'url'     => GETPOST('url', 'alphanohtml'),
        'token'   => GETPOST('apikey', 'alphanohtml'),
        'enabled' => GETPOSTINT('enabled'),
        'local'   => GETPOSTINT('local')
    ]);

    if ($result > 0) {
        setEventMessages($langs->trans('RemoteInstanceSaved'), []);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    setEventMessages($remoteInstance->error, [], 'errors');
    $action = 'edit';
}

if ($action == 'confirm_delete' && !empty($id)) {
    if ($remoteInstance->deleteInstance($id) > 0) {
        setEventMessages($langs->trans('RemoteInstanceDeleted'), []);
    } else {
        setEventMessages($langs->trans('RemoteInstanceDeleteFailed'), [], 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action == 'test' && !empty($id)) {
    $instance = $remoteInstance->getInstance($id);
    $result   = empty($instance) ? ['success' => false, 'error' => $langs->trans('RemoteInstanceNotFound')] : $remoteInstance->testConnection($instance);

    if ($result['success']) {
        setEventMessages($langs->trans('RemoteInstanceTestOk', $instance['label'], round($result['duration'] * 1000)), []);
    } else {
        setEventMessages($langs->trans('RemoteInstanceTestKo', $instance['label'] ?? $id) . ' : ' . $result['error'], [], 'errors');
    }
    $action = '';
}

if ($action == 'clearcache') {
    $nb = $remoteInstance->deleteCache();
    setEventMessages($langs->trans('RemoteCacheClearedNb', $nb), []);
    $action = '';
}

if ($action == 'setcachettl') {
    dolibarr_set_const($db, 'DIGIBOARD_REMOTE_CACHE_TTL', GETPOSTINT('cachettl'), 'integer', 0, '', $conf->entity);
    setEventMessages($langs->trans('SetupSaved'), []);
    $action = '';
}

/*
 * View
 */

$title   = $langs->trans('ModuleSetup', 'DigiBoard');
$helpUrl = 'FR:Module_DigiBoard';

saturne_header(0, '', $title, $helpUrl);

// Subheader
$linkBack = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($title, $linkBack, 'title_setup');

// Configuration header
$head = digiboard_admin_prepare_head();
print dol_get_fiche_head($head, 'remoteinstance', $title, -1, 'digiboard_color@digiboard');

print '<span class="opacitymedium">' . $langs->trans('RemoteInstancesDescription') . '</span><br><br>';

$instances = $remoteInstance->getInstances();

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Label') . '</td>';
print '<td>' . $langs->trans('RemoteInstanceUrl') . '</td>';
print '<td class="center">' . $langs->trans('RemoteInstanceToken') . '</td>';
print '<td class="center">' . $langs->trans('RemoteInstanceLocalUrl') . '</td>';
print '<td class="center">' . $langs->trans('Enabled') . '</td>';
print '<td class="right"></td>';
print '</tr>';

if (empty($instances)) {
    print '<tr class="oddeven"><td colspan="6" class="opacitymedium">' . $langs->trans('NoRemoteInstanceConfigured') . '</td></tr>';
}

foreach ($instances as $instance) {
    print '<tr class="oddeven">';
    print '<td>' . dol_escape_htmltag($instance['label']) . '</td>';
    print '<td>' . dol_escape_htmltag($instance['url']) . '</td>';
    print '<td class="center">' . digiboard_yes_no(!empty($instance['token'])) . '</td>';
    print '<td class="center">' . digiboard_yes_no(!empty($instance['local']), 1) . '</td>';
    print '<td class="center">' . digiboard_yes_no(!empty($instance['enabled'])) . '</td>';
    print '<td class="right nowraponall">';
    print '<a class="button small" href="' . $_SERVER['PHP_SELF'] . '?action=test&id=' . urlencode($instance['id']) . '&token=' . newToken() . '">' . $langs->trans('TestConnection') . '</a> ';
    print '<a class="button small" href="' . $_SERVER['PHP_SELF'] . '?action=edit&id=' . urlencode($instance['id']) . '">' . $langs->trans('Modify') . '</a> ';
    print '<a class="button small butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?action=confirm_delete&id=' . urlencode($instance['id']) . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans('ConfirmDeleteRemoteInstance', $instance['label'])) . '\');">' . $langs->trans('Delete') . '</a>';
    print '</td>';
    print '</tr>';
}

print '</table>';
print '</div>';

// The form both adds an instance and edits one, an instance is nothing but its connection settings
$edited = $action == 'edit' && !empty($id) ? $remoteInstance->getInstance($id) : [];

print '<br>';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';
print '<input type="hidden" name="id" value="' . dol_escape_htmltag($edited['id'] ?? '') . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans(empty($edited) ? 'AddRemoteInstance' : 'EditRemoteInstance') . '</td></tr>';

print '<tr class="oddeven"><td class="titlefield fieldrequired">' . $langs->trans('Label') . '</td>';
print '<td><input type="text" name="label" class="minwidth300" value="' . dol_escape_htmltag($edited['label'] ?? '') . '" required></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">' . $langs->trans('RemoteInstanceUrl') . '</td>';
print '<td><input type="url" name="url" class="minwidth500" placeholder="https://crm.example.com/htdocs" value="' . dol_escape_htmltag($edited['url'] ?? '') . '" required>';
print '<br><span class="opacitymedium small">' . $langs->trans('RemoteInstanceUrlDescription') . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('RemoteInstanceToken') . '</td>';
print '<td><input type="password" name="apikey" class="minwidth300" autocomplete="new-password" placeholder="' . (empty($edited['token']) ? '' : $langs->trans('RemoteInstanceTokenKept')) . '">';
print '<br><span class="opacitymedium small">' . $langs->trans('RemoteInstanceTokenDescription') . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('RemoteInstanceLocalUrl') . '</td>';
print '<td><input type="checkbox" name="local" value="1"' . (empty($edited['local']) ? '' : ' checked') . '>';
print '<span class="opacitymedium small paddingleft">' . $langs->trans('RemoteInstanceLocalUrlDescription') . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('Enabled') . '</td>';
print '<td><input type="checkbox" name="enabled" value="1"' . (empty($edited) || !empty($edited['enabled']) ? ' checked' : '') . '></td></tr>';

print '</table>';

print '<div class="center paddingtop">';
print '<input type="submit" class="button" value="' . $langs->trans('Save') . '">';
if (!empty($edited)) {
    print ' <a class="button button-cancel" href="' . $_SERVER['PHP_SELF'] . '">' . $langs->trans('Cancel') . '</a>';
}
print '</div>';
print '</form>';

// Cache settings, a dashboard is read far more often than it changes
print '<br>';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="setcachettl">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('RemoteCache') . '</td></tr>';
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans('RemoteCacheTtl') . '</td>';
print '<td><input type="number" name="cachettl" class="width100" min="0" value="' . $remoteInstance->getCacheTtl() . '"> ' . $langs->trans('Seconds');
print ' <input type="submit" class="button small" value="' . $langs->trans('Save') . '">';
print ' <a class="button small" href="' . $_SERVER['PHP_SELF'] . '?action=clearcache&token=' . newToken() . '">' . $langs->trans('ClearRemoteCache') . '</a>';
print '<br><span class="opacitymedium small">' . $langs->trans('RemoteCacheTtlDescription') . '</span></td></tr>';
print '</table>';
print '</form>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
