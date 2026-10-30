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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    view/risk_list.php
 * \ingroup digiboard
 * \brief   Simplified interactive risk listing page (Notion style)
 */

// Load DigiBoard environment
if (file_exists(__DIR__ . '/../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../digiboard.main.inc.php';
} elseif (file_exists(__DIR__ . '/../../digiboard.main.inc.php')) {
    require_once __DIR__ . '/../../digiboard.main.inc.php';
} else {
    die('Include of digiboard main fails');
}

// Global variables definitions
global $conf, $db, $hookmanager, $langs, $mc, $moduleName, $mysoc, $user;

// Load translation files required by the page
saturne_load_langs(['digiboard@digiboard', 'projects']);

// Security check
if (!$user->rights->digiboard->read) {
    accessforbidden();
}

// A risk or a task of another entity only opens once the session is in that entity: the cards
// send an object of another entity back to the module home. Multicompany switches through its
// switchentity URL parameter, but its redirect doubles the path of an install in a subfolder
$openId = GETPOSTINT('open_id');
if (in_array(GETPOST('action', 'aZ09'), ['openrisk', 'opentask'], true) && $openId > 0) {
    $openEntity = GETPOSTINT('open_entity');
    if ($openEntity > 0 && $openEntity != $conf->entity && isModEnabled('multicompany') && is_object($mc)) {
        if ($mc->switchEntity($openEntity) <= 0) {
            setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    if (GETPOST('action', 'aZ09') == 'openrisk') {
        header('Location: ' . dol_buildpath('/digiriskdolibarr/view/risk/risk_card.php?id=' . $openId, 1));
    } else {
        header('Location: ' . DOL_URL_ROOT . '/projet/tasks/task.php?id=' . $openId . '&withproject=1');
    }
    exit;
}

$title   = $langs->transnoentities('SimplifiedRiskList');
$helpUrl = 'FR:Module_' . $moduleName;

// Check if Digirisk module is enabled
if (empty($conf->digiriskdolibarr->enabled)) {
    saturne_header(0, '', $title, $helpUrl);
    print load_fiche_titre($title, '', 'fa-exclamation-triangle');
    print '<div class="warning paddingtopbottom">' . $langs->trans('ModuleDigiriskDolibarrRequired') . '</div>';
    llxFooter();
    $db->close();
    exit;
}

// Get parameters
$action       = GETPOST('action', 'aZ09');
$filterEntity = GETPOSTINT('filter_entity');
$elementId    = GETPOSTISSET('element_id') ? GETPOSTINT('element_id') : GETPOSTINT('establishment_id');
$categoryId   = (GETPOSTISSET('category_id') && GETPOST('category_id') !== '' && (int) GETPOST('category_id') >= 0) ? GETPOSTINT('category_id') : -1;
$cotationFilter  = GETPOST('cotation_filter', 'alpha');
if (empty($cotationFilter)) {
    $cotationFilter = 'all';
}
$riskType = GETPOSTISSET('risk_type') ? GETPOST('risk_type', 'alpha') : 'risk';
if ($riskType !== 'riskenvironmental') {
    $riskType = 'risk';
}
$search    = trim((string) GETPOST('search', 'alphanohtml'));
$sortfield = GETPOST('sortfield', 'alpha');
$sortorder = GETPOST('sortorder', 'alpha');
$page      = GETPOSTINT('page') > 0 ? GETPOSTINT('page') : 0;
$limit     = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;

if (empty($sortfield)) {
    $sortfield = 'cotation';
}
// The sort order goes as is into the ORDER BY clause
$sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';

// Highest cotation of each risk level, as the Digirisk scale (getEvaluationScale) splits it
$cotationLevelMax = [
    'low'          => 47,
    'plan'         => 50,
    'treat'        => 79,
    'unacceptable' => 100,
];
$riskLevelLabels = [
    'none'         => 'RiskNotAssessed',
    'low'          => 'RiskLow',
    'plan'         => 'RiskToPlan',
    'treat'        => 'RiskToTreat',
    'unacceptable' => 'RiskUnacceptable',
];

// Risk level key of a cotation, 'none' when the risk was never assessed
$getRiskLevel = function ($cotation) use ($cotationLevelMax) {
    if ($cotation === null) {
        return 'none';
    }
    foreach ($cotationLevelMax as $level => $max) {
        if ($cotation <= $max) {
            return $level;
        }
    }
    return 'unacceptable';
};

// Retrieve accessible entities (establishments)
$entities = [];
if (isModEnabled('multicompany') && is_object($mc)) {
    $entities = $mc->getEntitiesList(true, false, true);
}
if (empty($entities)) {
    $entityName = !empty($conf->global->MAIN_INFO_SOCIETE_NOM) ? $conf->global->MAIN_INFO_SOCIETE_NOM : (!empty($mysoc->name) ? $mysoc->name : 'Société');
    $entities[(int) $conf->entity] = $entityName;
}

// Validate selected filter entity
if ($filterEntity > 0 && !isset($entities[$filterEntity])) {
    $filterEntity = 0;
}

// Load Danger Categories from Digirisk JSON file
$dangerCategories = [];
$dangerCategoriesFile = DOL_DOCUMENT_ROOT . '/custom/digiriskdolibarr/js/json/dangerCategories.json';
if (file_exists($dangerCategoriesFile)) {
    $jsonRaw = (string) file_get_contents($dangerCategoriesFile);
    $json = json_decode($jsonRaw, true);
    if (!empty($json[0][$riskType]) && is_array($json[0][$riskType])) {
        foreach ($json[0][$riskType] as $cat) {
            $dangerCategories[$cat['position']] = $cat;
        }
    }
}

// Protection means are the risk signs of the risk's element filed as personal (OBLIGATION) or collective
// (epc) protective equipment, as the product risks of Digirisk pick their protections
$protectionCategories = [];
$signalisationFile    = DOL_DOCUMENT_ROOT . '/custom/digiriskdolibarr/js/json/signalisationCategories.json';
if (file_exists($signalisationFile)) {
    foreach ((array) json_decode((string) file_get_contents($signalisationFile), true) as $sign) {
        if (preg_match('/^(OBLIGATION|epc)\//', $sign['name_thumbnail'] ?? '')) {
            $protectionCategories[(int) $sign['position']] = $sign;
        }
    }
}

// Criteria of the advanced cotation method, from the grid Digirisk evaluates with. Its criteria come in
// the order of the assessment columns: an environmental risk stores frequency, history, control and
// environment into the exposition, occurrence, formation and protection columns
$cotationCriteria  = [];
$criteriaColumns   = ['gravite', 'exposition', 'occurrence', 'formation', 'protection'];
$cotationGridFile  = DOL_DOCUMENT_ROOT . '/custom/digiriskdolibarr/js/json/default.json';
if (file_exists($cotationGridFile)) {
    $cotationGrid = json_decode((string) file_get_contents($cotationGridFile), true);
    foreach ($cotationGrid[0]['option'][$riskType . '_variable'] ?? [] as $index => $variable) {
        if (!isset($criteriaColumns[$index]) || empty($variable['name'])) {
            continue;
        }
        // A level without threshold is one the criterion does not use
        $levels = [];
        foreach ($variable['option']['survey']['request'] ?? [] as $request) {
            if (isset($request['seuil']) && $request['seuil'] !== '') {
                $levels[(int) $request['seuil']] = (string) ($request['question'] ?? '');
            }
        }
        $cotationCriteria[$criteriaColumns[$index]] = [
            'label'    => 'Criterion' . $variable['name'],
            'levels'   => $levels,
            'maxLevel' => !empty($levels) ? max(array_keys($levels)) : 4,
        ];
    }
}

// Criteria values of the last assessment of a risk: only the advanced method rates criteria
$getRiskCriteria = function ($risk) use ($cotationCriteria) {
    $values = [];
    if ($risk->ra_method === 'advanced') {
        foreach ($cotationCriteria as $column => $criterion) {
            $value = $risk->{'ra_' . $column};
            if ($value !== null && $value !== '') {
                $values[$column] = (int) $value;
            }
        }
    }
    return $values;
};

// Validate category_id
if ($categoryId >= 0 && !isset($dangerCategories[$categoryId])) {
    $categoryId = -1;
}

// Build options list for Danger Category dropdown
$categoryOptions = [
    -1 => [
        'label'     => '-- ' . $langs->trans('AllCategories') . ' --',
        'data-html' => '-- ' . $langs->trans('AllCategories') . ' --',
    ],
];
$sortedCategories = $dangerCategories;
uasort($sortedCategories, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});
foreach ($sortedCategories as $cat) {
    $pos = (int) $cat['position'];
    $pictoImg = '';
    if (!empty($cat['thumbnail_name'])) {
        $pictoPath = '/custom/digiriskdolibarr/img/categorieDangers/' . $cat['thumbnail_name'] . '.png';
        $pictoImg = '<img src="' . dol_buildpath($pictoPath, 1) . '" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 6px;">';
    }
    $categoryOptions[$pos] = [
        'label'     => $cat['name'],
        'data-html' => $pictoImg . dol_escape_htmltag($cat['name']),
    ];
}

// Fetch Groupments & WorkUnits hierarchy for accessible entities
$elements         = [];
$elementsByParent = [];
$groupments       = [];

$sqlElements  = "SELECT rowid, ref, label, fk_parent, element_type, entity";
$sqlElements .= " FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_digiriskelement";
$sqlElements .= " WHERE status = 1";
if ($filterEntity > 0) {
    $sqlElements .= " AND entity = " . (int) $filterEntity;
} else {
    $sqlElements .= " AND entity IN (" . implode(',', array_map('intval', array_keys($entities))) . ")";
}
$sqlElements .= " ORDER BY entity ASC, fk_parent ASC, label ASC";

$resElements = $db->query($sqlElements);
if ($resElements) {
    while ($elem = $db->fetch_object($resElements)) {
        $elemId = (int) $elem->rowid;
        $elements[$elemId] = $elem;
        $elementsByParent[(int) $elem->fk_parent][] = $elemId;
        if ($elem->element_type === 'groupment') {
            $groupments[$elemId] = $elem;
        }
    }
    $db->free($resElements);
}

// Putting a groupment in the bin only marks that groupment: its units keep their own status. An
// element is only shown when its whole chain of parents is active, the way the Digirisk tree does,
// so walk down from the roots and leave out what hangs under a binned or archived element
$reachableElements = [];
$queue             = $elementsByParent[0] ?? [];
while (!empty($queue)) {
    $curr = (int) array_shift($queue);
    if (isset($reachableElements[$curr])) {
        continue;
    }
    $reachableElements[$curr] = true;
    foreach ($elementsByParent[$curr] ?? [] as $childId) {
        $queue[] = (int) $childId;
    }
}

$hiddenElementIds = array_keys(array_diff_key($elements, $reachableElements));
foreach ($hiddenElementIds as $hiddenElementId) {
    unset($elements[$hiddenElementId], $groupments[$hiddenElementId], $elementsByParent[$hiddenElementId]);
}

// If an element was selected but belongs to another entity when an entity filter is set, reset it
if ($filterEntity > 0 && $elementId > 0 && isset($elements[$elementId]) && (int) $elements[$elementId]->entity !== $filterEntity) {
    $elementId = 0;
}

// Resolve descendant elements when an element (groupment or workunit) is selected
$filterElementIds = [];
if ($elementId > 0 && isset($elements[$elementId])) {
    $queue = [$elementId];
    while (!empty($queue)) {
        $curr = array_shift($queue);
        $filterElementIds[] = (int) $curr;
        if (!empty($elementsByParent[$curr])) {
            foreach ($elementsByParent[$curr] as $childId) {
                $queue[] = (int) $childId;
            }
        }
    }
}

// Build options list for Groupment & Unit dropdown
$elementOptions = [0 => '-- ' . $langs->trans('AllGroupmentsAndUnits') . ' --'];
$showEntityPrefix = ($filterEntity <= 0 && count($entities) > 1);

foreach ($groupments as $gpId => $gp) {
    $prefix = ($showEntityPrefix && isset($entities[(int) $gp->entity])) ? '[' . $entities[(int) $gp->entity] . '] ' : '';
    $elementOptions[$gpId] = $prefix . '📁 ' . $gp->ref . ' - ' . $gp->label;

    if (!empty($elementsByParent[$gpId])) {
        foreach ($elementsByParent[$gpId] as $childId) {
            if (isset($elements[$childId])) {
                $child = $elements[$childId];
                $elementOptions[$childId] = $prefix . '&nbsp;&nbsp;&nbsp;&nbsp;↳ ⚙ ' . $child->ref . ' - ' . $child->label;
            }
        }
    }
}
// Any standalone elements (not under a groupment)
foreach ($elements as $eId => $e) {
    if (!isset($elementOptions[$eId])) {
        $prefix = ($showEntityPrefix && isset($entities[(int) $e->entity])) ? '[' . $entities[(int) $e->entity] . '] ' : '';
        $icon = ($e->element_type === 'groupment') ? '📁 ' : '⚙ ';
        $elementOptions[$eId] = $prefix . $icon . $e->ref . ' - ' . $e->label;
    }
}

// Build Text Search Filter Clause
$searchWhere = '';
if (!empty($search)) {
    $esc = $db->escape($search);

    // Match danger category names from JSON
    $matchingCatPositions = [];
    foreach ($dangerCategories as $pos => $cat) {
        if (stripos($cat['name'], $search) !== false) {
            $matchingCatPositions[] = (int) $pos;
        }
    }
    $catClause = !empty($matchingCatPositions) ? " OR r.category IN (" . implode(',', $matchingCatPositions) . ")" : "";

    // Match accessible entities / establishments names
    $matchingEntityIds = [];
    foreach ($entities as $eId => $eLabel) {
        if (stripos($eLabel, $search) !== false) {
            $matchingEntityIds[] = (int) $eId;
        }
    }
    $entityClause = !empty($matchingEntityIds) ? " OR r.entity IN (" . implode(',', $matchingEntityIds) . ")" : "";

    $searchWhere = " AND (r.ref LIKE '%" . $esc . "%'
        OR r.description LIKE '%" . $esc . "%'
        OR e.label LIKE '%" . $esc . "%'
        OR e.ref LIKE '%" . $esc . "%'
        OR p.label LIKE '%" . $esc . "%'
        OR ra.comment LIKE '%" . $esc . "%'
        OR u.firstname LIKE '%" . $esc . "%'
        OR u.lastname LIKE '%" . $esc . "%'
        OR CONCAT(u.firstname, ' ', u.lastname) LIKE '%" . $esc . "%'" . $catClause . $entityClause . "
        OR EXISTS (
            SELECT 1 FROM " . MAIN_DB_PREFIX . "projet_task_extrafields te
            INNER JOIN " . MAIN_DB_PREFIX . "projet_task t ON t.rowid = te.fk_object
            WHERE te.fk_risk = r.rowid AND (t.ref LIKE '%" . $esc . "%' OR t.label LIKE '%" . $esc . "%')
        )
    )";
}

// Calculate Cotation Level Counts (for the interactive Notion-style metrics pills)
$counts = [
    'all'          => 0,
    'low'          => 0,
    'plan'         => 0,
    'treat'        => 0,
    'unacceptable' => 0,
    'none'         => 0,
];

$sqlCounts  = "SELECT";
$sqlCounts .= " CASE";
$sqlCounts .= "   WHEN ra.cotation IS NULL THEN 'none'";
$sqlCounts .= "   WHEN ra.cotation < 48 THEN 'low'";
$sqlCounts .= "   WHEN ra.cotation < 51 THEN 'plan'";
$sqlCounts .= "   WHEN ra.cotation < 80 THEN 'treat'";
$sqlCounts .= "   ELSE 'unacceptable'";
$sqlCounts .= " END as cotation_level,";
$sqlCounts .= " COUNT(r.rowid) as cnt";
$sqlCounts .= " FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_risk r";
$sqlCounts .= " INNER JOIN " . MAIN_DB_PREFIX . "digiriskdolibarr_digiriskelement e ON e.rowid = r.fk_element";
$sqlCounts .= " LEFT JOIN " . MAIN_DB_PREFIX . "digiriskdolibarr_digiriskelement p ON p.rowid = e.fk_parent";
$sqlCounts .= " LEFT JOIN (";
$sqlCounts .= "   SELECT ra1.fk_risk, ra1.cotation, ra1.comment, ra1.fk_user_creat";
$sqlCounts .= "   FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_riskassessment ra1";
$sqlCounts .= "   INNER JOIN (";
$sqlCounts .= "     SELECT fk_risk, MAX(rowid) as max_id";
$sqlCounts .= "     FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_riskassessment";
$sqlCounts .= "     WHERE status = 1";
$sqlCounts .= "     GROUP BY fk_risk";
$sqlCounts .= "   ) ra2 ON ra1.rowid = ra2.max_id";
$sqlCounts .= " ) ra ON ra.fk_risk = r.rowid";
$sqlCounts .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = ra.fk_user_creat";
$sqlCounts .= " WHERE r.status <> -1 AND e.status = 1 AND r.type = '" . $db->escape($riskType) . "'";

if ($filterEntity > 0) {
    $sqlCounts .= " AND r.entity = " . (int) $filterEntity;
} else {
    $sqlCounts .= " AND r.entity IN (" . implode(',', array_map('intval', array_keys($entities))) . ")";
}

if (!empty($filterElementIds)) {
    $sqlCounts .= " AND r.fk_element IN (" . implode(',', $filterElementIds) . ")";
}
if (!empty($hiddenElementIds)) {
    $sqlCounts .= " AND r.fk_element NOT IN (" . implode(',', array_map('intval', $hiddenElementIds)) . ")";
}
if ($categoryId >= 0) {
    $sqlCounts .= " AND r.category = " . (int) $categoryId;
}
if (!empty($searchWhere)) {
    $sqlCounts .= $searchWhere;
}
$sqlCounts .= " GROUP BY cotation_level";

$resCounts = $db->query($sqlCounts);
if ($resCounts) {
    while ($row = $db->fetch_object($resCounts)) {
        $lvl = (string) $row->cotation_level;
        $cnt = (int) $row->cnt;
        if (isset($counts[$lvl])) {
            $counts[$lvl] = $cnt;
        }
        $counts['all'] += $cnt;
    }
    $db->free($resCounts);
}

// Build Main Risks Query
$sql  = "SELECT r.rowid, r.ref, r.description, r.category, r.status, r.type, r.entity,";
$sql .= " e.rowid as element_id, e.ref as element_ref, e.label as element_label, e.element_type,";
$sql .= " p.rowid as parent_id, p.ref as parent_ref, p.label as parent_label,";
$sql .= " ra.rowid as ra_id, ra.ref as ra_ref, ra.cotation, ra.date_riskassessment, ra.comment as ra_comment,";
$sql .= " ra.method as ra_method, ra.gravite as ra_gravite, ra.exposition as ra_exposition, ra.occurrence as ra_occurrence, ra.formation as ra_formation, ra.protection as ra_protection,";
$sql .= " u.rowid as ra_user_id, u.firstname as ra_user_firstname, u.lastname as ra_user_lastname";
$sql .= " FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_risk r";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "digiriskdolibarr_digiriskelement e ON e.rowid = r.fk_element";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "digiriskdolibarr_digiriskelement p ON p.rowid = e.fk_parent";
$sql .= " LEFT JOIN (";
$sql .= "   SELECT ra1.rowid, ra1.fk_risk, ra1.ref, ra1.cotation, ra1.date_riskassessment, ra1.comment, ra1.fk_user_creat,";
$sql .= "   ra1.method, ra1.gravite, ra1.exposition, ra1.occurrence, ra1.formation, ra1.protection";
$sql .= "   FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_riskassessment ra1";
$sql .= "   INNER JOIN (";
$sql .= "     SELECT fk_risk, MAX(rowid) as max_id";
$sql .= "     FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_riskassessment";
$sql .= "     WHERE status = 1";
$sql .= "     GROUP BY fk_risk";
$sql .= "   ) ra2 ON ra1.rowid = ra2.max_id";
$sql .= " ) ra ON ra.fk_risk = r.rowid";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = ra.fk_user_creat";
$sql .= " WHERE r.status <> -1 AND e.status = 1 AND r.type = '" . $db->escape($riskType) . "'";

if ($filterEntity > 0) {
    $sql .= " AND r.entity = " . (int) $filterEntity;
} else {
    $sql .= " AND r.entity IN (" . implode(',', array_map('intval', array_keys($entities))) . ")";
}

if (!empty($filterElementIds)) {
    $sql .= " AND r.fk_element IN (" . implode(',', $filterElementIds) . ")";
}
if (!empty($hiddenElementIds)) {
    $sql .= " AND r.fk_element NOT IN (" . implode(',', array_map('intval', $hiddenElementIds)) . ")";
}

if ($categoryId >= 0) {
    $sql .= " AND r.category = " . (int) $categoryId;
}

// Filter by Cotation Level
if ($cotationFilter === 'low') {
    $sql .= " AND ra.cotation IS NOT NULL AND ra.cotation < 48";
} elseif ($cotationFilter === 'plan') {
    $sql .= " AND ra.cotation >= 48 AND ra.cotation < 51";
} elseif ($cotationFilter === 'treat') {
    $sql .= " AND ra.cotation >= 51 AND ra.cotation < 80";
} elseif ($cotationFilter === 'unacceptable') {
    $sql .= " AND ra.cotation >= 80";
} elseif ($cotationFilter === 'none') {
    $sql .= " AND ra.cotation IS NULL";
}

// Text Search Filter
if (!empty($searchWhere)) {
    $sql .= $searchWhere;
}

// Sorting Clause
$orderClause = " ORDER BY ";
if ($sortfield === 'ref') {
    $orderClause .= "r.ref " . $sortorder;
} elseif ($sortfield === 'category') {
    $orderClause .= "r.category " . $sortorder;
} elseif ($sortfield === 'description') {
    $orderClause .= "r.description " . $sortorder;
} elseif ($sortfield === 'entity') {
    $orderClause .= "r.entity " . $sortorder;
} elseif ($sortfield === 'element') {
    $orderClause .= "e.label " . $sortorder;
} elseif ($sortfield === 'date') {
    $orderClause .= "ra.date_riskassessment " . $sortorder;
} else {
    // Default: cotation DESC, then ref ASC
    $orderClause .= "CASE WHEN ra.cotation IS NULL THEN -1 ELSE ra.cotation END " . $sortorder . ", r.ref ASC";
}

// Fetch the action plan tasks of the given risks in batch, grouped by risk
$fetchTasksByRisk = function (array $riskIds) use ($db) {
    $tasksByRisk = [];
    foreach (array_chunk($riskIds, 500) as $chunkIds) {
        $sqlTasks  = "SELECT t.rowid, t.ref, t.label, t.progress, t.dateo, t.datee, t.planned_workload, t.budget_amount, te.fk_risk,";
        $sqlTasks .= " u.rowid as user_id, u.firstname, u.lastname";
        $sqlTasks .= " FROM " . MAIN_DB_PREFIX . "projet_task t";
        $sqlTasks .= " JOIN " . MAIN_DB_PREFIX . "projet_task_extrafields te ON te.fk_object = t.rowid";
        $sqlTasks .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = t.fk_user_creat";
        $sqlTasks .= " WHERE te.fk_risk IN (" . implode(',', array_map('intval', $chunkIds)) . ")";
        $sqlTasks .= " ORDER BY t.rowid ASC";

        $resTasks = $db->query($sqlTasks);
        if ($resTasks) {
            while ($t = $db->fetch_object($resTasks)) {
                $tasksByRisk[(int) $t->fk_risk][] = $t;
            }
            $db->free($resTasks);
        }
    }
    return $tasksByRisk;
};

// Fetch the protection means of the given groupments and work units in batch, grouped by element
$fetchProtectionsByElement = function (array $elementIds) use ($db, $protectionCategories) {
    $protectionsByElement = [];
    if (empty($protectionCategories)) {
        return $protectionsByElement;
    }
    foreach (array_chunk(array_unique($elementIds), 500) as $chunkIds) {
        $sqlSigns  = "SELECT rs.fk_element, rs.category, rs.description";
        $sqlSigns .= " FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_risksign rs";
        $sqlSigns .= " WHERE rs.status = 1 AND rs.fk_element IN (" . implode(',', array_map('intval', $chunkIds)) . ")";
        $sqlSigns .= " AND rs.category IN (" . implode(',', array_keys($protectionCategories)) . ")";
        $sqlSigns .= " ORDER BY rs.category ASC, rs.rowid ASC";

        $resSigns = $db->query($sqlSigns);
        if ($resSigns) {
            while ($sign = $db->fetch_object($resSigns)) {
                $protectionsByElement[(int) $sign->fk_element][] = $sign;
            }
            $db->free($resSigns);
        }
    }
    return $protectionsByElement;
};

// Label of a protection mean on a single line: the name of its sign, then what the risk sign says about it
$getProtectionLabel = function ($protection) use ($protectionCategories) {
    $description = trim((string) preg_replace('/\s*\R\s*/', ' ', (string) $protection->description));
    return $protectionCategories[(int) $protection->category]['name'] . ($description !== '' ? ' : ' . $description : '');
};

// Exports of the whole filtered list (no pagination), in the order of the list
if ($action == 'export_csv' || $action == 'export_xlsx') {
    $resExport = $db->query($sql . $orderClause);
    if (!$resExport) {
        dol_print_error($db);
        exit;
    }
    $exportRisks = [];
    while ($obj = $db->fetch_object($resExport)) {
        $exportRisks[(int) $obj->rowid] = $obj;
    }
    $db->free($resExport);
    $exportTasks       = $fetchTasksByRisk(array_keys($exportRisks));
    $exportProtections = $fetchProtectionsByElement(array_map(function ($risk) {
        return (int) $risk->element_id;
    }, array_values($exportRisks)));

    // Excel export: the same risks under several views, one sheet each
    if ($action == 'export_xlsx') {
        require_once __DIR__ . '/../class/digiboardrisklistexport.class.php';

        $export                       = new DigiboardRiskListExport($db);
        $export->riskType             = $riskType;
        $export->entities             = $entities;
        $export->dangerCategories     = $dangerCategories;
        $export->protectionCategories = $protectionCategories;
        $export->cotationCriteria     = $cotationCriteria;
        $export->cotationLevelMax     = $cotationLevelMax;
        $export->riskLevelLabels      = $riskLevelLabels;
        $export->filters              = [
            $langs->transnoentities('RiskLevel')                => $langs->transnoentities($riskLevelLabels[$cotationFilter] ?? 'AllRisks'),
            $langs->transnoentities('FilterByEstablishment')    => $filterEntity > 0 ? $entities[$filterEntity] : $langs->transnoentities('AllEstablishments'),
            $langs->transnoentities('FilterByGroupmentAndUnit') => isset($elements[$elementId]) ? $elements[$elementId]->ref . ' - ' . $elements[$elementId]->label : $langs->transnoentities('AllGroupmentsAndUnits'),
            $langs->transnoentities('FilterByCategory')         => $categoryId >= 0 ? $dangerCategories[$categoryId]['name'] : $langs->transnoentities('AllCategories'),
            $langs->transnoentities('RiskXlsxSearch')           => $search,
        ];

        $fileName = str_replace(' ', '_', dol_sanitizeFileName($langs->transnoentities($riskType === 'riskenvironmental' ? 'RiskTypeEnvironmental' : 'RiskTypeProfessional')));
        $export->send($exportRisks, $exportTasks, $exportProtections, $fileName . '_' . dol_print_date(dol_now(), 'dayhourlog'));
        $db->close();
        exit;
    }

    // CSV export, one line per risk carrying its whole action plan.
    // Every cell is enclosed, and a text starting like a formula is neutralized for spreadsheet software
    $separator = getDolGlobalString('EXPORT_CSV_SEPARATOR_TO_USE', ';');
    $csvLine   = function (array $cells) use ($separator) {
        foreach ($cells as $key => $cell) {
            $cell = (string) $cell;
            if (preg_match('/^[=+\-@\t\r]/', $cell)) {
                $cell = "'" . $cell;
            }
            $cells[$key] = '"' . str_replace('"', '""', $cell) . '"';
        }
        return implode($separator, $cells) . "\r\n";
    };

    $csvHeader = [
        $langs->transnoentities('Establishment'),
        $langs->transnoentities('Groupment'),
        $langs->transnoentities('WorkUnit'),
        $langs->transnoentities('RiskRef'),
        $langs->transnoentities('DangerCategory'),
        $langs->transnoentities('Description'),
        $langs->transnoentities('RiskCotation'),
        $langs->transnoentities('RiskLevel'),
    ];
    foreach ($cotationCriteria as $criterion) {
        $csvHeader[] = $langs->transnoentities($criterion['label']);
    }
    $csvHeader[] = $langs->transnoentities('AssessmentDate');
    $csvHeader[] = $langs->transnoentities('Evaluator');
    $csvHeader[] = $langs->transnoentities('AssessmentComment');
    $csvHeader[] = $langs->transnoentities('ProtectionMeans');
    $csvHeader[] = $langs->transnoentities('ActionsCount');
    $csvHeader[] = $langs->transnoentities('ActionsCompletedCount');
    $csvHeader[] = $langs->transnoentities('ActionsAndTasks');
    $csv = $csvLine($csvHeader);

    $now = dol_now();
    foreach ($exportRisks as $riskId => $r) {
        $cotationVal = isset($r->cotation) ? (int) $r->cotation : null;
        // A risk sits either on a work unit (under its groupment) or directly on a groupment
        $isWorkUnit = $r->element_type === 'workunit';
        $cells      = [
            $entities[(int) $r->entity] ?? '',
            $isWorkUnit ? (!empty($r->parent_id) ? $r->parent_ref . ' - ' . $r->parent_label : '') : $r->element_ref . ' - ' . $r->element_label,
            $isWorkUnit ? $r->element_ref . ' - ' . $r->element_label : '',
            $r->ref,
            $dangerCategories[(int) $r->category]['name'] ?? '',
            $r->description,
            $cotationVal ?? '',
            $langs->transnoentities($riskLevelLabels[$getRiskLevel($cotationVal)]),
        ];
        $riskCriteria = $getRiskCriteria($r);
        foreach ($cotationCriteria as $column => $criterion) {
            $cells[] = $riskCriteria[$column] ?? '';
        }
        $cells[] = !empty($r->date_riskassessment) ? dol_print_date($db->jdate($r->date_riskassessment), 'day') : '';
        $cells[] = trim($r->ra_user_firstname . ' ' . $r->ra_user_lastname);
        $cells[] = $r->ra_comment;

        // Protection means and action plan each fit in one cell, one item per line
        $protectionLines = [];
        foreach ($exportProtections[(int) $r->element_id] ?? [] as $protection) {
            $protectionLines[] = $getProtectionLabel($protection);
        }
        $cells[] = implode("\n", $protectionLines);

        $riskTasks = $exportTasks[$riskId] ?? [];
        $nbDone    = 0;
        $taskLines = [];
        foreach ($riskTasks as $tk) {
            $taskProgress = (int) $tk->progress;
            $dueDate      = !empty($tk->datee) ? $db->jdate($tk->datee) : '';
            if ($taskProgress >= 100) {
                $nbDone++;
            }
            $details = [$taskProgress . ' %'];
            if (!empty($dueDate)) {
                $details[] = $langs->transnoentities('DateEnd') . ' ' . dol_print_date($dueDate, 'day') . ($taskProgress < 100 && $dueDate < $now ? ' - ' . $langs->transnoentities('Overdue') : '');
            }
            if (!empty($tk->firstname) || !empty($tk->lastname)) {
                $details[] = trim($tk->firstname . ' ' . $tk->lastname);
            }
            $taskLines[] = $tk->ref . ' - ' . $tk->label . ' (' . implode(', ', $details) . ')';
        }
        $cells[] = count($riskTasks);
        $cells[] = $nbDone;
        $cells[] = implode("\n", $taskLines);

        $csv .= $csvLine($cells);
    }

    $fileName = str_replace(' ', '_', dol_sanitizeFileName($langs->transnoentities($riskType === 'riskenvironmental' ? 'RiskTypeEnvironmental' : 'RiskTypeProfessional')));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '_' . dol_print_date($now, 'dayhourlog') . '.csv"');
    // UTF-8 BOM, so that spreadsheet software reads the accents
    print "\xEF\xBB\xBF" . $csv;
    $db->close();
    exit;
}

