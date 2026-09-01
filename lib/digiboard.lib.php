<?php
/* Copyright (C) 2024 EVARISK <technique@evarisk.com>
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
 * \file    lib/digiboard.lib.php
 * \ingroup digiboard
 * \brief   Library files with common functions for Admin conf
 */

/**
 * Prepare admin pages header
 *
 * @return array $head Array of tabs
 */
function digiboard_admin_prepare_head(): array
{
    // Global variables definitions
    global $conf, $langs;

    // Load translation files required by the page
    saturne_load_langs();

    // Initialize values
    $h    = 0;
    $head = [];

    $head[$h][0] = dol_buildpath('digiboard/admin/setup.php', 1);
    $head[$h][1] = $conf->browser->layout == 'classic' ? '<i class="fas fa-cog pictofixedwidth"></i>' . $langs->trans('ModuleSettings') : '<i class="fas fa-cog"></i>';
    $head[$h][2] = 'settings';
    $h++;

    $head[$h][0] = dol_buildpath('digiboard/admin/remoteinstance.php', 1);
    $head[$h][1] = $conf->browser->layout == 'classic' ? '<i class="fas fa-network-wired pictofixedwidth"></i>' . $langs->trans('RemoteInstances') : '<i class="fas fa-network-wired"></i>';
    $head[$h][2] = 'remoteinstance';
    $h++;

    $head[$h][0] = dol_buildpath('saturne/admin/about.php', 1) . '?module_name=DigiBoard';
    $head[$h][1] = $conf->browser->layout == 'classic' ? '<i class="fab fa-readme pictofixedwidth"></i>' . $langs->trans('About') : '<i class="fab fa-readme"></i>';
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'digiboard@digiboard');

    complete_head_from_modules($conf, $langs, null, $head, $h, 'digiboard@digiboard', 'remove');

    return $head;
}

/**
 * Show a yes or no state as an icon
 *
 * @param  bool   $state    State to show
 * @param  int    $hideNo   1 to show nothing rather than a no
 * @return string           Icon of the state
 */
function digiboard_yes_no(bool $state, int $hideNo = 0): string
{
    // Global variables definitions
    global $langs;

    if (!$state && !empty($hideNo)) {
        return '';
    }

    if ($state) {
        return '<i class="fas fa-check" style="color: #25a55b;" title="' . $langs->trans('Yes') . '"></i>';
    }

    return '<i class="fas fa-times" style="color: #a72d2d;" title="' . $langs->trans('No') . '"></i>';
}

/**
 * Show the ticket counters of every remote instance, side by side
 *
 * The band is what the page opens on: it says which instance is worth opening before its dashboard is even read.
 * Every instance is asked for its counters alone, which costs it no graph, and the answers are cached, so browsing
 * from one instance to another does not call them all again.
 *
 * @param  array  $instances  Instances to compare, keyed by their id
 * @param  string $period     Number of days the flow indicators cover, 0 for the whole history
 * @param  string $selectedId Id of the instance whose dashboard is displayed below the band
 * @return void
 */
function digiboard_show_remote_ticket_summary(array $instances, string $period, string $selectedId): void
{
    // Global variables definitions
    global $db, $langs;

    require_once __DIR__ . '/../class/digiboardremoteinstance.class.php';

    $remoteInstance = new DigiBoardRemoteInstance($db);

    $columns = [
        'ticketsOpen'       => 'NbOfOpenedTicket',
        'ticketsCreated'    => 'TicketsCreatedOverPeriod',
        'ticketsClosed'     => 'TicketsClosedOverPeriod',
        'ticketsUnassigned' => 'TicketsUnassigned',
        'ticketsStale'      => 'TicketsDormant',
        'meanBacklogAge'    => 'MeanBacklogAge',
        'meanResolution'    => 'MeanResolutionTime',
        'timeSpent'         => 'TotalTimeSpentOverPeriod'
    ];
    $delays = ['meanBacklogAge', 'meanResolution'];

    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>' . $langs->trans('RemoteInstance') . '</td>';
    foreach ($columns as $key => $label) {
        print '<td class="right">' . $langs->trans($label) . '</td>';
    }
    print '<td class="right">' . $langs->trans('RemoteAnswer') . '</td>';
    print '</tr>';

    foreach ($instances as $instance) {
        $result = $remoteInstance->getTicketSummary($instance, $period);

        print '<tr class="oddeven' . ($instance['id'] == $selectedId ? ' highlight' : '') . '">';
        print '<td><a href="' . dol_buildpath('/digiboard/view/remote_ticket_dashboard.php', 1) . '?instanceid=' . urlencode($instance['id']) . '">' . dol_escape_htmltag($instance['label']) . '</a>';
        print '<br><span class="opacitymedium small">' . dol_escape_htmltag($instance['url']) . '</span></td>';

        if (!$result['success']) {
            print '<td class="right" colspan="' . (count($columns) + 1) . '"><span class="error">' . dol_escape_htmltag($result['error']) . '</span></td>';
            print '</tr>';
            continue;
        }

        $summary = $result['data']['summary'] ?? [];
        foreach ($columns as $key => $label) {
            $value = $summary[$key] ?? 0;
            if ($key == 'timeSpent') {
                $value = empty($value) ? '-' : convertSecondToTime((int) $value, 'allhourmin');
            } elseif (in_array($key, $delays)) {
                $value = empty($value) ? '-' : convertSecondToTime((int) $value, 'all');
            }
            print '<td class="right">' . $value . '</td>';
        }

        print '<td class="right opacitymedium small">';
        print $result['fromCache'] ? $langs->trans('RemoteAnswerFromCache') : round($result['duration'] * 1000) . ' ms';
        print '</td>';
        print '</tr>';
    }

    print '</table>';
    print '</div>';
}

/**
 * Show the selector of the instance the page reads
 *
 * @param  array  $instances  Instances to choose from, keyed by their id
 * @param  string $selectedId Id of the instance currently read
 * @param  string $page       Page the selector submits to
 * @return void
 */
function digiboard_show_remote_instance_selector(array $instances, string $selectedId, string $page): void
{
    // Global variables definitions
    global $langs;

    $options = [];
    foreach ($instances as $instance) {
        $options[$instance['id']] = $instance['label'];
    }

    print '<form method="GET" action="' . $page . '" class="remote-instance-selector inline-block marginrightonly">';
    print '<span class="opacitymedium paddingright">' . $langs->trans('RemoteInstance') . '</span>';
    print Form::selectarray('instanceid', $options, $selectedId, 0, 0, 0, 'onchange="this.form.submit()"', 0, 0, 0, '', 'minwidth200');
    print '<noscript><input type="submit" class="button small" value="' . $langs->trans('Refresh') . '"></noscript>';
    print '</form>';
}
