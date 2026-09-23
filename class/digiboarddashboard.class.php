<?php
/* Copyright (C) 2024-2025 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
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
 * \file    class/digiboarddashboard.class.php
 * \ingroup digiboard
 * \brief   Class file for manage DigiBoardDashboard
 */

/**
 * Class for DigiBoardDashboard
 */
class DigiboardDashboard
{
    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Error message
     */
    public string $error = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Leave out the graphs and the lists the user hid
     *
     * A remote dashboard is computed on the instance owning the tickets, with the configuration of the user whose
     * token was used, so hiding a graph here cannot spare its computation: it is dropped on arrival instead, and
     * comes back in the list of the graphs that can be shown again.
     *
     * @param  array $dashboard Dashboard received from the remote instance
     * @return array            Dashboard without the graphs and lists the user hid
     */
    protected function filterHiddenGraphs(array $dashboard): array
    {
        $config = json_decode(getDolUserString('DIGIBOARD_DASHBOARD_CONFIG'));
        if (empty($config->graphs)) {
            return $dashboard;
        }

        foreach (['graphs', 'lists'] as $family) {
            if (empty($dashboard[$family]) || !is_array($dashboard[$family])) {
                continue;
            }
            foreach ($dashboard[$family] as $key => $item) {
                $name = $item['name'] ?? '';
                if (empty($name) || empty($config->graphs->$name->hide)) {
                    continue;
                }
                $dashboard['disabledGraphs'][$name] = $item['title'] ?? $name;
                unset($dashboard[$family][$key]);
            }
            $dashboard[$family] = array_values($dashboard[$family]);
        }

        return $dashboard;
    }

    /**
     * Load dashboard info
     *
     * @param  array|null $moreParams Parameters for load dashboard info
     * @return int|array
     * @throws Exception
     */
    public function load_dashboard(?array $moreParams = [])
    {
        // The ticket dashboard of a remote instance is a page of its own: it replaces the content instead of adding to it
        if (!empty($moreParams['RemoteTicketDashboard'])) {
            return ['ticket' => $this->filterHiddenGraphs($moreParams['RemoteTicketDashboard'])];
        }

        $array['lists'] = [];

        if (isModEnabled('digiriskdolibarr') && isModEnabled('multicompany')) {
            $getDigiRiskStatsList = $this->getDigiRiskStatsList();
            if ($getDigiRiskStatsList < 0) {
                $this->error = 'ErrorGetDigiRiskStatsList';
                return -1;
            }

            $array['digiriskdolibarr']['lists'] = [$getDigiRiskStatsList];
        }

        return $array;
    }

    /**
     * Get digirisk stats list
     *
     * @return int|array  -1 if error, Graph datas (label/color/type/title/data etc..)
     * @throws Exception
     */
    public function getDigiRiskStatsList()
    {
        global $db, $mc, $langs;

        $sharingEntities = $mc->sharings['digiriskstats'] ?? [];
        if (empty($sharingEntities)) {
            $this->error = 'ErrorGetSharingEntitiesForModule';
            return -1;
        }

        $entities                  = $mc->getEntitiesList(false, false, true);
        $sharingEntitiesAndCurrent = array_unique(array_merge([1], $sharingEntities));
        if (empty($entities) || empty($sharingEntitiesAndCurrent)) {
            $this->error = 'ErrorGetSharingEntitiesForModule2';
            return -1;
        }

        // Graph Title parameters
        $array['title'] = $langs->transnoentities('DigiRiskStatsList');
        $array['name']  = $langs->transnoentities('DigiRiskStatsList');
        $array['picto'] = 'digiriskdolibarr_color@digiriskdolibarr';

        // Graph parameters
        $array['type']   = 'list';
        $array['labels'] = ['Site', 'SIRET', 'RiskAssessmentDocument', 'NextGenerateDate', 'DelayGenerateDate', 'NbEmployees', 'NbEmployeesInvolved', 'GreyRisk', 'OrangeRisk', 'RedRisk', 'BlackRisk', 'TotalRisk'];
        if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT')) {
            $array['labels'][] = ['NbPresquAccidents', 'NbAccidents', 'NbAccidentsByEmployees', 'NbAccidentInvestigations', 'WorkStopDays', 'FrequencyIndex', 'FrequencyRate', 'GravityRate'];

            require_once __DIR__ . '/../../digiriskdolibarr/class/accident.class.php';

            $accident = new Accident($this->db);

            $accident->ismultientitymanaged = 0;
        }

        foreach ($mc->dao->entities as $mcDaoEntity) {
            if (!isset($mcDaoEntity->MAIN_MODULE_DIGIRISKDOLIBARR)) {
                unset($entities[$mcDaoEntity->id]);
            }
        }

