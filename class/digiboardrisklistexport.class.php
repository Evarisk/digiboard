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
 * \file    class/digiboardrisklistexport.class.php
 * \ingroup digiboard
 * \brief   Excel export of the simplified risk list, one sheet per view of the data
 */

require_once DOL_DOCUMENT_ROOT . '/includes/phpoffice/phpspreadsheet/src/autoloader.php';
require_once DOL_DOCUMENT_ROOT . '/includes/Psr/autoloader.php';
require_once PHPEXCELNEW_PATH . 'Spreadsheet.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Class for DigiboardRiskListExport
 *
 * The risks come from the query of the list, already filtered and sorted: this class only reads the history of
 * their assessments, then lays the same data out under several views, so that a report or a chart can be built
 * from any of them without reworking a flat file.
 */
class DigiboardRiskListExport
{
    /**
     * Colours of the Digirisk cotation chips, as in the risk overview workbook of Digirisk: background, text
     */
    public const LEVEL_COLOURS = [
        'low'          => ['ECECEC', '000000'],
        'plan'         => ['E9AD4F', 'FFFFFF'],
        'treat'        => ['E05353', 'FFFFFF'],
        'unacceptable' => ['2B2B2B', 'FFFFFF'],
        'none'         => ['F1F5F9', '64748B'],
    ];

    /**
     * Colours of the head rows: background, text
     */
    public const HEAD_COLOURS = ['263C5C', 'FFFFFF'];

    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Risk type of the list: risk or riskenvironmental
     */
    public string $riskType = 'risk';

    /**
     * @var array Names of the establishments, by entity
     */
    public array $entities = [];

    /**
     * @var array Danger categories of the risk type, by position
     */
    public array $dangerCategories = [];

    /**
     * @var array Protection signs, personal (OBLIGATION) or collective (epc), by position
     */
    public array $protectionCategories = [];

    /**
     * @var array Criteria of the advanced cotation method, by assessment column
     */
    public array $cotationCriteria = [];

    /**
     * @var array Highest cotation of each risk level, from the lowest level up
     */
    public array $cotationLevelMax = [];

    /**
     * @var array Translation key of each risk level, 'none' included
     */
    public array $riskLevelLabels = [];

    /**
     * @var array Filters of the list, as label => value, printed on the summary
     */
    public array $filters = [];

    /**
     * @var Spreadsheet Workbook being built
     */
    protected Spreadsheet $spreadsheet;

    /**
     * @var array Sheets after the summary, with their description and their number of lines, for the contents
     */
    protected array $contents = [];

    /**
     * @var string Excel format of a date, from the date format of the user language
     */
    protected string $dateFormat = 'dd/mm/yyyy';