// Count Total Matching Rows
$posFrom   = strpos($sql, ' FROM ');
$sqlTotal  = "SELECT COUNT(r.rowid) as total" . ($posFrom !== false ? substr($sql, $posFrom) : '');
$resTotal  = $db->query($sqlTotal);
$totalRows = 0;
if ($resTotal) {
    $totalRows = (int) $db->fetch_object($resTotal)->total;
    $db->free($resTotal);
}

// Paginate and Fetch
$sql .= $orderClause . $db->plimit($limit, $page * $limit);
$res = $db->query($sql);

$risks   = [];
$riskIds = [];
if ($res) {
    while ($obj = $db->fetch_object($res)) {
        $risks[]   = $obj;
        $riskIds[] = (int) $obj->rowid;
    }
    $db->free($res);
}

// Fetch Tasks for the retrieved risks in batch
$tasksByRisk = $fetchTasksByRisk($riskIds);

// Fetch Protection Means of the elements of the retrieved risks
$protectionsByElement = $fetchProtectionsByElement(array_map(function ($risk) {
    return (int) $risk->element_id;
}, $risks));

saturne_header(0, '', $title, $helpUrl);

// Notion-Style CSS
print '<style>
.notion-wrapper {
    margin-top: 15px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #37352f;
}
.notion-header-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 12px;
}
.notion-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    color: #6b7280;
    text-decoration: none;
    transition: all 0.15s ease;
}
.notion-tab-link:hover {
    background-color: #f3f4f6;
    color: #111827;
    text-decoration: none;
}
.notion-tab-link.active {
    background-color: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
}
.notion-metrics-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
    align-items: center;
}
.notion-metric-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    cursor: pointer;
}
.notion-metric-pill:hover {
    filter: brightness(0.95);
    text-decoration: none;
}
.notion-metric-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 700;
}