        require_once __DIR__ . '/../../digiriskdolibarr/class/digiriskdolibarrdocuments/riskassessmentdocument.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/evaluator.class.php';
        require_once __DIR__ . '/../../digiriskdolibarr/class/riskanalysis/risk.class.php';

        $riskAssessmentDocument = new RiskAssessmentDocument($this->db);
        $evaluator              = new Evaluator($this->db);
        $risk                   = new Risk($this->db);

        $riskAssessmentDocument->ismultientitymanaged = 0;
        $evaluator->ismultientitymanaged              = 0;

        $arrayDigiRiskStatsList = [];
        $riskAssessmentCotation = [1 => 'GreyRisk', 2 => 'OrangeRisk', 3 => 'RedRisk', 4 => 'BlackRisk'];
        $total                  = ['nbEmployees' => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 'totalRisks' => 0];
        $dangerCategories       = Risk::getDangerCategories();
        foreach ($entities as $entityID => $entityName) {
            if (!in_array($entityID, $sharingEntitiesAndCurrent)) {
                continue;
            }

            $arrayDigiRiskStatsList[$entityID]['Site']['value']   = $entityName;
            $arrayDigiRiskStatsList[$entityID]['Site']['morecss'] = 'left bold';
            $arrayDigiRiskStatsList[$entityID]['Siret']['value']  = dolibarr_get_const($db, 'MAIN_INFO_SIRET', $entityID);

            $moreParam['entity']                                                  = $entityID;
            $filterEntity                                                         = ' AND t.entity = ' . $entityID;
            $moreParam['filter']                                                  = $filterEntity;
            $arrayGetGenerationDateInfos                                          = $riskAssessmentDocument->getGenerationDateInfos($moreParam);
            $arrayDigiRiskStatsList[$entityID]['RiskAssessmentDocument']['value'] = $arrayGetGenerationDateInfos['lastgeneratedate'] . $arrayGetGenerationDateInfos['moreContent'];
            $arrayDigiRiskStatsList[$entityID]['NextGenerateDate']['value']       = $arrayGetGenerationDateInfos['nextgeneratedate'];
            $arrayDigiRiskStatsList[$entityID]['DelayGenerateDate']['value']      = $arrayGetGenerationDateInfos['delaygeneratedate'];

            $moreParam['filter']                                               = ' AND u.entity IN (0,' . $entityID . ')';
            $employees                                                         = $evaluator->getNbEmployees($moreParam);
            $moreParam['filter']                                               = $filterEntity;
            $arrayDigiRiskStatsList[$entityID]['NbEmployees']['value']         = $employees['nbemployees'];
            $arrayDigiRiskStatsList[$entityID]['NbEmployeesInvolved']['value'] = $evaluator->getNbEmployeesInvolved($moreParam)['nbemployeesinvolved'];
            $total['nbEmployees'] += $employees['nbemployees'];

            $moreParam['filterEntity']                = $filterEntity;
            $riskByDangerCategoriesAndRiskAssessments = $risk->getRiskByDangerCategoriesAndRiskAssessments($dangerCategories, 'risk', $moreParam);
            if (empty($riskByDangerCategoriesAndRiskAssessments)) {
                $riskByDangerCategoriesAndRiskAssessments['totalRisks']        = 0;
                $riskByDangerCategoriesAndRiskAssessments['nbRiskByCotations'] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            }

            $nbRiskByCotations = $riskByDangerCategoriesAndRiskAssessments['nbRiskByCotations'];
            $totalRisks        = $riskByDangerCategoriesAndRiskAssessments['totalRisks'];
            for ($i = 1; $i <= 4; $i++) {
                $nbRiskByCotations[$i] = $nbRiskByCotations[$i] ?? 0;

                $percent = ($nbRiskByCotations[$i] > 0 && $totalRisks > 0) ? round($nbRiskByCotations[$i] * 100 / $totalRisks, 1) : 0;

                $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['value'] = "
                    <div class='flex flex-col justify-center' style='padding-top: 10px;'>
                        <div style='height: 1em'>$nbRiskByCotations[$i]</div>
                        <div style='font-size: 0.75em; height: 0.75em;'>($percent%)</div>
                    </div>
                ";

                $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['morecss']  = 'risk-evaluation-cotation';
                $arrayDigiRiskStatsList[$entityID][$riskAssessmentCotation[$i]]['moreAttr'] = 'data-scale=' . $i . ' style="line-height: 0; border-radius: 0;"';

                $total[$i] += $nbRiskByCotations[$i];
            }

            $arrayDigiRiskStatsList[$entityID]['TotalRisk']['value'] = $totalRisks;

            $total['totalRisks'] += $totalRisks;

            if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT')) {
                $moreParam['filter']    = $filterEntity;
                $join                   = ' LEFT JOIN ' . MAIN_DB_PREFIX . $accident->table_element . ' as a ON a.rowid = t.fk_accident';
                $accidentsWithWorkStops = saturne_fetch_all_object_type('AccidentWorkStop', 'DESC', 't.rowid', 0, 0, ['customsql' => 't.entity = ' . $entityID], 'AND', false, false, false, $join);
                $accidents              = $accident->fetchAll('', '', 0, 0, ['customsql' => ' t.status > ' . Accident::STATUS_DRAFT . $moreParam['filter']]);
                if (empty($accidents) && !is_array($accidents)) {
                    $accidents = [];
                }
                if (empty($accidentsWithWorkStops) && !is_array($accidentsWithWorkStops)) {
                    $accidentsWithWorkStops = [];
                }

                $arrayDigiRiskStatsList[$entityID]['NbPresquAccidents']['value']        = $accident->getNbPresquAccidents()['nbpresquaccidents'];
                $arrayDigiRiskStatsList[$entityID]['NbAccidents']['value']              = $accident->getNbAccidents($accidents, $accidentsWithWorkStops)['data']['accidents'];
                $arrayDigiRiskStatsList[$entityID]['NbAccidentsByEmployees']['value']   = $accident->getNbAccidentsByEmployees($accidents, $accidentsWithWorkStops, $employees)['nbaccidentsbyemployees'];
                $arrayDigiRiskStatsList[$entityID]['NbAccidentInvestigations']['value'] = $accident->getNbAccidentInvestigations($moreParam)['nbaccidentinvestigations'];
                $arrayDigiRiskStatsList[$entityID]['WorkStopDays']['value']             = $accident->getNbWorkstopDays($accidentsWithWorkStops)['nbworkstopdays'];
                $arrayDigiRiskStatsList[$entityID]['FrequencyIndex']['value']           = $accident->getFrequencyIndex($accidentsWithWorkStops, $employees)['frequencyindex'];
                $arrayDigiRiskStatsList[$entityID]['FrequencyRate']['value']            = $accident->getFrequencyRate($accidentsWithWorkStops)['frequencyrate'];
                $arrayDigiRiskStatsList[$entityID]['GravityRate']['value']              = $accident->getGravityRate($accidentsWithWorkStops)['gravityrate'];
            }
        }

        $totalValue = ['Site' => ['value' => $langs->transnoentities('Total'), 'morecss' => 'bold'], 'Siret' => ['value' => ''], 'RiskAssessmentDocument' => ['value' => ''], 'NextGenerateDate' => ['value' => ''], 'DelayGenerateDate' => ['value' => ''], 'NbEmployees' => ['value' => ''], 'NbEmployeesInvolved' => ['value' => '']];

        $totalValue['NbEmployees']['value'] = $total['nbEmployees'];

        for ($i = 1; $i <= 4; $i++) {
            $percent = ($total[$i] > 0) ? round($total[$i] * 100 / $total['totalRisks'], 1) : 0;

            $totalValue[$riskAssessmentCotation[$i]]['value'] = "
                  <div class='flex flex-col justify-center' style='padding-top: 10px;'>
                        <div style='height: 1em'>$total[$i]</div>
                        <div style='font-size: 0.75em; height: 0.75em;'>($percent%)</div>
                  </div>
            ";

            $totalValue[$riskAssessmentCotation[$i]]['morecss']  = 'risk-evaluation-cotation';
            $totalValue[$riskAssessmentCotation[$i]]['moreAttr'] = 'data-scale=' . $i . ' style="line-height: 0; border-radius: 0;"';
        }

        $totalValue['TotalRisk']['value'] = $total['totalRisks'];

        if (getDolGlobalInt('DIGIBOARD_DIGIRISIK_STATS_LOAD_ACCIDENT')) {
            $totalValue = array_merge($totalValue, ['NbPresquAccidents' => ['value' => ''], 'NbAccidents' => ['value' => ''], 'NbAccidentsByEmployees' => ['value' => ''], 'NbAccidentInvestigations' => ['value' => ''], 'WorkStopDays' => ['value' => ''], 'FrequencyIndex' => ['value' => ''], 'FrequencyRate' => ['value' => ''], 'GravityRate' => ['value' => '']]);
        }

        $arrayDigiRiskStatsList['Total'] = $totalValue;

        $array['data'] = $arrayDigiRiskStatsList;

        return $array;
    }
}
