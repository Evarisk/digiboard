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
 * \file    view/remote_ticket_dashboard.php
 * \ingroup digiboard
 * \brief   Page with the ticket dashboard of another Dolibarr, read through its API
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

// Load Saturne libraries
require_once __DIR__ . '/../../saturne/class/saturnedashboard.class.php';

// Load DigiBoard libraries
require_once __DIR__ . '/../class/digiboardremoteinstance.class.php';
require_once __DIR__ . '/../lib/digiboard.lib.php';

// Global variables definitions
global $conf, $db, $hookmanager, $langs, $moduleName, $moduleNameLowerCase, $user;

// Load translation files required by the page
saturne_load_langs(['ticket', 'projects', 'companies']);

// Get parameters
$action     = GETPOST('action', 'aZ09');
$instanceId = GETPOST('instanceid', 'alpha');

// Initialize technical objects
$dashboard      = new SaturneDashboard($db, $moduleNameLowerCase);
$remoteInstance = new DigiBoardRemoteInstance($db);
$object         = null;

// The CSV export of a graph writes in the temp directory of the module, dashboard_actions.tpl.php reads that name
$upload_dir = $conf->$moduleNameLowerCase->multidir_output[$conf->entity ?? 1];

$hookmanager->initHooks([$moduleNameLowerCase . 'remoteticketdashboard', 'globalcard']);

// Security check - Protection if external user
$permissionToRead = $user->hasRight($moduleNameLowerCase, 'read');
saturne_check_access($permissionToRead);

/*
 * Actions
 */

$parameters = [];
$resHook    = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($resHook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($resHook)) {
    // Actions adddashboardinfo, closedashboardinfo, dashboardfilter, generate_csv
    require_once __DIR__ . '/../../saturne/core/tpl/actions/dashboard_actions.tpl.php';

    if ($action == 'refreshremote') {
        $remoteInstance->deleteCache();
        setEventMessages($langs->trans('RemoteCacheCleared'), []);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?instanceid=' . urlencode($instanceId));
        exit;
    }
}

/*
 * View
 */

$instances = $remoteInstance->getInstances(true);

// The instance last looked at is the one that comes back, until another one is picked
$lastInstanceId = getDolUserString('DIGIBOARD_REMOTE_INSTANCE');
if (empty($instanceId)) {
    $instanceId = $lastInstanceId;
}
if (!isset($instances[$instanceId])) {
    $instanceId = empty($instances) ? '' : (string) array_key_first($instances);
}

$dashboardConfig = json_decode(getDolUserString('DIGIBOARD_DASHBOARD_CONFIG')) ?: new stdClass();
$period          = (string) ($dashboardConfig->filters->ticketPeriod ?? '365');
$filterUserId    = (int) ($dashboardConfig->filters->ticketUser ?? 0);

if ($instanceId != $lastInstanceId) {
    // An assignee was picked on the instance that was displayed then: another instance numbers its users its own way
    $filterUserId = 0;
    if (isset($dashboardConfig->filters->ticketUser)) {
        $dashboardConfig->filters->ticketUser = 0;
    }
    dol_set_user_param($db, $conf, $user, [
        'DIGIBOARD_REMOTE_INSTANCE'   => $instanceId,
        'DIGIBOARD_DASHBOARD_CONFIG'  => json_encode($dashboardConfig)
    ]);
}

$title   = $langs->transnoentities('RemoteTicketDashboard');
$helpUrl = 'FR:Module_' . $moduleName;

saturne_header(0, '', $title, $helpUrl);

$morehtmlright = '';
if (!empty($instances)) {
    $morehtmlright .= '<a class="butAction" href="' . dol_buildpath('/digiboard/view/remote_ticket_list.php', 1) . '?instanceid=' . urlencode($instanceId) . '">' . $langs->transnoentities('RemoteTicketList') . '</a>';
    $morehtmlright .= '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=refreshremote&instanceid=' . urlencode($instanceId) . '&token=' . newToken() . '">' . $langs->transnoentities('RefreshRemoteData') . '</a>';
}
if ($user->hasRight($moduleNameLowerCase, 'adminpage', 'read')) {
    $morehtmlright .= '<a class="butAction" href="' . dol_buildpath('/digiboard/admin/remoteinstance.php', 1) . '">' . $langs->transnoentities('ConfigureRemoteInstances') . '</a>';
}

print load_fiche_titre($title, $morehtmlright, 'ticket');

if (empty($instances)) {
    print '<div class="warning">' . $langs->transnoentities('NoRemoteInstanceConfigured') . '</div>';

    llxFooter();
    $db->close();
    exit;
}

print '<div class="fichecenter">';

// The band compares every instance, the dashboard below shows the one that is selected
digiboard_show_remote_ticket_summary($instances, $period, (string) $instanceId);

print '<div class="paddingtop paddingbottom">';
digiboard_show_remote_instance_selector($instances, (string) $instanceId, $_SERVER['PHP_SELF']);
print '</div>';

$result = $remoteInstance->getTicketDashboard($instances[$instanceId], $period, $filterUserId);

if (!$result['success']) {
    print '<div class="error">' . $langs->transnoentities('RemoteInstanceCallFailed', dol_escape_htmltag($instances[$instanceId]['label'])) . ' : ' . dol_escape_htmltag($result['error']) . '</div>';
} else {
    print '<div class="opacitymedium paddingbottom">';
    print $langs->transnoentities('RemoteDataReadOn', dol_escape_htmltag($result['data']['instance']['name'] ?? $instances[$instanceId]['label']), dol_print_date($result['data']['instance']['date'] ?? dol_now(), 'dayhour'));
    print $result['fromCache'] ? ' - ' . $langs->transnoentities('RemoteAnswerFromCache') : '';
    print '</div>';

    $dashboard->show_dashboard(['RemoteTicketDashboard' => $result['data']]);
}

print '</div>';

// End of page
llxFooter();
$db->close();