    /**
     * @var int Date of the export
     */
    protected int $now = 0;

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
     * Send the workbook to the browser
     *
     * @param  array  $risks                Rows of the risk list query, by risk id, in the order of the list
     * @param  array  $tasksByRisk          Action plan tasks, by risk id
     * @param  array  $protectionsByElement Protection signs, by groupment or work unit id
     * @param  string $fileName             Name of the file, without extension
     * @return void
     */
    public function send(array $risks, array $tasksByRisk, array $protectionsByElement, string $fileName): void
    {
        $spreadsheet = $this->build($risks, $tasksByRisk, $protectionsByElement);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Build the workbook: a summary, then the detailed sheets, then the sheets that sum the risks up
     *
     * @param  array       $risks                Rows of the risk list query, by risk id, in the order of the list
     * @param  array       $tasksByRisk          Action plan tasks, by risk id
     * @param  array       $protectionsByElement Protection signs, by groupment or work unit id
     * @return Spreadsheet                       Workbook
     */
    public function build(array $risks, array $tasksByRisk, array $protectionsByElement): Spreadsheet
    {
        global $langs, $mysoc;

        $this->now        = dol_now();
        $this->dateFormat = strtolower($langs->transnoentities('FormatDateShortJava'));
        $this->contents   = [];

        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getProperties()->setCreator('DigiBoard')->setTitle($langs->transnoentities('SimplifiedRiskList') . ' - ' . $mysoc->name);
        $this->spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $this->spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $summarySheet = $this->spreadsheet->getActiveSheet();

        $assessmentsByRisk = $this->fetchAssessments(array_keys($risks));
        $records           = [];
        foreach ($risks as $riskId => $risk) {
            $records[$riskId] = $this->buildRecord($risk, $assessmentsByRisk[$riskId] ?? [], $tasksByRisk[$riskId] ?? [], $protectionsByElement[(int) $risk->element_id] ?? []);
        }

        $this->addRisksSheet($records);
        $this->addTasksSheet($records);
        $this->addAssessmentsSheet($records);
        $this->addProtectionsSheet($records, $protectionsByElement);
        $this->addEstablishmentsSheet($records);
        $this->addElementsSheet($records, $protectionsByElement);
        $this->addCategoriesSheet($records);
        $this->addMapSheet($records);
        $this->addCriteriaSheet($records);
        $this->addScheduleSheet($records);
        $this->fillSummarySheet($summarySheet, $records, $protectionsByElement);

        $this->spreadsheet->setActiveSheetIndex(0);

        return $this->spreadsheet;
    }

    /**
     * Load the assessments of the given risks, the current one and the ones it replaced
     *
     * @param  array $riskIds Risk ids
     * @return array          Assessments by risk id, oldest first
     */
    protected function fetchAssessments(array $riskIds): array
    {
        $assessmentsByRisk = [];
        foreach (array_chunk($riskIds, 500) as $chunkIds) {
            $sql  = "SELECT ra.rowid, ra.fk_risk, ra.ref, ra.cotation, ra.date_riskassessment, ra.date_creation, ra.comment, ra.method,";
            $sql .= " ra.gravite, ra.exposition, ra.occurrence, ra.formation, ra.protection,";
            $sql .= " u.firstname, u.lastname";
            $sql .= " FROM " . MAIN_DB_PREFIX . "digiriskdolibarr_riskassessment ra";
            $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = ra.fk_user_creat";
            // A new assessment sets the status of the previous ones to 0, a deleted one goes to -1
            $sql .= " WHERE ra.status IN (0, 1) AND ra.fk_risk IN (" . implode(',', array_map('intval', $chunkIds)) . ")";
            $sql .= " ORDER BY ra.fk_risk ASC, ra.rowid ASC";

            $resql = $this->db->query($sql);
            if ($resql) {
                while ($assessment = $this->db->fetch_object($resql)) {
                    $assessmentsByRisk[(int) $assessment->fk_risk][] = $assessment;
                }
                $this->db->free($resql);
            }
        }

        return $assessmentsByRisk;
    }

    /**
     * Gather everything the sheets print about a risk
     *
     * @param  stdClass $risk        Row of the risk list query
     * @param  array    $assessments Assessments of the risk, oldest first
     * @param  array    $tasks       Action plan tasks of the risk
     * @param  array    $protections Protection signs of the groupment or work unit of the risk
     * @return array                 Record of the risk
     */
    protected function buildRecord(stdClass $risk, array $assessments, array $tasks, array $protections): array
    {
        global $langs;

        $cotation = isset($risk->cotation) ? (int) $risk->cotation : null;

        // A risk sits either on a work unit (under its groupment) or directly on a groupment
        $isWorkUnit = $risk->element_type === 'workunit';
        $groupment  = $isWorkUnit ? (!empty($risk->parent_id) ? $risk->parent_ref . ' - ' . $risk->parent_label : '') : $risk->element_ref . ' - ' . $risk->element_label;
        $workUnit   = $isWorkUnit ? $risk->element_ref . ' - ' . $risk->element_label : '';

        // The previous cotation is the one of the assessment the current one replaced
        $currentAssessment = null;
        $previousCotation  = null;
        foreach ($assessments as $assessment) {
            if ((int) $assessment->rowid === (int) $risk->ra_id) {
                $currentAssessment = $assessment;
            } elseif ((int) $assessment->rowid < (int) $risk->ra_id && $assessment->cotation !== null) {
                $previousCotation = (int) $assessment->cotation;
            }
        }

        $taskRecords = [];
        foreach ($tasks as $task) {
            $progress = (int) $task->progress;
            $dueDate  = !empty($task->datee) ? $this->db->jdate($task->datee) : 0;
            $overdue  = $progress < 100 && !empty($dueDate) && $dueDate < $this->now;

            $details = [$progress . ' %'];
            if (!empty($dueDate)) {
                $details[] = $langs->transnoentities('DateEnd') . ' ' . dol_print_date($dueDate, 'day') . ($overdue ? ' - ' . $langs->transnoentities('Overdue') : '');
            }
            $author = trim($task->firstname . ' ' . $task->lastname);
            if ($author !== '') {
                $details[] = $author;
            }

            $taskRecords[] = [
                'task'     => $task,
                'progress' => $progress,
                'dueDate'  => $dueDate,
                'overdue'  => $overdue,
                'author'   => $author,
                'line'     => $task->ref . ' - ' . $task->label . ' (' . implode(', ', $details) . ')',
            ];
        }

        $protectionLabels = [];
        foreach ($protections as $protection) {
            $protectionLabels[] = $this->getProtectionLabel($protection);
        }

        return [
            'risk'          => $risk,
            'establishment' => $this->entities[(int) $risk->entity] ?? '',
            'groupment'     => $groupment,
            'workUnit'      => $workUnit,
            'category'      => $this->dangerCategories[(int) $risk->category]['name'] ?? '',
            'cotation'      => $cotation,
            'level'         => $this->getRiskLevel($cotation),
            'criteria'      => $this->getCriteriaValues((string) $risk->ra_method, $risk, 'ra_'),
            'date'          => $currentAssessment !== null ? $this->getAssessmentDate($currentAssessment) : null,
            'previous'      => $previousCotation,
            'assessments'   => $assessments,
            'protections'   => $protectionLabels,
            'tasks'         => $taskRecords,
        ];
    }

    /**
     * Risk level key of a cotation, 'none' when the risk was never assessed
     *
     * @param  int|null $cotation Cotation
     * @return string             Risk level key
     */
    protected function getRiskLevel(?int $cotation): string
    {
        if ($cotation === null) {
            return 'none';
        }
        foreach ($this->cotationLevelMax as $level => $max) {
            if ($cotation <= $max) {
                return $level;
            }
        }

        return 'unacceptable';
    }

    /**
     * Criteria values of an assessment: only the advanced method rates criteria
     *
     * @param  string   $method     Cotation method of the assessment
     * @param  stdClass $assessment Row holding the assessment columns
     * @param  string   $prefix     Prefix of the assessment columns in the row
     * @return array                Criterion value, by assessment column
     */
    protected function getCriteriaValues(string $method, stdClass $assessment, string $prefix = ''): array
    {
        $values = [];
        if ($method === 'advanced') {
            foreach (array_keys($this->cotationCriteria) as $column) {
                $value = $assessment->{$prefix . $column} ?? null;
                if ($value !== null && $value !== '') {
                    $values[$column] = (int) $value;
                }
            }
        }

        return $values;
    }

    /**
     * Label of a protection mean on a single line: the name of its sign, then what the risk sign says about it
     *
     * @param  stdClass $protection Risk sign
     * @return string               Label
     */
    protected function getProtectionLabel(stdClass $protection): string
    {
        $description = trim((string) preg_replace('/\s*\R\s*/', ' ', (string) $protection->description));

        return $this->protectionCategories[(int) $protection->category]['name'] . ($description !== '' ? ' : ' . $description : '');
    }

    /**
     * Translated label of a cotation method
     *
     * @param  string $method Cotation method
     * @return string         Label, empty when the risk was never assessed
     */
    protected function getMethodLabel(string $method): string
    {
        global $langs;

        if ($method === 'advanced') {
            return $langs->transnoentities('RiskXlsxMethodAdvanced');
        } elseif ($method !== '') {
            return $langs->transnoentities('RiskXlsxMethodStandard');
        }

        return '';
    }

    /**
     * Excel date of a date of the database, at the day the list prints
     *
     * @param  string|null $value Date as read from the database
     * @return float|int|null     Excel date, null when empty
     */
    protected function getExcelDate(?string $value)
    {
        if (empty($value)) {
            return null;
        }
        $timestamp = $this->db->jdate($value);
        if (empty($timestamp)) {
            return null;
        }
        // Built from the printed day rather than from the timestamp, which PhpSpreadsheet would read as UTC
        $day = array_map('intval', explode('-', dol_print_date($timestamp, '%Y-%m-%d')));

        return ExcelDate::formattedPHPToExcel($day[0], $day[1], $day[2]);
    }

    /**
     * Excel date of an assessment, the way Digirisk prints it: the date typed in the assessment when the setup
     * shows it, the date the assessment was made otherwise
     *
     * @param  stdClass       $assessment Assessment
     * @return float|int|null             Excel date, null when empty
     */
    protected function getAssessmentDate(stdClass $assessment)
    {
        if (getDolGlobalInt('DIGIRISKDOLIBARR_SHOW_RISKASSESSMENT_DATE') && !empty($assessment->date_riskassessment)) {
            return $this->getExcelDate($assessment->date_riskassessment);
        }

        return $this->getExcelDate($assessment->date_creation);
    }

    /**
     * Cell of a risk level: its label, coloured as the level
     *
     * @param  string $level Risk level key
     * @return array         Cell
     */
    protected function getLevelCell(string $level): array
    {
        global $langs;

        return ['value' => $langs->transnoentities($this->riskLevelLabels[$level]), 'level' => $level];
    }

    /**
     * Style array of the given colours
     *
     * @param  array $colours Background and text colours
     * @param  bool  $bold    Bold text
     * @return array          Style array
     */
    protected function getColourStyle(array $colours, bool $bold = false): array
    {
        return [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colours[0]]],
            'font' => ['color' => ['rgb' => $colours[1]], 'bold' => $bold],
        ];
    }

    /**
     * Add a sheet after the others, set up to print on one page width
     *
     * @param  string    $titleKey       Translation key of the sheet title
     * @param  string    $descriptionKey Translation key of the description printed in the contents of the summary
     * @return Worksheet                 Sheet
     */
    protected function addSheet(string $titleKey, string $descriptionKey): Worksheet
    {
        $sheet = $this->spreadsheet->createSheet();
        $this->setSheetTitle($sheet, $titleKey);
        $this->contents[] = ['sheet' => $sheet, 'description' => $descriptionKey, 'lines' => 0];

        return $sheet;
    }

    /**
     * Name a sheet and set up its printing: landscape, one page width, the company, the date and the page number
     *
     * @param  Worksheet $sheet    Sheet
     * @param  string    $titleKey Translation key of the sheet title
     * @return void
     */
    protected function setSheetTitle(Worksheet $sheet, string $titleKey): void
    {
        global $langs, $mysoc;

        // A sheet name holds 31 characters at most, without []:*?/\
        $title = dol_trunc(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $langs->transnoentities($titleKey)), 31, 'right', 'UTF-8', 1);
        $sheet->setTitle($title);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        // A & starts a code in a header or a footer: a literal one is doubled
        $sheet->getHeaderFooter()->setOddHeader('&L' . str_replace('&', '&&', $mysoc->name) . '&C&B' . str_replace('&', '&&', $title) . '&R' . dol_print_date($this->now, 'day'));
        $sheet->getHeaderFooter()->setOddFooter('&R&P / &N');
    }

    /**
     * Write a table: a head row, then one row per line, each column with its width and format
     *
     * A cell is a value, or an array with the value and the risk level colouring it. A text is always written
     * as a text, so that a description starting like a formula is never computed.
     *
     * @param  Worksheet $sheet          Sheet
     * @param  int       $headRow        Row of the head
     * @param  array     $columns        Columns: label, width, format (text, longtext, int, decimal, percent, money, date, month), level
     * @param  array     $lines          Lines of cells, in the order of the columns
     * @param  int       $frozenColumns  Columns kept in view when scrolling to the right
     * @return int                       Last row written
     */
    protected function writeTable(Worksheet $sheet, int $headRow, array $columns, array $lines, int $frozenColumns = 0): int
    {
        $numberFormats = [
            'int'     => '0',
            'decimal' => '0.0',
            'percent' => '0%',
            'money'   => '#,##0.00',
            'date'    => $this->dateFormat,
            'month'   => 'mmmm yyyy',
        ];

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        foreach (array_values($columns) as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($letter . $headRow, $column['label'], DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth($column['width'] ?? 14);
        }
        $sheet->getStyle('A' . $headRow . ':' . $lastColumn . $headRow)->applyFromArray($this->getColourStyle(self::HEAD_COLOURS, true) + [
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        // The head of a column counting a risk level takes the colour of the level, as the legend of a chart would
        foreach (array_values($columns) as $index => $column) {
            if (!empty($column['level'])) {
                $sheet->getStyle(Coordinate::stringFromColumnIndex($index + 1) . $headRow)->applyFromArray($this->getColourStyle(self::LEVEL_COLOURS[$column['level']], true));
            }
        }

        $row = $headRow;
        foreach ($lines as $line) {
            $row++;
            foreach (array_values($line) as $index => $cell) {
                $coordinate = Coordinate::stringFromColumnIndex($index + 1) . $row;
                $level      = null;
                if (is_array($cell)) {
                    $level = $cell['level'] ?? null;
                    $cell  = $cell['value'] ?? null;
                }
                if ($level !== null) {
                    $sheet->getStyle($coordinate)->applyFromArray($this->getColourStyle(self::LEVEL_COLOURS[$level], true));
                }
                if ($cell === null || $cell === '') {
                    continue;
                }
                if (is_int($cell) || is_float($cell)) {
                    $sheet->setCellValueExplicit($coordinate, $cell, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) $cell, DataType::TYPE_STRING);
                }
            }
        }

        if ($row > $headRow) {
            foreach (array_values($columns) as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $style  = $sheet->getStyle($letter . ($headRow + 1) . ':' . $letter . $row);
                $format = $column['format'] ?? 'text';
                if (isset($numberFormats[$format])) {
                    $style->getNumberFormat()->setFormatCode($numberFormats[$format]);
                }
                if ($format === 'longtext') {
                    $style->getAlignment()->setWrapText(true);
                }
            }
        }
        $sheet->getStyle('A' . $headRow . ':' . $lastColumn . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');

        $sheet->setAutoFilter('A' . $headRow . ':' . $lastColumn . $row);
        $sheet->freezePane(Coordinate::stringFromColumnIndex($frozenColumns + 1) . ($headRow + 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headRow, $headRow);

        $this->contents[count($this->contents) - 1]['lines'] = count($lines);

        return $row;
    }

    /**
     * Columns locating a risk: establishment, groupment, work unit
     *
     * @return array Columns
     */
    protected function getLocationColumns(): array
    {
        global $langs;

        return [
            ['label' => $langs->transnoentities('Establishment'), 'width' => 22],
            ['label' => $langs->transnoentities('Groupment'), 'width' => 26],
            ['label' => $langs->transnoentities('WorkUnit'), 'width' => 26],
        ];
    }

    /**
     * Cells locating a risk: establishment, groupment, work unit
     *
     * @param  array $record Record of the risk
     * @return array         Cells
     */
    protected function getLocationCells(array $record): array
    {
        return [$record['establishment'], $record['groupment'], $record['workUnit']];
    }

    /**
     * Columns of the criteria of the advanced method
     *
     * @return array Columns
     */
    protected function getCriteriaColumns(): array
    {
        global $langs;

        $columns = [];
        foreach ($this->cotationCriteria as $criterion) {
            $columns[] = ['label' => $langs->transnoentities($criterion['label']), 'width' => 11, 'format' => 'int'];
        }

        return $columns;
    }

    /**
     * Sheet of the risks: one line per risk, with its last assessment, its protections and its action plan
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addRisksSheet(array $records): void
    {
        global $langs;

        $sheet   = $this->addSheet('RiskXlsxSheetRisks', 'RiskXlsxSheetRisksDesc');
        $columns = array_merge($this->getLocationColumns(), [
            ['label' => $langs->transnoentities('RiskRef'), 'width' => 10],
            ['label' => $langs->transnoentities('DangerCategory'), 'width' => 22],
            ['label' => $langs->transnoentities('Description'), 'width' => 40, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('RiskCotation'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskLevel'), 'width' => 18],
            ['label' => $langs->transnoentities('Method'), 'width' => 12],
        ], $this->getCriteriaColumns(), [
            ['label' => $langs->transnoentities('AssessmentDate'), 'width' => 12, 'format' => 'date'],
            ['label' => $langs->transnoentities('Evaluator'), 'width' => 18],
            ['label' => $langs->transnoentities('AssessmentComment'), 'width' => 36, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('RiskXlsxPreviousCotation'), 'width' => 11, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxCotationChange'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxAssessmentsNumber'), 'width' => 11, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxProtectionsNumber'), 'width' => 11, 'format' => 'int'],
            ['label' => $langs->transnoentities('ProtectionMeans'), 'width' => 36, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('ActionsCount'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('ActionsCompletedCount'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxActionsOverdue'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxAverageProgress'), 'width' => 11, 'format' => 'percent'],
            ['label' => $langs->transnoentities('ActionsAndTasks'), 'width' => 60, 'format' => 'longtext'],
        ]);

        $lines = [];
        foreach ($records as $record) {
            $risk  = $record['risk'];
            $cells = array_merge($this->getLocationCells($record), [
                $risk->ref,
                $record['category'],
                $risk->description,
                $record['cotation'],
                $this->getLevelCell($record['level']),
                $this->getMethodLabel((string) $risk->ra_method),
            ]);
            foreach (array_keys($this->cotationCriteria) as $column) {
                $cells[] = $record['criteria'][$column] ?? null;
            }

            $nbDone     = 0;
            $nbOverdue  = 0;
            $progresses = [];
            $taskLines  = [];
            foreach ($record['tasks'] as $taskRecord) {
                $nbDone      += $taskRecord['progress'] >= 100 ? 1 : 0;
                $nbOverdue   += $taskRecord['overdue'] ? 1 : 0;
                $progresses[] = $taskRecord['progress'] / 100;
                $taskLines[]  = $taskRecord['line'];
            }

            $cells[] = $record['date'];
            $cells[] = trim($risk->ra_user_firstname . ' ' . $risk->ra_user_lastname);
            $cells[] = $risk->ra_comment;
            $cells[] = $record['previous'];
            $cells[] = $record['previous'] !== null && $record['cotation'] !== null ? $record['cotation'] - $record['previous'] : null;
            $cells[] = count($record['assessments']);
            $cells[] = count($record['protections']);
            $cells[] = implode("\n", $record['protections']);
            $cells[] = count($record['tasks']);
            $cells[] = $nbDone;
            $cells[] = $nbOverdue;
            $cells[] = !empty($progresses) ? array_sum($progresses) / count($progresses) : null;
            $cells[] = implode("\n", $taskLines);

            $lines[] = $cells;
        }

        $this->writeTable($sheet, 1, $columns, $lines, 4);
    }

    /**
     * Sheet of the action plan: one line per task, with the risk it treats
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addTasksSheet(array $records): void
    {
        global $langs;

        $sheet   = $this->addSheet('RiskXlsxSheetTasks', 'RiskXlsxSheetTasksDesc');
        $columns = array_merge($this->getLocationColumns(), [
            ['label' => $langs->transnoentities('RiskRef'), 'width' => 10],
            ['label' => $langs->transnoentities('DangerCategory'), 'width' => 22],
            ['label' => $langs->transnoentities('RiskCotation'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskLevel'), 'width' => 18],
            ['label' => $langs->transnoentities('RiskXlsxTaskRef'), 'width' => 12],
            ['label' => $langs->transnoentities('Label'), 'width' => 40, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('Author'), 'width' => 18],
            ['label' => $langs->transnoentities('DateStart'), 'width' => 12, 'format' => 'date'],
            ['label' => $langs->transnoentities('DateEnd'), 'width' => 12, 'format' => 'date'],
            ['label' => $langs->transnoentities('Progress'), 'width' => 11, 'format' => 'percent'],
            ['label' => $langs->transnoentities('Status'), 'width' => 12],
            ['label' => $langs->transnoentities('Overdue'), 'width' => 10],
            ['label' => $langs->transnoentities('RiskXlsxOverdueDays'), 'width' => 11, 'format' => 'int'],
            ['label' => $langs->transnoentities('PlannedWorkload') . ' (h)', 'width' => 12, 'format' => 'decimal'],
            ['label' => $langs->transnoentities('Budget'), 'width' => 12, 'format' => 'money'],
        ]);

        $lines = [];
        foreach ($records as $record) {
            foreach ($record['tasks'] as $taskRecord) {
                $task = $taskRecord['task'];
                if ($taskRecord['progress'] >= 100) {
                    $status = $langs->transnoentities('TaskCompleted');
                } elseif ($taskRecord['progress'] > 0) {
                    $status = $langs->transnoentities('TaskInProgress');
                } else {
                    $status = $langs->transnoentities('RiskXlsxTaskToDo');
                }

                $lines[] = array_merge($this->getLocationCells($record), [
                    $record['risk']->ref,
                    $record['category'],
                    $record['cotation'],
                    $this->getLevelCell($record['level']),
                    $task->ref,
                    $task->label,
                    $taskRecord['author'],
                    $this->getExcelDate($task->dateo),
                    $this->getExcelDate($task->datee),
                    $taskRecord['progress'] / 100,
                    $status,
                    $langs->transnoentities($taskRecord['overdue'] ? 'Yes' : 'No'),
                    $taskRecord['overdue'] ? (int) floor(($this->now - $taskRecord['dueDate']) / 86400) : null,
                    // The planned workload is stored in seconds
                    !empty($task->planned_workload) ? round((float) $task->planned_workload / 3600, 2) : null,
                    $task->budget_amount !== null ? (float) $task->budget_amount : null,
                ]);
            }
        }

        $this->writeTable($sheet, 1, $columns, $lines, 4);
    }

    /**
     * Sheet of the assessments: every assessment of the risks, to follow how their cotation evolved
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addAssessmentsSheet(array $records): void
    {
        global $langs;

        $sheet   = $this->addSheet('RiskXlsxSheetAssessments', 'RiskXlsxSheetAssessmentsDesc');
        $columns = array_merge($this->getLocationColumns(), [
            ['label' => $langs->transnoentities('RiskRef'), 'width' => 10],
            ['label' => $langs->transnoentities('DangerCategory'), 'width' => 22],
            ['label' => $langs->transnoentities('RiskXlsxAssessmentRef'), 'width' => 12],
            ['label' => $langs->transnoentities('AssessmentDate'), 'width' => 12, 'format' => 'date'],
            ['label' => $langs->transnoentities('RiskCotation'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskLevel'), 'width' => 18],
            ['label' => $langs->transnoentities('Method'), 'width' => 12],
        ], $this->getCriteriaColumns(), [
            ['label' => $langs->transnoentities('Evaluator'), 'width' => 18],
            ['label' => $langs->transnoentities('AssessmentComment'), 'width' => 40, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('RiskXlsxCurrentAssessment'), 'width' => 12],
        ]);

        $lines = [];
        foreach ($records as $record) {
            foreach ($record['assessments'] as $assessment) {
                $cotation = $assessment->cotation !== null ? (int) $assessment->cotation : null;
                $criteria = $this->getCriteriaValues((string) $assessment->method, $assessment);
                $cells    = array_merge($this->getLocationCells($record), [
                    $record['risk']->ref,
                    $record['category'],
                    $assessment->ref,
                    $this->getAssessmentDate($assessment),
                    $cotation,
                    $this->getLevelCell($this->getRiskLevel($cotation)),
                    $this->getMethodLabel((string) $assessment->method),
                ]);
                foreach (array_keys($this->cotationCriteria) as $column) {
                    $cells[] = $criteria[$column] ?? null;
                }
                $cells[] = trim($assessment->firstname . ' ' . $assessment->lastname);
                $cells[] = $assessment->comment;
                $cells[] = $langs->transnoentities((int) $assessment->rowid === (int) $record['risk']->ra_id ? 'Yes' : 'No');

                $lines[] = $cells;
            }
        }

        $this->writeTable($sheet, 1, $columns, $lines, 4);
    }

    /**
     * Sheet of the protections: one line per personal or collective protection sign of the groupments and units
     *
     * @param  array $records              Records of the risks
     * @param  array $protectionsByElement Protection signs, by groupment or work unit id
     * @return void
     */
    protected function addProtectionsSheet(array $records, array $protectionsByElement): void
    {
        global $langs;

        $sheet   = $this->addSheet('RiskXlsxSheetProtections', 'RiskXlsxSheetProtectionsDesc');
        $columns = array_merge($this->getLocationColumns(), [
            ['label' => $langs->transnoentities('Type'), 'width' => 10],
            ['label' => $langs->transnoentities('RiskXlsxSign'), 'width' => 34],
            ['label' => $langs->transnoentities('Description'), 'width' => 50, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('RiskXlsxElementRisks'), 'width' => 12, 'format' => 'int'],
        ]);

        $lines = [];
        foreach ($this->groupByElement($records) as $elementId => $elementRecords) {
            foreach ($protectionsByElement[$elementId] ?? [] as $protection) {
                $sign    = $this->protectionCategories[(int) $protection->category];
                $lines[] = array_merge($this->getLocationCells($elementRecords[0]), [
                    $langs->transnoentities(strpos($sign['name_thumbnail'], 'epc/') === 0 ? 'RiskXlsxCollectiveProtection' : 'RiskXlsxPersonalProtection'),
                    $sign['name'],
                    trim((string) $protection->description),
                    count($elementRecords),
                ]);
            }
        }

        $this->writeTable($sheet, 1, $columns, $lines, 3);
    }

    /**
     * Records grouped by groupment or work unit, sorted by establishment, groupment and work unit
     *
     * @param  array $records Records of the risks
     * @return array          Records, by groupment or work unit id
     */
    protected function groupByElement(array $records): array
    {
        $recordsByElement = [];
        foreach ($records as $record) {
            $recordsByElement[(int) $record['risk']->element_id][] = $record;
        }
        uasort($recordsByElement, function ($a, $b) {
            return strnatcasecmp(implode("\x01", $this->getLocationCells($a[0])), implode("\x01", $this->getLocationCells($b[0])));
        });

        return $recordsByElement;
    }

    /**
     * Columns summing risks up: number by level, cotations and actions
     *
     * @return array Columns
     */
    protected function getStatsColumns(): array
    {
        global $langs;

        $columns = [['label' => $langs->transnoentities('Total'), 'width' => 9, 'format' => 'int']];
        foreach (array_keys(self::LEVEL_COLOURS) as $level) {
            $columns[] = ['label' => $langs->transnoentities($this->riskLevelLabels[$level]), 'width' => 12, 'format' => 'int', 'level' => $level];
        }

        return array_merge($columns, [
            ['label' => $langs->transnoentities('RiskXlsxAverageCotation'), 'width' => 11, 'format' => 'decimal'],
            ['label' => $langs->transnoentities('RiskXlsxMaxCotation'), 'width' => 11, 'format' => 'int'],
            ['label' => $langs->transnoentities('ActionsCount'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('ActionsCompletedCount'), 'width' => 10, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxActionsOverdue'), 'width' => 10, 'format' => 'int'],
        ]);
    }

    /**
     * Cells summing risks up: number by level, cotations and actions
     *
     * @param  array $records Records of the risks
     * @return array          Cells
     */
    protected function getStatsCells(array $records): array
    {
        $levels    = array_fill_keys(array_keys(self::LEVEL_COLOURS), 0);
        $cotations = [];
        $nbActions = 0;
        $nbDone    = 0;
        $nbOverdue = 0;
        foreach ($records as $record) {
            $levels[$record['level']]++;
            if ($record['cotation'] !== null) {
                $cotations[] = $record['cotation'];
            }
            foreach ($record['tasks'] as $taskRecord) {
                $nbActions++;
                $nbDone    += $taskRecord['progress'] >= 100 ? 1 : 0;
                $nbOverdue += $taskRecord['overdue'] ? 1 : 0;
            }
        }

        return array_merge([count($records)], array_values($levels), [
            !empty($cotations) ? round(array_sum($cotations) / count($cotations), 1) : null,
            !empty($cotations) ? max($cotations) : null,
            $nbActions,
            $nbDone,
            $nbOverdue,
        ]);
    }

    /**
     * Sheet by establishment
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addEstablishmentsSheet(array $records): void
    {
        global $langs;

        $recordsByEstablishment = [];
        foreach ($records as $record) {
            $recordsByEstablishment[$record['establishment']][] = $record;
        }
        uksort($recordsByEstablishment, 'strnatcasecmp');

        $lines = [];
        foreach ($recordsByEstablishment as $establishment => $establishmentRecords) {
            $lines[] = array_merge([(string) $establishment], $this->getStatsCells($establishmentRecords));
        }

        $sheet = $this->addSheet('RiskXlsxSheetEstablishments', 'RiskXlsxSheetEstablishmentsDesc');
        $this->writeTable($sheet, 1, array_merge([['label' => $langs->transnoentities('Establishment'), 'width' => 30]], $this->getStatsColumns()), $lines, 1);
    }

    /**
     * Sheet by groupment and work unit
     *
     * @param  array $records              Records of the risks
     * @param  array $protectionsByElement Protection signs, by groupment or work unit id
     * @return void
     */
    protected function addElementsSheet(array $records, array $protectionsByElement): void
    {
        global $langs;

        $lines = [];
        foreach ($this->groupByElement($records) as $elementId => $elementRecords) {
            $lines[] = array_merge($this->getLocationCells($elementRecords[0]), $this->getStatsCells($elementRecords), [count($protectionsByElement[$elementId] ?? [])]);
        }

        $sheet = $this->addSheet('RiskXlsxSheetElements', 'RiskXlsxSheetElementsDesc');
        $this->writeTable($sheet, 1, array_merge($this->getLocationColumns(), $this->getStatsColumns(), [
            ['label' => $langs->transnoentities('RiskXlsxProtectionsNumber'), 'width' => 11, 'format' => 'int'],
        ]), $lines, 3);
    }

    /**
     * Sheet by danger category
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addCategoriesSheet(array $records): void
    {
        global $langs;

        $recordsByCategory = [];
        foreach ($records as $record) {
            $recordsByCategory[$record['category']][] = $record;
        }
        uksort($recordsByCategory, 'strnatcasecmp');

        $lines = [];
        foreach ($recordsByCategory as $category => $categoryRecords) {
            $lines[] = array_merge([(string) $category], $this->getStatsCells($categoryRecords));
        }

        $sheet = $this->addSheet('RiskXlsxSheetCategories', 'RiskXlsxSheetCategoriesDesc');
        $this->writeTable($sheet, 1, array_merge([['label' => $langs->transnoentities('DangerCategory'), 'width' => 46]], $this->getStatsColumns()), $lines, 1);
    }

    /**
     * Sheet mapping the risks: one line per groupment and unit, one column per danger category, the highest
     * cotation at their crossing, coloured as its level
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addMapSheet(array $records): void
    {
        global $langs;

        $categories = [];
        foreach ($records as $record) {
            $categories[(int) $record['risk']->category] = $record['category'];
        }
        uasort($categories, 'strnatcasecmp');

        $columns = $this->getLocationColumns();
        foreach ($categories as $categoryName) {
            $columns[] = ['label' => $categoryName, 'width' => 6, 'format' => 'int'];
        }
        $columns[] = ['label' => $langs->transnoentities('RiskXlsxMaxCotation'), 'width' => 11, 'format' => 'int'];

        $lines = [];
        foreach ($this->groupByElement($records) as $elementRecords) {
            // Highest cotation of the element in each category, -1 for a category whose risks were never assessed
            $maxByCategory = [];
            foreach ($elementRecords as $record) {
                $position                 = (int) $record['risk']->category;
                $maxByCategory[$position] = max($maxByCategory[$position] ?? -1, $record['cotation'] ?? -1);
            }

            $cells = $this->getLocationCells($elementRecords[0]);
            foreach (array_keys($categories) as $position) {
                if (!isset($maxByCategory[$position])) {
                    $cells[] = null;
                } else {
                    $cotation = $maxByCategory[$position] >= 0 ? $maxByCategory[$position] : null;
                    $cells[]  = ['value' => $cotation, 'level' => $this->getRiskLevel($cotation)];
                }
            }
            $elementMax = !empty($maxByCategory) && max($maxByCategory) >= 0 ? max($maxByCategory) : null;
            $cells[]    = ['value' => $elementMax, 'level' => $this->getRiskLevel($elementMax)];

            $lines[] = $cells;
        }

        $sheet   = $this->addSheet('RiskXlsxSheetMap', 'RiskXlsxSheetMapDesc');
        $lastRow = $this->writeTable($sheet, 1, $columns, $lines, 3);

        // Category names upwards, so that the matrix stays narrow
        $firstCategory = Coordinate::stringFromColumnIndex(4);
        $lastCategory  = Coordinate::stringFromColumnIndex(count($columns) - 1);
        if (!empty($categories)) {
            $sheet->getStyle($firstCategory . '1:' . $lastCategory . '1')->getAlignment()->setTextRotation(90)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_BOTTOM);
            $sheet->getStyle($firstCategory . '2:' . Coordinate::stringFromColumnIndex(count($columns)) . max(2, $lastRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getRowDimension(1)->setRowHeight(150);
    }

    /**
     * Sheet of the criteria: how the risks assessed with the advanced method spread over the levels of each criterion
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addCriteriaSheet(array $records): void
    {
        global $langs;

        $lines = [];
        foreach ($this->cotationCriteria as $column => $criterion) {
            $counts  = array_fill_keys(array_keys($criterion['levels']), 0);
            $nbRated = 0;
            foreach ($records as $record) {
                if (isset($record['criteria'][$column])) {
                    $nbRated++;
                    $counts[$record['criteria'][$column]] = ($counts[$record['criteria'][$column]] ?? 0) + 1;
                }
            }
            ksort($counts);
            foreach ($counts as $value => $count) {
                $lines[] = [
                    $langs->transnoentities($criterion['label']),
                    (int) $value,
                    $criterion['levels'][$value] ?? '',
                    $count,
                    $nbRated > 0 ? $count / $nbRated : null,
                ];
            }
        }

        $sheet = $this->addSheet('RiskXlsxSheetCriteria', 'RiskXlsxSheetCriteriaDesc');
        $this->writeTable($sheet, 1, [
            ['label' => $langs->transnoentities('RiskXlsxCriterion'), 'width' => 16],
            ['label' => $langs->transnoentities('RiskXlsxCriterionLevel'), 'width' => 8, 'format' => 'int'],
            ['label' => $langs->transnoentities('Label'), 'width' => 60, 'format' => 'longtext'],
            ['label' => $langs->transnoentities('RiskXlsxRisksNumber'), 'width' => 12, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxShare'), 'width' => 10, 'format' => 'percent'],
        ], $lines, 1);
    }

    /**
     * Sheet of the schedule: the actions due each month, the ones without an end date last
     *
     * @param  array $records Records of the risks
     * @return void
     */
    protected function addScheduleSheet(array $records): void
    {
        global $langs;

        $months = [];
        foreach ($records as $record) {
            foreach ($record['tasks'] as $taskRecord) {
                $month = !empty($taskRecord['dueDate']) ? dol_print_date($taskRecord['dueDate'], '%Y-%m') : '';
                if (!isset($months[$month])) {
                    $months[$month] = ['due' => 0, 'done' => 0, 'overdue' => 0];
                }
                $months[$month]['due']++;
                $months[$month]['done']    += $taskRecord['progress'] >= 100 ? 1 : 0;
                $months[$month]['overdue'] += $taskRecord['overdue'] ? 1 : 0;
            }
        }
        ksort($months);
        // The empty key sorts first: the actions without an end date go last
        if (isset($months[''])) {
            $withoutDate = $months[''];
            unset($months['']);
            $months[''] = $withoutDate;
        }

        $lines = [];
        foreach ($months as $month => $count) {
            if ($month === '') {
                $monthCell = $langs->transnoentities('RiskXlsxNoDueDate');
            } else {
                $monthParts = array_map('intval', explode('-', (string) $month));
                $monthCell  = ExcelDate::formattedPHPToExcel($monthParts[0], $monthParts[1], 1);
            }
            $lines[] = [$monthCell, $count['due'], $count['done'], $count['overdue'], $count['due'] - $count['done']];
        }

        $sheet = $this->addSheet('RiskXlsxSheetSchedule', 'RiskXlsxSheetScheduleDesc');
        $this->writeTable($sheet, 1, [
            ['label' => $langs->transnoentities('Month'), 'width' => 18, 'format' => 'month'],
            ['label' => $langs->transnoentities('RiskXlsxActionsDue'), 'width' => 12, 'format' => 'int'],
            ['label' => $langs->transnoentities('ActionsCompletedCount'), 'width' => 12, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxActionsOverdue'), 'width' => 12, 'format' => 'int'],
            ['label' => $langs->transnoentities('RiskXlsxActionsRemaining'), 'width' => 12, 'format' => 'int'],
        ], $lines, 1);
    }

    /**
     * Write the title of a section of the summary, across its four columns
     *
     * @param  Worksheet $sheet Sheet
     * @param  int       $row   Row
     * @param  string    $label Title
     * @return void
     */
    protected function writeSummarySection(Worksheet $sheet, int $row, string $label): void
    {
        $sheet->setCellValueExplicit('A' . $row, $label, DataType::TYPE_STRING);
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($this->getColourStyle(self::HEAD_COLOURS, true));
    }

    /**
     * Summary: what was exported, the key figures, the risk levels and the contents of the workbook
     *
     * @param  Worksheet $sheet                Sheet, the first of the workbook
     * @param  array     $records              Records of the risks
     * @param  array     $protectionsByElement Protection signs, by groupment or work unit id
     * @return void
     */
    protected function fillSummarySheet(Worksheet $sheet, array $records, array $protectionsByElement): void
    {
        global $langs, $mysoc, $user;

        $this->setSheetTitle($sheet, 'RiskXlsxSheetSummary');
        $sheet->getPageSetup()->setFitToHeight(1);
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(80);

        $sheet->setCellValueExplicit('A1', $langs->transnoentities('SimplifiedRiskList') . ' - ' . $langs->transnoentities($this->riskType === 'riskenvironmental' ? 'RiskTypeEnvironmental' : 'RiskTypeProfessional'), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::HEAD_COLOURS[0]);
        $sheet->setCellValueExplicit('A2', (string) $mysoc->name, DataType::TYPE_STRING);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValueExplicit('A3', $langs->transnoentities('RiskXlsxExportedOn', dol_print_date($this->now, 'dayhour'), $user->getFullName($langs)), DataType::TYPE_STRING);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

        // Filters of the list
        $row = 5;
        $this->writeSummarySection($sheet, $row, $langs->transnoentities('RiskXlsxFilters'));
        foreach ($this->filters as $label => $value) {
            $row++;
            $sheet->setCellValueExplicit('A' . $row, (string) $label, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, (string) $value, DataType::TYPE_STRING);
            $sheet->mergeCells('B' . $row . ':D' . $row);
        }

        // Key figures
        $levels      = array_fill_keys(array_keys(self::LEVEL_COLOURS), 0);
        $cotations   = [];
        $methods     = ['standard' => 0, 'advanced' => 0];
        $elementIds  = [];
        $entityIds   = [];
        $nbActions   = 0;
        $taskCounts  = ['done' => 0, 'inProgress' => 0, 'toDo' => 0, 'overdue' => 0];
        $progressSum = 0;
        foreach ($records as $record) {
            $levels[$record['level']]++;
            if ($record['cotation'] !== null) {
                $cotations[] = $record['cotation'];
                $methods[$record['risk']->ra_method === 'advanced' ? 'advanced' : 'standard']++;
            }
            $elementIds[(int) $record['risk']->element_id] = true;
            $entityIds[(int) $record['risk']->entity]      = true;
            foreach ($record['tasks'] as $taskRecord) {
                $nbActions++;
                $progressSum += $taskRecord['progress'];
                if ($taskRecord['progress'] >= 100) {
                    $taskCounts['done']++;
                } elseif ($taskRecord['progress'] > 0) {
                    $taskCounts['inProgress']++;
                } else {
                    $taskCounts['toDo']++;
                }
                $taskCounts['overdue'] += $taskRecord['overdue'] ? 1 : 0;
            }
        }
        $nbProtections = 0;
        foreach (array_keys($elementIds) as $elementId) {
            $nbProtections += count($protectionsByElement[$elementId] ?? []);
        }
        $nbRisks = count($records);

        // Each figure with its share of the risks, or of the actions, when it is a part of them
        $keyFigures = [
            ['RiskXlsxRisksNumber', $nbRisks, null],
            ['RiskXlsxAssessedRisks', count($cotations), $nbRisks],
            ['RiskXlsxStandardAssessedRisks', $methods['standard'], $nbRisks],
            ['RiskXlsxAdvancedAssessedRisks', $methods['advanced'], $nbRisks],
            ['RiskXlsxAverageCotation', !empty($cotations) ? round(array_sum($cotations) / count($cotations), 1) : null, null],
            ['RiskXlsxMaxCotation', !empty($cotations) ? max($cotations) : null, null],
            ['RiskXlsxEstablishmentsNumber', count($entityIds), null],
            ['RiskXlsxElementsNumber', count($elementIds), null],
            ['RiskXlsxProtectionsNumber', $nbProtections, null],
            ['ActionsCount', $nbActions, null],
            ['ActionsCompletedCount', $taskCounts['done'], $nbActions],
            ['RiskXlsxActionsInProgress', $taskCounts['inProgress'], $nbActions],
            ['RiskXlsxActionsToDo', $taskCounts['toDo'], $nbActions],
            ['RiskXlsxActionsOverdue', $taskCounts['overdue'], $nbActions],
            ['RiskXlsxAverageProgress', $nbActions > 0 ? $progressSum / $nbActions / 100 : null, null],
        ];
        $row += 2;
        $this->writeSummarySection($sheet, $row, $langs->transnoentities('RiskXlsxKeyFigures'));
        foreach ($keyFigures as $keyFigure) {
            $row++;
            $sheet->setCellValueExplicit('A' . $row, $langs->transnoentities($keyFigure[0]), DataType::TYPE_STRING);
            if ($keyFigure[1] !== null) {
                $sheet->setCellValueExplicit('B' . $row, $keyFigure[1], DataType::TYPE_NUMERIC);
            }
            if ($keyFigure[0] === 'RiskXlsxAverageProgress') {
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('0%');
            } elseif ($keyFigure[0] === 'RiskXlsxAverageCotation') {
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('0.0');
            }
            if (!empty($keyFigure[2])) {
                $sheet->setCellValueExplicit('C' . $row, $keyFigure[1] / $keyFigure[2], DataType::TYPE_NUMERIC);
                $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0%');
            }
        }

        // Risk levels, with the cotations they cover, ready for a chart
        $row += 2;
        $this->writeSummarySection($sheet, $row, $langs->transnoentities('RiskLevel'));
        $row++;
        $heads = ['RiskLevel', 'RiskXlsxRisksNumber', 'RiskXlsxShare', 'RiskCotation'];
        foreach ($heads as $index => $head) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1) . $row, $langs->transnoentities($head), DataType::TYPE_STRING);
        }
        $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $minCotation = 0;
        foreach (array_keys(self::LEVEL_COLOURS) as $level) {
            $row++;
            $sheet->setCellValueExplicit('A' . $row, $langs->transnoentities($this->riskLevelLabels[$level]), DataType::TYPE_STRING);
            $sheet->getStyle('A' . $row)->applyFromArray($this->getColourStyle(self::LEVEL_COLOURS[$level], true));
            $sheet->setCellValueExplicit('B' . $row, $levels[$level], DataType::TYPE_NUMERIC);
            if ($nbRisks > 0) {
                $sheet->setCellValueExplicit('C' . $row, $levels[$level] / $nbRisks, DataType::TYPE_NUMERIC);
                $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('0%');
            }
            if (isset($this->cotationLevelMax[$level])) {
                $sheet->setCellValueExplicit('D' . $row, $minCotation . ' - ' . $this->cotationLevelMax[$level], DataType::TYPE_STRING);
                $minCotation = $this->cotationLevelMax[$level] + 1;
            }
        }

        // Contents, each sheet a link
        $row += 2;
        $this->writeSummarySection($sheet, $row, $langs->transnoentities('RiskXlsxContents'));
        $row++;
        $heads = ['RiskXlsxSheet', 'RiskXlsxLines', '', 'Description'];
        foreach ($heads as $index => $head) {
            if ($head !== '') {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1) . $row, $langs->transnoentities($head), DataType::TYPE_STRING);
            }
        }
        $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
        $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach ($this->contents as $content) {
            $row++;
            $title = $content['sheet']->getTitle();
            $sheet->setCellValueExplicit('A' . $row, $title, DataType::TYPE_STRING);
            $sheet->getCell('A' . $row)->getHyperlink()->setUrl("sheet://'" . str_replace("'", "''", $title) . "'!A1");
            $sheet->getStyle('A' . $row)->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');
            $sheet->setCellValueExplicit('B' . $row, $content['lines'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('D' . $row, $langs->transnoentities($content['description']), DataType::TYPE_STRING);
        }
        $sheet->getStyle('D1:D' . $row)->getAlignment()->setWrapText(true)->setIndent(1);
    }
}
