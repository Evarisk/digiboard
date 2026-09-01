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
 * \file    view/remote_ticket_list.php
 * \ingroup digiboard
 * \brief   Page listing the tickets of another Dolibarr, read through its API
 */

// Load DigiBoard environment
if (file_exists('../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../digiboard.main.inc.php';
} elseif (file_exists('../../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../../digiboard.main.inc.php';
} else {
    die('Include of digiboard main fails');
}

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
$openOnly   = GETPOSTISSET('openonly') ? GETPOSTINT('openonly') : 1;
$period     = GETPOSTISSET('period') ? GETPOST('period', 'alpha') : '365';
$search     = GETPOST('search', 'alphanohtml');
$sortfield  = GETPOST('sortfield', 'aZ09comma');
$sortorder  = GETPOST('sortorder', 'aZ09comma');
$page       = GETPOSTINT('page') > 0 ? GETPOSTINT('page') : 0;
$limit      = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;

if (empty($sortfield)) {
    $sortfield = 'dateCreation';
}
if (empty($sortorder)) {
    $sortorder = 'DESC';
}

// Initialize technical objects
$remoteInstance = new DigiBoardRemoteInstance($db);
$object         = null;

$hookmanager->initHooks([$moduleNameLowerCase . 'remoteticketlist', 'globallist']);

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

if (empty($resHook) && $action == 'refreshremote') {
    $remoteInstance->deleteCache();
    setEventMessages($langs->trans('RemoteCacheCleared'), []);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?instanceid=' . urlencode($instanceId));
    exit;
}

/*
 * View
 */

$instances = $remoteInstance->getInstances(true);

$lastInstanceId = getDolUserString('DIGIBOARD_REMOTE_INSTANCE');
if (empty($instanceId)) {
    $instanceId = $lastInstanceId;
}
if (!isset($instances[$instanceId])) {
    $instanceId = empty($instances) ? '' : (string) array_key_first($instances);
}

$title   = $langs->transnoentities('RemoteTicketList');
$helpUrl = 'FR:Module_' . $moduleName;

saturne_header(0, '', $title, $helpUrl);

if (empty($instances)) {
    print load_fiche_titre($title, '', 'ticket');
    print '<div class="warning">' . $langs->transnoentities('NoRemoteInstanceConfigured') . '</div>';

    llxFooter();
    $db->close();
    exit;
}

$result  = $remoteInstance->getTickets($instances[$instanceId], $period, 0, $openOnly, 0);
$tickets = $result['success'] ? ($result['data']['tickets'] ?? []) : [];

// The whole list comes in one answer: filtering, sorting and paging are done on what is already here
if (!empty($search)) {
    $tickets = array_filter($tickets, function ($ticket) use ($search) {
        return stripos($ticket['ref'] . ' ' . $ticket['subject'] . ' ' . $ticket['societyName'] . ' ' . $ticket['assigneeName'], $search) !== false;
    });
}

$sortableFields = ['ref', 'subject', 'societyName', 'assigneeName', 'statusLabel', 'dateCreation', 'lastMessage', 'nbMessages', 'timeSpent'];
if (in_array($sortfield, $sortableFields)) {
    usort($tickets, function ($first, $second) use ($sortfield, $sortorder) {
        $firstValue  = $first[$sortfield] ?? '';
        $secondValue = $second[$sortfield] ?? '';
        $comparison  = is_numeric($firstValue) && is_numeric($secondValue) ? $firstValue <=> $secondValue : strcasecmp((string) $firstValue, (string) $secondValue);

        return strtoupper($sortorder) == 'DESC' ? -$comparison : $comparison;
    });
}

$nbTotal = count($tickets);
$rows    = array_slice($tickets, $page * $limit, $limit);

$param  = '&instanceid=' . urlencode($instanceId) . '&openonly=' . $openOnly . '&period=' . urlencode($period);
$param .= empty($search) ? '' : '&search=' . urlencode($search);

$morehtmlright  = '<a class="butAction" href="' . dol_buildpath('/digiboard/view/remote_ticket_dashboard.php', 1) . '?instanceid=' . urlencode($instanceId) . '">' . $langs->transnoentities('RemoteTicketDashboard') . '</a>';
$morehtmlright .= '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=refreshremote' . $param . '&token=' . newToken() . '">' . $langs->transnoentities('RefreshRemoteData') . '</a>';

print load_fiche_titre($title, $morehtmlright, 'ticket');

if (!$result['success']) {
    print '<div class="error">' . $langs->transnoentities('RemoteInstanceCallFailed', dol_escape_htmltag($instances[$instanceId]['label'])) . ' : ' . dol_escape_htmltag($result['error']) . '</div>';

    llxFooter();
    $db->close();
    exit;
}

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '" class="paddingbottom">';
print '<span class="opacitymedium paddingright">' . $langs->trans('RemoteInstance') . '</span>';

$instanceOptions = [];
foreach ($instances as $instance) {
    $instanceOptions[$instance['id']] = $instance['label'];
}
print Form::selectarray('instanceid', $instanceOptions, $instanceId, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 marginrightonly');

print Form::selectarray('period', [
    '30'  => $langs->trans('TicketPeriodLastMonth'),
    '90'  => $langs->trans('TicketPeriodLastMonths', '03'),
    '180' => $langs->trans('TicketPeriodLastMonths', '06'),
    '365' => $langs->trans('TicketPeriodLastMonths', '12'),
    '730' => $langs->trans('TicketPeriodLastMonths', '24'),
    '0'   => $langs->trans('TicketPeriodAll')
], $period, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 marginrightonly');

print '<input type="text" name="search" class="marginrightonly" placeholder="' . $langs->trans('Search') . '" value="' . dol_escape_htmltag($search) . '">';
print '<label class="marginrightonly"><input type="checkbox" name="openonly" value="1"' . (empty($openOnly) ? '' : ' checked') . '> ' . $langs->trans('OpenTicketsOnly') . '</label>';
print '<input type="submit" class="button small" value="' . $langs->trans('Filter') . '">';
print '</form>';

print_barre_liste('', $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', count($rows), $nbTotal, '', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';

print '<tr class="liste_titre">';
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 'ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Subject', $_SERVER['PHP_SELF'], 'subject', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('ThirdParty', $_SERVER['PHP_SELF'], 'societyName', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('AssignedTo', $_SERVER['PHP_SELF'], 'assigneeName', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'statusLabel', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DateCreation', $_SERVER['PHP_SELF'], 'dateCreation', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('LastMessage', $_SERVER['PHP_SELF'], 'lastMessage', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('NbOfExchanges', $_SERVER['PHP_SELF'], 'nbMessages', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TimeSpent', $_SERVER['PHP_SELF'], 'timeSpent', '', $param, '', $sortfield, $sortorder, 'right ');
print '</tr>';

if (empty($rows)) {
    print '<tr class="oddeven"><td colspan="9" class="opacitymedium">' . $langs->trans('NoRecordFound') . '</td></tr>';
}

foreach ($rows as $ticket) {
    print '<tr class="oddeven">';

    // The ticket lives on the other instance: its card is opened there, in a tab of its own
    print '<td class="nowraponall"><a href="' . dol_escape_htmltag($ticket['url']) . '" target="_blank" rel="noopener">';
    print '<i class="fas fa-ticket-alt paddingright" style="color: #6c6aa8;"></i>' . dol_escape_htmltag($ticket['ref']) . '</a></td>';

    print '<td class="tdoverflowmax300" title="' . dol_escape_htmltag($ticket['subject']) . '">' . dol_escape_htmltag($ticket['subject']) . '</td>';
    print '<td class="tdoverflowmax150">' . dol_escape_htmltag($ticket['societyName']) . '</td>';
    print '<td class="tdoverflowmax150">' . (empty($ticket['assigneeName']) ? '<span class="opacitymedium">' . $langs->trans('TicketsUnassigned') . '</span>' : dol_escape_htmltag($ticket['assigneeName'])) . '</td>';
    print '<td class="nowraponall">' . dol_escape_htmltag($ticket['statusLabel']) . '</td>';
    print '<td class="center nowraponall">' . dol_print_date($ticket['dateCreation'], 'day') . '</td>';
    print '<td class="center nowraponall">' . (empty($ticket['lastMessage']) ? '<span class="opacitymedium">' . $langs->trans('None') . '</span>' : dol_print_date($ticket['lastMessage'], 'dayhour')) . '</td>';
    print '<td class="right">' . $ticket['nbMessages'] . '</td>';
    print '<td class="right">' . (empty($ticket['timeSpent']) ? '-' : convertSecondToTime((int) $ticket['timeSpent'], 'allhourmin')) . '</td>';

    print '</tr>';
}

print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
