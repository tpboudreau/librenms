<?php
/**
 * Sping.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2020 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS;

use Log;
use Symfony\Component\Process\Process;

class Sping
{
    /**
     * Run sping against a device and collect stats.
     *
     * @param object $arguments
     * @return object
     */
    public function sping($arguments)
    {
        $tool = Config::get('snmpstatus', '/usr/bin/snmpstatus');
        $timeout = Config::get('snmp_ping_timeout', 5);
        $retries = Config::get('snmp_ping_retries', 1);
        $env = ['SPING_TOOL' => "$tool", 'SPING_RETRIES' => "$retries", 'SPING_TIMEOUT' => "$timeout"];
        $cmd = [Config::get('install_dir', '/opt/librenms') . "/sping", $arguments];

        $process = new Process($cmd, null, $env);
        $process->run();
        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            if (preg_match('/succeeded \((?<elapsed>[\d.]+) ms\)/', $output, $values)) {
                $rtt = (Config::get('record_snmp_ping_rtt') === true ? floatval($values['elapsed']) : (float)0.0);
                $response = [
                    'result' => true,
                    'last_ping_timetaken' => $rtt,
                    'db' => [
                        'xmt' => (int)1,
                        'rcv' => (int)1,
                        'loss' => 0.0,
                        'min' => $rtt,
                        'max' => $rtt,
                        'avg' => $rtt
                    ]
                ];
            } else {
                $response = [
                    'result' => false,
                    'last_ping_timetaken' => (float)0.0,
                    'db' => [
                        'xmt' => (int)1,
                        'rcv' => (int)0,
                        'loss' => (float)100.0,
                        'min' => (float)0.0,
                        'max' => (float)0.0,
                        'avg' =>(float)0.0
                    ]
                ];
            }
        } else {
            $response = [
                'result' => false,
                'last_ping_timetaken' => (float)0.0,
                'db' => [
                    'xmt' => (int)1,
                    'rcv' => (int)0,
                    'loss' => (float)100.0,
                    'min' => (float)0.0,
                    'max' => (float)0.0,
                    'avg' =>(float)0.0
                ]
            ];
        }

        return $response;
    }
}
