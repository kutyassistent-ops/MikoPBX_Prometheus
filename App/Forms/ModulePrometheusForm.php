<?php
/*
 * ModulePrometheus - Prometheus exporter for MikoPBX
 * Copyright (C) 2026 Community
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
namespace Modules\ModulePrometheus\App\Forms;

use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Hidden;

class ModulePrometheusForm extends Form
{
    public function initialize($entity = null, $options = null): void
    {
        // id: the value of the stored record. Without it the controller
        // could not address the row and always fell back to findFirst().
        $this->add(new Hidden('id', ['value' => (string)($entity->id ?? '')]));
    }
}
