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
 * \file    class/actions_digiboard.class.php
 * \ingroup digiboard
 * \brief   DigiBoard hook overload
 */

/**
 * Class ActionsDigiboard
 */
class ActionsDigiboard
{
    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Error code (or message)
     */
    public string $error = '';

    /**
     * @var array Errors.
     */
    public array $errors = [];

    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
     */
    public array $results = [];

    /**
     * @var string|null String displayed by executeHook() immediately after return
     */
    public ?string $resprints;

    /**
     * Constructor
     *
     *  @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Overloading the addHtmlHeader function : replacing the parent's function with the one below
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function addHtmlHeader(array $parameters): int
    {
        if (strpos($parameters['context'], 'digiboardindex') !== false) {
            $resourcesRequired = [
                'css'        => '/custom/digiriskdolibarr/css/digiriskdolibarr.min.css',
                'cssSaturne' => '/custom/saturne/css/saturne.min.css',
            ];

            $out  = '<!-- Includes CSS added by module digiriskdolibarr -->';
            $out .= '<link rel="stylesheet" type="text/css" href="' . dol_buildpath($resourcesRequired['css'], 1) . '">';
            $out .= '<link rel="stylesheet" type="text/css" href="' . dol_buildpath($resourcesRequired['cssSaturne'], 1) . '">';

            $this->resprints = $out;
        }

        return 0; // or return 1 to replace standard code
    }

    /**
     * Overloading the printUserListWhere function : replacing the parent's function with the one below
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function printUserListWhere(array $parameters): int
    {
        if (strpos($parameters['context'], 'digiboardindex') !== false) {
            $this->resprints = ' WHERE 1 = 1';
            return 1;
        }

        return 0; // or return 1 to replace standard code
    }

    /**
     * Overloading the checkSecureAccess function : replacing the parent's function with the one below
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function checkSecureAccess(array $parameters): int
    {
        global $conf;

        if (strpos($parameters['context'], 'main') !== false) {
            // Since Dolibarr 24, $parameters['entity'] is the session entity for a logged user: main.inc.php
            // overwrites the global $entity document.php read from the URL. Read the requested one ourselves
            $entity = GETPOSTISSET('entity') ? GETPOSTINT('entity') : (int) $parameters['entity'];

            // Only documents of another entity need their path rewritten: those of the current one are already right
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            if ($parameters['modulepart'] == 'digiriskdolibarr' && $entity > 0 && $entity != (int) $conf->entity && strpos($referer, dol_buildpath('custom/digiboard/index.php', 1)) !== false) {
                $filePath                       = preg_split('/' . $parameters['modulepart'] . '/', $parameters['original_file']);
                $this->results['original_file'] = $filePath[0] . $entity . '/' . $parameters['modulepart'] . $filePath[1];
                return 1;
            }
        }

        return 0; // or return 1 to replace standard code
    }
}
