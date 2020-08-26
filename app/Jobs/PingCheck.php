<?php
/**
 * PingCheck.php
 *
 * Device up/down icmp check job
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
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Jobs;

use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use LibreNMS\Config;
use LibreNMS\Sping;
use LibreNMS\RRD\RrdDefinition;
use Symfony\Component\Process\Process;
use LibreNMS\Alert\AlertRules;
use Log;

class PingCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $process;
    private $rrd_tags;

    private $use_snmp_ping;
    private $record_snmp_ping_rtt;

    /** @var \Illuminate\Database\Eloquent\Collection $devices List of devices keyed by hostname */
    private $devices;
    /** @var array $groups List of device group ids to check */
    private $groups = [];

    // working data for loop
    /** @var Collection $tiered */
    private $tiered;
    /** @var Collection $current */
    private $current;
    private $current_tier;
    /** @var Collection $deferred */
    private $deferred;

    /**
     * Create a new job instance.
     *
     * @param array $groups List of distributed poller groups to check
     */
    public function __construct($groups = [])
    {
        if (is_array($groups)) {
            $this->groups = $groups;
        }

        // define rrd tags
        $rrd_step = Config::get('ping_rrd_step', Config::get('rrd.step', 300));
        $rrd_def = RrdDefinition::make()->addDataset('ping', 'GAUGE', 0, 65535, $rrd_step * 2);
        $this->rrd_tags = ['rrd_def' => $rrd_def, 'rrd_step' => $rrd_step];
        $this->use_snmp_ping = Config::get('use_snmp_ping') === true ? true : false;

        if ($this->use_snmp_ping) {
            // set up sping configuration
            $this->record_snmp_ping_rtt = Config::get('record_snmp_ping_rtt') === true ? true : false;
            $this->process = NULL;
        } else {
            // set up fping process
            $timeout = Config::get('fping_options.timeout', 500); // must be smaller than period
            $retries = Config::get('fping_options.retries', 2);  // how many retries on failure
            $cmd = ['fping', '-f', '-', '-e', '-t', $timeout, '-r', $retries];
            $wait = Config::get('rrd.step', 300) * 2;
            $this->process = new Process($cmd, null, null, null, $wait);
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ping_start = microtime(true);

        $this->fetchDevices();
        $device_set = $this->tiered->get(1, collect())->keys()// root nodes before standalone nodes
            ->merge($this->devices->keys())
            ->unique();

        if ($this->use_snmp_ping) {
            $this->handle_sping($device_set);
        } else {
            $this->handle_fping($device_set);
        }

        // check for any left over devices
        if ($this->deferred->isNotEmpty()) {
            d_echo("Leftover devices, this shouldn't happen: " . $this->deferred->flatten(1)->implode('hostname', ', ') . PHP_EOL);
            d_echo("Devices left in tier: " . collect($this->current)->implode('hostname', ', ') . PHP_EOL);
        }

        if (\App::runningInConsole()) {
            printf("Pinged %s devices in %.2fs\n", $this->devices->count(), microtime(true) - $ping_start);
        }
    }

    private function handle_fping($device_set)
    {
        d_echo($this->process->getCommandLine() . PHP_EOL);

        // send hostnames to stdin to avoid overflowing cli length limits
        $fping_input = $device_set->implode(PHP_EOL);
        $this->process->setInput($fping_input);
        $this->process->start(); // start as early as possible

        foreach ($this->process as $type => $line) {
            d_echo($line);

            if (Process::ERR === $type) {
                // Check for devices we couldn't resolve dns for
                if (preg_match('/^(?<hostname>[^\s]+): (?:Name or service not known|Temporary failure in name resolution)/', $line, $errored)) {
                    $this->recordData([
                        'hostname' => $errored['hostname'],
                        'status' => 'unreachable'
                    ]);
                }
                continue;
            }

            if (preg_match(
                '/^(?<hostname>[^\s]+) is (?<status>alive|unreachable)(?: \((?<rtt>[\d.]+) ms\))?/',
                $line,
                $captured
            )) {
                $this->recordData($captured);
                $this->processTier();
            }
        }
    }

    private function handle_sping($device_set)
    {
        $probe = new Sping;
	$targets = $this->devices->intersectByKeys($device_set->flip())->values();
        foreach ($targets as $t) {
            $response = $probe->sping($t);
            if ($response['result'] === true) {
                $this->recordData(['hostname' => $t['hostname'], 'status' => 'alive', 'rtt' => $response['last_ping_timetaken']]);
                $this->processTier();
            } else {
                $this->recordData(['hostname' => $t['hostname'], 'status' => 'unreachable', 'rtt' => (float)0.0]);
            }
        }
    }

    private function fetchDevices()
    {
        if (isset($this->devices)) {
            return $this->devices;
        }

        global $vdebug;

        $base_columns = ['devices.device_id', 'hostname', 'status', 'status_reason', 'last_ping', 'last_ping_timetaken', 'max_depth'];
        $snmp_columns = ['transport', 'port', 'snmpver', 'community', 'authname', 'authlevel', 'authalgo', 'authpass', 'cryptoalgo', 'cryptopass'];
        /** @var Builder $query */
        $query = Device::canPing()->select(array_merge($base_columns, ($this->use_snmp_ping ? $snmp_columns : [])))->orderBy('max_depth');

        if ($this->groups) {
            $query->whereIn('poller_group', $this->groups);
        }

        $this->devices = $query->get()->keyBy('hostname');

        // working collections
        $this->tiered = $this->devices->groupBy('max_depth', true);
        $this->deferred = collect();

        // start with tier 1 (the root nodes, 0 is standalone)
        $this->current_tier = 1;
        $this->current = $this->tiered->get($this->current_tier, collect());

        if ($vdebug) {
            $this->tiered->each(function (Collection $tier, $index) {
                echo "Tier $index (" . $tier->count() . "): ";
                echo $tier->implode('hostname', ', ');
                echo PHP_EOL;
            });
        }

        return $this->devices;
    }

    /**
     * Check if this tier is complete and move to the next tier
     * If we moved to the next tier, check if we can report any of our deferred results
     */
    private function processTier()
    {
        global $vdebug;

        if ($this->current->isNotEmpty()) {
            return;
        }

        $this->current_tier++;  // next tier

        if (!$this->tiered->has($this->current_tier)) {
            // out of devices
            return;
        }

        if ($vdebug) {
            echo "Out of devices at this tier, moving to tier $this->current_tier\n";
        }

        $this->current = $this->tiered->get($this->current_tier);

        // update and remove devices in the current tier
        foreach ($this->deferred->pull($this->current_tier, []) as $data) {
            $this->recordData($data);
        }

        // try to process the new tier in case we took care of all the devices
        $this->processTier();
    }

    /**
     * If the device is on the current tier, record the data and remove it
     * $data should have keys: hostname, status, and conditionally rtt
     *
     * @param $data
     */
    private function recordData($data)
    {
        global $vdebug;

        if ($vdebug) {
            echo "Attempting to record data for {$data['hostname']}... ";
        }

        /** @var Device $device */
        $device = $this->devices->get($data['hostname']);

        // process the data if this is a standalone device or in the current tier
        if ($device->max_depth === 0 || $this->current->has($device->hostname)) {
            if ($vdebug) {
                echo "Success\n";
            }

            // mark up only if snmp is not down too
            $device->status = ($data['status'] == 'alive' && $device->status_reason != 'snmp');
            $device->last_ping = Carbon::now();
            if (isset($data['rtt'])) {
                if ($this->use_snmp_ping) {
                    $device->last_ping_timetaken = ($this->record_snmp_ping_rtt ? floatval($data['rtt']) : (float)0.0);
                } else {
                    $device->last_ping_timetaken = floatval($data['rtt']);
                }
            } else {
                $device->last_ping_timetaken = (float)0.0;
            }

            if ($device->isDirty('status')) {
                // if changed, update reason
                $device->status_reason = $device->status ? '' : ($this->use_snmp_ping ? 'snmp_ping' : 'icmp');
                $type = $device->status ? 'up' : 'down';
                Log::event('Device status changed to ' . ucfirst($type) . " from ping check.", $device->device_id, $type);
            }

            $device->save(); // only saves if needed (which is every time because of last_ping)

            echo "Device $device->hostname changed status to $type, running alerts\n";
            $rules = new AlertRules;
            $rules->runRules($device->device_id);

            // add data to rrd
            app('Datastore')->put($device->toArray(), 'ping-perf', $this->rrd_tags, ['ping' => $device->last_ping_timetaken]);

            // done with this device
            $this->complete($device->hostname);
            d_echo("Recorded data for $device->hostname (tier $device->max_depth)\n");
        } else {
            if ($vdebug) {
                echo "Deferred\n";
            }

            $this->defer($data);
        }
    }

    /**
     * Done processing $hostname, remove it from our active data
     *
     * @param $hostname
     */
    private function complete($hostname)
    {
        $this->current->offsetUnset($hostname);
        $this->deferred->each->offsetUnset($hostname);
    }

    /**
     * Defer this data processing until all parent devices are complete
     *
     *
     * @param $data
     */
    private function defer($data)
    {
        $device = $this->devices->get($data['hostname']);

        if ($this->deferred->has($device->max_depth)) {
            // add this data to the proper tier, unless it already exists...
            $tier = $this->deferred->get($device->max_depth);
            if (!$tier->has($device->hostname)) {
                $tier->put($device->hostname, $data);
            }
        } else {
            // create a new tier containing this data
            $this->deferred->put($device->max_depth, collect([$device->hostname => $data]));
        }
    }
}