/* Metric Colors */
.metric-all, .metric-all:link, .metric-all:visited { background-color: #f3f4f6; color: #374151 !important; }
.metric-all:hover { background-color: #e5e7eb; color: #111827 !important; text-decoration: none; }
.metric-all .notion-metric-count { background: #3b82f6; color: #ffffff !important; }
.metric-all.active { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25); }

.metric-low, .metric-low:link, .metric-low:visited { background-color: #f1f5f9; color: #4b5563 !important; }
.metric-low:hover { background-color: #e2e8f0; color: #1e293b !important; text-decoration: none; }
.metric-low .notion-metric-count { background: #64748b; color: #ffffff !important; }
.metric-low.active { border-color: #64748b; box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.25); }

.metric-plan, .metric-plan:link, .metric-plan:visited { background-color: #fffbeb; color: #92400e !important; }
.metric-plan:hover { background-color: #fef3c7; color: #78350f !important; text-decoration: none; }
.metric-plan .notion-metric-count { background: #f59e0b; color: #ffffff !important; }
.metric-plan.active { border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.25); }

.metric-treat, .metric-treat:link, .metric-treat:visited { background-color: #fef2f2; color: #991b1b !important; }
.metric-treat:hover { background-color: #fee2e2; color: #7f1d1d !important; text-decoration: none; }
.metric-treat .notion-metric-count { background: #ef4444; color: #ffffff !important; }
.metric-treat.active { border-color: #ef4444; box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25); }

.metric-unacceptable, .metric-unacceptable:link, .metric-unacceptable:visited { background-color: #111827; color: #ffffff !important; }
.metric-unacceptable:hover { background-color: #1f2937; color: #ffffff !important; text-decoration: none; }
.metric-unacceptable .notion-metric-count { background: #ffffff; color: #111827 !important; }
.metric-unacceptable.active { border-color: #ffffff; box-shadow: 0 0 0 2px rgba(17, 24, 39, 0.4); }

.metric-none, .metric-none:link, .metric-none:visited { background-color: #f8fafc; color: #64748b !important; border: 1px dashed #cbd5e1; }
.metric-none:hover { background-color: #f1f5f9; color: #334155 !important; text-decoration: none; }
.metric-none .notion-metric-count { background: #94a3b8; color: #ffffff !important; }
.metric-none.active { border-color: #64748b; box-shadow: 0 0 0 2px rgba(100, 116, 139, 0.25); }

.notion-filter-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.notion-search-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 220px;
}
.notion-search-group input[type="text"] {
    flex: 1;
    min-width: 120px;
}
.notion-search-group .button {
    margin: 0 !important;
    white-space: nowrap;
}
.notion-btn-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    min-width: 30px !important;
    max-width: 30px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 4px;
    background-color: #f1f5f9;
    color: #64748b;
    border: 1px solid #cbd5e1;
    font-size: 13px;
    line-height: 1;
    text-decoration: none !important;
    transition: all 0.15s ease;
    cursor: pointer;
    box-sizing: border-box;
    flex-shrink: 0;
}
.notion-btn-reset:hover {
    background-color: #fee2e2;
    color: #dc2626;
    border-color: #fca5a5;
    text-decoration: none !important;
}
.notion-table-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}
.notion-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.notion-table th {
    background: #f9fafb;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    color: #4b5563;
    border-bottom: 1px solid #e5e7eb;
    letter-spacing: 0.02em;
}
.notion-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: top;
}
.notion-table tr:last-child td {
    border-bottom: none;
}
.notion-table tr:hover td {
    background-color: #fbfcfd;
}

/* Notion Tag & Pill Badges */
.notion-badge-ref {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 700;
    font-size: 13px;
    color: #1e40af;
    text-decoration: none;
}
.notion-badge-ref:hover {
    text-decoration: underline;
}
.notion-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.notion-tag-establishment {
    background-color: #f1f5f9;
    color: #1e293b;
    border: 1px solid #cbd5e1;
}
.notion-tag-groupment {
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}
.notion-tag-workunit {
    background-color: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.notion-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 600;
}
.status-low { background: #f1f5f9; color: #4b5563; border: 1px solid #cbd5e1; }
.status-plan { background: #fef3c7; color: #92400e; }
.status-treat { background: #fee2e2; color: #991b1b; }
.status-unacceptable { background: #111827; color: #ffffff !important; }
.status-none { background: #f1f5f9; color: #64748b; }

/* Advanced Cotation Criteria */
.notion-criteria {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 6px;
    font-size: 11px;
    color: #4b5563;
}
.notion-criterion {
    display: flex;
    align-items: center;
    gap: 6px;
}
.notion-criterion-label {
    min-width: 74px;
}
.notion-criterion-gauge {
    display: inline-flex;
    gap: 2px;
}
.notion-criterion-gauge span {
    width: 10px;
    height: 6px;
    border-radius: 2px;
    background-color: #e5e7eb;
}
.notion-criterion-gauge span.on {
    background-color: #64748b;
}
.notion-criterion-value {
    font-weight: 700;
    color: #1f2937;
}

/* Checklist Task Styling */
/* Protection Means */
.notion-protection-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    max-width: 128px;
}
.notion-protection-picto {
    width: 28px;
    height: 28px;
    border-radius: 4px;
    object-fit: contain;
}
.notion-empty-text {
    font-size: 12px;
    color: #9ca3af;
    font-style: italic;
}

.notion-task-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 6px;
}
.notion-task-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #f9fafb;
    padding: 6px 10px;
    border-radius: 6px;
    border: 1px solid #f3f4f6;
    font-size: 12px;
}
.notion-task-check {
    margin-top: 2px;
    font-size: 14px;
    min-width: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.notion-task-check.completed { color: #10b981; }
.notion-task-check.pending { color: #9ca3af; }
.notion-task-pct {
    font-size: 10px;
    font-weight: 700;
    color: #1d4ed8;
    background-color: #dbeafe;
    border: 1px solid #bfdbfe;
    padding: 1px 5px;
    border-radius: 9999px;
    line-height: 12px;
    white-space: nowrap;
}
.notion-task-content {
    flex: 1;
}
.notion-task-title {
    font-weight: 500;
    color: #1f2937;
}
.notion-task-meta {
    font-size: 11px;
    color: #6b7280;
    display: flex;
    gap: 8px;
    margin-top: 2px;
}
.notion-task-overdue {
    color: #dc2626;
    font-weight: 600;
}
.notion-progress-mini {
    width: 60px;
    height: 6px;
    background: #e5e7eb;
    border-radius: 9999px;
    overflow: hidden;
    display: inline-block;
    vertical-align: middle;
}
.notion-progress-bar {
    height: 100%;
    background: #10b981;
}
.notion-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    background-color: #f3f4f6;
    color: #374151;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease;
}
.notion-action-btn:hover {
    background-color: #e5e7eb;
    color: #111827;
    text-decoration: none;
}
</style>';

print '<div class="notion-wrapper">';

// Header: Risk Type Tabs (Professionnels / Environnementaux)
print '<div class="notion-header-tabs">';
$urlRiskTypeProf = $_SERVER['PHP_SELF'] . '?risk_type=risk' . ($filterEntity > 0 ? '&filter_entity=' . $filterEntity : '') . ($elementId > 0 ? '&element_id=' . $elementId : '') . (!empty($search) ? '&search=' . urlencode($search) : '');
$urlRiskTypeEnv  = $_SERVER['PHP_SELF'] . '?risk_type=riskenvironmental' . ($filterEntity > 0 ? '&filter_entity=' . $filterEntity : '') . ($elementId > 0 ? '&element_id=' . $elementId : '') . (!empty($search) ? '&search=' . urlencode($search) : '');
print '<a href="' . $urlRiskTypeProf . '" class="notion-tab-link' . ($riskType === 'risk' ? ' active' : '') . '">';
print '<i class="fas fa-user-shield"></i> ' . $langs->trans('RiskTypeProfessional') . '</a>';
print '<a href="' . $urlRiskTypeEnv . '" class="notion-tab-link' . ($riskType === 'riskenvironmental' ? ' active' : '') . '">';
print '<i class="fas fa-leaf"></i> ' . $langs->trans('RiskTypeEnvironmental') . '</a>';
print '</div>';

// Top Metrics Bar (Interactive Notion Pills)
$baseUrl = $_SERVER['PHP_SELF'] . '?risk_type=' . urlencode($riskType) . ($filterEntity > 0 ? '&filter_entity=' . $filterEntity : '') . ($elementId > 0 ? '&element_id=' . $elementId : '') . ($categoryId >= 0 ? '&category_id=' . $categoryId : '') . (!empty($search) ? '&search=' . urlencode($search) : '');

print '<div class="notion-metrics-bar">';

// All
print '<a href="' . $baseUrl . '&cotation_filter=all" class="notion-metric-pill metric-all' . ($cotationFilter === 'all' ? ' active' : '') . '">';
print '<span class="notion-metric-count">' . $counts['all'] . '</span> ' . $langs->trans('AllRisks') . '</a>';

// The four cotation levels
foreach (array_keys($cotationLevelMax) as $level) {
    print '<a href="' . $baseUrl . '&cotation_filter=' . $level . '" class="notion-metric-pill metric-' . $level . ($cotationFilter === $level ? ' active' : '') . '">';
    print '<span class="notion-metric-count">' . $counts[$level] . '</span> ' . $langs->trans($riskLevelLabels[$level]) . '</a>';
}

// None (Unassessed) - Only shown if there are unassessed risks or currently filtered on it
if ($counts['none'] > 0 || $cotationFilter === 'none') {
    print '<a href="' . $baseUrl . '&cotation_filter=none" class="notion-metric-pill metric-none' . ($cotationFilter === 'none' ? ' active' : '') . '">';
    print '<span class="notion-metric-count">' . $counts['none'] . '</span> ' . $langs->trans('RiskNotAssessed') . '</a>';
}

print '</div>';

// Filters Card (Establishment, GP/UT, Category & Search)
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '" class="notion-filter-card">';
print '<input type="hidden" name="risk_type" value="' . dol_escape_htmltag($riskType) . '">';
print '<input type="hidden" name="cotation_filter" value="' . dol_escape_htmltag($cotationFilter) . '">';

// Establishment (Company/Entity) Selector
if (count($entities) > 1) {
    print '<div style="display: inline-flex; align-items: center; gap: 8px;">';
    print '<span class="opacitymedium" title="' . dol_escape_htmltag($langs->trans('FilterByEstablishment')) . '"><i class="fas fa-building pictofixedwidth"></i></span>';
    $estabOptions = [0 => '-- ' . $langs->trans('AllEstablishments') . ' --'];
    foreach ($entities as $eid => $elabel) {
        $estabOptions[$eid] = $elabel;
    }
    print Form::selectarray('filter_entity', $estabOptions, $filterEntity, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1);
    print '</div>';
}

// Groupment & WorkUnit (GP/UT) Selector
print '<div style="display: inline-flex; align-items: center; gap: 8px;">';
print '<span class="opacitymedium" title="' . dol_escape_htmltag($langs->trans('FilterByGroupmentAndUnit')) . '"><i class="fas fa-sitemap pictofixedwidth"></i></span>';
print Form::selectarray('element_id', $elementOptions, $elementId, 0, 0, 0, '', 0, 0, 0, '', 'minwidth250', 1);
print '</div>';

// Category Selector
print '<div style="display: inline-flex; align-items: center; gap: 8px;">';
print '<span class="opacitymedium" title="' . dol_escape_htmltag($langs->trans('FilterByCategory')) . '"><i class="fas fa-exclamation-triangle pictofixedwidth"></i></span>';
print Form::selectarray('category_id', $categoryOptions, $categoryId, 0, 0, 0, '', 0, 0, 0, '', 'minwidth220', 1);
print '<script nonce="' . getNonce() . '">
$(document).ready(function() {
    if ($.fn.select2 && $("#category_id").data("select2")) {
        $("#category_id").select2({
            templateResult: function(data, container) {
                if (data.element && $(data.element).attr("data-html") !== undefined && typeof htmlEntityDecodeJs === "function") {
                    return htmlEntityDecodeJs($(data.element).attr("data-html"));
                }
                return data.text;
            },
            templateSelection: function(selection) {
                if (selection.element && $(selection.element).attr("data-html") !== undefined && typeof htmlEntityDecodeJs === "function") {
                    return htmlEntityDecodeJs($(selection.element).attr("data-html"));
                }
                return selection.text;
            },
            escapeMarkup: function(m) { return m; },
            containerCssClass: ":all:",
            selectionCssClass: ":all:",
            dropdownCssClass: "ui-dialog"
        });
    }
});
</script>';
print '</div>';

// Search Input and Action Buttons
print '<div class="notion-search-group">';
print '<input type="text" name="search" class="centpercent" placeholder="' . $langs->trans('SearchRiskPlaceholder') . '" value="' . dol_escape_htmltag($search) . '">';
print '<input type="submit" class="button small" value="' . $langs->trans('Search') . '">';
if (!empty($search) || $filterEntity > 0 || $elementId > 0 || $categoryId >= 0 || $cotationFilter !== 'all') {
    $resetUrl = $_SERVER['PHP_SELF'] . '?risk_type=' . urlencode($riskType);
    print '<a href="' . $resetUrl . '" class="notion-btn-reset" title="' . dol_escape_htmltag($langs->trans('Reset')) . '"><i class="fas fa-times"></i></a>';
}
print '</div>';
print '</form>';

// Standard Dolibarr List Barre (Pagination & Counts)
$param = '&risk_type=' . urlencode($riskType) . '&cotation_filter=' . urlencode($cotationFilter) . ($filterEntity > 0 ? '&filter_entity=' . $filterEntity : '') . ($elementId > 0 ? '&element_id=' . $elementId : '') . ($categoryId >= 0 ? '&category_id=' . $categoryId : '') . (!empty($search) ? '&search=' . urlencode($search) : '');
$exportUrl      = $_SERVER['PHP_SELF'] . '?action=export_csv&token=' . newToken() . $param . '&sortfield=' . urlencode($sortfield) . '&sortorder=' . urlencode($sortorder);
$exportXlsxUrl  = $_SERVER['PHP_SELF'] . '?action=export_xlsx&token=' . newToken() . $param . '&sortfield=' . urlencode($sortfield) . '&sortorder=' . urlencode($sortorder);
$morehtmlright  = dolGetButtonTitle($langs->trans('ExportXLSX'), $langs->trans('ExportRiskListXLSXHelp'), 'fa fa-file-excel', $exportXlsxUrl);
$morehtmlright .= dolGetButtonTitle($langs->trans('ExportCSV'), $langs->trans('ExportRiskListCSVHelp'), 'fa fa-file-csv', $exportUrl);
print_barre_liste('', $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', count($risks), $totalRows, 'fa-exclamation-triangle', 0, $morehtmlright, '', $limit, 0, 0, 1);

// Notion Table
print '<div class="notion-table-card">';
print '<table class="notion-table">';

// Header Row
print '<thead><tr>';
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 'ref', '', $param, '', $sortfield, $sortorder, 'nowrap ');
print_liste_field_titre($langs->trans('Cat'), $_SERVER['PHP_SELF'], 'category', '', $param, '', $sortfield, $sortorder, 'center nowrap ');
print_liste_field_titre('Description', $_SERVER['PHP_SELF'], 'description', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Establishment'), $_SERVER['PHP_SELF'], 'entity', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('GroupmentAndUnit'), $_SERVER['PHP_SELF'], 'element', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('RiskLevel'), $_SERVER['PHP_SELF'], 'cotation', '', $param, '', $sortfield, $sortorder);
print '<th class="nowraponall"><i class="fas fa-hard-hat"></i> ' . $langs->trans('ProtectionMeans') . '</th>';
print '<th><i class="fas fa-tasks"></i> ' . $langs->trans('ActionsAndTasks') . '</th>';
print '<th class="center" style="width: 100px;">' . $langs->trans('Action') . '</th>';
print '</tr></thead>';

print '<tbody>';
if (empty($risks)) {
    print '<tr><td colspan="9" class="center opacitymedium paddingtopbottom">' . $langs->trans('NoRecordFound') . '</td></tr>';
} else {
    foreach ($risks as $r) {
        $riskId = (int) $r->rowid;
        $isOtherEntity = !empty($r->entity) && $r->entity != $conf->entity;
        $urlRiskCard   = $isOtherEntity ? $_SERVER['PHP_SELF'] . '?action=openrisk&token=' . newToken() . '&open_id=' . $riskId . '&open_entity=' . (int) $r->entity : dol_buildpath('/digiriskdolibarr/view/risk/risk_card.php?id=' . $riskId, 1);
        $riskEntityId   = (int) $r->entity;
        $riskEntityName = $entities[$riskEntityId] ?? ($riskEntityId > 0 ? $langs->trans('Entity') . ' ' . $riskEntityId : '');

        // Danger Category Picto
        $catPos = (int) $r->category;
        $catData = $dangerCategories[$catPos] ?? null;
        $catName = $catData['name'] ?? '';
        $pictoImg = '';
        if (!empty($catData['thumbnail_name'])) {
            $pictoPath = '/custom/digiriskdolibarr/img/categorieDangers/' . $catData['thumbnail_name'] . '.png';
            $pictoImg = '<img src="' . dol_buildpath($pictoPath, 1) . '" alt="' . dol_escape_htmltag($catName) . '" title="' . dol_escape_htmltag($catName) . '" style="width: 28px; height: 28px; vertical-align: middle;">';
        } else {
            $pictoImg = '<i class="fas fa-exclamation-triangle" title="' . dol_escape_htmltag($catName) . '" style="color: #f59e0b; font-size: 20px; vertical-align: middle;"></i>';
        }

        // Cotation Badge Info
        $cotationVal = isset($r->cotation) ? (int) $r->cotation : null;
        $riskLevel   = $getRiskLevel($cotationVal);
        $statusClass = 'status-' . $riskLevel;
        $statusLabel = $langs->trans($riskLevelLabels[$riskLevel]) . ($cotationVal !== null ? ' (' . $cotationVal . ')' : '');

        print '<tr>';

        // Column 1: Ref
        print '<td class="nowraponall" style="width: 80px;">';
        print '<a href="' . $urlRiskCard . '" class="notion-badge-ref" title="' . dol_escape_htmltag($langs->trans('ViewRiskCard')) . '">';
        print dol_escape_htmltag($r->ref);
        print '</a>';
        print '</td>';

        // Column 2: Danger Category Picto
        print '<td class="center" style="width: 50px;">';
        print $pictoImg;
        print '</td>';

        // Column 3: Description
        print '<td>';
        print '<div style="font-weight: 600; color: #1f2937; margin-bottom: 2px;">' . dol_escape_htmltag($r->description) . '</div>';
        if (!empty($catName)) {
            print '<div style="font-size: 11px; color: #9ca3af;">' . dol_escape_htmltag($catName) . '</div>';
        }
        print '</td>';

        // Column 3: Establishment (Company / Entity)
        print '<td style="min-width: 140px;">';
        print '<div class="notion-tag notion-tag-establishment">';
        print '<i class="fas fa-building pictofixedwidth"></i> ' . dol_escape_htmltag($riskEntityName);
        print '</div>';
        print '</td>';

        // Column 4: Groupment & WorkUnit (GP/UT)
        print '<td style="min-width: 180px;">';
        if (!empty($r->parent_label)) {
            print '<div class="notion-tag notion-tag-groupment" style="margin-bottom: 4px;" title="' . dol_escape_htmltag($langs->trans('Groupment')) . '">';
            print '<i class="fas fa-folder pictofixedwidth"></i> ' . dol_escape_htmltag($r->parent_label);
            print '</div><br>';
            print '<div class="notion-tag notion-tag-workunit" title="' . dol_escape_htmltag($langs->trans('WorkUnit')) . '">';
            print '<i class="fas fa-sitemap pictofixedwidth"></i> ' . dol_escape_htmltag($r->element_label);
            print '</div>';
        } elseif ($r->element_type === 'workunit') {
            print '<div class="notion-tag notion-tag-workunit" title="' . dol_escape_htmltag($langs->trans('WorkUnit')) . '">';
            print '<i class="fas fa-sitemap pictofixedwidth"></i> ' . dol_escape_htmltag($r->element_label);
            print '</div>';
        } else {
            print '<div class="notion-tag notion-tag-groupment" title="' . dol_escape_htmltag($langs->trans('Groupment')) . '">';
            print '<i class="fas fa-folder pictofixedwidth"></i> ' . dol_escape_htmltag($r->element_label);
            print '</div>';
        }
        print '</td>';

        // Column 5: Cotation & Last Assessment
        print '<td style="min-width: 180px;">';
        print '<div class="notion-status-pill ' . $statusClass . '">';
        print dol_escape_htmltag($statusLabel);
        print '</div>';
        $riskCriteria = $getRiskCriteria($r);
        if (!empty($riskCriteria)) {
            print '<div class="notion-criteria">';
            foreach ($riskCriteria as $column => $criterionVal) {
                $criterion = $cotationCriteria[$column];
                $levelText = $criterion['levels'][$criterionVal] ?? '';
                print '<div class="notion-criterion" title="' . dol_escape_htmltag($langs->transnoentities($criterion['label']) . ' : ' . $criterionVal . ($levelText !== '' ? ' - ' . $levelText : '')) . '">';
                print '<span class="notion-criterion-label">' . $langs->trans($criterion['label']) . '</span>';
                print '<span class="notion-criterion-gauge">';
                for ($step = 1; $step <= $criterion['maxLevel']; $step++) {
                    print '<span' . ($step <= $criterionVal ? ' class="on"' : '') . '></span>';
                }
                print '</span>';
                print '<span class="notion-criterion-value">' . $criterionVal . '</span>';
                print '</div>';
            }
            print '</div>';
        }
        if ($cotationVal !== null) {
            print '<div style="font-size: 11px; color: #6b7280; margin-top: 5px;">';
            if (!empty($r->ra_user_firstname) || !empty($r->ra_user_lastname)) {
                $evaluatorName = trim($r->ra_user_firstname . ' ' . $r->ra_user_lastname);
                print '<i class="fas fa-user-check"></i> ' . dol_escape_htmltag($evaluatorName) . ' &bull; ';
            }
            if (!empty($r->date_riskassessment)) {
                print dol_print_date($db->jdate($r->date_riskassessment), 'day');
            }
            print '</div>';
            if (!empty($r->ra_comment)) {
                print '<div style="font-size: 11px; color: #4b5563; font-style: italic; margin-top: 3px;" title="' . dol_escape_htmltag($r->ra_comment) . '">';
                print '&laquo; ' . dol_trunc(dol_escape_htmltag($r->ra_comment), 60) . ' &raquo;';
                print '</div>';
            }
        }
        print '</td>';

        // Column: Protection Means of the risk's groupment or work unit
        $elementProtections = $protectionsByElement[(int) $r->element_id] ?? [];
        print '<td>';
        if (empty($elementProtections)) {
            print '<span class="notion-empty-text">' . $langs->trans('NoProtectionMeans') . '</span>';
        } else {
            print '<div class="notion-protection-list">';
            foreach ($elementProtections as $protection) {
                $protectionSign = $protectionCategories[(int) $protection->category];
                print '<img src="' . dol_buildpath('/custom/digiriskdolibarr/img/' . $protectionSign['name_thumbnail'], 1) . '" class="notion-protection-picto" alt="' . dol_escape_htmltag($protectionSign['name']) . '" title="' . dol_escape_htmltag($getProtectionLabel($protection)) . '" loading="lazy">';
            }
            print '</div>';
        }
        print '</td>';

        // Column 5: Action Plan & Tasks
        $riskTasks = $tasksByRisk[$riskId] ?? [];
        $nbTasks   = count($riskTasks);
        $nbDone    = 0;
        foreach ($riskTasks as $t) {
            if ((int) $t->progress >= 100) {
                $nbDone++;
            }
        }

        print '<td style="min-width: 280px;">';
        if ($nbTasks === 0) {
            print '<span style="font-size: 12px; color: #9ca3af; font-style: italic;">' . $langs->trans('NoTasks') . '</span>';
        } else {
            $pctDone = round(($nbDone / $nbTasks) * 100);
            print '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">';
            print '<span style="font-size: 12px; font-weight: 600; color: #374151;">' . $langs->trans('TasksCompleted', $nbDone, $nbTasks) . '</span>';
            print '<div class="notion-progress-mini"><div class="notion-progress-bar" style="width: ' . $pctDone . '%;"></div></div>';
            print '<span style="font-size: 11px; color: #6b7280;">' . $pctDone . '%</span>';
            print '</div>';

            print '<div class="notion-task-list">';
            foreach ($riskTasks as $tk) {
                $taskProgress = isset($tk->progress) ? (int) $tk->progress : 0;
                $isCompleted = $taskProgress >= 100;
                $urlTask = $isOtherEntity ? $_SERVER['PHP_SELF'] . '?action=opentask&token=' . newToken() . '&open_id=' . (int) $tk->rowid . '&open_entity=' . (int) $r->entity : DOL_URL_ROOT . '/projet/tasks/task.php?id=' . (int) $tk->rowid . '&withproject=1';
                $isOverdue = false;
                if (!$isCompleted && !empty($tk->datee)) {
                    $dueDate = $db->jdate($tk->datee);
                    if ($dueDate && $dueDate < dol_now()) {
                        $isOverdue = true;
                    }
                }

                print '<div class="notion-task-item">';
                if ($isCompleted) {
                    print '<div class="notion-task-check completed" title="100%">';
                    print '<i class="fas fa-check-circle"></i>';
                    print '</div>';
                } elseif ($taskProgress > 0) {
                    print '<div class="notion-task-check in-progress" title="' . $taskProgress . '%">';
                    print '<span class="notion-task-pct">' . $taskProgress . '%</span>';
                    print '</div>';
                } else {
                    print '<div class="notion-task-check pending" title="0%">';
                    print '<i class="far fa-circle"></i>';
                    print '</div>';
                }

                print '<div class="notion-task-content">';
                print '<a href="' . $urlTask . '" target="_blank" rel="noopener" style="font-weight: 600; color: #2563eb; text-decoration: none; margin-right: 4px;">';
                print dol_escape_htmltag($tk->ref);
                print '</a>';
                print '<span class="notion-task-title">' . dol_escape_htmltag($tk->label) . '</span>';

                print '<div class="notion-task-meta">';
                if (!empty($tk->firstname) || !empty($tk->lastname)) {
                    print '<span><i class="fas fa-user"></i> ' . dol_escape_htmltag(trim($tk->firstname . ' ' . $tk->lastname)) . '</span>';
                }
                if ($taskProgress > 0 && !$isCompleted) {
                    print '<span><i class="fas fa-tasks"></i> ' . $taskProgress . '%</span>';
                }
                if (!empty($tk->datee)) {
                    print '<span class="' . ($isOverdue ? 'notion-task-overdue' : '') . '">';
                    print '<i class="far fa-calendar-alt"></i> ' . dol_print_date($db->jdate($tk->datee), 'day');
                    if ($isOverdue) {
                        print ' (' . $langs->trans('Overdue') . ')';
                    }
                    print '</span>';
                }
                print '</div>'; // end meta
                print '</div>'; // end content
                print '</div>'; // end item
            }
            print '</div>'; // end list
        }
        print '</td>';

        // Column 6: Action Button
        print '<td class="center" style="vertical-align: middle;">';
        print '<a href="' . $urlRiskCard . '" class="notion-action-btn" title="' . dol_escape_htmltag($langs->trans('ViewRiskCard')) . '">';
        print '<i class="fas fa-external-link-alt"></i> ' . $langs->trans('ViewRiskCard');
        print '</a>';
        print '</td>';

        print '</tr>';
    }
}
print '</tbody>';
print '</table>';
print '</div>'; // end notion-table-card

print '</div>'; // end notion-wrapper

llxFooter();
$db->close();
